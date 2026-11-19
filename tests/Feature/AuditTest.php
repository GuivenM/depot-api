<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use LogicException;
use Tests\TenantTestCase;

class AuditTest extends TenantTestCase
{
    private Tenant $t;
    private Product $p;   // 30 casiers (360 bouteilles) en stock, 7 000 / casier

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->makeTenant('depot-a');
        $this->p = $this->makeProduct($this->t, ['name' => 'Béninoise 65cl'], crates: 30);
        $this->openCash($this->t, 'gerant', 50000);
    }

    // ---------------------------------------------------------------- aides

    /** Entrées du journal, les plus récentes d'abord. */
    private function logs(array $query = []): array
    {
        return $this->asRole($this->t)->getJson('/api/v1/audit-logs?'.http_build_query($query))->assertOk()->json('data');
    }

    private function one(string $action): array
    {
        $found = $this->logs(['action' => $action]);
        $this->assertCount(1, $found, "Une entrée « {$action} » est attendue.");

        return $found[0];
    }

    private function sell(int $crates = 2, string $method = 'cash', array $extra = [], string $role = 'gerant')
    {
        $items = [['product_id' => $this->p->id, 'unit' => 'crate', 'quantity' => $crates]];

        return $this->asRole($this->t, $role)->postJson('/api/v1/sales', $this->saleBody($items, $method, $extra));
    }

    private function supplier(): int
    {
        return (int) $this->asRole($this->t)->postJson('/api/v1/suppliers', ['name' => 'Brasserie'])->assertCreated()->json('id');
    }

    private function buy(int $supplier)
    {
        return $this->asRole($this->t, 'magasinier')->postJson('/api/v1/purchases', [
            'uuid' => (string) Str::uuid(), 'supplier_id' => $supplier,
            'items' => [['product_id' => $this->p->id, 'unit' => 'crate', 'quantity' => 10, 'unit_cost' => 6000]],
        ]);
    }

    // ---------------------------------------------------------------- prix, limites, configuration

    public function test_creation_et_changement_de_prix_d_un_produit_sont_traces(): void
    {
        $id = $this->asRole($this->t, 'magasinier')->postJson('/api/v1/products', [
            'name' => 'Castel 65cl', 'price_crate' => 8000, 'bottles_per_crate' => 12,
        ])->assertCreated()->json('id');

        $created = $this->one('product.created');
        $this->assertSame('product', $created['subject_type']);
        $this->assertSame($id, $created['subject_id']);
        $this->assertEquals(8000, $created['details']['values']['price_crate']);
        $this->assertSame('Magasinier', $created['user']['name']);

        $this->asRole($this->t, 'magasinier')->putJson("/api/v1/products/{$id}", ['price_crate' => 8500, 'cost_crate' => 6500])->assertOk();

        $updated = $this->one('product.updated');
        $this->assertEquals(8000, $updated['details']['price_crate']['from']);
        $this->assertEquals(8500, $updated['details']['price_crate']['to']);
        $this->assertEquals(6500, $updated['details']['cost_crate']['to']);
        $this->assertArrayNotHasKey('name', $updated['details']);   // inchangé : pas tracé
    }

    public function test_une_modification_sans_changement_ne_laisse_aucune_trace(): void
    {
        $id = $this->asRole($this->t)->postJson('/api/v1/products', ['name' => 'X', 'price_crate' => 5000])->json('id');

        $this->asRole($this->t)->putJson("/api/v1/products/{$id}", ['price_crate' => 5000])->assertOk();

        $this->assertCount(0, $this->logs(['action' => 'product.updated']));
    }

    public function test_limite_de_credit_tracee_et_changements_du_caissier_ignores(): void
    {
        $id = $this->makeCustomer($this->t, 100000);
        $this->assertEquals(100000, $this->one('customer.created')['details']['values']['credit_limit']);

        $this->asRole($this->t)->putJson("/api/v1/customers/{$id}", ['credit_limit' => 250000])->assertOk();

        $log = $this->one('customer.updated');
        $this->assertEquals(100000, $log['details']['credit_limit']['from']);
        $this->assertEquals(250000, $log['details']['credit_limit']['to']);

        // Un caissier ne peut pas toucher à la limite : rien d'audité (le nom n'est pas un champ surveillé).
        $this->asRole($this->t, 'caissier')->putJson("/api/v1/customers/{$id}", ['name' => 'Nouveau nom', 'credit_limit' => 1])->assertOk();
        $this->assertCount(1, $this->logs(['action' => 'customer.updated']));
    }

    public function test_type_de_casier_et_fournisseur(): void
    {
        $type = (int) $this->asRole($this->t)->postJson('/api/v1/containers', ['name' => 'Casier 65cl', 'deposit_amount' => 3000])->json('id');
        $this->asRole($this->t)->putJson("/api/v1/containers/{$type}", ['deposit_amount' => 3500])->assertOk();
        $this->assertEquals(3500, $this->one('container_type.updated')['details']['deposit_amount']['to']);

        $supplier = $this->supplier();
        $this->asRole($this->t)->putJson("/api/v1/suppliers/{$supplier}", ['active' => false])->assertOk();
        $this->assertEquals(0, $this->one('supplier.updated')['details']['active']['to']);
    }

    // ---------------------------------------------------------------- dettes, stock, casiers

    public function test_abandon_de_creance_trace_avec_le_solde_avant_apres(): void
    {
        $customer = $this->makeCustomer($this->t, 100000);
        $this->sell(2, 'credit', ['customer_id' => $customer])->assertCreated();   // dette 14 000

        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/adjustments", ['amount' => -4000, 'note' => 'abandon accordé'])->assertCreated();

        $log = $this->one('customer.debt_adjusted');
        $this->assertSame($customer, $log['subject_id']);
        $this->assertEquals(14000, $log['details']['balance_before']);
        $this->assertEquals(10000, $log['details']['balance_after']);
        $this->assertSame('abandon accordé', $log['note']);
    }

    public function test_un_ajustement_refuse_ne_laisse_aucune_trace(): void
    {
        $customer = $this->makeCustomer($this->t);

        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/adjustments", ['amount' => -500, 'note' => 'x'])->assertStatus(422);

        $this->assertCount(0, $this->logs(['action' => 'customer.debt_adjusted']));
    }

    public function test_dette_fournisseur_ajustee(): void
    {
        $supplier = $this->supplier();
        $this->buy($supplier)->assertCreated();   // dette 60 000

        $this->asRole($this->t)->postJson("/api/v1/suppliers/{$supplier}/adjustments", ['amount' => -1000, 'note' => 'remise'])->assertCreated();

        $log = $this->one('supplier.debt_adjusted');
        $this->assertEquals(60000, $log['details']['balance_before']);
        $this->assertEquals(59000, $log['details']['balance_after']);
    }

    public function test_casse_et_ajustement_de_stock_traces_mais_pas_les_receptions(): void
    {
        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/stock/adjustments', ['product_id' => $this->p->id, 'type' => 'casse', 'bottles' => 3])->assertCreated();
        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/stock/adjustments', ['product_id' => $this->p->id, 'type' => 'ajustement', 'bottles' => 5, 'note' => 'retrouvées'])->assertCreated();
        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/stock/receipts', ['product_id' => $this->p->id, 'crates' => 1])->assertCreated();

        $this->assertEquals(-3, $this->one('stock.breakage')['details']['bottles']);

        $adj = $this->one('stock.adjusted');
        $this->assertEquals(5, $adj['details']['bottles']);
        $this->assertEquals(362, $adj['details']['stock_after']);   // 360 - 3 + 5
        $this->assertSame('retrouvées', $adj['note']);
        $this->assertCount(2, $this->logs(['subject_type' => 'product', 'subject_id' => $this->p->id]));
    }

    public function test_casiers_dus_et_vides_du_depot(): void
    {
        $type = (int) $this->asRole($this->t)->postJson('/api/v1/containers', ['name' => 'Casier 65cl', 'deposit_amount' => 3000])->json('id');
        $this->asRole($this->t)->postJson('/api/v1/containers/depot-movements', ['container_type_id' => $type, 'kind' => 'adjustment', 'quantity' => 20, 'note' => 'Stock initial'])->assertCreated();
        $this->asRole($this->t)->postJson('/api/v1/containers/depot-movements', ['container_type_id' => $type, 'kind' => 'loss', 'quantity' => 2, 'note' => 'cassés'])->assertCreated();
        $this->asRole($this->t)->postJson('/api/v1/containers/depot-movements', ['container_type_id' => $type, 'kind' => 'from_supplier', 'quantity' => 5])->assertCreated();   // pas tracé

        $adj = $this->one('container.depot_adjusted');
        $this->assertEquals(0, $adj['details']['empty_stock_before']);
        $this->assertEquals(20, $adj['details']['empty_stock_after']);

        $loss = $this->one('container.depot_loss');
        $this->assertEquals(-2, $loss['details']['delta']);
        $this->assertEquals(18, $loss['details']['empty_stock_after']);

        // Abandon de casiers dus par un client : le client doit d'abord en détenir.
        $customer = $this->makeCustomer($this->t);
        $this->asRole($this->t)->putJson("/api/v1/products/{$this->p->id}", ['container_type_id' => $type])->assertOk();
        $this->sell(3, 'credit', ['customer_id' => $customer])->assertCreated();   // doit 3 casiers

        $this->asRole($this->t)->postJson("/api/v1/customers/{$customer}/containers/adjustments", [
            'container_type_id' => $type, 'quantity' => -1, 'note' => 'abandon',
        ])->assertCreated();

        $log = $this->one('container.holding_adjusted');
        $this->assertEquals(3, $log['details']['quantity_before']);
        $this->assertEquals(2, $log['details']['quantity_after']);
    }

    // ---------------------------------------------------------------- ventes, achats, inventaire, caisse

    public function test_depassement_de_limite_trace_seulement_quand_il_a_lieu(): void
    {
        $customer = $this->makeCustomer($this->t, 20000);

        $this->sell(2, 'credit', ['customer_id' => $customer])->assertCreated();   // 14 000 : dans la limite
        $this->assertCount(0, $this->logs(['action' => 'sale.limit_overridden']));

        $this->sell(2, 'credit', ['customer_id' => $customer])->assertStatus(422);   // 28 000 > 20 000, sans dérogation
        $this->assertCount(0, $this->logs(['action' => 'sale.limit_overridden']));

        $saleId = $this->sell(2, 'credit', ['customer_id' => $customer, 'override_limit' => true])->assertCreated()->json('id');

        $log = $this->one('sale.limit_overridden');
        $this->assertSame('sale', $log['subject_type']);
        $this->assertSame($saleId, $log['subject_id']);
        $this->assertEquals(14000, $log['details']['balance_before']);
        $this->assertEquals(14000, $log['details']['new_debt']);
        $this->assertEquals(20000, $log['details']['credit_limit']);
    }

    public function test_annulation_de_vente_et_d_achat_tracees_avec_la_raison(): void
    {
        $saleId = $this->sell(2)->assertCreated()->json('id');
        $this->asRole($this->t)->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'erreur de saisie'])->assertOk();

        $sale = $this->one('sale.voided');
        $this->assertSame($saleId, $sale['subject_id']);
        $this->assertSame('erreur de saisie', $sale['note']);
        $this->assertEquals(14000, $sale['details']['total']);

        $purchaseId = $this->buy($this->supplier())->assertCreated()->json('id');
        $this->asRole($this->t)->postJson("/api/v1/purchases/{$purchaseId}/void", ['reason' => 'mauvaise facture'])->assertOk();

        $purchase = $this->one('purchase.voided');
        $this->assertSame($purchaseId, $purchase['subject_id']);
        $this->assertSame('mauvaise facture', $purchase['note']);
    }

    public function test_validation_d_inventaire_tracee(): void
    {
        $id = $this->asRole($this->t, 'magasinier')->postJson('/api/v1/inventories', [])->assertCreated()->json('id');
        $lineId = (int) collect($this->asRole($this->t)->getJson("/api/v1/inventories/{$id}")->json('lines'))->firstWhere('name', 'Béninoise 65cl')['id'];

        $this->asRole($this->t, 'magasinier')->postJson("/api/v1/inventories/{$id}/counts", ['counts' => [['line_id' => $lineId, 'crates' => 29]]])->assertOk();
        $this->asRole($this->t)->postJson("/api/v1/inventories/{$id}/validate")->assertOk();

        $log = $this->one('inventory.validated');
        $this->assertSame($id, $log['subject_id']);
        $this->assertEquals(1, $log['details']['lines_with_gap']);
    }

    public function test_cloture_de_caisse_tracee_si_ecart_ou_cloture_par_un_tiers(): void
    {
        // Caisse du gérant : clôture exacte -> rien.
        $this->asRole($this->t)->postJson('/api/v1/cash/close', ['counted_cash' => 50000])->assertOk();
        $this->assertCount(0, $this->logs(['action' => 'cash.closed']));

        // Nouvelle caisse avec un manquant -> tracé.
        $this->openCash($this->t, 'gerant', 50000);
        $this->asRole($this->t)->postJson('/api/v1/cash/close', ['counted_cash' => 48000])->assertOk();

        $gap = $this->one('cash.closed');
        $this->assertEquals(-2000, $gap['details']['difference']);

        // Caisse d'un vendeur clôturée exactement par le gérant -> tracé (clôture par un tiers).
        $this->openCash($this->t, 'vendeur', 10000);
        $vendorId = (int) $this->t->run(fn () => User::where('role', 'vendeur')->value('id'));
        $sessionId = (int) $this->t->run(fn () => CashSession::open()->where('user_id', $vendorId)->value('id'));

        $this->asRole($this->t)->postJson('/api/v1/cash/close', ['counted_cash' => 10000, 'session_id' => $sessionId])->assertOk();

        $this->assertCount(2, $this->logs(['action' => 'cash.closed']));
        $this->assertEquals($vendorId, $this->logs(['action' => 'cash.closed'])[0]['details']['owner_id']);
    }

    // ---------------------------------------------------------------- comptes

    public function test_actions_sur_les_comptes_tracees_sans_mot_de_passe(): void
    {
        $created = $this->asRole($this->t)->postJson('/api/v1/users', ['name' => 'Awa', 'email' => 'awa@depot-a.test', 'role' => 'caissier'])->assertCreated();
        $id = $created->json('user.id');
        $temp = $created->json('temporary_password');

        $this->asRole($this->t)->putJson("/api/v1/users/{$id}", ['role' => 'vendeur'])->assertOk();
        $this->asRole($this->t)->postJson("/api/v1/users/{$id}/reset-password")->assertOk();
        $this->asRole($this->t)->postJson("/api/v1/users/{$id}/deactivate")->assertOk();
        $this->asRole($this->t)->postJson("/api/v1/users/{$id}/activate")->assertOk();

        $newTemp = $this->asRole($this->t)->postJson("/api/v1/users/{$id}/reset-password")->json('temporary_password');
        $token = $this->api($this->t)->postJson('/api/v1/login', ['email' => 'awa@depot-a.test', 'password' => $newTemp])->assertOk()->json('token');
        $this->api($this->t, $token)->postJson('/api/v1/me/password', [
            'current_password' => $newTemp, 'password' => 'definitif-mdp-1', 'password_confirmation' => 'definitif-mdp-1',
        ])->assertNoContent();

        $this->assertSame('caissier', $this->one('user.created')['details']['role']);
        $this->assertSame('vendeur', $this->one('user.updated')['details']['role']['to']);
        $this->assertCount(2, $this->logs(['action' => 'user.password_reset']));
        $this->one('user.deactivated');
        $this->one('user.activated');

        $own = $this->one('user.password_changed');
        $this->assertSame('Awa', $own['user']['name']);   // l'auteur est l'intéressé lui-même
        $this->assertSame($id, $own['subject_id']);

        // Jamais de secret dans le journal.
        foreach ($this->logs(['action' => 'user.*']) as $log) {
            $details = json_encode($log['details']);
            foreach ([$temp, $newTemp, 'definitif-mdp-1', 'password'] as $secret) {
                $this->assertStringNotContainsString($secret, $details);
            }
        }
    }

    // ---------------------------------------------------------------- immuabilité

    public function test_une_entree_du_journal_ne_peut_etre_ni_modifiee_ni_supprimee(): void
    {
        $this->asRole($this->t)->postJson('/api/v1/products', ['name' => 'X', 'price_crate' => 5000])->assertCreated();

        $this->t->run(function () {
            $log = AuditLog::firstOrFail();

            try {
                $log->update(['action' => 'falsifie']);
                $this->fail('La modification aurait dû être refusée.');
            } catch (LogicException) {
            }

            try {
                $log->delete();
                $this->fail('La suppression aurait dû être refusée.');
            } catch (LogicException) {
            }

            $this->assertSame('product.created', $log->fresh()->action);
        });
    }

    // ---------------------------------------------------------------- consultation

    public function test_seul_le_gerant_consulte_le_journal(): void
    {
        foreach (['caissier', 'vendeur', 'magasinier'] as $role) {
            $this->asRole($this->t, $role)->getJson('/api/v1/audit-logs')->assertForbidden();
        }
    }

    public function test_filtres_par_action_utilisateur_sujet_et_periode(): void
    {
        $id = $this->asRole($this->t, 'magasinier')->postJson('/api/v1/products', ['name' => 'Castel', 'price_crate' => 8000])->json('id');
        $this->asRole($this->t, 'magasinier')->putJson("/api/v1/products/{$id}", ['price_crate' => 9000])->assertOk();
        $this->makeCustomer($this->t, 50000);

        $this->assertCount(2, $this->logs(['action' => 'product.*']));
        $this->assertCount(1, $this->logs(['action' => 'product.updated']));
        $this->assertCount(1, $this->logs(['action' => 'customer.created']));
        $this->assertCount(2, $this->logs(['subject_type' => 'product', 'subject_id' => $id]));

        $storeKeeper = (int) $this->t->run(fn () => User::where('role', 'magasinier')->value('id'));
        $this->assertCount(2, $this->logs(['user_id' => $storeKeeper]));

        // Du plus récent au plus ancien.
        $this->assertSame('product.updated', $this->logs(['subject_type' => 'product'])[0]['action']);

        $today = now('Africa/Porto-Novo')->toDateString();
        $this->assertCount(3, $this->logs(['from' => $today, 'to' => $today]));
        $this->assertCount(0, $this->logs(['from' => '2020-01-01', 'to' => '2020-01-31']));

        $this->asRole($this->t)->getJson('/api/v1/audit-logs?to='.$today)->assertStatus(422);   // `to` sans `from`
    }

    public function test_le_journal_ne_fuit_pas_entre_depots(): void
    {
        $b = $this->makeTenant('depot-b');

        $this->asRole($this->t)->postJson('/api/v1/products', ['name' => 'Produit A', 'price_crate' => 5000])->assertCreated();
        $this->asRole($b)->postJson('/api/v1/products', ['name' => 'Produit B', 'price_crate' => 5000])->assertCreated();
        $this->asRole($b)->postJson('/api/v1/products', ['name' => 'Produit B2', 'price_crate' => 5000])->assertCreated();

        $this->assertCount(1, $this->logs());
        $this->assertCount(2, $this->asRole($b)->getJson('/api/v1/audit-logs')->assertOk()->json('data'));
    }
}
