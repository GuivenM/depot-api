<?php

namespace App\Services;

use App\Exceptions\DepotException;
use App\Models\CashSession;
use App\Models\ContainerHolding;
use App\Models\ContainerType;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Inventory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres du tableau de bord et des rapports (lecture seule).
 * Toutes les dates saisies (YYYY-MM-DD) sont interprétées dans le fuseau du dépôt (Africa/Porto-Novo par défaut),
 * puis converties vers le fuseau de stockage pour interroger la base.
 */
class ReportService
{
    private const TZ = 'Africa/Porto-Novo';

    /** @return array{from:string,to:string,timezone:string,days:int,start:Carbon,end:Carbon} */
    public function period(?string $from, ?string $to, int $maxDays = 366): array
    {
        $tz = config('depot.timezone', self::TZ);
        $today = Carbon::now($tz)->toDateString();
        $from ??= $today;
        $to ??= $today;

        $startLocal = Carbon::createFromFormat('Y-m-d', $from, $tz)->startOfDay();
        $endLocal = Carbon::createFromFormat('Y-m-d', $to, $tz)->endOfDay();

        if ($endLocal->lt($startLocal)) {
            throw new DepotException('La date de fin précède la date de début.');
        }

        $days = (int) $startLocal->diffInDays($endLocal->copy()->startOfDay()) + 1;

        if ($days > $maxDays) {
            throw new DepotException("Période trop longue (maximum {$maxDays} jours).");
        }

        $storage = config('app.timezone');

        return [
            'from'     => $from,
            'to'       => $to,
            'timezone' => $tz,
            'days'     => $days,
            'start'    => $startLocal->copy()->setTimezone($storage),
            'end'      => $endLocal->copy()->setTimezone($storage),
        ];
    }

