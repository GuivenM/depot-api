<?php

namespace App\Services;

use App\Exceptions\DepotException;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function __construct(private StockService $stock, private CreditService $credit, private ContainerService $containers)
    {
    }

    /**
     * Enregistre une vente. Idempotent sur `uuid` : un renvoi (hors ligne, double clic)
     * retourne la vente existante sans rien dupliquer.
     *
     * @return array{0: Sale, 1: bool} [vente, créée maintenant ?]
     */
    public function create(User $by, array $data): array
    {
        if ($existing = Sale::where('uuid', $data['uuid'])->first()) {
            return [$existing->load('items'), false];
        }

        try {
            $sale = DB::transaction(fn () => $this->record($by, $data));
        } catch (UniqueConstraintViolationException) {
            // deux requêtes simultanées avec le même uuid : l'autre a gagné
            return [Sale::where('uuid', $data['uuid'])->firstOrFail()->load('items'), false];
        }

        return [$sale->load('items'), true];
    }

    /**
     * Annule une vente : restitue le stock, la dette (et l'acompte), les casiers ; la vente sort de la caisse.
     * Réservé aux ventes dont la caisse est encore ouverte. Gérant : toute vente ; autres rôles : les leurs.
     */
    public function void(User $by, int $saleId, string $reason): Sale
    {
        return DB::transaction(function () use ($by, $saleId, $reason) {
            $sale = Sale::findOrFail($saleId);

            // Ordre des verrous (comme à la vente) : caisse -> client -> produits -> casiers.
            $session = CashSession::lockForUpdate()->findOrFail($sale->cash_session_id);
            $sale = Sale::lockForUpdate()->findOrFail($saleId);

            if ($sale->status === 'voided') {
                throw new DepotException('Cette vente est déjà annulée.', 409);
            }
            if ($by->role !== 'gerant' && $sale->user_id !== $by->id) {
                throw new DepotException('Vous ne pouvez annuler que vos propres ventes.', 403);
            }
            if ($session->closed_at) {
                throw new DepotException('La caisse de cette vente est clôturée : annulation impossible.', 409);
            }

            $customer = $sale->payment_method === 'credit'
                ? Customer::lockForUpdate()->findOrFail($sale->customer_id)
                : null;

            $movements = StockMovement::where('reference_type', $sale->getMorphClass())
                ->where('reference_id', $sale->id)
                ->where('type', 'sortie')
                ->orderBy('product_id')
                ->get();

            foreach ($movements as $m) {
                $this->stock->move(Product::findOrFail($m->product_id), 'annulation', -$m->quantity, $by, $sale, 'Annulation vente #'.$sale->id);
            }

            if ($customer) {
                $this->credit->voidSale($customer, $sale, $by, $reason);
            }

            $this->containers->reverse($by, $sale, $reason);

            $sale->update([
                'status'      => 'voided',
                'voided_at'   => now(),
                'voided_by'   => $by->id,
                'void_reason' => $reason,
            ]);

            app(AuditService::class)->record($by, 'sale.voided', $sale, [
                'total' => (int) $sale->total, 'payment_method' => $sale->payment_method,
                'customer_id' => $sale->customer_id, 'sold_by' => $sale->user_id,
            ], $reason);

            return $sale->load('items');
        });
    }

    private function record(User $by, array $data): Sale
    {
        // Verrou sur la caisse : une clôture concurrente attend la fin de la vente.
        $session = CashSession::open()->where('user_id', $by->id)->lockForUpdate()->first();

        if (! $session) {
            throw DepotException::noOpenCashSession();
        }

        $isCredit = $data['payment_method'] === 'credit';
        $customer = null;

        if (! empty($data['customer_id'])) {
            $customer = Customer::lockForUpdate()->findOrFail($data['customer_id']);

            if (! $customer->active) {
                throw new DepotException('Ce client est désactivé.');
            }
        }

        if ($isCredit && ! $customer) {
            throw new DepotException('Un client est requis pour une vente à crédit.');
        }

        // Tri par produit : ordre de verrouillage constant, pas de deadlock entre ventes.
        $lines = collect($data['items'])->sortBy('product_id')->values();
        $products = Product::whereIn('id', $lines->pluck('product_id')->unique())->get()->keyBy('id');

        $prepared = [];
        $soldByType = [];   // casiers pleins vendus par type de casier
        $total = 0;

        foreach ($lines as $line) {
            $product = $products[$line['product_id']];

            if (! $product->active) {
                throw new DepotException("« {$product->name} » n'est plus en vente.");
            }

            $isCrate = $line['unit'] === 'crate';
            $price = $isCrate ? $product->price_crate : $product->price_bottle;

            if (is_null($price)) {
                throw new DepotException("« {$product->name} » n'a pas de prix à la bouteille.");
            }

            $quantity = (int) $line['quantity'];

            if ($isCrate && $product->container_type_id) {
                $soldByType[$product->container_type_id] = ($soldByType[$product->container_type_id] ?? 0) + $quantity;
            }
            $lineTotal = (int) $price * $quantity;
            $total += $lineTotal;

            $prepared[] = [
                'product_id' => $product->id,
                'unit'       => $line['unit'],
                'quantity'   => $quantity,
                'bottles'    => $isCrate ? $quantity * $product->bottles_per_crate : $quantity,
                'unit_price' => (int) $price,    // le prix vient toujours du serveur
                'line_total' => $lineTotal,
            ];
        }

        $paidNow = 0;
        $limitOverride = null;

        if ($isCredit) {
            $paidNow = (int) ($data['paid_now'] ?? 0);

            if ($paidNow >= $total) {
                throw new DepotException('Le paiement immédiat couvre tout le total : enregistrez une vente comptant.');
            }
            if ($paidNow > 0 && empty($data['paid_method'])) {
                throw new DepotException('Précisez le mode du paiement immédiat (cash ou momo).');
            }

            $newDebt = $total - $paidNow;
            $override = $by->role === 'gerant' && ! empty($data['override_limit']);

            if ($override && $customer->balance + $newDebt > $customer->credit_limit) {
                $limitOverride = [
                    'customer_id' => $customer->id, 'balance_before' => (int) $customer->balance,
                    'new_debt' => $newDebt, 'credit_limit' => (int) $customer->credit_limit,
                ];
            }

            if (! $override && $customer->balance + $newDebt > $customer->credit_limit) {
                throw new DepotException(
                    "Limite de crédit dépassée (dette actuelle {$customer->balance} FCFA, limite {$customer->credit_limit} FCFA)."
                );
            }
        }

        $sale = Sale::create([
            'uuid'            => $data['uuid'],
            'cash_session_id' => $session->id,
            'user_id'         => $by->id,
            'customer_id'     => $customer?->id,
            'payment_method'  => $data['payment_method'],
            'total'           => $total,
            'status'          => 'completed',
            'sold_at'         => $data['sold_at'] ?? now(),
        ]);

        if ($limitOverride) {   // le gérant a vendu au-delà de la limite de crédit du client
            app(AuditService::class)->record($by, 'sale.limit_overridden', $sale, $limitOverride);
        }

        foreach ($prepared as $row) {
            $sale->items()->create($row);
            $this->stock->move($products[$row['product_id']], 'sortie', -$row['bottles'], $by, $sale, 'Vente #'.$sale->id);
        }

        $returnedByType = [];
        foreach ($data['empties_returned'] ?? [] as $r) {
            $id = (int) $r['container_type_id'];
            $returnedByType[$id] = ($returnedByType[$id] ?? 0) + (int) $r['quantity'];
        }

        if ($soldByType || $returnedByType) {
            $this->containers->applySale($by, $customer, $sale, $soldByType, $returnedByType);
        }

        if ($isCredit) {
            $this->credit->charge($customer, $total, $by, $sale, 'Vente #'.$sale->id);

            if ($paidNow > 0) {
                $this->credit->applyPayment($customer, $by, $paidNow, $data['paid_method'], $session, $sale, note: 'Acompte vente #'.$sale->id);
            }
        }

        return $sale;
    }
}
