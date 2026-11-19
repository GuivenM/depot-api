<?php

namespace App\Services;

use App\Exceptions\DepotException;
use App\Models\ContainerType;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Inventaire physique : on compte, le système mémorise ce qu'il croyait avoir AU MOMENT du comptage,
 * puis la validation applique l'ÉCART (compté - attendu) et non la valeur absolue : les ventes faites
 * après le comptage restent valables. Un inventaire peut être partiel (lignes non comptées ignorées).
 */
class InventoryService
{
    public function __construct(private StockService $stock, private ContainerService $containers)
    {
    }

    public function start(User $by, ?string $note = null): Inventory
    {
        return DB::transaction(function () use ($by, $note) {
            if (Inventory::where('status', 'open')->lockForUpdate()->exists()) {
                throw new DepotException('Un inventaire est déjà en cours : validez-le ou annulez-le.', 409);
            }

            $inventory = Inventory::create(['user_id' => $by->id, 'status' => 'open', 'note' => $note, 'started_at' => now()]);

            foreach (Product::where('active', true)->orderBy('id')->pluck('id') as $id) {
                $inventory->lines()->create(['kind' => 'product', 'product_id' => $id]);
            }
            foreach (ContainerType::where('active', true)->orderBy('id')->pluck('id') as $id) {
                $inventory->lines()->create(['kind' => 'container', 'container_type_id' => $id]);
            }

            return $inventory;
        });
    }

    /**
     * Enregistre des comptages (ligne produit : crates et/ou bottles ; ligne casiers : quantity).
     * Recompter une ligne remplace le comptage précédent (et réactualise le stock attendu).
     *
     * @param array<int,array<string,mixed>> $counts
     */
    public function count(User $by, int $inventoryId, array $counts): Inventory
    {
        return DB::transaction(function () use ($by, $inventoryId, $counts) {
            $inventory = Inventory::lockForUpdate()->findOrFail($inventoryId);

            if ($inventory->status !== 'open') {
                throw new DepotException("Cet inventaire n'est plus ouvert.", 409);
            }

            foreach ($counts as $c) {
                $line = InventoryLine::where('inventory_id', $inventory->id)->whereKey($c['line_id'])->first()
                    ?? throw new DepotException("Ligne d'inventaire inconnue ({$c['line_id']}).");

                if ($line->kind === 'product') {
                    if (! array_key_exists('crates', $c) && ! array_key_exists('bottles', $c)) {
                        throw new DepotException('Ligne produit : indiquez crates et/ou bottles.');
                    }

                    $product = Product::findOrFail($line->product_id);
                    $expected = (int) $product->stock_bottles;
                    $counted = (int) ($c['crates'] ?? 0) * $product->bottles_per_crate + (int) ($c['bottles'] ?? 0);
                } else {
                    if (! array_key_exists('quantity', $c)) {
                        throw new DepotException('Ligne casiers : indiquez quantity.');
                    }

                    $expected = (int) ContainerType::findOrFail($line->container_type_id)->empty_stock;
                    $counted = (int) $c['quantity'];
                }

                $line->update([
                    'expected'   => $expected,
                    'counted'    => $counted,
                    'difference' => $counted - $expected,
                    'counted_by' => $by->id,
                    'counted_at' => now(),
                ]);
            }

            return $inventory;
        });
    }

    /** Applique les écarts des lignes comptées (gérant). Tout ou rien. */
    public function validate(User $by, int $inventoryId): Inventory
    {
        return DB::transaction(function () use ($by, $inventoryId) {
            $inventory = Inventory::lockForUpdate()->findOrFail($inventoryId);

            if ($inventory->status !== 'open') {
                throw new DepotException("Cet inventaire n'est plus ouvert.", 409);
            }

            $lines = $inventory->lines()->whereNotNull('counted')->get();

            if ($lines->isEmpty()) {
                throw new DepotException('Aucune ligne comptée : rien à valider.');
            }

            $gaps = 0;
            $shortage = 0;
            $surplus = 0;

            // Ordre des verrous : produits (triés), puis types de casiers (triés).
            foreach ($lines->where('kind', 'product')->sortBy('product_id') as $line) {
                if ((int) $line->difference === 0) {
                    continue;
                }

                $product = Product::findOrFail($line->product_id);
                $this->stock->move($product, 'inventaire', (int) $line->difference, $by, $inventory, 'Inventaire #'.$inventory->id);
                $gaps++;

                if ($product->cost_crate !== null) {
                    $value = (int) round(abs($line->difference) * $product->cost_crate / max(1, $product->bottles_per_crate));
                    $line->difference < 0 ? $shortage += $value : $surplus += $value;
                }
            }

            foreach ($lines->where('kind', 'container')->sortBy('container_type_id') as $line) {
                if ((int) $line->difference === 0) {
                    continue;
                }

                $this->containers->applyInventory($by, $line->container_type_id, (int) $line->difference, $inventory);
                $gaps++;
            }

            $inventory->update([
                'status'         => 'validated',
                'validated_at'   => now(),
                'validated_by'   => $by->id,
                'lines_with_gap' => $gaps,
                'shortage_value' => $shortage,
                'surplus_value'  => $surplus,
            ]);

            app(AuditService::class)->record($by, 'inventory.validated', $inventory, [
                'lines_with_gap' => $gaps, 'shortage_value' => $shortage, 'surplus_value' => $surplus,
            ]);

            return $inventory;
        });
    }

    public function cancel(int $inventoryId): Inventory
    {
        return DB::transaction(function () use ($inventoryId) {
            $inventory = Inventory::lockForUpdate()->findOrFail($inventoryId);

            if ($inventory->status !== 'open') {
                throw new DepotException("Cet inventaire n'est plus ouvert.", 409);
            }

            $inventory->update(['status' => 'cancelled']);

            return $inventory;
        });
    }
}
