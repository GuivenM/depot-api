<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class CreditTest extends TenantTestCase
{
    private Tenant $t;
    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->makeTenant('depot-a');
        $this->p = $this->makeProduct($this->t, [], crates: 10);   // 120 bouteilles, 7 000 FCFA le casier
        $this->openCash($this->t);
    }

    private function crates(int $n): array
    {
        return [['product_id' => $this->p->id, 'unit' => 'crate', 'quantity' => $n]];
    }

    private function balanceOf(int $customerId): int
    {
        return (int) $this->asRole($this->t)->getJson("/api/v1/customers/{$customerId}/ledger")->json('customer.balance');
    }

    public function test_vente_a_credit_avec_acompte_met_a_jour_la_dette_et_le_journal(): void
    {
        $customer = $this->makeCustomer($this->t, 100000);

        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(5), 'credit', [
            'customer_id' => $customer, 'paid_now' => 20000, 'paid_method' => 'cash',
        ]))->assertCreated();   // 35 000 - 20 000 d'acompte

        $ledger = $this->asRole($this->t)->getJson("/api/v1/customers/{$customer}/ledger");

        $this->assertEquals(15000, $ledger->json('customer.balance'));
        $this->assertCount(2, $ledger->json('entries.data'));
        $this->assertSame(60, $this->stockOf($this->t, $this->p->id));   // 120 - 5 x 12
    }

    public function test_limite_de_credit_depassee_refuse_et_annule_tout(): void
    {
        $customer = $this->makeCustomer($this->t, 10000);

        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(5), 'credit', ['customer_id' => $customer]))
            ->assertStatus(422);

        $this->assertSame(0, $this->balanceOf($customer));
        $this->assertSame(120, $this->stockOf($this->t, $this->p->id));
        $this->assertSame(0, $this->t->run(fn () => Sale::count()));
    }

    public function test_seul_le_gerant_peut_depasser_la_limite(): void
    {
        $customer = $this->makeCustomer($this->t, 10000);
        $this->openCash($this->t, 'caissier');

        $body = fn () => $this->saleBody($this->crates(5), 'credit', ['customer_id' => $customer, 'override_limit' => true]);

        $this->asRole($this->t, 'caissier')->postJson('/api/v1/sales', $body())->assertStatus(422);
        $this->asRole($this->t, 'gerant')->postJson('/api/v1/sales', $body())->assertCreated();

        $this->assertSame(35000, $this->balanceOf($customer));
    }

    public function test_vente_a_credit_sans_client_est_refusee(): void
    {
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(1), 'credit'))->assertStatus(422);
    }

    public function test_le_caissier_ne_fixe_pas_la_limite_de_credit(): void
    {
        $res = $this->asRole($this->t, 'caissier')->postJson('/api/v1/customers', [
            'name' => 'Resto X', 'type' => 'restaurant', 'credit_limit' => 999999,
        ])->assertCreated();

        $this->assertEquals(0, $res->json('credit_limit'));
    }

    public function test_remboursement_cash_entre_dans_la_caisse_theorique(): void
    {
        $customer = $this->makeCustomer($this->t, 100000);

        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(5), 'credit', [
            'customer_id' => $customer, 'paid_now' => 20000, 'paid_method' => 'cash',
        ]))->assertCreated();

        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/payments", [
            'uuid' => (string) Str::uuid(), 'amount' => 15000, 'method' => 'cash',
        ])->assertCreated();

        $this->assertSame(0, $this->balanceOf($customer));

        // fond 50 000 + acompte 20 000 + remboursement 15 000 = 85 000 (les 35 000 à crédit n'entrent pas au tiroir)
        $res = $this->asRole($this->t)->postJson('/api/v1/cash/close', ['counted_cash' => 85000])->assertOk();

        $this->assertEquals(85000, $res->json('session.expected_cash'));
        $this->assertEquals(0, $res->json('session.difference'));
        $this->assertEquals(35000, $res->json('summary.credit_sales'));
    }

    public function test_un_remboursement_ne_peut_pas_depasser_la_dette(): void
    {
        $customer = $this->makeCustomer($this->t, 100000);
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(1), 'credit', ['customer_id' => $customer]))->assertCreated();

        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/payments", [
            'uuid' => (string) Str::uuid(), 'amount' => 8000, 'method' => 'cash',
        ])->assertStatus(422);

        $this->assertSame(7000, $this->balanceOf($customer));
    }

    public function test_un_remboursement_rejoue_avec_le_meme_uuid_ne_se_duplique_pas(): void
    {
        $customer = $this->makeCustomer($this->t, 100000);
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(2), 'credit', ['customer_id' => $customer]))->assertCreated();   // 14 000

        $payment = ['uuid' => (string) Str::uuid(), 'amount' => 4000, 'method' => 'momo'];

        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/payments", $payment)->assertCreated();
        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/payments", $payment)->assertOk();

        $this->assertSame(10000, $this->balanceOf($customer));
    }

    public function test_remboursement_cash_sans_caisse_ouverte_est_refuse(): void
    {
        $customer = $this->makeCustomer($this->t, 100000);
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(1), 'credit', ['customer_id' => $customer]))->assertCreated();

        $this->openCash($this->t, 'caissier', 0);   // le caissier a une caisse, le vendeur non
        $this->asRole($this->t, 'vendeur')->postJson("/api/v1/customers/{$customer}/payments", [
            'uuid' => (string) Str::uuid(), 'amount' => 1000, 'method' => 'cash',
        ])->assertStatus(409);
    }

    public function test_abandon_de_creance_reserve_au_gerant_et_jamais_negatif(): void
    {
        $customer = $this->makeCustomer($this->t, 100000);
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(1), 'credit', ['customer_id' => $customer]))->assertCreated();   // 7 000

        $this->asRole($this->t, 'caissier')->postJson("/api/v1/customers/{$customer}/adjustments", ['amount' => -1000, 'note' => 'geste'])->assertForbidden();
        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/adjustments", ['amount' => -9000, 'note' => 'trop'])->assertStatus(422);
        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/adjustments", ['amount' => -7000, 'note' => 'abandon'])->assertCreated();

        $this->assertSame(0, $this->balanceOf($customer));
    }

    public function test_les_creances_listent_les_debiteurs_et_le_depassement(): void
    {
        $customer = $this->makeCustomer($this->t, 10000);
        $this->asRole($this->t)->postJson('/api/v1/sales', $this->saleBody($this->crates(2), 'credit', [
            'customer_id' => $customer, 'override_limit' => true,   // 14 000 > limite 10 000
        ]))->assertCreated();

        $res = $this->asRole($this->t)->getJson('/api/v1/receivables')->assertOk();

        $this->assertEquals(14000, $res->json('total_outstanding'));
        $this->assertTrue($res->json('customers.0.over_limit'));
    }
}
