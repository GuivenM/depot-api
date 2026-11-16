<?php

namespace App\Http\Controllers;

use App\Models\ContainerHolding;
use App\Models\ContainerType;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\SupplierService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class SupplierController extends Controller
{
    public function __construct(private SupplierService $suppliers)
    {
    }

    public function index(Request $request)
    {
        $query = Supplier::query()->orderBy('name');

        if ($s = $request->query('q')) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"));
        }

        return $query->paginate(100);
    }

    public function store(Request $request)
    {
        $supplier = Supplier::create($this->validated($request));

        return response()->json($supplier->fresh(), 201);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $supplier->update($this->validated($request, partial: true));

        return $supplier->fresh();
    }

    public function ledger(Supplier $supplier)
    {
        return [
            'supplier' => $supplier,
            'entries'  => $supplier->ledger()->latest('id')->paginate(50),
        ];
    }

    public function pay(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'uuid'   => 'required|uuid',
            'amount' => 'required|integer|min:1',
            'method' => 'required|in:cash,momo,bank',
            'note'   => 'nullable|string|max:255',
        ]);

        [$payment, $created] = $this->suppliers->recordPayment($request->user(), $supplier->id, $data);

        return response()->json([
            'payment' => $payment,
            'balance' => $supplier->fresh()->balance,
        ], $created ? 201 : 200);
    }

    public function adjust(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'amount' => 'required|integer|not_in:0',
            'note'   => 'required|string|max:255',
        ]);

        [$fresh, $entry] = $this->suppliers->adjust($request->user(), $supplier->id, $data['amount'], $data['note']);

        return response()->json(['entry' => $entry, 'balance' => $fresh->balance], 201);
    }

    /** Casiers que le dépôt doit à ce fournisseur (négatif = le fournisseur nous en doit). */
    public function containers(Supplier $supplier)
    {
        $holdings = ContainerHolding::where('party_type', 'supplier')
            ->where('party_id', $supplier->id)
            ->where('quantity', '!=', 0)
            ->get();

        $types = ContainerType::whereIn('id', $holdings->pluck('container_type_id'))->get()->keyBy('id');

        return $holdings->map(fn ($h) => [
            'container_type_id' => $h->container_type_id,
            'name'              => $types[$h->container_type_id]->name,
            'quantity'          => $h->quantity,
            'value'             => $h->quantity * $types[$h->container_type_id]->deposit_amount,
        ])->values();
    }

    /** Dettes fournisseurs : qui on doit payer, combien, dernier paiement. */
    public function payables()
    {
        $suppliers = Supplier::where('balance', '>', 0)->orderByDesc('balance')->get();

        $lastPaid = SupplierPayment::selectRaw('supplier_id, max(paid_at) as last_paid_at')
            ->whereIn('supplier_id', $suppliers->pluck('id'))
            ->groupBy('supplier_id')
            ->pluck('last_paid_at', 'supplier_id');

        return [
            'total_payable' => (int) $suppliers->sum('balance'),
            'suppliers'     => $suppliers->map(fn ($s) => [
                'id'              => $s->id,
                'name'            => $s->name,
                'phone'           => $s->phone,
                'balance'         => $s->balance,
                'last_payment_at' => $lastPaid[$s->id] ?? null,
            ])->values(),
        ];
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        $data = $request->validate([
            'name'   => "$req|string|max:120",
            'phone'  => 'nullable|string|max:30',
            'note'   => 'nullable|string|max:255',
            'active' => 'sometimes|boolean',
        ]);

        // Activation : réservée au gérant.
        if ($request->user()->role !== 'gerant') {
            $data = Arr::except($data, ['active']);
        }

        return $data;
    }
}
