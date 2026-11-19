<?php

namespace App\Services;

use App\Exceptions\DepotException;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seul point d'entrée pour modifier la dette d'un client.
 * Les méthodes charge/applyPayment supposent que $customer a été chargé avec lockForUpdate()
 * à l'intérieur d'une transaction (ordre des verrous : caisse -> client -> produits).
 */
class CreditService
{
    public function charge(Customer $customer, int $amount, User $by, Model $ref, ?string $note = null): void
    {
        $this->post($customer, 'sale_credit', $amount, $by, $ref, $note);
    }

    public function applyPayment(
        Customer $customer,
        User $by,
        int $amount,
        string $method,
        ?CashSession $session,
        ?Sale $sale = null,
        ?string $uuid = null,
        ?string $note = null,
    ): Payment {
        $payment = Payment::create([
            'uuid'            => $uuid ?? (string) Str::uuid(),
            'customer_id'     => $customer->id,
            'cash_session_id' => $session?->id,
            'sale_id'         => $sale?->id,
            'user_id'         => $by->id,
            'amount'          => $amount,
            'method'          => $method,
            'note'            => $note,
            'paid_at'         => now(),
        ]);

        $this->post($customer, 'payment', -$amount, $by, $payment, $note);

        return $payment;
    }

    /**
     * Encaissement d'un remboursement de dette. Idempotent sur `uuid`.
     *
     * @return array{0: Payment, 1: bool} [paiement, créé maintenant ?]
     */
    public function recordPayment(User $by, int $customerId, array $data): array
    {
        if ($existing = Payment::where('uuid', $data['uuid'])->first()) {
            return [$existing, false];
        }

        try {
            $payment = DB::transaction(fn () => $this->doPayment($by, $customerId, $data));
        } catch (UniqueConstraintViolationException) {
            return [Payment::where('uuid', $data['uuid'])->firstOrFail(), false];
        }

        return [$payment, true];
    }

    private function doPayment(User $by, int $customerId, array $data): Payment
    {
        $open = CashSession::open()->where('user_id', $by->id);

        // Le cash entre dans le tiroir : une caisse ouverte est obligatoire. MoMo : facultative.
        $session = $data['method'] === 'cash'
            ? ((clone $open)->lockForUpdate()->first() ?? throw DepotException::noOpenCashSession())
            : (clone $open)->first();

        $customer = Customer::lockForUpdate()->findOrFail($customerId);

        if ($data['amount'] > $customer->balance) {
            throw new DepotException("Le paiement dépasse la dette du client ({$customer->balance} FCFA).");
        }

        return $this->applyPayment(
            $customer, $by, (int) $data['amount'], $data['method'], $session,
            uuid: $data['uuid'], note: $data['note'] ?? null,
        );
    }

    /**
     * Annule la dette d'une vente à crédit et son acompte éventuel (l'acompte est marqué annulé : il sort de la caisse).
     * Refusé si la dette du client deviendrait négative (il a déjà remboursé davantage que le reste dû).
     */
    public function voidSale(Customer $customer, Sale $sale, User $by, string $reason): void
    {
        $charges = CustomerLedgerEntry::where('reference_type', $sale->getMorphClass())
            ->where('reference_id', $sale->id)
            ->where('type', 'sale_credit')
            ->get();

        foreach ($charges as $charge) {
            $this->post($customer, 'void', -$charge->amount, $by, $sale, $reason);
        }

        foreach (Payment::where('sale_id', $sale->id)->whereNull('voided_at')->get() as $payment) {
            $payment->update(['voided_at' => now()]);
            $this->post($customer, 'void', $payment->amount, $by, $payment, $reason);
        }

        if ($customer->balance < 0) {
            throw new DepotException(
                "Annulation impossible : le client a déjà remboursé plus que le reste de cette vente. Corrigez d'abord sa dette (ajustement)."
            );
        }
    }

    /**
     * Correction ou abandon de créance (gérant). $amount signé : < 0 réduit la dette, > 0 l'augmente.
     *
     * @return array{0: Customer, 1: CustomerLedgerEntry}
     */
    public function adjust(User $by, int $customerId, int $amount, string $note): array
    {
        return DB::transaction(function () use ($by, $customerId, $amount, $note) {
            $customer = Customer::lockForUpdate()->findOrFail($customerId);

            if ($customer->balance + $amount < 0) {
                throw new DepotException('Cet ajustement rendrait la dette négative.');
            }

            $before = (int) $customer->balance;
            $entry = $this->post($customer, 'adjustment', $amount, $by, null, $note);
            app(AuditService::class)->record($by, 'customer.debt_adjusted', $customer, [
                'amount' => $amount, 'balance_before' => $before, 'balance_after' => (int) $customer->balance,
            ], $note);

            return [$customer->fresh(), $entry];
        });
    }

    private function post(Customer $customer, string $type, int $amount, User $by, ?Model $ref, ?string $note): CustomerLedgerEntry
    {
        $customer->balance = (int) $customer->balance + $amount;
        $customer->save();

        return CustomerLedgerEntry::create([
            'customer_id'    => $customer->id,
            'type'           => $type,
            'amount'         => $amount,
            'balance_after'  => $customer->balance,
            'reference_type' => $ref?->getMorphClass(),
            'reference_id'   => $ref?->getKey(),
            'user_id'        => $by->id,
            'note'           => $note,
        ]);
    }
}
