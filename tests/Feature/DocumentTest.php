<?php

namespace Tests\Feature;

use App\Models\CashSession;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\ReportService;
use App\Support\Fmt;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Les chiffres des documents sont vérifiés sur les données préparées par DocumentService ;
 * le rendu PDF lui-même est vérifié par son en-tête (%PDF) et les droits d'accès.
 */
class DocumentTest extends TenantTestCase
{
    private Tenant $t;
    private Product $p;   // 30 casiers (360 bouteilles), 7 000 FCFA / casier, coût 6 000

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->makeTenant('depot-a');
        $this->p = $this->makeProduct($this->t, ['name' => 'Béninoise 65cl', 'cost_crate' => 6000], crates: 30);
        $this->openCash($this->t, 'gerant', 50000);
    }

    // ---------------------------------------------------------------- aides

    private function sell(int $crates = 2, string $method = 'cash', array $extra = [], string $role = 'gerant')
    {
        $items = [['product_id' => $this->p->id, 'unit' => 'crate', 'quantity' => $crates]];

        return $this->asRole($this->t, $role)->postJson('/api/v1/sales', $this->saleBody($items, $method, $extra))->assertCreated();
    }

    private function docs(callable $fn): mixed
    {
        return $this->t->run(fn () => $fn(app(DocumentService::class), app(ReportService::class)));
    }

    private function today(): string
    {
        return now(Fmt::timezone())->toDateString();
    }

    private function assertPdf($response): void
    {
        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    private function url(string $path): string
    {
        return '/api/v1/'.$path;
    }

    // ---------------------------------------------------------------- reçu

    public function test_recu_d_une_vente_au_comptant(): void
    {
        $this->openCash($this->t, 'vendeur', 10000);
        $id = $this->sell(2, 'cash', [], 'vendeur')->json('id');

        $r = $this->docs(fn ($d) => $d->receipt(Sale::findOrFail($id)));

        $this->assertSame('V-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT), $r['number']);
        $this->assertSame('Vendeur', $r['seller']);
        $this->assertSame('Espèces', $r['method']);
        $this->assertNull($r['customer']);
        $this->assertFalse($r['voided']);
        $this->assertCount(1, $r['lines']);
        $this->assertSame(['name' => 'Béninoise 65cl', 'quantity' => 2, 'unit' => 'casier', 'unit_price' => 7000, 'total' => 14000], $r['lines'][0]);
        $this->assertSame([14000, 14000, 0], [$r['total'], $r['paid'], $r['remaining']]);

        $this->assertPdf($this->asRole($this->t, 'vendeur')->get($this->url("sales/{$id}/receipt.pdf")));
    }

    public function test_recu_reserve_au_vendeur_de_la_vente_ou_au_gerant(): void
    {
        $id = $this->sell(1)->json('id');   // vente du gérant

        $this->assertPdf($this->asRole($this->t)->get($this->url("sales/{$id}/receipt.pdf")));
        $this->asRole($this->t, 'vendeur')->get($this->url("sales/{$id}/receipt.pdf"))->assertForbidden();
        $this->asRole($this->t, 'caissier')->get($this->url("sales/{$id}/receipt.pdf"))->assertForbidden();
        $this->asRole($this->t, 'magasinier')->get($this->url("sales/{$id}/receipt.pdf"))->assertForbidden();
    }

    public function test_recu_d_une_vente_a_credit_partiellement_payee(): void
    {
        $customer = $this->makeCustomer($this->t);
        $id = $this->sell(2, 'credit', ['customer_id' => $customer, 'paid_now' => 5000, 'paid_method' => 'cash'])->json('id');

        $r = $this->docs(fn ($d) => $d->receipt(Sale::findOrFail($id)));

        $this->assertTrue($r['is_credit']);
        $this->assertSame('Bar Chez Paul', $r['customer']['name']);
        $this->assertSame([14000, 5000, 9000], [$r['total'], $r['paid'], $r['remaining']]);
    }

    public function test_recu_d_une_vente_annulee(): void
    {
        $id = $this->sell(1)->json('id');
        $this->asRole($this->t)->postJson($this->url("sales/{$id}/void"), ['reason' => 'erreur de saisie'])->assertOk();

        $r = $this->docs(fn ($d) => $d->receipt(Sale::findOrFail($id)));

        $this->assertTrue($r['voided']);
        $this->assertSame('erreur de saisie', $r['void_reason']);
        $this->assertPdf($this->asRole($this->t)->get($this->url("sales/{$id}/receipt.pdf")));
    }

    public function test_recu_indique_les_casiers_a_rendre(): void
    {
        $type = (int) $this->asRole($this->t)->postJson($this->url('containers'), ['name' => 'Casier 65cl', 'deposit_amount' => 3000])->json('id');
        $this->asRole($this->t)->putJson($this->url("products/{$this->p->id}"), ['container_type_id' => $type])->assertOk();

        $customer = $this->makeCustomer($this->t);
        $id = $this->sell(3, 'credit', ['customer_id' => $customer])->json('id');

        $r = $this->docs(fn ($d) => $d->receipt(Sale::findOrFail($id)));

        $this->assertSame([['name' => 'Casier 65cl', 'quantity' => 3]], $r['containers']);
    }

    // ---------------------------------------------------------------- relevé client

    public function test_releve_de_compte_avec_solde_d_ouverture_et_de_cloture(): void
    {
        $customer = $this->makeCustomer($this->t);
        $this->sell(2, 'credit', ['customer_id' => $customer]);   // +14 000
        $this->asRole($this->t)->postJson($this->url("customers/{$customer}/payments"), [
            'uuid' => (string) Str::uuid(), 'amount' => 4000, 'method' => 'cash',
        ])->assertCreated();                                       // −4 000

        $today = $this->today();
        $s = $this->docs(fn ($d, $reports) => $d->statement(\App\Models\Customer::findOrFail($customer), $reports->period($today, $today)));

        $this->assertSame(0, $s['opening']);
        $this->assertSame(10000, $s['closing']);
        $this->assertSame(14000, $s['charged']);
        $this->assertSame(4000, $s['paid']);
        $this->assertCount(2, $s['rows']);
        $this->assertSame('Vente à crédit', $s['rows'][0]['label']);
        $this->assertSame('Paiement', $s['rows'][1]['label']);
        $this->assertSame(-4000, $s['rows'][1]['amount']);
        $this->assertSame(10000, $s['rows'][1]['balance_after']);

        // Période sans mouvement : le solde d'ouverture reprend celui d'avant.
        $tomorrow = now(Fmt::timezone())->addDay()->toDateString();
        $later = $this->docs(fn ($d, $reports) => $d->statement(\App\Models\Customer::findOrFail($customer), $reports->period($tomorrow, $tomorrow)));
        $this->assertSame([10000, 10000, 0], [$later['opening'], $later['closing'], count($later['rows'])]);
    }

    public function test_releve_pdf_droits_et_periode(): void
    {
        $customer = $this->makeCustomer($this->t);

        $this->assertPdf($this->asRole($this->t)->get($this->url("customers/{$customer}/statement.pdf")));
        $this->assertPdf($this->asRole($this->t, 'vendeur')->get($this->url("customers/{$customer}/statement.pdf?from=2026-01-01&to=2026-01-31")));
        $this->asRole($this->t, 'magasinier')->get($this->url("customers/{$customer}/statement.pdf"))->assertForbidden();
        $this->asRole($this->t)->getJson($this->url("customers/{$customer}/statement.pdf?from=2026-02-01&to=2026-01-01"))->assertStatus(422);
    }

    // ---------------------------------------------------------------- rapport d'activité

    public function test_rapport_d_activite(): void
    {
        $this->sell(2);   // 14 000 en espèces

        $today = $this->today();
        $a = $this->docs(fn ($d, $reports) => $d->activity($reports->period($today, $today)));

        $this->assertSame(1, $a['overview']['sales']['count']);
        $this->assertSame(14000, $a['overview']['sales']['revenue']);
        $this->assertCount(1, $a['days']);
        $this->assertSame(14000, $a['days'][0]['cash']);
        $this->assertSame('Béninoise 65cl', $a['top'][0]['name']);
        $this->assertSame(14000, $a['top'][0]['revenue']);

        $this->assertPdf($this->asRole($this->t)->get($this->url('reports/activity.pdf')));
        $this->asRole($this->t, 'caissier')->get($this->url('reports/activity.pdf'))->assertForbidden();
        $this->asRole($this->t)->getJson($this->url('reports/activity.pdf?from=2026-02-01&to=2026-01-01'))->assertStatus(422);
    }

    // ---------------------------------------------------------------- caisse

    public function test_cloture_de_caisse(): void
    {
        $this->sell(2);   // 14 000 en espèces
        $this->asRole($this->t)->postJson($this->url('cash/expenses'), ['category' => 'transport', 'amount' => 2000])->assertCreated();

        $sessionId = (int) $this->asRole($this->t)->postJson($this->url('cash/close'), ['counted_cash' => 61500])->assertOk()->json('session.id');

        $c = $this->docs(fn ($d) => $d->cashClosing(CashSession::findOrFail($sessionId)));

        $this->assertFalse($c['open']);
        $this->assertNotEmpty($c['owner']);
        $this->assertSame(14000, $c['summary']['cash_sales']);
        $this->assertSame(62000, $c['summary']['expected_cash']);   // 50 000 + 14 000 − 2 000
        $this->assertSame(61500, $c['counted']);
        $this->assertSame(-500, $c['difference']);
        $this->assertSame(1, $c['sales_count']);
        $this->assertSame('transport', $c['expenses'][0]['category']);
        $this->assertSame(2000, $c['expenses'][0]['amount']);

        $this->assertPdf($this->asRole($this->t)->get($this->url("cash/sessions/{$sessionId}/report.pdf")));
        $this->asRole($this->t, 'vendeur')->get($this->url("cash/sessions/{$sessionId}/report.pdf"))->assertForbidden();
    }

    public function test_un_vendeur_imprime_sa_propre_caisse_en_cours(): void
    {
        $this->openCash($this->t, 'vendeur', 10000);
        $vendorId = (int) $this->t->run(fn () => User::where('role', 'vendeur')->value('id'));
        $sessionId = (int) $this->t->run(fn () => CashSession::open()->where('user_id', $vendorId)->value('id'));

        $this->assertPdf($this->asRole($this->t, 'vendeur')->get($this->url("cash/sessions/{$sessionId}/report.pdf")));

        $c = $this->docs(fn ($d) => $d->cashClosing(CashSession::findOrFail($sessionId)));
        $this->assertTrue($c['open']);
        $this->assertNull($c['counted']);
    }

    // ---------------------------------------------------------------- créances, stock

    public function test_creances_clients(): void
    {
        $a = $this->makeCustomer($this->t, 100000, 'Bar A');
        $b = $this->makeCustomer($this->t, 100000, 'Bar B');
        $this->sell(2, 'credit', ['customer_id' => $a]);   // 14 000
        $this->sell(3, 'credit', ['customer_id' => $b]);   // 21 000
        $this->asRole($this->t)->putJson($this->url("customers/{$a}"), ['credit_limit' => 10000])->assertOk();   // A dépasse sa limite
        $this->asRole($this->t)->postJson($this->url("customers/{$b}/payments"), ['uuid' => (string) Str::uuid(), 'amount' => 1000, 'method' => 'cash'])->assertCreated();

        $r = $this->docs(fn ($d) => $d->receivables());

        $this->assertSame(['Bar B', 'Bar A'], array_column($r['rows'], 'name'));   // du plus gros solde au plus petit
        $this->assertSame([20000, 14000], array_column($r['rows'], 'balance'));
        $this->assertSame(34000, $r['total']);
        $this->assertSame(1, $r['over_limit']);
        $this->assertNotNull($r['rows'][0]['last_payment']);
        $this->assertNull($r['rows'][1]['last_payment']);

        $this->assertPdf($this->asRole($this->t)->get($this->url('reports/receivables.pdf')));
        $this->asRole($this->t, 'caissier')->get($this->url('reports/receivables.pdf'))->assertForbidden();
        $this->asRole($this->t, 'magasinier')->get($this->url('reports/receivables.pdf'))->assertForbidden();
    }

    public function test_etat_du_stock_valorise(): void
    {
        $this->makeProduct($this->t, ['name' => 'Sans coût']);   // stock 0, aucun coût d'achat

        $s = $this->docs(fn ($d) => $d->stockValuation());

        $this->assertSame(['Béninoise 65cl', 'Sans coût'], array_column($s['rows'], 'name'));
        $this->assertSame([30, 0, 360, 180000], [$s['rows'][0]['crates'], $s['rows'][0]['loose'], $s['rows'][0]['bottles'], $s['rows'][0]['value']]);
        $this->assertSame('Rupture', $s['rows'][1]['status']);
        $this->assertNull($s['rows'][1]['value']);
        $this->assertSame(180000, $s['total_value']);
        $this->assertSame(1, $s['unknown_cost']);

        $this->assertPdf($this->asRole($this->t)->get($this->url('reports/stock.pdf')));
        $this->assertPdf($this->asRole($this->t, 'magasinier')->get($this->url('reports/stock.pdf')));
        $this->asRole($this->t, 'vendeur')->get($this->url('reports/stock.pdf'))->assertForbidden();
    }

    // ---------------------------------------------------------------- export CSV

    public function test_export_csv_des_ventes(): void
    {
        $customer = $this->makeCustomer($this->t, 100000, '=1+1');   // nom piégé : doit être neutralisé
        $this->sell(2, 'credit', ['customer_id' => $customer]);
        $cashId = $this->sell(1)->json('id');
        $this->asRole($this->t)->postJson($this->url("sales/{$cashId}/void"), ['reason' => 'test'])->assertOk();

        $response = $this->asRole($this->t)->get($this->url('reports/sales.csv'));
        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));

        $content = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);   // BOM : Excel lit l'UTF-8

        $lines = preg_split('/\r\n|\n/', trim(substr($content, 3)));
        $this->assertCount(3, $lines);                              // en-tête + 2 articles
        $this->assertStringStartsWith('Vente;Date;Vendeur;Client;Paiement;Statut;Produit', $lines[0]);

        $credit = str_getcsv($lines[1], ';', '"', '');
        $this->assertSame("'=1+1", $credit[3]);
        $this->assertSame(['Crédit', 'validée', 'Béninoise 65cl', 'casier', '2', '7000', '14000'], array_slice($credit, 4));

        $voided = str_getcsv($lines[2], ';', '"', '');
        $this->assertSame('annulée', $voided[5]);

        $this->asRole($this->t, 'vendeur')->get($this->url('reports/sales.csv'))->assertForbidden();
        $this->asRole($this->t, 'caissier')->get($this->url('reports/sales.csv'))->assertForbidden();
    }

    // ---------------------------------------------------------------- mise en forme, isolation

    public function test_formatage_des_montants(): void
    {
        $this->assertSame("14\u{00A0}000\u{00A0}FCFA", Fmt::money(14000));
        $this->assertSame('0', Fmt::money(0, false));
        $this->assertSame("1\u{00A0}250\u{00A0}000", Fmt::money(1250000, false));
        $this->assertSame("+3\u{00A0}000", Fmt::signed(3000));
        $this->assertSame("\u{2212}3\u{00A0}000", Fmt::signed(-3000));
        $this->assertSame('0', Fmt::signed(0));
    }

    public function test_un_depot_ne_peut_pas_imprimer_les_documents_d_un_autre(): void
    {
        $id = $this->sell(1)->json('id');
        $b = $this->makeTenant('depot-b');

        $this->asRole($b)->get($this->url("sales/{$id}/receipt.pdf"))->assertNotFound();
        $this->assertPdf($this->asRole($this->t)->get($this->url("sales/{$id}/receipt.pdf")));
    }
}
