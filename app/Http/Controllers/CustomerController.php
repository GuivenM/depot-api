<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payment;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CustomerController extends Controller
{
    public function __construct(private CreditService $credit)
    {
    }

    public function index(Request $request)
    {
        $query = Customer::query()->orderBy('name');

        if ($s = $request->query('q')) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"));
        }
        if ($request->boolean('debtors')) {
            $query->where('balance', '>', 0);
        }

        return $query->paginate(100);
    }

    public function store(Request $request)
    {
        $customer = Customer::create($this->validated($request));

        return response()->json($customer->fresh(), 201);
    }

    public function update(Request $request, Customer $customer)
    {
        $customer->update($this->validated($request, partial: true));

        return $customer->fresh();
    }

    public function ledger(Customer $customer)
    {
        return [
            'customer' => $customer,
            'entries'  => $customer->ledger()->latest('id')->paginate(50),
        ];
    }

    public function pay(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'uuid'   => 'required|uuid',
            'amount' => 'required|integer|min:1',
            'method' => 'required|in:cash,momo',
            'note'   => 'nullable|string|max:255',
        ]);

        [$payment, $created] = $this->credit->recordPayment($request->user(), $customer->id, $data);

        return response()->json([
            'payment' => $payment,
            'balance' => $customer->fresh()->balance,
        ], $created ? 201 : 200);
    }

    /** Correction / abandon de créance : gérant uniquement (route protégée). */
    public function adjust(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'amount' => 'required|integer|not_in:0',
            'note'   => 'required|string|max:255',
        ]);

        [$fresh, $entry] = $this->credit->adjust($request->user(), $customer->id, $data['amount'], $data['note']);

        return response()->json(['entry' => $entry, 'balance' => $fresh->balance], 201);
    }

    /** Créances : qui doit combien, dernier paiement, dépassement de limite. */
    public function receivables()
    {
        $customers = Customer::where('balance', '>', 0)->orderByDesc('balance')->get();

        $lastPaid = Payment::selectRaw('customer_id, max(paid_at) as last_paid_at')
            ->whereIn('customer_id', $customers->pluck('id'))
            ->groupBy('customer_id')
            ->pluck('last_paid_at', 'customer_id');

        return [
            'total_outstanding' => (int) $customers->sum('balance'),
            'customers' => $customers->map(fn ($c) => [
                'id'              => $c->id,
                'name'            => $c->name,
                'phone'           => $c->phone,
                'type'            => $c->type,
                'balance'         => $c->balance,
                'credit_limit'    => $c->credit_limit,
                'over_limit'      => $c->balance > $c->credit_limit,
                'last_payment_at' => $lastPaid[$c->id] ?? null,
            ])->values(),
        ];
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        $data = $request->validate([
            'name'         => "$req|string|max:120",
            'phone'        => 'nullable|string|max:30',
            'type'         => "$req|in:bar,restaurant,revendeur,particulier",
            'credit_limit' => 'sometimes|integer|min:0',
            'active'       => 'sometimes|boolean',
            'note'         => 'nullable|string|max:255',
        ]);

        // Limite de crédit et activation : réservées au gérant.
        if ($request->user()->role !== 'gerant') {
            $data = Arr::except($data, ['credit_limit', 'active']);
        }

        return $data;
    }
}
