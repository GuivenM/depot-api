<?php

namespace Tests\Feature;

use App\Models\ContainerHolding;
use App\Models\ContainerType;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TenantTestCase;

class DemoDataTest extends TenantTestCase
{
    public function test_par_defaut_trois_produits_comme_avant(): void
    {
        $t = $this->makeTenant('depot-a');

        $this->assertSame(0, Artisan::call('depot:demo', ['tenant' => 'depot-a']));

        $this->assertSame(3, $t->run(fn () => Product::count()));
        $this->assertSame(0, $t->run(fn () => Customer::count()));
    }

    public function test_jeu_de_donnees_complet(): void
    {
        $t = $this->makeTenant('depot-a');

        $this->assertSame(0, Artisan::call('depot:demo', ['tenant' => 'depot-a', '--full' => true]));

        $t->run(function () {
            $this->assertSame(6, Product::count());
            $this->assertSame(6, Product::whereNotNull('cost_crate')->count());   // marge calculable
            $this->assertSame(2, ContainerType::count());
            $this->assertSame([40, 40], ContainerType::orderBy('id')->pluck('empty_stock')->map(fn ($v) => (int) $v)->all());
            $this->assertSame(5, Customer::count());
            $this->assertSame(2, Supplier::count());

            $beninoise = Product::where('name', 'Béninoise 65cl')->first();
            $this->assertSame(300, (int) $beninoise->stock_bottles);                    // 25 casiers de 12
            $this->assertNotNull($beninoise->container_type_id);

            $this->assertSame(0, (int) Product::where('name', 'like', 'Eau%')->value('stock_bottles'));   // rupture voulue
            $fanta = Product::where('name', 'Fanta 33cl')->first();
            $this->assertLessThanOrEqual((int) $fanta->alert_threshold, (int) $fanta->stock_bottles);      // sous le seuil voulu
        });

        // Les alertes de stock de l'API voient bien une rupture et un stock bas.
        $alerts = $this->asRole($t)->getJson('/api/v1/reports/stock-alerts')->assertOk()->json('products');
        $this->assertCount(2, $alerts);
    }

    public function test_comptes_de_test_avec_mot_de_passe_provisoire_qui_fonctionne(): void
    {
        $t = $this->makeTenant('depot-a');

        $this->assertSame(0, Artisan::call('depot:demo', ['tenant' => 'depot-a', '--users' => true]));
        $output = Artisan::output();

        foreach (['caissier', 'vendeur', 'magasinier'] as $role) {
            $this->assertSame(1, preg_match("/Compte {$role} : {$role}@demo\\.test — mot de passe provisoire : (\\S+)/u", $output, $m), $role);

            $login = $this->api($t)->postJson('/api/v1/login', ['email' => "{$role}@demo.test", 'password' => $m[1]])->assertOk();
            $this->assertTrue($login->json('user.must_change_password'));
            $this->assertSame($role, $login->json('user.role'));
        }
    }

    public function test_ne_s_ajoute_jamais_a_un_depot_qui_a_deja_des_produits(): void
    {
        $t = $this->makeTenant('depot-a');
        $this->makeProduct($t, ['name' => 'Vrai produit'], crates: 1);

        $this->assertSame(1, Artisan::call('depot:demo', ['tenant' => 'depot-a', '--full' => true, '--users' => true]));

        $t->run(function () {
            $this->assertSame(1, Product::count());
            $this->assertSame(0, Customer::count());
            $this->assertSame(0, User::where('email', 'like', '%@demo.test')->count());   // pas de comptes non plus
        });
    }

    public function test_les_donnees_completes_permettent_une_vente_et_un_recu(): void
    {
        $t = $this->makeTenant('depot-a');
        Artisan::call('depot:demo', ['tenant' => 'depot-a', '--full' => true]);
        $this->openCash($t, 'gerant', 20000);

        $productId = $t->run(fn () => Product::where('name', 'Castel 65cl')->value('id'));
        $customerId = $t->run(fn () => Customer::where('name', 'like', 'Restaurant%')->value('id'));

        $sale = $this->asRole($t)->postJson('/api/v1/sales', $this->saleBody(
            [['product_id' => $productId, 'unit' => 'crate', 'quantity' => 2]], 'credit', ['customer_id' => $customerId],
        ))->assertCreated();

        $this->assertSame(14400, (int) $sale->json('total'));                              // 2 × 7 200
        $this->assertSame(2, $t->run(fn () => (int) ContainerHolding::where('party_type', 'customer')->where('party_id', $customerId)->sum('quantity')));   // casiers dus
    }
}
