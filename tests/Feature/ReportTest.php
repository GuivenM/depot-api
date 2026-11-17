<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class ReportTest extends TenantTestCase
{
    private Tenant $t;
    private Product $p;   // 30 casiers (360 bouteilles), coût 5 000 / casier, vendu 7 000 / casier

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->makeTenant('depot-a');
        $this->p = $this->makeProduct($this->t, ['name' => 'Béninoise 65cl', 'cost_crate' => 5000], crates: 30);
        $this->openCash($this->t, 'gerant', 50000);
    }

    // ---------------------------------------------------------------- aides

    private function sell(int $crates, string $method = 'cash', array $extra = [], ?Product $product = null)
    {
        $items = [['product_id' => ($product ?? $this->p)->id, 'unit' => 'crate', 'quantity' => $crates]];

        return $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($items, $method, $extra));
    }

    private function overview(string $query = '')
    {
        return $this->asRole($this->t)->getJson('/api/v1/reports/overview'.$query);
    }

    private function today(): string
    {
        return Carbon::now('Africa/Porto-Novo')->toDateString();
    }

    // ---------------------------------------------------------------- vue d'ensemble

    public function test_ventes_par_mode_de_paiement_et_marge_estimee(): void
    {
        $customer = $this->makeCustomer($this->t, 10000);

        $this->sell(2, 'cash')->assertCreated();                                    // 14 000
        $this->sell(1, 'momo')->assertCreated();                                    //  7 000
        $this->sell(1, 'credit', ['customer_id' => $customer])->assertCreated();   //  7 000

        $res = $this->overview()->assertOk();

        $this->assertEquals(3, $res->json('sales.count'));
        $this->assertEquals(28000, $res->json('sales.revenue'));
        $this->assertEquals(14000, $res->json('sales.by_method.cash.amount'));
        $this->assertEquals(1, $res->json('sales.by_method.cash.count'));
        $this->assertEquals(7000, $res->json('sales.by_method.momo.amount'));
        $this->assertEquals(7000, $res->json('sales.by_method.credit.amount'));

        // 4 casiers vendus x 5 000 de coût = 20 000 ; marge = 28 000 - 20 000
        $this->assertEquals(20000, $res->json('margin.estimated_cost'));
        $this->assertEquals(8000, $res->json('margin.estimated_margin'));
        $this->assertEquals(0, $res->json('margin.uncosted_revenue'));
    }

    public function test_les_ventes_annulees_sont_exclues_du_chiffre_d_affaires(): void
    {
        $id = $this->sell(2)->assertCreated()->json('id');
        $this->sell(1)->assertCreated();

        $this->asRole($this->t)->postJson("/api/v1/sales/{$id}/void", ['reason' => 'erreur'])->assertOk();

        $res = $this->overview()->assertOk();

        $this->assertEquals(7000, $res->json('sales.revenue'));
        $this->assertEquals(1, $res->json('sales.count'));
        $this->assertEquals(1, $res->json('sales.voided.count'));
        $this->assertEquals(14000, $res->json('sales.voided.amount'));
    }

    public function test_un_produit_sans_cout_n_entre_pas_dans_la_marge(): void
    {
        $free = $this->makeProduct($this->t, ['name' => 'Sans coût', 'cost_crate' => null], crates: 5);

        $this->sell(1, 'cash')->assertCreated();                // 7 000, coût connu 5 000
        $this->sell(1, 'cash', [], $free)->assertCreated();     // 7 000, coût inconnu

        $res = $this->overview()->assertOk();

        $this->assertEquals(14000, $res->json('sales.revenue'));
        $this->assertEquals(5000, $res->json('margin.estimated_cost'));
        $this->assertEquals(2000, $res->json('margin.estimated_margin'));
        $this->assertEquals(7000, $res->json('margin.uncosted_revenue'));
    }

    public function test_depenses_encaissements_et_achats(): void
    {
        $customer = $this->makeCustomer($this->t, 100000);
        $supplier = (int) $this->asRole($this->t)->postJson('/api/v1/suppliers', ['name' => 'Brasserie'])->assertCreated()->json('id');

        $this->asRole($this->t)->postJson('/api/v1/cash/expenses', ['category' => 'transport', 'amount' => 2000])->assertCreated();

        $this->sell(2, 'credit', ['customer_id' => $customer])->assertCreated();   // dette 14 000
        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/payments", ['uuid' => (string) Str::uuid(), 'amount' => 3000, 'method' => 'cash'])->assertCreated();
        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/payments", ['uuid' => (string) Str::uuid(), 'amount' => 1000, 'method' => 'momo'])->assertCreated();

        $this->asRole($this->t)->postJson('/api/v1/purchases', [
            'uuid' => (string) Str::uuid(), 'supplier_id' => $supplier,
            'items' => [['product_id' => $this->p->id, 'unit' => 'crate', 'quantity' => 10, 'unit_cost' => 6000]],
            'paid_now' => 20000, 'paid_method' => 'bank',
        ])->assertCreated();

        $res = $this->overview()->assertOk();

        $this->assertEquals(2000, $res->json('expenses.total'));
        $this->assertEquals(3000, $res->json('collections.customer_payments.cash'));
        $this->assertEquals(1000, $res->json('collections.customer_payments.momo'));
        $this->assertEquals(20000, $res->json('collections.supplier_payments.bank'));
        $this->assertEquals(1, $res->json('purchases.count'));
        $this->assertEquals(60000, $res->json('purchases.total'));
    }

    public function test_situation_actuelle_creances_dettes_stock_et_casiers(): void
    {
        $customer = $this->makeCustomer($this->t, 10000);
        $supplier = (int) $this->asRole($this->t)->postJson('/api/v1/suppliers', ['name' => 'Brasserie'])->assertCreated()->json('id');

        $this->sell(1, 'credit', ['customer_id' => $customer])->assertCreated();   // dette 7 000, limite 10 000

        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/purchases', [
            'uuid' => (string) Str::uuid(), 'supplier_id' => $supplier,
            'items' => [['product_id' => $this->p->id, 'unit' => 'crate', 'quantity' => 10, 'unit_cost' => 6000]],
        ])->assertCreated();   // dette fournisseur 60 000 ; le dernier coût passe à 6 000

        $res = $this->overview()->assertOk();

        $this->assertEquals(7000, $res->json('snapshot.receivables.total'));
        $this->assertEquals(1, $res->json('snapshot.receivables.debtors'));
        $this->assertEquals(0, $res->json('snapshot.receivables.over_limit'));
        $this->assertEquals(60000, $res->json('snapshot.payables.total'));

        // stock : 360 - 12 + 120 = 468 bouteilles = 39 casiers à 6 000 (dernier coût)
        $this->assertEquals(234000, $res->json('snapshot.stock.value_at_cost'));
    }

    public function test_la_periode_filtre_les_resultats(): void
    {
        $this->sell(2)->assertCreated();

        $this->assertEquals(14000, $this->overview()->json('sales.revenue'));
        $this->assertEquals(14000, $this->overview('?from='.$this->today().'&to='.$this->today())->json('sales.revenue'));
        $this->assertEquals(0, $this->overview('?from=2020-01-01&to=2020-01-31')->json('sales.revenue'));
    }

    public function test_periodes_invalides_sont_refusees(): void
    {
        $this->overview('?from=2026-11-10&to=2026-11-01')->assertStatus(422);       // fin avant début
        $this->overview('?from=2024-01-01&to=2026-01-01')->assertStatus(422);       // plus de 366 jours
        $this->overview('?from=10/11/2026')->assertStatus(422);                     // mauvais format
    }

    // ---------------------------------------------------------------- jour par jour, produits, alertes, caisses

    public function test_courbe_jour_par_jour_avec_jours_vides_a_zero(): void
    {
        $this->sell(2)->assertCreated();
        $from = Carbon::now('Africa/Porto-Novo')->subDays(2)->toDateString();

        $res = $this->asRole($this->t)->getJson("/api/v1/reports/daily?from={$from}&to=".$this->today())->assertOk();

        $this->assertCount(3, $res->json('days'));
        $this->assertSame($from, $res->json('days.0.date'));
        $this->assertEquals(0, $res->json('days.0.revenue'));
        $this->assertSame($this->today(), $res->json('days.2.date'));
        $this->assertEquals(14000, $res->json('days.2.revenue'));
        $this->assertEquals(14000, $res->json('days.2.cash'));
        $this->assertEquals(1, $res->json('days.2.sales_count'));
    }

    public function test_la_courbe_est_limitee_a_92_jours(): void
    {
        $from = Carbon::now('Africa/Porto-Novo')->subDays(100)->toDateString();

        $this->asRole($this->t)->getJson("/api/v1/reports/daily?from={$from}&to=".$this->today())->assertStatus(422);
    }

    public function test_produits_les_plus_vendus(): void
    {
        $other = $this->makeProduct($this->t, ['name' => 'Castel 65cl', 'price_crate' => 8000], crates: 5);

        $this->sell(3)->assertCreated();                      // 21 000
        $this->sell(1, 'cash', [], $other)->assertCreated();   //  8 000

        $res = $this->asRole($this->t)->getJson('/api/v1/reports/top-products?limit=5')->assertOk();

        $this->assertSame('Béninoise 65cl', $res->json('products.0.name'));
        $this->assertEquals(36, $res->json('products.0.bottles_sold'));
        $this->assertEquals(3, $res->json('products.0.crates_sold'));
        $this->assertEquals(21000, $res->json('products.0.revenue'));
        $this->assertSame('Castel 65cl', $res->json('products.1.name'));
    }

    public function test_alertes_de_stock_rupture_et_stock_bas(): void
    {
        $this->makeProduct($this->t, ['name' => 'Bas', 'alert_threshold' => 24], crates: 1);   // 12 <= 24
        $this->makeProduct($this->t, ['name' => 'Rupture']);                                    // 0 <= 0

        $res = $this->asRole($this->t, 'magasinier')->getJson('/api/v1/reports/stock-alerts')->assertOk();

        $byName = collect($res->json('products'))->keyBy('name');

        $this->assertEquals(2, $res->json('count'));          // le produit principal (360 bouteilles) n'y est pas
        $this->assertSame('bas', $byName['Bas']['status']);
        $this->assertSame('rupture', $byName['Rupture']['status']);
        $this->assertEquals(1, $byName['Bas']['stock_crates']);
    }

    public function test_ecarts_de_caisse_par_vendeur(): void
    {
        $this->sell(2)->assertCreated();   // théorique 64 000
        $this->asRole($this->t)->postJson('/api/v1/cash/close', ['counted_cash' => 63000])->assertOk();

        $res = $this->asRole($this->t)->getJson('/api/v1/reports/cash-sessions')->assertOk();

        $this->assertCount(1, $res->json('sessions'));
        $this->assertEquals(-1000, $res->json('sessions.0.difference'));
        $this->assertSame('Gerant', $res->json('by_user.0.user'));
        $this->assertEquals(1, $res->json('by_user.0.sessions_with_gap'));
        $this->assertEquals(-1000, $res->json('by_user.0.total_difference'));
    }

    // ---------------------------------------------------------------- droits et isolation

    public function test_rapports_reserves_au_gerant_sauf_les_alertes_de_stock(): void
    {
        foreach (['caissier', 'vendeur', 'magasinier'] as $role) {
            $this->asRole($this->t, $role)->getJson('/api/v1/reports/overview')->assertForbidden();
            $this->asRole($this->t, $role)->getJson('/api/v1/reports/cash-sessions')->assertForbidden();
        }

        $this->asRole($this->t, 'magasinier')->getJson('/api/v1/reports/stock-alerts')->assertOk();
        $this->asRole($this->t, 'vendeur')->getJson('/api/v1/reports/stock-alerts')->assertForbidden();
    }

    public function test_les_rapports_d_un_depot_ne_montrent_pas_les_donnees_d_un_autre(): void
    {
        $b = $this->makeTenant('depot-b');
        $this->sell(2)->assertCreated();

        $this->assertEquals(14000, $this->overview()->json('sales.revenue'));
        $this->assertEquals(0, $this->asRole($b)->getJson('/api/v1/reports/overview')->json('sales.revenue'));
        $this->assertEquals(0, $this->asRole($b)->getJson('/api/v1/reports/overview')->json('snapshot.stock.value_at_cost'));
    }
}
