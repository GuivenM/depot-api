<?php

namespace App\Services;

use App\Exceptions\DepotException;
use App\Models\CashSession;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seul point d'entrée pour modifier la dette envers un fournisseur.
 * charge/applyPayment supposent $supplier chargé avec lockForUpdate() dans une transaction
 * (ordre des verrous : caisse -> fournisseur -> produits -> types de casiers -> soldes).
 */
class SupplierService
{
    public function charge(Supplier $supplier, int $amount, User $by, Model $ref, ?string $note = null): void
    {
        $this->post($supplier, 'purchase', $amount, $by, $ref, $note);
    }

    public function applyPayment(
        Supplier $supplier,
        User $by,
        int $amount,
        string $method,
        ?CashSession $session,
        ?Purchase $purchase = null,
        ?string $uuid = null,
        ?string $note = null,
    ): SupplierPayment {
        $payment = SupplierPayment::create([
            'uuid'            => $uuid ?? (string) Str::uuid(),
            'supplier_id'     => $supplier->id,
            'cash_session_id' => $session?->id,
            'purchase_id'     => $purchase?->id,
            'user_id'         => $by->id,
            'amount'          => $amount,
            'method'          => $method,
            'note'            => $note,
            'paid_at'         => now(),
        ]);

        $this->post($supplier, 'payment', -$amount, $by, $payment, $note);

        return $payment;
    }

    /**
     * Règlement d'une dette fournisseur. Idempotent sur `uuid`.
     *
     * @return array{0: SupplierPayment, 1: bool}
     */
    public function recordPayment(User $by, int $supplierId, array $data): array
    {
        if ($existing = SupplierPayment::where('uuid', $data['uuid'])->first()) {
            return [$existing, false];
        }

        try {
            $payment = DB::transaction(fn () => $this->doPayment($by, $supplierId, $data));
        } catch (UniqueConstraintViolationException) {
            return [SupplierPayment::where('uuid', $data['uuid'])->firstOrFail(), false];
        }

        return [$payment, true];
    }

    private function doPayment(User $by, int $supplierId, array $data): SupplierPayment
    {
        // Un règlement en cash sort du tiroir : une caisse ouverte est obligatoire.
        $session = $data['method'] === 'cash'
            ? (CashSession::open()->where('user_id', $by->id)->lockForUpdate()->first() ?? throw DepotException::noOpenCashSession())
            : null;

        $supplier = Supplier::lockForUpdate()->findOrFail($supplierId);

        if ($data['amount'] > $supplier->balance) {
            throw new DepotException("Le paiement dépasse la dette envers ce fournisseur ({$supplier->balance} FCFA).");
        }

        return $this->applyPayment(
            $supplier, $by, (int) $data['amount'], $data['method'], $session,
            uuid: $data['uuid'], note: $data['note'] ?? null,
        );
    }

    /**
     * Correction de la dette (gérant). $amount signé.
     *
     * @return array{0: Supplier, 1: SupplierLedgerEntry}
     */
    public function adjust(User $by, int $supplierId, int $amount, string $note): array
    {
        return DB::transaction(function () use ($by, $supplierId, $amount, $note) {
            $supplier = Supplier::lockForUpdate()->findOrFail($supplierId);

            if ($supplier->balance + $amount < 0) {
                throw new DepotException('Cet ajustement rendrait la dette négative.');
            }

            $entry = $this->post($supplier, 'adjustment', $amount, $by, null, $note);

            return [$supplier->fresh(), $entry];
        });
    }

    private function post(Supplier $supplier, string $type, int $amount, User $by, ?Model $ref, ?string $note): SupplierLedgerEntry
    {
        $supplier->balance = (int) $supplier->balance + $amount;
        $supplier->save();

        return SupplierLedgerEntry::create([
            'supplier_id'    => $supplier->id,
            'type'           => $type,
            'amount'         => $amount,
            'balance_after'  => $supplier->balance,
            'reference_type' => $ref?->getMorphClass(),
            'reference_id'   => $ref?->getKey(),
            'user_id'        => $by->id,
            'note'           => $note,
        ]);
    }
}
