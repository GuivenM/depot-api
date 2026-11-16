<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class PurchaseTest extends TenantTestCase
{
    private Tenant $t;
    private Product $p;
    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->makeTenant('depot-a');
        $this->p = $this->makeProduct($this->t);   // stock 0
        $this->supplier = (int) $this->asRole($this->t)->postJson('/api/v1/suppliers', ['name' => 'Brasserie du Bénin'])
            ->assertCreated()->json('id');
    }

    private function buy(array $extra = [], int $crates = 10, int $cost = 6000, string $role = 'magasinier', ?Product $product = null)
    {
        $items = [['product_id' => ($product ?? $this->p)->id, 'unit' => 'crate', 'quantity' => $crates, 'unit_cost' => $cost]];

        return $this->asRole($this->t, $role)->postJson('/api/v1/purchases', array_merge([
            'uuid' => (string) Str::uuid(), 'supplier_id' => $this->supplier, 'items' => $items,
        ], $extra));
    }

    private function debt(): int
    {
        return (int) $this->asRole($this->t)->getJson("/api/v1/suppliers/{$this->supplier}/ledger")->json('supplier.balance');
    }

    private function withContainers(int $empties = 20): int
    {
        $type = (int) $this->asRole($this->t)->postJson('/api/v1/containers', ['name' => 'Casier 65cl', 'deposit_amount' => 3000])
            ->assertCreated()->json('id');
        $this->asRole($this->t)->putJson("/api/v1/products/{$this->p->id}", ['container_type_id' => $type])->assertOk();
        $this->asRole($this->t)->postJson('/api/v1/containers/depot-movements', [
            'container_type_id' => $type, 'kind' => 'adjustment', 'quantity' => $empties, 'note' => 'Stock initial',
        ])->assertCreated();

        return $type;
    }

    public function test_reception_augmente_le_stock_la_dette_et_le_dernier_cout(): void
    {
        $res = $this->buy()->assertCreated();   // 10 casiers à 6 000

        $this->assertEquals(60000, $res->json('total'));
        $this->assertSame(120, $this->stockOf($this->t, $this->p->id));
        $this->assertSame(60000, $this->debt());
        $this->assertEquals(6000, $this->asRole($this->t)->getJson('/api/v1/products')->json('0.cost_crate'));
    }

    public function test_un_achat_rejoue_avec_le_meme_uuid_ne_se_duplique_pas(): void
    {
        $body = ['uuid' => (string) Str::uuid()];

        $this->buy($body)->assertCreated();
        $this->buy($body)->assertOk();

        $this->assertSame(1, $this->t->run(fn () => Purchase::count()));
        $this->assertSame(120, $this->stockOf($this->t, $this->p->id));
        $this->assertSame(60000, $this->debt());
    }

    public function test_paiement_partiel_en_cash_par_le_gerant_sort_du_tiroir(): void
    {
        $this->openCash($this->t, 'gerant', 50000);

        $this->buy(['paid_now' => 20000, 'paid_method' => 'cash'], role: 'gerant')->assertCreated();

        $this->assertSame(40000, $this->debt());

        // fond 50 000 - 20 000 payés au fournisseur en cash = 30 000
        $res = $this->asRole($this->t)->postJson('/api/v1/cash/close', ['counted_cash' => 30000])->assertOk();
        $this->assertEquals(30000, $res->json('session.expected_cash'));
        $this->assertEquals(0, $res->json('session.difference'));
        $this->assertEquals(20000, $res->json('summary.supplier_payments_cash'));
    }

    public function test_le_magasinier_recoit_mais_ne_paie_pas(): void
    {
        $this->buy(['paid_now' => 1000, 'paid_method' => 'momo'], role: 'magasinier')->assertForbidden();
        $this->assertSame(0, $this->stockOf($this->t, $this->p->id));
        $this->assertSame(0, $this->t->run(fn () => Purchase::count()));

        $this->buy(role: 'magasinier')->assertCreated();
    }

    public function test_paiement_cash_sans_caisse_ouverte_annule_tout(): void
    {
        $this->buy(['paid_now' => 5000, 'paid_method' => 'cash'], role: 'gerant')->assertStatus(409);

        $this->assertSame(0, $this->stockOf($this->t, $this->p->id));
        $this->assertSame(0, $this->t->run(fn () => Purchase::count()));
        $this->assertSame(0, $this->debt());
    }

    public function test_paiement_a_l_achat_ne_peut_pas_depasser_le_total(): void
    {
        $this->buy(['paid_now' => 70000, 'paid_method' => 'momo'], role: 'gerant')->assertStatus(422);

        $this->assertSame(0, $this->stockOf($this->t, $this->p->id));
    }

    public function test_reglement_ulterieur_momo_idempotent_et_plafonne(): void
    {
        $this->buy()->assertCreated();   // dette 60 000

        $payment = ['uuid' => (string) Str::uuid(), 'amount' => 25000, 'method' => 'momo'];

        $this->asRole($this->t)->postJson("/api/v1/suppliers/{$this->supplier}/payments", $payment)->assertCreated();
        $this->asRole($this->t)->postJson("/api/v1/suppliers/{$this->supplier}/payments", $payment)->assertOk();
        $this->assertSame(35000, $this->debt());

        $this->asRole($this->t)->postJson("/api/v1/suppliers/{$this->supplier}/payments", [
            'uuid' => (string) Str::uuid(), 'amount' => 40000, 'method' => 'bank',
        ])->assertStatus(422);
        $this->assertSame(35000, $this->debt());
    }

    public function test_reglement_cash_diminue_la_caisse_theorique(): void
    {
        $this->openCash($this->t, 'gerant', 50000);
        $this->buy()->assertCreated();

        $this->asRole($this->t)->postJson("/api/v1/suppliers/{$this->supplier}/payments", [
            'uuid' => (string) Str::uuid(), 'amount' => 15000, 'method' => 'cash',
        ])->assertCreated();

        $res = $this->asRole($this->t)->postJson('/api/v1/cash/close', ['counted_cash' => 35000])->assertOk();

        $this->assertEquals(35000, $res->json('session.expected_cash'));
        $this->assertEquals(0, $res->json('session.difference'));
    }

    public function test_achat_atomique_une_ligne_invalide_annule_tout(): void
    {
        $off = $this->makeProduct($this->t, ['name' => 'Désactivé', 'active' => false]);

        $items = [
            ['product_id' => $this->p->id, 'unit' => 'crate', 'quantity' => 5, 'unit_cost' => 6000],
            ['product_id' => $off->id, 'unit' => 'crate', 'quantity' => 1, 'unit_cost' => 6000],
        ];

        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/purchases', [
            'uuid' => (string) Str::uuid(), 'supplier_id' => $this->supplier, 'items' => $items,
        ])->assertStatus(422);

        $this->assertSame(0, $this->stockOf($this->t, $this->p->id));
        $this->assertSame(0, $this->debt());
    }

    public function test_10_casiers_recus_8_vides_rendus_le_depot_doit_2_au_fournisseur(): void
    {
        $this->withContainers(20);

        $this->buy(['empties_returned' => [['container_type_id' => $this->typeIdOf(), 'quantity' => 8]]])->assertCreated();

        $held = $this->asRole($this->t, 'magasinier')->getJson("/api/v1/suppliers/{$this->supplier}/containers");
        $this->assertCount(1, $held->json());
        $this->assertEquals(2, $held->json('0.quantity'));

        $overview = $this->asRole($this->t)->getJson('/api/v1/containers');
        $this->assertEquals(12, $overview->json('0.empty_stock'));        // 20 - 8 rendus
        $this->assertEquals(2, $overview->json('0.owed_to_suppliers'));
    }

    public function test_rendre_plus_de_vides_que_le_depot_n_en_a_annule_tout(): void
    {
        $type = $this->withContainers(20);

        $this->buy(['empties_returned' => [['container_type_id' => $type, 'quantity' => 25]]])->assertStatus(422);

        $this->assertSame(0, $this->stockOf($this->t, $this->p->id));
        $this->assertSame(0, $this->t->run(fn () => Purchase::count()));
        $this->assertSame(0, $this->debt());
        $this->assertEquals(20, $this->asRole($this->t)->getJson('/api/v1/containers')->json('0.empty_stock'));
    }

    public function test_dettes_fournisseurs_listees_pour_le_gerant(): void
    {
        $this->buy()->assertCreated();

        $res = $this->asRole($this->t)->getJson('/api/v1/payables')->assertOk();

        $this->assertEquals(60000, $res->json('total_payable'));
        $this->assertSame('Brasserie du Bénin', $res->json('suppliers.0.name'));
    }

    public function test_ajustement_de_dette_reserve_au_gerant_et_jamais_negatif(): void
    {
        $this->buy()->assertCreated();   // 60 000

        $this->asRole($this->t, 'magasinier')->postJson("/api/v1/suppliers/{$this->supplier}/adjustments", ['amount' => -1000, 'note' => 'x'])->assertForbidden();
        $this->asRole($this->t)->postJson("/api/v1/suppliers/{$this->supplier}/adjustments", ['amount' => -70000, 'note' => 'trop'])->assertStatus(422);
        $this->asRole($this->t)->postJson("/api/v1/suppliers/{$this->supplier}/adjustments", ['amount' => -10000, 'note' => 'remise'])->assertCreated();

        $this->assertSame(50000, $this->debt());
    }

    public function test_roles_sur_fournisseurs_et_achats(): void
    {
        $this->asRole($this->t, 'caissier')->postJson('/api/v1/purchases', [
            'uuid' => (string) Str::uuid(), 'supplier_id' => $this->supplier,
            'items' => [['product_id' => $this->p->id, 'unit' => 'crate', 'quantity' => 1, 'unit_cost' => 1]],
        ])->assertForbidden();

        $this->asRole($this->t, 'vendeur')->getJson('/api/v1/suppliers')->assertForbidden();
        $this->asRole($this->t, 'magasinier')->getJson("/api/v1/suppliers/{$this->supplier}/ledger")->assertForbidden();
        $this->asRole($this->t, 'magasinier')->postJson("/api/v1/suppliers/{$this->supplier}/payments", [
            'uuid' => (string) Str::uuid(), 'amount' => 1, 'method' => 'momo',
        ])->assertForbidden();
    }

    public function test_les_fournisseurs_ne_fuient_pas_entre_depots(): void
    {
        $b = $this->makeTenant('depot-b');

        $this->assertCount(1, $this->asRole($this->t)->getJson('/api/v1/suppliers')->json('data'));
        $this->assertCount(0, $this->asRole($b)->getJson('/api/v1/suppliers')->json('data'));
    }

    private function typeIdOf(): int
    {
        return (int) $this->asRole($this->t)->getJson('/api/v1/containers')->json('0.id');
    }
}
