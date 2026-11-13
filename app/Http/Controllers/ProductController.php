<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index()
    {
        return Product::orderBy('name')->get()->each->append(['stock_crates', 'stock_loose_bottles']);
    }

    public function store(Request $request)
    {
        $product = Product::create($this->validated($request));

        return response()->json($product->fresh(), 201);
    }

    public function update(Request $request, Product $product)
    {
        $product->update($this->validated($request, partial: true));

        return $product->fresh();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name'              => "$req|string|max:120",
            'brand'             => 'nullable|string|max:80',
            'format_cl'         => 'nullable|integer|min:1|max:5000',
            'bottles_per_crate' => 'sometimes|integer|min:1|max:48',
            'price_crate'       => "$req|integer|min:0",
            'price_bottle'      => 'nullable|integer|min:0',
            'cost_crate'        => 'nullable|integer|min:0',
            'alert_threshold'   => 'sometimes|integer|min:0',
            'active'            => 'sometimes|boolean',
        ]);
    }
}
