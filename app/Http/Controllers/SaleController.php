<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(private SaleService $sales)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Sale::with('items')->latest('sold_at');

        if ($user->role !== 'gerant') {
            $query->where('user_id', $user->id);
        }
        if ($customerId = $request->query('customer_id')) {
            $query->where('customer_id', $customerId);
        }
        if ($sessionId = $request->query('session_id')) {
            $query->where('cash_session_id', $sessionId);
        }

        return $query->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'uuid'                 => 'required|uuid',
            'payment_method'       => 'required|in:cash,momo,credit',
            'customer_id'          => 'required_if:payment_method,credit|nullable|integer|exists:customers,id',
            'paid_now'             => 'nullable|integer|min:0',
            'paid_method'          => 'nullable|in:cash,momo',
            'override_limit'       => 'nullable|boolean',
            'empties_returned'     => 'nullable|array|max:20',
            'empties_returned.*.container_type_id' => 'required|integer|exists:container_types,id',
            'empties_returned.*.quantity'          => 'required|integer|min:1|max:100000',
            'sold_at'              => 'nullable|date|before_or_equal:now',
            'items'                => 'required|array|min:1|max:100',
            'items.*.product_id'   => 'required|integer|exists:products,id',
            'items.*.unit'         => 'required|in:crate,bottle',
            'items.*.quantity'     => 'required|integer|min:1|max:100000',
        ]);

        [$sale, $created] = $this->sales->create($request->user(), $data);

        return response()->json($sale, $created ? 201 : 200);
    }
}
