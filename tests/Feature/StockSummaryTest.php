<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

class StockSummaryTest extends TenantTestCase
{
    public function test_bilan_du_jour_entrees_ventes_casse_et_cloture(): void
    {
        $t = $this->makeTenant('depot-bilan');
        // makeProduct enregistre le stock initial comme une ENTRÉE du jour (30 casiers).
        $p = $this->makeProduct($t, ['name' => 'Béninoise 65cl'], crates: 30);
        $bpc = (int) $p->bottles_per_crate;
        $this->openCash($t, 'gerant', 50000);

        $this->asRole($t)->postJson('/api/v1/sales', $this->saleBody([
            ['product_id' => $p->id, 'unit' => 'crate', 'quantity' => 2],
        ]))->assertCreated();
        $this->asRole($t)->postJson('/api/v1/stock/receipts', ['product_id' => $p->id, 'crates' => 5])->assertCreated();
        $this->asRole($t)->postJson('/api/v1/stock/adjustments', ['product_id' => $p->id, 'type' => 'casse', 'bottles' => 6])->assertCreated();

        $res = $this->asRole($t, 'magasinier')->getJson('/api/v1/reports/stock-summary')->assertOk();
        $row = collect($res->json('products'))->firstWhere('id', $p->id);

        $this->assertEquals(0, $row['opening']);
        $this->assertEquals(35 * $bpc, $row['entries']);
        $this->assertEquals(2 * $bpc, $row['sales']);
        $this->assertEquals(6, $row['breakage']);
        $this->assertEquals(33 * $bpc - 6, $row['closing']);
        $this->assertEquals($this->stockOf($t, $p->id), $row['closing']);

        // L'équation du bilan tient toujours.
        $this->assertEquals(
            $row['closing'],
            $row['opening'] + $row['entries'] - $row['sales'] - $row['breakage'] + $row['corrections'] + $row['voids'],
        );
    }

    public function test_une_periode_passee_remonte_le_stock_dans_le_temps(): void
    {
        $t = $this->makeTenant('depot-bilan-2');
        $p = $this->makeProduct($t, [], crates: 10);   // entrée créée aujourd'hui

        $res = $this->asRole($t)->getJson('/api/v1/reports/stock-summary?from=2020-01-01&to=2020-01-02')->assertOk();
        $row = collect($res->json('products'))->firstWhere('id', $p->id);

        $this->assertEquals(0, $row['entries']);
        $this->assertEquals(0, $row['opening']);
        $this->assertEquals(0, $row['closing']);   // en 2020, ce produit n'avait pas de stock
    }

    public function test_le_vendeur_n_a_pas_acces_au_bilan(): void
    {
        $t = $this->makeTenant('depot-bilan-3');

        $this->asRole($t, 'vendeur')->getJson('/api/v1/reports/stock-summary')->assertForbidden();
    }
}
