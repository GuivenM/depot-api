<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\AuditService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function __construct(private StockService $stock, private AuditService $audit)
    {
    }

    /** Réception de marchandise : entrée en stock. */
    public function receipt(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'crates'     => 'nullable|integer|min:0',
            'bottles'    => 'nullable|integer|min:0',
            'note'       => 'nullable|string|max:255',
        ]);

        $product = Product::findOrFail($data['product_id']);
        $qty = ($data['crates'] ?? 0) * $product->bottles_per_crate + ($data['bottles'] ?? 0);

        if ($qty <= 0) {
            throw ValidationException::withMessages(['crates' => ['Quantité nulle.']]);
        }

        $movement = $this->stock->move($product, 'entree', $qty, $request->user(), null, $data['note'] ?? null);

        return response()->json([
            'movement'      => $movement,
            'stock_bottles' => $product->fresh()->stock_bottles,
        ], 201);
    }

    /** Casse ou correction manuelle (toujours tracée dans le journal). */
    public function adjust(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'type'       => 'required|in:casse,ajustement',
            'bottles'    => 'required|integer|not_in:0',   // casse : valeur absolue ; ajustement : signée
            'note'       => 'required_if:type,ajustement|nullable|string|max:255',
        ]);

        $product = Product::findOrFail($data['product_id']);
        $qty = $data['type'] === 'casse' ? -abs($data['bottles']) : $data['bottles'];

        $movement = DB::transaction(function () use ($request, $data, $product, $qty) {
            $movement = $this->stock->move($product, $data['type'], $qty, $request->user(), null, $data['note'] ?? null);

            $this->audit->record($request->user(), $data['type'] === 'casse' ? 'stock.breakage' : 'stock.adjusted', $product, [
                'bottles'     => $qty,
                'stock_after' => (int) $product->fresh()->stock_bottles,
            ], $data['note'] ?? null);

            return $movement;
        });

        return response()->json([
            'movement'      => $movement,
            'stock_bottles' => $product->fresh()->stock_bottles,
        ], 201);
    }

    public function movements(Product $product)
    {
        return $product->movements()->latest()->paginate(50);
    }
}
