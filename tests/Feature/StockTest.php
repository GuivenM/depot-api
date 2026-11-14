<?php

namespace Tests\Feature;

use App\Models\StockMovement;
use App\Models\Tenant;
use Tests\TenantTestCase;

class StockTest extends TenantTestCase
{
    private Tenant $t;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->makeTenant('depot-a');
        $this->productId = $this->makeProduct($this->t)->id;
    }

    public function test_reception_ajoute_casiers_et_bouteilles_en_bouteilles(): void
    {
        $res = $this->asRole($this->t, 'magasinier')->postJson('/api/v1/stock/receipts', [
            'product_id' => $this->productId, 'crates' => 2, 'bottles' => 3,
        ])->assertCreated();

        $this->assertEquals(27, $res->json('stock_bottles'));   // 2 x 12 + 3
        $this->assertSame(27, $this->stockOf($this->t, $this->productId));
    }

    public function test_reception_nulle_est_refusee(): void
    {
        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/stock/receipts', [
            'product_id' => $this->productId, 'crates' => 0, 'bottles' => 0,
        ])->assertStatus(422);
    }

    public function test_casse_retire_du_stock_et_laisse_une_trace(): void
    {
        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/stock/receipts', ['product_id' => $this->productId, 'crates' => 2])->assertCreated();

        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/stock/adjustments', [
            'product_id' => $this->productId, 'type' => 'casse', 'bottles' => 5,
        ])->assertCreated();

        $this->assertSame(19, $this->stockOf($this->t, $this->productId));
        $this->assertEquals(-5, $this->t->run(fn () => StockMovement::where('type', 'casse')->sum('quantity')));
    }

    public function test_le_stock_ne_peut_pas_devenir_negatif(): void
    {
        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/stock/receipts', ['product_id' => $this->productId, 'bottles' => 10])->assertCreated();

        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/stock/adjustments', [
            'product_id' => $this->productId, 'type' => 'ajustement', 'bottles' => -100, 'note' => 'erreur de saisie',
        ])->assertStatus(422);

        $this->assertSame(10, $this->stockOf($this->t, $this->productId));
    }

    public function test_un_ajustement_exige_une_note(): void
    {
        $this->asRole($this->t, 'gerant')->postJson('/api/v1/stock/adjustments', [
            'product_id' => $this->productId, 'type' => 'ajustement', 'bottles' => 4,
        ])->assertStatus(422);
    }

    public function test_le_journal_du_stock_est_coherent_avec_le_cache(): void
    {
        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/stock/receipts', ['product_id' => $this->productId, 'crates' => 3])->assertCreated();
        $this->asRole($this->t, 'magasinier')->postJson('/api/v1/stock/adjustments', ['product_id' => $this->productId, 'type' => 'casse', 'bottles' => 2])->assertCreated();

        $journal = (int) $this->t->run(fn () => StockMovement::where('product_id', $this->productId)->sum('quantity'));

        $this->assertSame($journal, $this->stockOf($this->t, $this->productId));
        $this->assertSame(34, $journal);
    }
}
