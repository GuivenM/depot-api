<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\ContainerHolding;
use App\Models\ContainerMovement;
use App\Models\ContainerType;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Fmt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Prépare les données des documents (PDF, CSV) à partir des mêmes sources que l'API :
 * aucun chiffre n'est recalculé ici d'une autre façon que dans ReportService / CashSession::summary().
 * Les vues ne font que de la mise en page.
 */
class DocumentService
{
    public const METHODS = ['cash' => 'Espèces', 'momo' => 'Mobile Money', 'credit' => 'Crédit'];

    private const LEDGER_LABELS = [
        'sale_credit' => 'Vente à crédit', 'payment' => 'Paiement', 'adjustment' => 'Ajustement', 'void' => 'Annulation',
    ];

    public function __construct(private ReportService $reports)
    {
    }

    public function depotName(): string
    {
        return (string) (tenant('name') ?? tenant('id') ?? config('app.name'));
    }

    /** Reçu / facture d'une vente. */
    public function receipt(Sale $sale): array
    {
        $sale->loadMissing('items.product:id,name', 'user:id,name');
        $customer = $sale->customer_id ? Customer::find($sale->customer_id) : null;

        $total = (int) $sale->total;
        $paid = $sale->payment_method === 'credit'
            ? (int) Payment::where('sale_id', $sale->id)->whereNull('voided_at')->sum('amount')
            : $total;

        $types = ContainerType::pluck('name', 'id');
        $containers = ContainerMovement::where('reference_type', $sale->getMorphClass())
            ->where('reference_id', $sale->id)->where('party_type', 'customer')
            ->selectRaw('container_type_id, sum(party_delta) as net')->groupBy('container_type_id')->get()
            ->filter(fn ($r) => (int) $r->net > 0)
            ->map(fn ($r) => ['name' => $types[$r->container_type_id] ?? 'Casier', 'quantity' => (int) $r->net])
            ->values()->all();

        return [
            'depot'      => $this->depotName(),
            'number'     => 'V-'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT),
            'sold_at'    => Fmt::date($sale->sold_at),
            'seller'     => $sale->user?->name,
            'customer'   => $customer ? ['name' => $customer->name, 'phone' => $customer->phone] : null,
            'voided'     => $sale->status === 'voided',
            'void_reason' => $sale->void_reason,
            'method'     => self::METHODS[$sale->payment_method] ?? $sale->payment_method,
            'is_credit'  => $sale->payment_method === 'credit',
            'lines'      => $sale->items->map(fn ($i) => [
                'name'       => $i->product?->name ?? 'Produit',
                'quantity'   => (int) $i->quantity,
                'unit'       => $i->unit === 'crate' ? 'casier' : 'bouteille',
                'unit_price' => (int) $i->unit_price,
                'total'      => (int) $i->line_total,
            ])->all(),
            'total'      => $total,
            'paid'       => $paid,
            'remaining'  => max(0, $total - $paid),
            'containers' => $containers,
        ];
    }

    /** Relevé de compte d'un client sur une période (solde d'ouverture, mouvements, solde final). */
    public function statement(Customer $customer, array $p): array
    {
        $opening = (int) (CustomerLedgerEntry::where('customer_id', $customer->id)
            ->where('created_at', '<', $p['start'])->orderByDesc('id')->value('balance_after') ?? 0);

        $entries = CustomerLedgerEntry::where('customer_id', $customer->id)
            ->whereBetween('created_at', [$p['start'], $p['end']])->orderBy('id')->limit(2000)->get();

        $types = ContainerType::pluck('name', 'id');
        $holdings = ContainerHolding::where('party_type', 'customer')->where('party_id', $customer->id)
            ->where('quantity', '>', 0)->get()
            ->map(fn ($h) => ['name' => $types[$h->container_type_id] ?? 'Casier', 'quantity' => (int) $h->quantity])->all();

        return [
            'depot'    => $this->depotName(),
            'customer' => ['name' => $customer->name, 'phone' => $customer->phone, 'type' => $customer->type, 'credit_limit' => (int) $customer->credit_limit],
            'period'   => $this->periodLabel($p),
            'opening'  => $opening,
            'closing'  => $entries->isEmpty() ? $opening : (int) $entries->last()->balance_after,
            'balance_now' => (int) $customer->balance,
            'charged'  => (int) $entries->where('amount', '>', 0)->sum('amount'),
            'paid'     => (int) abs($entries->where('amount', '<', 0)->sum('amount')),
            'rows'     => $entries->map(fn ($e) => [
                'date'          => Fmt::date($e->created_at),
                'label'         => self::LEDGER_LABELS[$e->type] ?? $e->type,
                'note'          => $e->note,
                'amount'        => (int) $e->amount,
                'balance_after' => (int) $e->balance_after,
            ])->all(),
            'holdings' => $holdings,
            'truncated' => $entries->count() >= 2000,
        ];
    }

    /** Rapport d'activité : ventes, encaissements, marge, journée par journée, meilleurs produits, caisses. */
    public function activity(array $p): array
    {
        $overview = $this->reports->overview($p);

        return [
            'depot'    => $this->depotName(),
            'period'   => $this->periodLabel($p),
            'overview' => $overview,
            'days'     => array_values(array_filter($this->reports->daily($p)['days'], fn ($d) => $d['sales_count'] > 0 || $d['expenses'] > 0)),
            'top'      => $this->reports->topProducts($p, 10)['products'],
            'cash'     => $this->reports->cashSessions($p),
            'alerts'   => $this->reports->stockAlerts(),
            'methods'  => self::METHODS,
        ];
    }

    /** Clôture (ou état en cours) d'une caisse. */
    public function cashClosing(CashSession $session): array
    {
        $session->loadMissing('user:id,name');
        $summary = $session->summary();
        $closedBy = $session->closed_by ? User::whereKey($session->closed_by)->value('name') : null;

        $sales = $session->sales();

        return [
            'depot'    => $this->depotName(),
            'number'   => 'C-'.str_pad((string) $session->id, 6, '0', STR_PAD_LEFT),
            'owner'    => $session->user?->name,
            'closed_by' => $closedBy,
            'open'     => $session->closed_at === null,
            'opened_at' => Fmt::date($session->opened_at),
            'closed_at' => Fmt::date($session->closed_at),
            'summary'  => $summary,
            'sales_count'  => (int) (clone $sales)->where('status', 'completed')->count(),
            'voided_count' => (int) (clone $sales)->where('status', 'voided')->count(),
            'expenses' => $session->expenses()->orderBy('id')->get()->map(fn ($e) => [
                'time' => Fmt::date($e->created_at, 'H:i'), 'category' => $e->category, 'note' => $e->note, 'amount' => (int) $e->amount,
            ])->all(),
            'counted'    => $session->counted_cash === null ? null : (int) $session->counted_cash,
            'difference' => $session->difference === null ? null : (int) $session->difference,
            'note'       => $session->note,
        ];
    }

    /** Créances clients : qui doit combien, depuis quand (dernier paiement), casiers dus. */
    public function receivables(): array
    {
        $lastPayment = Payment::whereNull('voided_at')->groupBy('customer_id')
            ->selectRaw('customer_id, max(paid_at) as last_paid')->pluck('last_paid', 'customer_id');
        $crates = ContainerHolding::where('party_type', 'customer')->where('quantity', '>', 0)
            ->groupBy('party_id')->selectRaw('party_id, sum(quantity) as n')->pluck('n', 'party_id');

        $rows = Customer::where('balance', '>', 0)->orderByDesc('balance')->get()->map(fn ($c) => [
            'name'       => $c->name,
            'phone'      => $c->phone,
            'type'       => $c->type,
            'balance'    => (int) $c->balance,
            'limit'      => (int) $c->credit_limit,
            'over_limit' => $c->balance > $c->credit_limit,
            'last_payment' => isset($lastPayment[$c->id]) ? Fmt::date($lastPayment[$c->id], 'd/m/Y') : null,
            'crates'     => (int) ($crates[$c->id] ?? 0),
        ])->all();

        return [
            'depot' => $this->depotName(),
            'date'  => Fmt::date(now(), 'd/m/Y H:i'),
            'rows'  => $rows,
            'total' => (int) array_sum(array_column($rows, 'balance')),
            'over_limit' => count(array_filter($rows, fn ($r) => $r['over_limit'])),
        ];
    }

    /** État du stock valorisé au coût d'achat, avec casiers vides. */
    public function stockValuation(): array
    {
        $unknownCost = 0;
        $rows = Product::where('active', true)->orderBy('name')->get()->map(function ($p) use (&$unknownCost) {
            $bpc = max(1, (int) $p->bottles_per_crate);
            $stock = (int) $p->stock_bottles;
            $value = $p->cost_crate === null ? null : (int) round($stock * $p->cost_crate / $bpc);
            if ($value === null) {
                $unknownCost++;
            }

            return [
                'name'      => $p->name,
                'crates'    => intdiv(max(0, $stock), $bpc),
                'loose'     => max(0, $stock) % $bpc,
                'bottles'   => $stock,
                'threshold' => (int) $p->alert_threshold,
                'status'    => $stock <= 0 ? 'Rupture' : ($stock <= $p->alert_threshold ? 'Bas' : ''),
                'cost'      => $p->cost_crate === null ? null : (int) $p->cost_crate,
                'value'     => $value,
            ];
        })->all();

        return [
            'depot' => $this->depotName(),
            'date'  => Fmt::date(now(), 'd/m/Y H:i'),
            'rows'  => $rows,
            'total_value' => (int) array_sum(array_map(fn ($r) => (int) $r['value'], $rows)),
            'unknown_cost' => $unknownCost,
            'empties' => ContainerType::orderBy('name')->get()->map(fn ($t) => ['name' => $t->name, 'quantity' => (int) $t->empty_stock])->all(),
        ];
    }

    /** Lignes de vente de la période pour l'export CSV (une ligne par article vendu). */
    public function salesRows(array $p): array
    {
        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->whereBetween('sales.sold_at', [$p['start'], $p['end']])
            ->orderBy('sales.sold_at')->orderBy('sales.id')->orderBy('sale_items.id')
            ->get([
                'sales.id as sale_id', 'sales.sold_at', 'sales.payment_method', 'sales.status', 'users.name as seller',
                'customers.name as customer', 'products.name as product', 'sale_items.unit', 'sale_items.quantity',
                'sale_items.unit_price', 'sale_items.line_total',
            ]);

        return $rows->map(fn ($r) => [
            'V-'.str_pad((string) $r->sale_id, 6, '0', STR_PAD_LEFT),
            Fmt::date(Carbon::parse($r->sold_at, config('app.timezone')), 'Y-m-d H:i'),
            $r->seller,
            $r->customer ?? '',
            self::METHODS[$r->payment_method] ?? $r->payment_method,
            $r->status === 'voided' ? 'annulée' : 'validée',
            $r->product,
            $r->unit === 'crate' ? 'casier' : 'bouteille',
            (int) $r->quantity,
            (int) $r->unit_price,
            (int) $r->line_total,
        ])->all();
    }

    private function periodLabel(array $p): string
    {
        return $p['from'] === $p['to'] ? Fmt::day($p['from']) : 'du '.Fmt::day($p['from']).' au '.Fmt::day($p['to']);
    }
}
