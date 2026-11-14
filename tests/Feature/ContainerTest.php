<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use Tests\TenantTestCase;

class ContainerTest extends TenantTestCase
{
    private Tenant $t;
    private Product $p;
    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->makeTenant('depot-a');
        $this->p = $this->makeProduct($this->t, [], crates: 30);   // 360 bouteilles
        $this->openCash($this->t);

        $this->typeId = (int) $this->asRole($this->t)->postJson('/api/v1/containers', ['name' => 'Casier 65cl', 'deposit_amount' => 3000])
            ->assertCreated()->json('id');

        $this->asRole($this->t)->putJson("/api/v1/products/{$this->p->id}", ['container_type_id' => $this->typeId])->assertOk();

        // Stock initial de casiers vides au dépôt : 20
        $this->asRole($this->t)->postJson('/api/v1/containers/depot-movements', [
            'container_type_id' => $this->typeId, 'kind' => 'adjustment', 'quantity' => 20, 'note' => 'Stock initial',
        ])->assertCreated();
    }

    private function emptyStock(): int
    {
        return (int) $this->asRole($this->t)->getJson('/api/v1/containers')->json('0.empty_stock');
    }

    private function sell(int $crates, int $returned, ?int $customer = null, string $unit = 'crate')
    {
        $extra = $customer ? ['customer_id' => $customer] : [];
        if ($returned > 0) {
            $extra['empties_returned'] = [['container_type_id' => $this->typeId, 'quantity' => $returned]];
        }

        $items = [['product_id' => $this->p->id, 'unit' => $unit, 'quantity' => $crates]];

        return $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($items, 'cash', $extra));
    }

    public function test_10_casiers_vendus_8_rendus_le_client_en_doit_2(): void
    {
        $customer = $this->makeCustomer($this->t);

        $this->sell(10, 8, $customer)->assertCreated();

        $held = $this->asRole($this->t)->getJson("/api/v1/customers/{$customer}/containers");
        $this->assertCount(1, $held->json());
        $this->assertEquals(2, $held->json('0.quantity'));
        $this->assertEquals(6000, $held->json('0.value'));

        $overview = $this->asRole($this->t)->getJson('/api/v1/containers');
        $this->assertEquals(28, $overview->json('0.empty_stock'));          // 20 + 8 rendus
        $this->assertEquals(2, $overview->json('0.owed_by_customers'));
        $this->assertEquals(6000, $overview->json('0.owed_value'));
    }

    public function test_client_anonyme_doit_equilibrer_l_echange_sinon_tout_est_annule(): void
    {
        $this->sell(3, 1)->assertStatus(422);

        $this->assertSame(360, $this->stockOf($this->t, $this->p->id));   // le stock n'a pas bougé
        $this->assertSame(0, $this->t->run(fn () => Sale::count()));
        $this->assertSame(20, $this->emptyStock());
    }

    public function test_client_anonyme_avec_echange_equilibre(): void
    {
        $this->sell(3, 3)->assertCreated();

        $this->assertSame(23, $this->emptyStock());
        $this->assertSame(360 - 36, $this->stockOf($this->t, $this->p->id));
    }

    public function test_client_anonyme_ne_peut_pas_rendre_plus_que_vendu(): void
    {
        $this->sell(2, 5)->assertStatus(422);
        $this->assertSame(20, $this->emptyStock());
    }

    public function test_retour_de_casiers_apres_la_vente(): void
    {
        $customer = $this->makeCustomer($this->t);
        $this->sell(10, 8, $customer)->assertCreated();

        $this->asRole($this->t, 'magasinier')->postJson("/api/v1/customers/{$customer}/containers/returns", [
            'container_type_id' => $this->typeId, 'quantity' => 2,
        ])->assertCreated()->assertJsonPath('quantity_left', 0);

        $this->assertSame(30, $this->emptyStock());

        // Il ne doit plus rien : un retour de plus est refusé.
        $this->asRole($this->t, 'magasinier')->postJson("/api/v1/customers/{$customer}/containers/returns", [
            'container_type_id' => $this->typeId, 'quantity' => 1,
        ])->assertStatus(422);
    }

    public function test_un_client_ne_peut_pas_rendre_plus_de_casiers_qu_il_n_en_detient_a_la_vente(): void
    {
        $customer = $this->makeCustomer($this->t);

        $this->sell(2, 5, $customer)->assertStatus(422);

        $this->assertCount(0, $this->asRole($this->t)->getJson("/api/v1/customers/{$customer}/containers")->json());
        $this->assertSame(20, $this->emptyStock());
    }

    public function test_vente_a_la_bouteille_n_affecte_pas_les_casiers(): void
    {
        $customer = $this->makeCustomer($this->t);

        $this->sell(5, 0, $customer, 'bottle')->assertCreated();

        $this->assertCount(0, $this->asRole($this->t)->getJson("/api/v1/customers/{$customer}/containers")->json());
        $this->assertSame(20, $this->emptyStock());
    }

    public function test_produit_non_consigne_n_affecte_pas_les_casiers(): void
    {
        $customer = $this->makeCustomer($this->t);
        $can = $this->makeProduct($this->t, ['name' => 'Canette'], crates: 5);   // sans container_type_id

        $items = [['product_id' => $can->id, 'unit' => 'crate', 'quantity' => 2]];
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($items, 'cash', ['customer_id' => $customer]))->assertCreated();

        $this->assertCount(0, $this->asRole($this->t)->getJson("/api/v1/customers/{$customer}/containers")->json());
    }

    public function test_les_vides_du_depot_ne_peuvent_pas_etre_negatifs(): void
    {
        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/containers/depot-movements', [
            'container_type_id' => $this->typeId, 'kind' => 'to_supplier', 'quantity' => 25,
        ])->assertStatus(422);

        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/containers/depot-movements', [
            'container_type_id' => $this->typeId, 'kind' => 'to_supplier', 'quantity' => 15,
        ])->assertCreated()->assertJsonPath('empty_stock', 5);
    }

    public function test_correction_de_casiers_dus_reservee_au_gerant(): void
    {
        $customer = $this->makeCustomer($this->t);
        $this->sell(10, 8, $customer)->assertCreated();   // doit 2

        $payload = ['container_type_id' => $this->typeId, 'quantity' => -2, 'note' => 'casiers perdus, abandon'];

        $this->asRole($this->t, 'caissier')->postJson("/api/v1/customers/{$customer}/containers/adjustments", $payload)->assertForbidden();
        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/containers/adjustments", $payload)->assertCreated()->assertJsonPath('quantity_owed', 0);
    }

    public function test_qui_nous_doit_des_casiers(): void
    {
        $customer = $this->makeCustomer($this->t, 100000, 'Bar Chez Paul');
        $this->sell(10, 8, $customer)->assertCreated();

        $holders = $this->asRole($this->t)->getJson("/api/v1/containers/{$this->typeId}/holders")->assertOk();

        $this->assertCount(1, $holders->json());
        $this->assertSame('Bar Chez Paul', $holders->json('0.name'));
        $this->assertEquals(2, $holders->json('0.quantity'));
    }
}
