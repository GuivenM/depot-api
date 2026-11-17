<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function __construct(private PurchaseService $purchases)
    {
    }

    public function index(Request $request)
    {
        $query = Purchase::with('items')->latest('purchased_at');

        if ($supplierId = $request->query('supplier_id')) {
            $query->where('supplier_id', $supplierId);
        }

        return $query->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'uuid'                 => 'required|uuid',
            'supplier_id'          => 'required|integer|exists:suppliers,id',
            'invoice_ref'          => 'nullable|string|max:60',
            'purchased_at'         => 'nullable|date|before_or_equal:now',
            'note'                 => 'nullable|string|max:255',
            'items'                => 'required|array|min:1|max:100',
            'items.*.product_id'   => 'required|integer|exists:products,id',
            'items.*.unit'         => 'required|in:crate,bottle',
            'items.*.quantity'     => 'required|integer|min:1|max:100000',
            'items.*.unit_cost'    => 'required|integer|min:0',
            'paid_now'             => 'nullable|integer|min:0',
            'paid_method'          => 'nullable|in:cash,momo,bank',
            'empties_returned'     => 'nullable|array|max:20',
            'empties_returned.*.container_type_id' => 'required|integer|exists:container_types,id',
            'empties_returned.*.quantity'          => 'required|integer|min:1|max:100000',
        ]);

        [$purchase, $created] = $this->purchases->create($request->user(), $data);

        return response()->json($purchase, $created ? 201 : 200);
    }

    public function void(Request $request, Purchase $purchase)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->purchases->void($request->user(), $purchase->id, $data['reason']);
    }
}