    /** Vue d'ensemble : ventes, marge estimée, dépenses, encaissements, achats + situation actuelle. */
    public function overview(array $p): array
    {
        $range = [$p['start'], $p['end']];

        $sales = Sale::whereBetween('sold_at', $range);

        $byMethod = (clone $sales)->where('status', 'completed')
            ->selectRaw('payment_method, count(*) as n, sum(total) as amount')
            ->groupBy('payment_method')->get()->keyBy('payment_method');

        $method = function (string $m) use ($byMethod) {
            $row = $byMethod->get($m);

            return ['count' => (int) ($row->n ?? 0), 'amount' => (int) ($row->amount ?? 0)];
        };

        $voided = (clone $sales)->where('status', 'voided')
            ->selectRaw('count(*) as n, coalesce(sum(total), 0) as amount')->first();

        // Marge estimée : sur les lignes dont le produit a un coût connu, avec le DERNIER coût d'achat.
        $lines = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sold_at', $range)
            ->selectRaw('
                coalesce(sum(case when products.cost_crate is not null then sale_items.line_total else 0 end), 0) as costed_revenue,
                coalesce(sum(case when products.cost_crate is null then sale_items.line_total else 0 end), 0) as uncosted_revenue,
                coalesce(sum(case when products.cost_crate is not null then sale_items.bottles * products.cost_crate * 1.0 / products.bottles_per_crate else 0 end), 0) as cost
            ')->first();

        $cost = (int) round((float) $lines->cost);

        $customerPayments = Payment::whereNull('voided_at')->whereBetween('paid_at', $range)
            ->selectRaw('method, sum(amount) as amount')->groupBy('method')->pluck('amount', 'method');

        $supplierPayments = SupplierPayment::whereNull('voided_at')->whereBetween('paid_at', $range)
            ->selectRaw('method, sum(amount) as amount')->groupBy('method')->pluck('amount', 'method');

        $purchases = Purchase::where('status', 'received')->whereBetween('purchased_at', $range)
            ->selectRaw('count(*) as n, coalesce(sum(total), 0) as amount')->first();

        $revenue = (int) $byMethod->sum('amount');

        return [
            'period' => ['from' => $p['from'], 'to' => $p['to'], 'timezone' => $p['timezone'], 'days' => $p['days']],
            'sales' => [
                'count'     => (int) $byMethod->sum('n'),
                'revenue'   => $revenue,
                'by_method' => ['cash' => $method('cash'), 'momo' => $method('momo'), 'credit' => $method('credit')],
                'voided'    => ['count' => (int) $voided->n, 'amount' => (int) $voided->amount],
            ],
            'margin' => [
                'estimated_cost'    => $cost,
                'estimated_margin'  => (int) $lines->costed_revenue - $cost,
                'uncosted_revenue'  => (int) $lines->uncosted_revenue,
            ],
            'expenses' => ['total' => (int) Expense::whereBetween('created_at', $range)->sum('amount')],
            'collections' => [
                'customer_payments' => [
                    'cash' => (int) ($customerPayments['cash'] ?? 0),
                    'momo' => (int) ($customerPayments['momo'] ?? 0),
                ],
                'supplier_payments' => [
                    'cash' => (int) ($supplierPayments['cash'] ?? 0),
                    'momo' => (int) ($supplierPayments['momo'] ?? 0),
                    'bank' => (int) ($supplierPayments['bank'] ?? 0),
                ],
            ],
            'purchases' => ['count' => (int) $purchases->n, 'total' => (int) $purchases->amount],
            'snapshot'  => $this->snapshot(),
        ];
    }

    /** Situation à l'instant T (indépendante de la période). */
    public function snapshot(): array
    {
        $stockValue = Product::selectRaw('
            coalesce(sum(case when cost_crate is not null then stock_bottles * cost_crate * 1.0 / bottles_per_crate else 0 end), 0) as v
        ')->value('v');

        $lastInventory = Inventory::where('status', 'validated')->latest('validated_at')->first();

        return [
            'receivables' => [
                'total'      => (int) Customer::where('balance', '>', 0)->sum('balance'),
                'debtors'    => Customer::where('balance', '>', 0)->count(),
                'over_limit' => Customer::whereColumn('balance', '>', 'credit_limit')->count(),
            ],
            'payables' => ['total' => (int) Supplier::where('balance', '>', 0)->sum('balance')],
            'containers' => [
                'empty_stock'        => (int) ContainerType::sum('empty_stock'),
                'owed_by_customers'  => (int) ContainerHolding::where('party_type', 'customer')->sum('quantity'),
                'owed_to_suppliers'  => (int) ContainerHolding::where('party_type', 'supplier')->sum('quantity'),
            ],
            'stock' => [
                'value_at_cost'   => (int) round((float) $stockValue),
                'below_threshold' => Product::where('active', true)->whereColumn('stock_bottles', '<=', 'alert_threshold')->count(),
            ],
            'last_inventory' => $lastInventory ? [
                'id'             => $lastInventory->id,
                'validated_at'   => $lastInventory->validated_at,
                'lines_with_gap' => $lastInventory->lines_with_gap,
                'shortage_value' => $lastInventory->shortage_value,
                'surplus_value'  => $lastInventory->surplus_value,
            ] : null,
        ];
    }

    /** Une ligne par jour (heure du dépôt), jours sans vente inclus (à zéro), pour tracer une courbe. */
    public function daily(array $p): array
    {
        $tz = $p['timezone'];
        $range = [$p['start'], $p['end']];

        $days = [];
        for ($d = Carbon::parse($p['from'], $tz); $d->toDateString() <= $p['to']; $d->addDay()) {
            $days[$d->toDateString()] = [
                'date' => $d->toDateString(), 'sales_count' => 0, 'revenue' => 0,
                'cash' => 0, 'momo' => 0, 'credit' => 0, 'expenses' => 0,
            ];
        }

        $sales = Sale::where('status', 'completed')->whereBetween('sold_at', $range)->get(['sold_at', 'total', 'payment_method']);
        foreach ($sales as $s) {
            $key = $s->sold_at->copy()->setTimezone($tz)->toDateString();
            if (! isset($days[$key])) {
                continue;
            }
            $days[$key]['sales_count']++;
            $days[$key]['revenue'] += (int) $s->total;
            if (isset($days[$key][$s->payment_method])) {
                $days[$key][$s->payment_method] += (int) $s->total;
            }
        }

        foreach (Expense::whereBetween('created_at', $range)->get(['created_at', 'amount']) as $e) {
            $key = $e->created_at->copy()->setTimezone($tz)->toDateString();
            if (isset($days[$key])) {
                $days[$key]['expenses'] += (int) $e->amount;
            }
        }

        return [
            'period' => ['from' => $p['from'], 'to' => $p['to'], 'timezone' => $tz, 'days' => $p['days']],
            'days'   => array_values($days),
        ];
    }

    public function topProducts(array $p, int $limit = 10): array
    {
        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.sold_at', [$p['start'], $p['end']])
            ->groupBy('products.id', 'products.name', 'products.bottles_per_crate')
            ->selectRaw('products.id as product_id, products.name, products.bottles_per_crate, sum(sale_items.bottles) as bottles_sold, sum(sale_items.line_total) as revenue')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return [
            'period'   => ['from' => $p['from'], 'to' => $p['to'], 'timezone' => $p['timezone'], 'days' => $p['days']],
            'products' => $rows->map(fn ($r) => [
                'product_id'   => (int) $r->product_id,
                'name'         => $r->name,
                'bottles_sold' => (int) $r->bottles_sold,
                'crates_sold'  => round($r->bottles_sold / max(1, $r->bottles_per_crate), 1),
                'revenue'      => (int) $r->revenue,
            ])->values()->all(),
        ];
    }

    /** Produits en rupture ou sous leur seuil d'alerte (seuil en bouteilles). */
    public function stockAlerts(): array
    {
        $products = Product::where('active', true)
            ->whereColumn('stock_bottles', '<=', 'alert_threshold')
            ->orderBy('stock_bottles')
            ->get()
            ->map(fn ($p) => [
                'id'                  => $p->id,
                'name'                => $p->name,
                'stock_bottles'       => (int) $p->stock_bottles,
                'stock_crates'        => intdiv(max(0, (int) $p->stock_bottles), max(1, $p->bottles_per_crate)),
                'stock_loose_bottles' => max(0, (int) $p->stock_bottles) % max(1, $p->bottles_per_crate),
                'alert_threshold'     => (int) $p->alert_threshold,
                'status'              => $p->stock_bottles <= 0 ? 'rupture' : 'bas',
            ])->values();

        return ['count' => $products->count(), 'products' => $products->all()];
    }

    /**
     * Bilan de stock par produit sur la période (remplace le calcul fait à la main) :
     * ouverture + entrées - ventes - casse ± corrections ± annulations = clôture.
     * Tout est en BOUTEILLES. La clôture se déduit du stock actuel moins les mouvements
     * postérieurs à la période ; l'ouverture = clôture - mouvements de la période.
     */
    public function stockSummary(array $p): array
    {
        $range = [$p['start'], $p['end']];

        $inPeriod = StockMovement::whereBetween('created_at', $range)
            ->selectRaw('product_id, type, SUM(quantity) as qty')
            ->groupBy('product_id', 'type')
            ->get()
            ->groupBy('product_id');

        $after = StockMovement::where('created_at', '>', $p['end'])
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $products = Product::where('active', true)->orderBy('name')->get()->map(function (Product $prod) use ($inPeriod, $after) {
            $by = ($inPeriod[$prod->id] ?? collect())->mapWithKeys(fn ($r) => [$r->type => (int) $r->qty]);
            $closing = (int) $prod->stock_bottles - (int) ($after[$prod->id] ?? 0);

            return [
                'id'                => $prod->id,
                'name'              => $prod->name,
                'bottles_per_crate' => (int) $prod->bottles_per_crate,
                'opening'           => $closing - (int) $by->sum(),
                'entries'           => (int) ($by['entree'] ?? 0),
                'sales'             => -(int) ($by['sortie'] ?? 0),
                'breakage'          => -(int) ($by['casse'] ?? 0),
                'corrections'       => (int) ($by['ajustement'] ?? 0) + (int) ($by['inventaire'] ?? 0),
                'voids'             => (int) ($by['annulation'] ?? 0),
                'closing'           => $closing,
            ];
        })->values();

        return [
            'period'   => ['from' => $p['from'], 'to' => $p['to'], 'timezone' => $p['timezone'], 'days' => $p['days']],
            'products' => $products->all(),
        ];
    }

    /** Caisses clôturées sur la période, avec écarts, et bilan par vendeur (pour repérer les écarts répétés). */
    public function cashSessions(array $p): array
    {
        $sessions = CashSession::with('user:id,name')
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$p['start'], $p['end']])
            ->orderByDesc('closed_at')
            ->get();

        return [
            'period'   => ['from' => $p['from'], 'to' => $p['to'], 'timezone' => $p['timezone'], 'days' => $p['days']],
            'sessions' => $sessions->map(fn ($s) => [
                'id'             => $s->id,
                'user_id'        => $s->user_id,
                'user'           => $s->user?->name,
                'opened_at'      => $s->opened_at,
                'closed_at'      => $s->closed_at,
                'opening_amount' => (int) $s->opening_amount,
                'expected_cash'  => (int) $s->expected_cash,
                'counted_cash'   => (int) $s->counted_cash,
                'difference'     => (int) $s->difference,
            ])->values()->all(),
            'by_user' => $sessions->groupBy('user_id')->map(fn ($g) => [
                'user_id'          => $g->first()->user_id,
                'user'             => $g->first()->user?->name,
                'sessions'         => $g->count(),
                'sessions_with_gap' => $g->where('difference', '!=', 0)->count(),
                'total_difference' => (int) $g->sum('difference'),
            ])->sortByDesc('sessions_with_gap')->values()->all(),
        ];
    }
}
