<?php

namespace App\Services;

use App\Exceptions\InsufficientStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Seul point d'entrée pour modifier le stock.
     * $qty est en BOUTEILLES, signée (+ entrée, - sortie).
     */
    public function move(Product $product, string $type, int $qty, User $by, ?Model $ref = null, ?string $note = null): StockMovement
    {
        return DB::transaction(function () use ($product, $type, $qty, $by, $ref, $note) {
            $p = Product::lockForUpdate()->findOrFail($product->id);

            if ($p->stock_bottles + $qty < 0) {
                throw new InsufficientStock($p);
            }

            $p->increment('stock_bottles', $qty);

            return StockMovement::create([
                'product_id'     => $p->id,
                'type'           => $type,
                'quantity'       => $qty,
                'user_id'        => $by->id,
                'note'           => $note,
                'reference_type' => $ref?->getMorphClass(),
                'reference_id'   => $ref?->getKey(),
            ]);
        });
    }
}
