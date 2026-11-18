<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use Tests\TenantTestCase;

class InventoryTest extends TenantTestCase
{
    private Tenant $t;
    private Product $p;   // Béninoise : 10 casiers (120 bouteilles), coût 5 000 / casier
    private Product $r;   // Castel : 5 casiers (60 bouteilles)
    private int $type;    // casier 65cl : 20 vides au dépôt

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->makeTenant('depot-a');
        $this->p = $this->makeProduct($this->t, ['name' => 'Béninoise 65cl', 'cost_crate' => 5000], crates: 10);
        $this->r = $this->makeProduct($this->t, ['name' => 'Castel 65cl', 'cost_crate' => 6000], crates: 5);

        $this->type = (int) $this->asRole($this->t)->postJson('/api/v1/containers', ['name' => 'Casier 65cl', 'deposit_amount' => 3000])
            ->assertCreated()->json('id');
        $this->asRole($this->t)->postJson('/api/v1/containers/depot-movements', [
            'container_type_id' => $this->type, 'kind' => 'adjustment', 'quantity' => 20, 'note' => 'Stock initial',
        ])->assertCreated();
    }

    // ---------------------------------------------------------------- aides

    private function start(string $role = 'magasinier')
    {
        return $this->asRole($this->t, $role)->postJson('/api/v1/inventories', ['note' => 'Inventaire de fin de mois']);
    }

    private function show(int $id, string $role = 'gerant')
    {
        return $this->asRole($this->t, $role)->getJson("/api/v1/inventories/{$id}");
    }

    private function lineId(int $inventoryId, string $name): int
    {
        return (int) collect($this->show($inventoryId)->json('lines'))->firstWhere('name', $name)['id'];
    }

    private function submitCounts(int $inventoryId, array $counts, string $role = 'magasinier')
    {
        return $this->asRole($this->t, $role)->postJson("/api/v1/inventories/{$inventoryId}/counts", ['counts' => $counts]);
    }

    private function validateInv(int $id, string $role = 'gerant')
    {
        return $this->asRole($this->t, $role)->postJson("/api/v1/inventories/{$id}/validate");
    }

    private function emptyStock(): int
    {
        return (int) $this->asRole($this->t)->getJson('/api/v1/containers')->json('0.empty_stock');
    }

    // ---------------------------------------------------------------- ouverture et comptage

    public function test_un_inventaire_liste_les_produits_actifs_et_les_types_de_casiers(): void
    {
        $this->makeProduct($this->t, ['name' => 'Désactivé', 'active' => false]);

        $id = $this->start()->assertCreated()->json('id');
        $show = $this->show($id);

        $names = collect($show->json('lines'))->pluck('name')->all();
        sort($names);

        $this->assertSame(['Béninoise 65cl', 'Casier 65cl', 'Castel 65cl'], $names);
        $this->assertEquals(3, $show->json('progress.total'));
        $this->assertEquals(0, $show->json('progress.counted'));
        $this->assertSame('open', $show->json('status'));
    }

    public function test_un_seul_inventaire_ouvert_a_la_fois(): void
    {
        $this->start()->assertCreated();
        $this->start()->assertStatus(409);
    }

    public function test_comptage_produit_en_casiers_et_bouteilles_et_comptage_de_casiers(): void
    {
        $id = $this->start()->json('id');

        $this->submitCounts($id, [
            ['line_id' => $this->lineId($id, 'Béninoise 65cl'), 'crates' => 9, 'bottles' => 6],   // 114 pour 120 attendues
            ['line_id' => $this->lineId($id, 'Casier 65cl'), 'quantity' => 18],                   // 18 pour 20 attendus
        ])->assertOk();

        $lines = collect($this->show($id)->json('lines'))->keyBy('name');

        $this->assertEquals(120, $lines['Béninoise 65cl']['expected']);
        $this->assertEquals(114, $lines['Béninoise 65cl']['counted']);
        $this->assertEquals(-6, $lines['Béninoise 65cl']['difference']);
        $this->assertEquals(-2, $lines['Casier 65cl']['difference']);
        $this->assertNull($lines['Castel 65cl']['counted']);
        $this->assertEquals(2, $this->show($id)->json('progress.counted'));
    }

    public function test_recompter_une_ligne_remplace_le_comptage_et_reactualise_l_attendu(): void
    {
        $id = $this->start()->json('id');
        $line = $this->lineId($id, 'Béninoise 65cl');

        $this->submitCounts($id, [['line_id' => $line, 'crates' => 9]])->assertOk();   // 108 pour 120

        // une vente de 1 casier, puis un nouveau comptage
        $this->openCash($this->t);
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody([['product_id' => $this->p->id, 'unit' => 'crate', 'quantity' => 1]]))->assertCreated();

        $this->submitCounts($id, [['line_id' => $line, 'crates' => 10]])->assertOk();   // 120 pour 108 attendues

        $l = collect($this->show($id)->json('lines'))->firstWhere('name', 'Béninoise 65cl');
        $this->assertEquals(108, $l['expected']);
        $this->assertEquals(120, $l['counted']);
        $this->assertEquals(12, $l['difference']);
    }

    public function test_comptages_invalides_sont_refuses(): void
    {
        $id = $this->start()->json('id');
        $product = $this->lineId($id, 'Béninoise 65cl');
        $container = $this->lineId($id, 'Casier 65cl');

        $this->submitCounts($id, [['line_id' => 999999, 'crates' => 1]])->assertStatus(422);
        $this->submitCounts($id, [['line_id' => $product]])->assertStatus(422);                       // ni crates ni bottles
        $this->submitCounts($id, [['line_id' => $container, 'crates' => 3]])->assertStatus(422);     // il faut quantity
        $this->submitCounts($id, [['line_id' => $product, 'crates' => -1]])->assertStatus(422);
    }

    // ---------------------------------------------------------------- validation

    public function test_validation_applique_les_ecarts_au_stock_et_aux_casiers(): void
    {
        $id = $this->start()->json('id');

        $this->submitCounts($id, [
            ['line_id' => $this->lineId($id, 'Béninoise 65cl'), 'crates' => 9, 'bottles' => 6],   // -6 bouteilles
            ['line_id' => $this->lineId($id, 'Casier 65cl'), 'quantity' => 18],                   // -2 casiers
            ['line_id' => $this->lineId($id, 'Castel 65cl'), 'crates' => 6],                      // +12 bouteilles (72 pour 60)
        ])->assertOk();

        $res = $this->validateInv($id)->assertOk();

        $this->assertSame('validated', $res->json('status'));
        $this->assertSame(114, $this->stockOf($this->t, $this->p->id));
        $this->assertSame(72, $this->stockOf($this->t, $this->r->id));
        $this->assertEquals(18, $this->emptyStock());
        $this->assertEquals(3, $res->json('lines_with_gap'));
        $this->assertEquals(2500, $res->json('shortage_value'));    // 6 bouteilles x 5 000 / 12
        $this->assertEquals(6000, $res->json('surplus_value'));     // 12 bouteilles x 6 000 / 12

        // L'écart est tracé dans le journal du stock.
        $this->assertEquals(-6, $this->t->run(fn () => StockMovement::where('product_id', $this->p->id)->where('type', 'inventaire')->sum('quantity')));
    }

    public function test_les_lignes_non_comptees_sont_ignorees(): void
    {
        $id = $this->start()->json('id');
        $this->submitCounts($id, [['line_id' => $this->lineId($id, 'Béninoise 65cl'), 'crates' => 9]])->assertOk();   // 108 pour 120

        $this->validateInv($id)->assertOk();

        $this->assertSame(108, $this->stockOf($this->t, $this->p->id));
        $this->assertSame(60, $this->stockOf($this->t, $this->r->id));
        $this->assertEquals(20, $this->emptyStock());
    }

    public function test_valider_sans_rien_compter_est_refuse(): void
    {
        $id = $this->start()->json('id');

        $this->validateInv($id)->assertStatus(422);
    }

    public function test_l_ecart_est_relatif_les_ventes_faites_apres_le_comptage_restent_valables(): void
    {
        $id = $this->start()->json('id');
        $this->submitCounts($id, [['line_id' => $this->lineId($id, 'Béninoise 65cl'), 'crates' => 9, 'bottles' => 6]])->assertOk();   // 114 pour 120 : -6

        $this->openCash($this->t);
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody([['product_id' => $this->p->id, 'unit' => 'crate', 'quantity' => 2]]))->assertCreated();   // 120 -> 96

        $this->validateInv($id)->assertOk();

        $this->assertSame(90, $this->stockOf($this->t, $this->p->id));   // 96 - 6, et non 114
    }

    public function test_validation_impossible_si_le_stock_deviendrait_negatif_et_tout_est_annule(): void
    {
        $tiny = $this->makeProduct($this->t, ['name' => 'Rare'], crates: 1);   // 12 bouteilles

        $id = $this->start()->json('id');
        $this->submitCounts($id, [
            ['line_id' => $this->lineId($id, 'Rare'), 'crates' => 0],                              // attendu 12, compté 0 : -12
            ['line_id' => $this->lineId($id, 'Béninoise 65cl'), 'crates' => 9],                    // -12 aussi
        ])->assertOk();

        $this->openCash($this->t);
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody([['product_id' => $tiny->id, 'unit' => 'crate', 'quantity' => 1]]))->assertCreated();   // Rare : 0

        $this->validateInv($id)->assertStatus(422);

        $this->assertSame(0, $this->stockOf($this->t, $tiny->id));
        $this->assertSame(120, $this->stockOf($this->t, $this->p->id));   // l'autre ligne n'a pas été appliquée
        $this->assertSame('open', $this->show($id)->json('status'));
    }

    public function test_validation_refusee_si_les_vides_deviendraient_negatifs(): void
    {
        $id = $this->start()->json('id');
        $this->submitCounts($id, [['line_id' => $this->lineId($id, 'Casier 65cl'), 'quantity' => 5]])->assertOk();   // 20 -> 5 : -15

        // entre-temps, 18 vides repartent chez le fournisseur : il n'en reste que 2
        $this->asRole($this->t)->postJson('/api/v1/containers/depot-movements', [
            'container_type_id' => $this->type, 'kind' => 'to_supplier', 'quantity' => 18,
        ])->assertCreated();

        $this->validateInv($id)->assertStatus(422);

        $this->assertEquals(2, $this->emptyStock());
        $this->assertSame('open', $this->show($id)->json('status'));
    }

    // ---------------------------------------------------------------- annulation, cycle de vie, droits

    public function test_un_inventaire_valide_ou_annule_ne_peut_plus_changer(): void
    {
        $id = $this->start()->json('id');
        $line = $this->lineId($id, 'Béninoise 65cl');
        $this->submitCounts($id, [['line_id' => $line, 'crates' => 9]])->assertOk();
        $this->validateInv($id)->assertOk();

        $this->submitCounts($id, [['line_id' => $line, 'crates' => 5]])->assertStatus(409);
        $this->validateInv($id)->assertStatus(409);

        $second = $this->start()->assertCreated()->json('id');   // plus d'inventaire ouvert : on peut en rouvrir un
        $this->asRole($this->t, 'magasinier')->postJson("/api/v1/inventories/{$second}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->submitCounts($second, [['line_id' => $this->lineId($second, 'Castel 65cl'), 'crates' => 1]])->assertStatus(409);
        $this->assertSame(60, $this->stockOf($this->t, $this->r->id));
    }

    public function test_le_magasinier_compte_mais_seul_le_gerant_valide(): void
    {
        $id = $this->start('magasinier')->assertCreated()->json('id');
        $this->submitCounts($id, [['line_id' => $this->lineId($id, 'Béninoise 65cl'), 'crates' => 9]], 'magasinier')->assertOk();

        $this->validateInv($id, 'magasinier')->assertForbidden();
        $this->assertSame(120, $this->stockOf($this->t, $this->p->id));

        $this->validateInv($id, 'gerant')->assertOk();
    }

    public function test_caissier_et_vendeur_n_ont_pas_acces_aux_inventaires(): void
    {
        $id = $this->start()->json('id');

        foreach (['caissier', 'vendeur'] as $role) {
            $this->asRole($this->t, $role)->getJson('/api/v1/inventories')->assertForbidden();
            $this->asRole($this->t, $role)->postJson('/api/v1/inventories', [])->assertForbidden();
            $this->submitCounts($id, [['line_id' => 1, 'crates' => 1]], $role)->assertForbidden();
        }
    }

    public function test_le_dernier_inventaire_apparait_dans_le_tableau_de_bord(): void
    {
        $this->assertNull($this->asRole($this->t)->getJson('/api/v1/reports/overview')->json('snapshot.last_inventory'));

        $id = $this->start()->json('id');
        $this->submitCounts($id, [['line_id' => $this->lineId($id, 'Béninoise 65cl'), 'crates' => 9, 'bottles' => 6]])->assertOk();
        $this->validateInv($id)->assertOk();

        $res = $this->asRole($this->t)->getJson('/api/v1/reports/overview')->assertOk();

        $this->assertEquals($id, $res->json('snapshot.last_inventory.id'));
        $this->assertEquals(2500, $res->json('snapshot.last_inventory.shortage_value'));
    }

    public function test_les_inventaires_ne_fuient_pas_entre_depots(): void
    {
        $b = $this->makeTenant('depot-b');
        $this->start()->assertCreated();

        $this->assertCount(1, $this->asRole($this->t)->getJson('/api/v1/inventories')->json('data'));
        $this->assertCount(0, $this->asRole($b)->getJson('/api/v1/inventories')->json('data'));
    }
}
