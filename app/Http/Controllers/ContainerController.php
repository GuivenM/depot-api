<?php

namespace App\Http\Controllers;

use App\Models\ContainerHolding;
use App\Models\ContainerType;
use App\Models\Customer;
use App\Services\ContainerService;
use Illuminate\Http\Request;

class ContainerController extends Controller
{
    public function __construct(private ContainerService $containers)
    {
    }

    /** Types de casiers : vides au dépôt, casiers dus par les clients et leur valeur de consigne. */
    public function index()
    {
        $owed = ContainerHolding::where('party_type', 'customer')
            ->selectRaw('container_type_id, sum(quantity) as qty')
            ->groupBy('container_type_id')
            ->pluck('qty', 'container_type_id');

        $owedSuppliers = ContainerHolding::where('party_type', 'supplier')
            ->selectRaw('container_type_id, sum(quantity) as qty')
            ->groupBy('container_type_id')
            ->pluck('qty', 'container_type_id');

        return ContainerType::orderBy('name')->get()->map(fn ($t) => [
            'id'                => $t->id,
            'name'              => $t->name,
            'deposit_amount'    => $t->deposit_amount,
            'active'            => $t->active,
            'empty_stock'       => $t->empty_stock,
            'owed_by_customers' => (int) ($owed[$t->id] ?? 0),
            'owed_value'        => (int) ($owed[$t->id] ?? 0) * $t->deposit_amount,
            'owed_to_suppliers' => (int) ($owedSuppliers[$t->id] ?? 0),
        ])->values();
    }

    public function store(Request $request)
    {
        $type = ContainerType::create($this->validated($request));

        return response()->json($type->fresh(), 201);
    }

    public function update(Request $request, ContainerType $containerType)
    {
        $containerType->update($this->validated($request, partial: true));

        return $containerType->fresh();
    }

    /** Qui nous doit des casiers de ce type. */
    public function holders(ContainerType $containerType)
    {
        $holdings = ContainerHolding::where('container_type_id', $containerType->id)
            ->where('party_type', 'customer')
            ->where('quantity', '>', 0)
            ->orderByDesc('quantity')
            ->get();

        $customers = Customer::whereIn('id', $holdings->pluck('party_id'))->get()->keyBy('id');

        return $holdings->map(fn ($h) => [
            'customer_id' => $h->party_id,
            'name'        => $customers[$h->party_id]->name ?? null,
            'phone'       => $customers[$h->party_id]->phone ?? null,
            'quantity'    => $h->quantity,
            'value'       => $h->quantity * $containerType->deposit_amount,
        ])->values();
    }

    public function movements(ContainerType $containerType)
    {
        return $containerType->movements()->latest('id')->paginate(50);
    }

    /** Casiers que ce client doit rendre. */
    public function customerHoldings(Customer $customer)
    {
        $holdings = ContainerHolding::where('party_type', 'customer')
            ->where('party_id', $customer->id)
            ->where('quantity', '>', 0)
            ->get();

        $types = ContainerType::whereIn('id', $holdings->pluck('container_type_id'))->get()->keyBy('id');

        return $holdings->map(fn ($h) => [
            'container_type_id' => $h->container_type_id,
            'name'              => $types[$h->container_type_id]->name,
            'quantity'          => $h->quantity,
            'value'             => $h->quantity * $types[$h->container_type_id]->deposit_amount,
        ])->values();
    }

    public function customerReturn(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'container_type_id' => 'required|integer|exists:container_types,id',
            'quantity'          => 'required|integer|min:1|max:100000',
            'note'              => 'nullable|string|max:255',
        ]);

        $holding = $this->containers->customerReturn(
            $request->user(), $customer, $data['container_type_id'], $data['quantity'], $data['note'] ?? null
        );

        return response()->json(['quantity_left' => $holding->quantity], 201);
    }

    /** Gérant : correction / abandon de casiers dus. */
    public function adjustHolding(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'container_type_id' => 'required|integer|exists:container_types,id',
            'quantity'          => 'required|integer|not_in:0',   // signé
            'note'              => 'required|string|max:255',
        ]);

        $holding = $this->containers->adjustHolding(
            $request->user(), $customer, $data['container_type_id'], $data['quantity'], $data['note']
        );

        return response()->json(['quantity_owed' => $holding->quantity], 201);
    }

    /** Casiers vides du dépôt : arrivée/retour fournisseur, perte, correction (inventaire initial inclus). */
    public function depotMovement(Request $request)
    {
        $data = $request->validate([
            'container_type_id' => 'required|integer|exists:container_types,id',
            'kind'              => 'required|in:from_supplier,to_supplier,loss,adjustment',
            'quantity'          => 'required|integer|not_in:0',   // signé pour adjustment, valeur absolue sinon
            'note'              => 'required_if:kind,adjustment,loss|nullable|string|max:255',
        ]);

        $type = $this->containers->depotMovement(
            $request->user(), $data['container_type_id'], $data['kind'], $data['quantity'], $data['note'] ?? null
        );

        return response()->json(['empty_stock' => $type->empty_stock], 201);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name'           => "$req|string|max:120",
            'deposit_amount' => "$req|integer|min:0",
            'active'         => 'sometimes|boolean',
        ]);
    }
}
