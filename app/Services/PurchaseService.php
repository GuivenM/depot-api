<?php

namespace App\Services;

use App\Exceptions\DepotException;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(
        private StockService $stock,
        private SupplierService $suppliers,
        private ContainerService $containers,
    ) {
    }

    /**
     * Enregistre une réception de marchandise. Idempotent sur `uuid`.
     *
     * @return array{0: Purchase, 1: bool} [achat, créé maintenant ?]
     */
    public function create(User $by, array $data): array
    {
        if ($existing = Purchase::where('uuid', $data['uuid'])->first()) {
            return [$existing->load('items'), false];
        }

        try {
            $purchase = DB::transaction(fn () => $this->record($by, $data));
        } catch (UniqueConstraintViolationException) {
            return [Purchase::where('uuid', $data['uuid'])->firstOrFail()->load('items'), false];
        }

        return [$purchase->load('items'), true];
    }

    /**
     * Annule une réception (gérant) : retire la marchandise du stock (refusé si elle est déjà vendue),
     * annule la dette et les règlements, rend les casiers. Les règlements cash doivent appartenir à des caisses ouvertes.
     */
    public function void(User $by, int $purchaseId, string $reason): Purchase
    {
        return DB::transaction(function () use ($by, $purchaseId, $reason) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchaseId);

            if ($purchase->status === 'voided') {
                throw new DepotException('Cet achat est déjà annulé.', 409);
            }

            // Ordre des verrous : caisse -> fournisseur -> produits -> casiers.
            $sessionIds = SupplierPayment::where('purchase_id', $purchase->id)
                ->whereNull('voided_at')->where('method', 'cash')
                ->pluck('cash_session_id')->unique()->sort();

            foreach ($sessionIds as $sessionId) {
                if (CashSession::lockForUpdate()->findOrFail($sessionId)->closed_at) {
                    throw new DepotException('Un règlement en cash de cet achat appartient à une caisse clôturée : annulation impossible.', 409);
                }
            }

            $supplier = Supplier::lockForUpdate()->findOrFail($purchase->supplier_id);

            $movements = StockMovement::where('reference_type', $purchase->getMorphClass())
                ->where('reference_id', $purchase->id)
                ->where('type', 'entree')
                ->orderBy('product_id')
                ->get();

            foreach ($movements as $m) {
                // Lève InsufficientStock si la marchandise a déjà été vendue.
                $this->stock->move(Product::findOrFail($m->product_id), 'annulation', -$m->quantity, $by, $purchase, 'Annulation achat #'.$purchase->id);
            }

            $this->suppliers->voidPurchase($supplier, $purchase, $by, $reason);
            $this->containers->reverse($by, $purchase, $reason);

            $purchase->update([
                'status'      => 'voided',
                'voided_at'   => now(),
                'voided_by'   => $by->id,
                'void_reason' => $reason,
            ]);

            app(AuditService::class)->record($by, 'purchase.voided', $purchase, [
                'total' => (int) $purchase->total, 'supplier_id' => $purchase->supplier_id,
            ], $reason);

            return $purchase->load('items');
        });
    }

    private function record(User $by, array $data): Purchase
    {
        $paidNow = (int) ($data['paid_now'] ?? 0);
        $method = $data['paid_method'] ?? null;

        if ($paidNow > 0) {
            if ($by->role !== 'gerant') {
                throw new DepotException('Seul le gérant peut régler un fournisseur.', 403);
            }
            if (! $method) {
                throw new DepotException('Précisez le mode de paiement (cash, momo ou bank).');
            }
        }

        // Ordre des verrous : caisse -> fournisseur -> produits -> types de casiers -> soldes.
        $session = null;
        if ($paidNow > 0 && $method === 'cash') {
            $session = CashSession::open()->where('user_id', $by->id)->lockForUpdate()->first()
                ?? throw DepotException::noOpenCashSession();
        }

        $supplier = Supplier::lockForUpdate()->findOrFail($data['supplier_id']);

        if (! $supplier->active) {
            throw new DepotException('Ce fournisseur est désactivé.');
        }

        $lines = collect($data['items'])->sortBy('product_id')->values();
        $products = Product::whereIn('id', $lines->pluck('product_id')->unique())->get()->keyBy('id');

        $prepared = [];
        $receivedByType = [];   // casiers pleins reçus par type de casier (donc casiers dus au fournisseur)
        $total = 0;

        foreach ($lines as $line) {
            $product = $products[$line['product_id']];

            if (! $product->active) {
                throw new DepotException("« {$product->name} » est désactivé.");
            }

            $isCrate = $line['unit'] === 'crate';
            $quantity = (int) $line['quantity'];
            $unitCost = (int) $line['unit_cost'];
            $lineTotal = $quantity * $unitCost;
            $total += $lineTotal;

            if ($isCrate && $product->container_type_id) {
                $receivedByType[$product->container_type_id] = ($receivedByType[$product->container_type_id] ?? 0) + $quantity;
            }

            $prepared[] = [
                'product_id' => $product->id,
                'unit'       => $line['unit'],
                'quantity'   => $quantity,
                'bottles'    => $isCrate ? $quantity * $product->bottles_per_crate : $quantity,
                'unit_cost'  => $unitCost,
                'line_total' => $lineTotal,
            ];
        }

        if ($paidNow > $total) {
            throw new DepotException("Le paiement dépasse le total de l'achat ({$total} FCFA).");
        }

        $purchase = Purchase::create([
            'uuid'         => $data['uuid'],
            'supplier_id'  => $supplier->id,
            'user_id'      => $by->id,
            'invoice_ref'  => $data['invoice_ref'] ?? null,
            'total'        => $total,
            'status'       => 'received',
            'purchased_at' => $data['purchased_at'] ?? now(),
            'note'         => $data['note'] ?? null,
        ]);

        foreach ($prepared as $row) {
            $purchase->items()->create($row);
            $this->stock->move($products[$row['product_id']], 'entree', $row['bottles'], $by, $purchase, 'Achat #'.$purchase->id);

            if ($row['unit'] === 'crate') {
                Product::whereKey($row['product_id'])->update(['cost_crate' => $row['unit_cost']]);   // dernier coût d'achat
            }
        }

        $returnedByType = [];
        foreach ($data['empties_returned'] ?? [] as $r) {
            $id = (int) $r['container_type_id'];
            $returnedByType[$id] = ($returnedByType[$id] ?? 0) + (int) $r['quantity'];
        }

        if ($receivedByType || $returnedByType) {
            $this->containers->applyPurchase($by, $supplier, $purchase, $receivedByType, $returnedByType);
        }

        if ($total > 0) {
            $this->suppliers->charge($supplier, $total, $by, $purchase, 'Achat #'.$purchase->id);
        }

        if ($paidNow > 0) {
            $this->suppliers->applyPayment($supplier, $by, $paidNow, $method, $session, $purchase, note: 'Règlement achat #'.$purchase->id);
        }

        return $purchase;
    }
}
