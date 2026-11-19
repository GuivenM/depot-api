<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\AuditService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /** Champs dont toute modification est tracée (prix, coût, conditionnement, activation, consigne). */
    private const WATCH = ['name', 'price_crate', 'price_bottle', 'cost_crate', 'bottles_per_crate', 'alert_threshold', 'active', 'container_type_id'];

    public function __construct(private AuditService $audit)
    {
    }

    public function index()
    {
        return Product::orderBy('name')->get()->each->append(['stock_crates', 'stock_loose_bottles']);
    }

    public function store(Request $request)
    {
        $product = $this->audit->createAudited($request->user(), 'product.created', Product::class, $this->validated($request), self::WATCH);

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product)
    {
        return $this->audit->updateAudited($request->user(), 'product.updated', $product, $this->validated($request, partial: true), self::WATCH);
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
            'container_type_id' => 'nullable|integer|exists:container_types,id',
        ]);
    }
}
