<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class VoidTest extends TenantTestCase
{
    private Tenant $t;
    private Product $p;   // produit vendu : 30 casiers (360 bouteilles) en stock
    private Product $q;   // produit acheté : stock 0
    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->makeTenant('depot-a');
        $this->p = $this->makeProduct($this->t, ['name' => 'Béninoise 65cl'], crates: 30);
        $this->q = $this->makeProduct($this->t, ['name' => 'Castel 65cl']);
        $this->supplier = (int) $this->asRole($this->t)->postJson('/api/v1/suppliers', ['name' => 'Brasserie'])->assertCreated()->json('id');
        $this->openCash($this->t, 'gerant', 50000);
    }

    // ---------------------------------------------------------------- aides

    private function sell(int $crates = 2, string $method = 'cash', array $extra = [], string $role = 'gerant')
    {
        $items = [['product_id' => $this->p->id, 'unit' => 'crate', 'quantity' => $crates]];

        return $this->asRole($this->t, $role)->postJson('/api/v1/sales', $this->saleBody($items, $method, $extra));
    }

    private function voidSale(int $id, string $role = 'gerant', ?string $reason = 'erreur de saisie')
    {
        return $this->asRole($this->t, $role)->postJson("/api/v1/sales/{$id}/void", $reason === null ? [] : ['reason' => $reason]);
    }

    private function buy(int $crates = 10, array $extra = [], string $role = 'magasinier')
    {
        $items = [['product_id' => $this->q->id, 'unit' => 'crate', 'quantity' => $crates, 'unit_cost' => 6000]];

        return $this->asRole($this->t, $role)->postJson('/api/v1/purchases', array_merge([
            'uuid' => (string) Str::uuid(), 'supplier_id' => $this->supplier, 'items' => $items,
        ], $extra));
    }

    private function voidPurchase(int $id, string $role = 'gerant')
    {
        return $this->asRole($this->t, $role)->postJson("/api/v1/purchases/{$id}/void", ['reason' => 'mauvaise facture']);
    }

    private function supplierDebt(): int
    {
        return (int) $this->asRole($this->t)->getJson("/api/v1/suppliers/{$this->supplier}/ledger")->json('supplier.balance');
    }

    private function customerDebt(int $customer): int
    {
        return (int) $this->asRole($this->t)->getJson("/api/v1/customers/{$customer}/ledger")->json('customer.balance');
    }

    private function emptyStock(): int
    {
        return (int) $this->asRole($this->t)->getJson('/api/v1/containers')->json('0.empty_stock');
    }

    private function closeCash(int $counted)
    {
        return $this->asRole($this->t)->postJson('/api/v1/cash/close', ['counted_cash' => $counted])->assertOk();
    }

    /** Crée un type de casier avec 20 vides au dépôt et le lie au produit donné. */
    private function containersFor(Product $product): int
    {
        $type = (int) $this->asRole($this->t)->postJson('/api/v1/containers', ['name' => 'Casier 65cl', 'deposit_amount' => 3000])
            ->assertCreated()->json('id');
        $this->asRole($this->t)->putJson("/api/v1/products/{$product->id}", ['container_type_id' => $type])->assertOk();
        $this->asRole($this->t)->postJson('/api/v1/containers/depot-movements', [
            'container_type_id' => $type, 'kind' => 'adjustment', 'quantity' => 20, 'note' => 'Stock initial',
        ])->assertCreated();

        return $type;
    }

    // ---------------------------------------------------------------- annulation de vente

    public function test_annuler_une_vente_cash_restitue_le_stock_et_la_sort_de_la_caisse(): void
    {
        $id = $this->sell(2)->assertCreated()->json('id');
        $this->assertSame(336, $this->stockOf($this->t, $this->p->id));

        $this->voidSale($id)->assertOk()->assertJsonPath('status', 'voided');

        $this->assertSame(360, $this->stockOf($this->t, $this->p->id));

        $res = $this->closeCash(50000);   // la vente annulée n'entre plus dans la caisse théorique
        $this->assertEquals(50000, $res->json('session.expected_cash'));
        $this->assertEquals(0, $res->json('session.difference'));
    }

    public function test_une_vente_ne_s_annule_qu_une_fois(): void
    {
        $id = $this->sell(2)->assertCreated()->json('id');

        $this->voidSale($id)->assertOk();
        $this->voidSale($id)->assertStatus(409);

        $this->assertSame(360, $this->stockOf($this->t, $this->p->id));   // restitué une seule fois
    }

    public function test_la_raison_est_obligatoire(): void
    {
        $id = $this->sell(1)->assertCreated()->json('id');

        $this->voidSale($id, 'gerant', null)->assertStatus(422);
        $this->assertSame(348, $this->stockOf($this->t, $this->p->id));
    }

    public function test_un_caissier_annule_ses_ventes_mais_pas_celles_des_autres(): void
    {
        $this->openCash($this->t, 'caissier', 0);

        $mine = $this->sell(1, 'cash', [], 'caissier')->assertCreated()->json('id');
        $theirs = $this->sell(1, 'cash', [], 'gerant')->assertCreated()->json('id');

        $this->voidSale($theirs, 'caissier')->assertForbidden();
        $this->voidSale($mine, 'caissier')->assertOk();

        $this->assertSame(348, $this->stockOf($this->t, $this->p->id));   // seule la vente du gérant reste
    }

    public function test_le_magasinier_ne_peut_pas_annuler_de_vente(): void
    {
        $id = $this->sell(1)->assertCreated()->json('id');

        $this->voidSale($id, 'magasinier')->assertForbidden();
    }

    public function test_pas_d_annulation_apres_la_cloture_de_la_caisse(): void
    {
        $id = $this->sell(2)->assertCreated()->json('id');
        $this->closeCash(64000);

        $this->voidSale($id)->assertStatus(409);

        $this->assertSame(336, $this->stockOf($this->t, $this->p->id));
        $this->assertSame('completed', $this->t->run(fn () => Sale::findOrFail($id)->status));
    }

    public function test_annuler_une_vente_a_credit_annule_la_dette_et_l_acompte(): void
    {
        $customer = $this->makeCustomer($this->t, 100000);
        $id = $this->sell(5, 'credit', ['customer_id' => $customer, 'paid_now' => 20000, 'paid_method' => 'cash'])
            ->assertCreated()->json('id');

        $this->assertSame(15000, $this->customerDebt($customer));

        $this->voidSale($id)->assertOk();

        $this->assertSame(0, $this->customerDebt($customer));
        $this->assertSame(360, $this->stockOf($this->t, $this->p->id));
        $this->assertCount(4, $this->asRole($this->t)->getJson("/api/v1/customers/{$customer}/ledger")->json('entries.data'));

        // L'acompte est rendu au client : il ne compte plus dans la caisse.
        $res = $this->closeCash(50000);
        $this->assertEquals(50000, $res->json('session.expected_cash'));
        $this->assertEquals(0, $res->json('summary.credit_sales'));
    }

    public function test_annulation_refusee_si_le_client_a_deja_rembourse_plus_que_le_reste(): void
    {
        $customer = $this->makeCustomer($this->t, 100000);
        $id = $this->sell(5, 'credit', ['customer_id' => $customer])->assertCreated()->json('id');   // dette 35 000

        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/payments", [
            'uuid' => (string) Str::uuid(), 'amount' => 35000, 'method' => 'cash',
        ])->assertCreated();

        // Annuler la vente ferait passer sa dette à -35 000 : refusé, et rien ne bouge.
        $this->voidSale($id)->assertStatus(422);

        $this->assertSame(300, $this->stockOf($this->t, $this->p->id));
        $this->assertSame('completed', $this->t->run(fn () => Sale::findOrFail($id)->status));
        $this->assertSame(0, $this->customerDebt($customer));
    }

    public function test_annuler_une_vente_restitue_les_casiers(): void
    {
        $this->containersFor($this->p);
        $customer = $this->makeCustomer($this->t);
        $id = $this->sell(10, 'cash', [
            'customer_id' => $customer,
            'empties_returned' => [['container_type_id' => $this->typeId(), 'quantity' => 8]],
        ])->assertCreated()->json('id');

        $this->assertEquals(28, $this->emptyStock());
        $this->assertCount(1, $this->asRole($this->t)->getJson("/api/v1/customers/{$customer}/containers")->json());

        $this->voidSale($id)->assertOk();

        $this->assertEquals(20, $this->emptyStock());
        $this->assertCount(0, $this->asRole($this->t)->getJson("/api/v1/customers/{$customer}/containers")->json());
        $this->assertSame(360, $this->stockOf($this->t, $this->p->id));
    }

    public function test_annulation_refusee_si_le_client_a_deja_rendu_les_casiers_dus(): void
    {
        $type = $this->containersFor($this->p);
        $customer = $this->makeCustomer($this->t);
        $id = $this->sell(10, 'cash', [
            'customer_id' => $customer,
            'empties_returned' => [['container_type_id' => $type, 'quantity' => 8]],
        ])->assertCreated()->json('id');   // il doit 2

        $this->asRole($this->t, 'magasinier')->postJson("/api/v1/customers/{$customer}/containers/returns", [
            'container_type_id' => $type, 'quantity' => 2,
        ])->assertCreated();

        $this->voidSale($id)->assertStatus(422);

        $this->assertSame(240, $this->stockOf($this->t, $this->p->id));   // le stock n'a pas été restitué
        $this->assertEquals(30, $this->emptyStock());
    }

    // ---------------------------------------------------------------- annulation d'achat

    public function test_annuler_un_achat_retire_le_stock_et_annule_la_dette(): void
    {
        $id = $this->buy(10)->assertCreated()->json('id');
        $this->assertSame(120, $this->stockOf($this->t, $this->q->id));
        $this->assertSame(60000, $this->supplierDebt());

        $this->voidPurchase($id)->assertOk()->assertJsonPath('status', 'voided');

        $this->assertSame(0, $this->stockOf($this->t, $this->q->id));
        $this->assertSame(0, $this->supplierDebt());
        $this->voidPurchase($id)->assertStatus(409);
    }

    public function test_annulation_d_achat_refusee_si_la_marchandise_est_deja_vendue(): void
    {
        $id = $this->buy(10)->assertCreated()->json('id');

        $items = [['product_id' => $this->q->id, 'unit' => 'crate', 'quantity' => 5]];
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($items))->assertCreated();   // reste 60 bouteilles

        $this->voidPurchase($id)->assertStatus(422);

        $this->assertSame(60, $this->stockOf($this->t, $this->q->id));
        $this->assertSame(60000, $this->supplierDebt());
    }

    public function test_annuler_un_achat_paye_en_cash_remet_l_argent_en_caisse(): void
    {
        $id = $this->buy(10, ['paid_now' => 20000, 'paid_method' => 'cash'], 'gerant')->assertCreated()->json('id');
        $this->assertSame(40000, $this->supplierDebt());

        $this->voidPurchase($id)->assertOk();

        $this->assertSame(0, $this->supplierDebt());

        $res = $this->closeCash(50000);   // le règlement annulé ne sort plus du tiroir
        $this->assertEquals(50000, $res->json('session.expected_cash'));
    }

    public function test_pas_d_annulation_d_achat_dont_le_reglement_cash_est_dans_une_caisse_cloturee(): void
    {
        $id = $this->buy(10, ['paid_now' => 20000, 'paid_method' => 'cash'], 'gerant')->assertCreated()->json('id');
        $this->closeCash(30000);

        $this->voidPurchase($id)->assertStatus(409);

        $this->assertSame(120, $this->stockOf($this->t, $this->q->id));
        $this->assertSame(40000, $this->supplierDebt());
    }

    public function test_seul_le_gerant_annule_un_achat(): void
    {
        $id = $this->buy(10)->assertCreated()->json('id');

        $this->voidPurchase($id, 'magasinier')->assertForbidden();
        $this->voidPurchase($id, 'caissier')->assertForbidden();
    }

    public function test_annulation_d_achat_refusee_si_on_a_paye_au_dela_du_reste_dû(): void
    {
        $id = $this->buy(10)->assertCreated()->json('id');   // dette 60 000
        $this->asRole($this->t)->postJson("/api/v1/suppliers/{$this->supplier}/payments", [
            'uuid' => (string) Str::uuid(), 'amount' => 60000, 'method' => 'bank',
        ])->assertCreated();

        $this->voidPurchase($id)->assertStatus(422);

        $this->assertSame(120, $this->stockOf($this->t, $this->q->id));
    }

    public function test_annuler_un_achat_rend_les_casiers(): void
    {
        $type = $this->containersFor($this->q);

        $id = $this->buy(10, ['empties_returned' => [['container_type_id' => $type, 'quantity' => 8]]])->assertCreated()->json('id');

        $this->assertEquals(12, $this->emptyStock());
        $this->assertEquals(2, $this->asRole($this->t)->getJson("/api/v1/suppliers/{$this->supplier}/containers")->json('0.quantity'));

        $this->voidPurchase($id)->assertOk();

        $this->assertEquals(20, $this->emptyStock());
        $this->assertCount(0, $this->asRole($this->t)->getJson("/api/v1/suppliers/{$this->supplier}/containers")->json());
        $this->assertSame(0, $this->stockOf($this->t, $this->q->id));
    }

    private function typeId(): int
    {
        return (int) $this->asRole($this->t)->getJson('/api/v1/containers')->json('0.id');
    }
}
