<?php

namespace App\Services;

use App\Exceptions\DepotException;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function __construct(private StockService $stock)
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

    private function record(User $by, array $data): Sale
    {
        // Verrou sur la caisse : une clôture concurrente attend la fin de la vente.
        $session = CashSession::open()->where('user_id', $by->id)->lockForUpdate()->first();

        if (! $session) {
            throw DepotException::noOpenCashSession();
        }

        // Tri par produit : ordre de verrouillage constant, pas de deadlock entre ventes.
        $lines = collect($data['items'])->sortBy('product_id')->values();
        $products = Product::whereIn('id', $lines->pluck('product_id')->unique())->get()->keyBy('id');

        $prepared = [];
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

        $sale = Sale::create([
            'uuid'            => $data['uuid'],
            'cash_session_id' => $session->id,
            'user_id'         => $by->id,
            'payment_method'  => $data['payment_method'],
            'total'           => $total,
            'status'          => 'completed',
            'sold_at'         => $data['sold_at'] ?? now(),
        ]);

        foreach ($prepared as $row) {
            $sale->items()->create($row);
            $this->stock->move($products[$row['product_id']], 'sortie', -$row['bottles'], $by, $sale, 'Vente #'.$sale->id);
        }

        return $sale;
    }
}
