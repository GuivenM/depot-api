<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Tenant;
use Tests\TenantTestCase;

class SalesTest extends TenantTestCase
{
    private Tenant $t;
    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->makeTenant('depot-a');
        $this->p = $this->makeProduct($this->t, [], crates: 10);   // 120 bouteilles
    }

    private function crates(int $n, ?Product $product = null): array
    {
        return [['product_id' => ($product ?? $this->p)->id, 'unit' => 'crate', 'quantity' => $n]];
    }

    public function test_vente_comptant_retire_le_stock_et_utilise_le_prix_du_serveur(): void
    {
        $this->openCash($this->t);

        // Le client essaie d'imposer un prix et un total : ils doivent être ignorés.
        $body = $this->saleBody($this->crates(2), 'cash', ['unit_price' => 1, 'total' => 1]);
        $res = $this->asRole($this->t)->postJson('/api/v1/sales', $body)->assertCreated();

        $this->assertEquals(14000, $res->json('total'));
        $this->assertEquals(7000, $res->json('items.0.unit_price'));
        $this->assertSame(120 - 24, $this->stockOf($this->t, $this->p->id));
        $this->assertEquals(-24, $this->t->run(fn () => StockMovement::where('type', 'sortie')->sum('quantity')));
    }

    public function test_une_vente_rejouee_avec_le_meme_uuid_ne_se_duplique_pas(): void
    {
        $this->openCash($this->t);
        $body = $this->saleBody($this->crates(2));

        $this->asRole($this->t)->postJson('/api/v1/sales', $body)->assertCreated();
        $this->asRole($this->t)->postJson('/api/v1/sales', $body)->assertOk();

        $this->assertSame(1, $this->t->run(fn () => Sale::count()));
        $this->assertSame(96, $this->stockOf($this->t, $this->p->id));
    }

    public function test_stock_insuffisant_annule_toute_la_vente(): void
    {
        $this->openCash($this->t);
        $rare = $this->makeProduct($this->t, ['name' => 'Rare'], crates: 1);   // 12 bouteilles

        $items = array_merge($this->crates(1), $this->crates(2, $rare));   // la 2e ligne est impossible
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($items))->assertStatus(422);

        $this->assertSame(0, $this->t->run(fn () => Sale::count()));
        $this->assertSame(120, $this->stockOf($this->t, $this->p->id));   // la 1re ligne n'a pas été débitée
        $this->assertSame(12, $this->stockOf($this->t, $rare->id));
        $this->assertSame(0, $this->t->run(fn () => StockMovement::where('type', 'sortie')->count()));
    }

    public function test_vente_sans_caisse_ouverte_est_refusee(): void
    {
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(1)))->assertStatus(409);

        $this->assertSame(120, $this->stockOf($this->t, $this->p->id));
    }

    public function test_vente_a_la_bouteille(): void
    {
        $this->openCash($this->t);

        $items = [['product_id' => $this->p->id, 'unit' => 'bottle', 'quantity' => 5]];
        $res = $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($items))->assertCreated();

        $this->assertEquals(3000, $res->json('total'));
        $this->assertSame(115, $this->stockOf($this->t, $this->p->id));
    }

    public function test_vente_a_la_bouteille_sans_prix_unitaire_est_refusee(): void
    {
        $this->openCash($this->t);
        $noBottlePrice = $this->makeProduct($this->t, ['name' => 'Fût', 'price_bottle' => null], crates: 1);

        $items = [['product_id' => $noBottlePrice->id, 'unit' => 'bottle', 'quantity' => 1]];
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($items))->assertStatus(422);
    }

    public function test_produit_desactive_ne_se_vend_plus(): void
    {
        $this->openCash($this->t);
        $this->asRole($this->t)->putJson("/api/v1/products/{$this->p->id}", ['active' => false])->assertOk();

        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(1)))->assertStatus(422);
    }

    public function test_un_magasinier_ne_peut_pas_vendre(): void
    {
        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/sales', $this->saleBody($this->crates(1)))->assertForbidden();
    }

    public function test_cloture_de_caisse_calcule_le_theorique_et_l_ecart(): void
    {
        $this->openCash($this->t, 'gerant', 50000);

        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(2), 'cash'))->assertCreated();   // 14 000
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(1), 'momo'))->assertCreated();   //  7 000 (hors tiroir)
        $this->asRole($this->t)->postJson('/api/v1/cash/expenses', ['category' => 'transport', 'amount' => 2000])->assertCreated();

        $res = $this->asRole($this->t)->postJson('/api/v1/cash/close', ['counted_cash' => 61000])->assertOk();

        $this->assertEquals(62000, $res->json('session.expected_cash'));   // 50 000 + 14 000 - 2 000
        $this->assertEquals(-1000, $res->json('session.difference'));
        $this->assertEquals(7000, $res->json('summary.momo_sales'));

        // Caisse clôturée : plus de vente possible, et on ne peut pas la clôturer deux fois.
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(1)))->assertStatus(409);
        $this->asRole($this->t)->postJson('/api/v1/cash/close', ['counted_cash' => 0])->assertStatus(409);
    }

    public function test_on_ne_peut_pas_ouvrir_deux_caisses(): void
    {
        $this->openCash($this->t);

        $this->asRole($this->t)->postJson('/api/v1/cash/open', ['opening_amount' => 0])->assertStatus(409);
    }

    public function test_chaque_vendeur_a_sa_propre_caisse(): void
    {
        $this->openCash($this->t, 'gerant');

        // Le caissier n'a pas ouvert la sienne : il ne peut pas vendre avec celle du gérant.
        $this->asRole($this->t, 'caissier')->postJson('/api/v1/sales', $this->saleBody($this->crates(1)))->assertStatus(409);

        $this->openCash($this->t, 'caissier');
        $this->asRole($this->t, 'caissier')->postJson('/api/v1/sales', $this->saleBody($this->crates(1)))->assertCreated();
    }
}
