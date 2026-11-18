<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventories)
    {
    }

    public function index()
    {
        return Inventory::latest('id')->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['note' => 'nullable|string|max:255']);

        $inventory = $this->inventories->start($request->user(), $data['note'] ?? null);

        return response()->json($this->present($inventory), 201);
    }

    public function show(Inventory $inventory)
    {
        return $this->present($inventory);
    }

    /** Comptages : { counts: [ {line_id, crates, bottles} | {line_id, quantity} ] } */
    public function count(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'counts'            => 'required|array|min:1|max:200',
            'counts.*.line_id'  => 'required|integer',
            'counts.*.crates'   => 'sometimes|integer|min:0|max:1000000',
            'counts.*.bottles'  => 'sometimes|integer|min:0|max:1000000',
            'counts.*.quantity' => 'sometimes|integer|min:0|max:1000000',
        ]);

        return $this->present($this->inventories->count($request->user(), $inventory->id, $data['counts']));
    }

    public function validateInventory(Request $request, Inventory $inventory)
    {
        return $this->present($this->inventories->validate($request->user(), $inventory->id));
    }

    public function cancel(Inventory $inventory)
    {
        return $this->present($this->inventories->cancel($inventory->id));
    }

    private function present(Inventory $inventory): array
    {
        $lines = $inventory->lines()->with(['product:id,name,bottles_per_crate', 'containerType:id,name'])->orderBy('id')->get();

        return array_merge($inventory->fresh()->toArray(), [
            'progress' => ['total' => $lines->count(), 'counted' => $lines->whereNotNull('counted')->count()],
            'lines'    => $lines->map(fn ($l) => [
                'id'                => $l->id,
                'kind'              => $l->kind,
                'product_id'        => $l->product_id,
                'container_type_id' => $l->container_type_id,
                'name'              => $l->kind === 'product' ? $l->product?->name : $l->containerType?->name,
                'bottles_per_crate' => $l->kind === 'product' ? $l->product?->bottles_per_crate : null,
                'expected'          => $l->expected,
                'counted'           => $l->counted,
                'difference'        => $l->difference,
                'counted_at'        => $l->counted_at,
            ])->values()->all(),
        ]);
    }
}
