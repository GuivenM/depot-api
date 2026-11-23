<?php

namespace App\Console\Commands;

use App\Models\ContainerType;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContainerService;
use App\Services\StockService;
use App\Services\UserService;
use Illuminate\Console\Command;

class SeedDemoProducts extends Command
{
    protected $signature = 'depot:demo {tenant : id/code du tenant}
        {--full : jeu de données complet (produits avec coût, casiers consignés, clients, fournisseurs) au lieu de 3 produits}
        {--users : crée aussi trois comptes de test (caissier, vendeur, magasinier) avec un mot de passe provisoire affiché une fois}';

    protected $description = 'Données de démonstration pour tester un dépôt (tenant vide uniquement : à ne JAMAIS lancer sur un vrai dépôt)';

    /** Prix en FCFA donnés à titre d'exemple : à remplacer par les vrais. */
    private const PRODUCTS = [
        // nom, marque, cl, bouteilles/casier, coût casier, prix casier, prix bouteille, casiers en stock, casier consigné
        ['Béninoise 65cl',     'Béninoise', 65, 12, 5400, 6500,  600, 25, 'c65'],
        ['Castel 65cl',        'Castel',    65, 12, 6200, 7200,  650, 20, 'c65'],
        ['Beaufort 65cl',      'Beaufort',  65, 12, 5800, 6800,  600, 15, 'c65'],
        ['Coca-Cola 33cl',     'Coca-Cola', 33, 24, 5600, 6500,  300, 12, 'c33'],
        ['Fanta 33cl',         'Fanta',     33, 24, 5600, 6500,  300,  1, 'c33'],   // volontairement bas (sous le seuil) : alerte de réapprovisionnement
        ['Eau Possotomè 1,5L', 'Possotomè', 150, 6,  1900, 2400,  450,  0, null],   // volontairement en rupture
    ];

    private const CONTAINERS = ['c65' => ['Casier 65cl', 2500], 'c33' => ['Casier 33cl', 2000]];

    private const CUSTOMERS = [
        ['Bar Chez Mama (démo)',        'bar',         '0100000001', 150000],
        ['Restaurant Le Palmier (démo)', 'restaurant', '0100000002', 300000],
        ['Revendeur Koffi (démo)',      'revendeur',   '0100000003', 500000],
        ['Madame Adjovi (démo)',        'particulier', '0100000004',  20000],
        ['Bar Le Rendez-vous (démo)',   'bar',         '0100000005', 100000],
    ];

    private const SUPPLIERS = [['Brasserie (démo)', '0100000011'], ['Grossiste boissons (démo)', '0100000012']];

    public function handle(StockService $stock, ContainerService $containers, UserService $users): int
    {
        $tenant = Tenant::findOrFail($this->argument('tenant'));

        $accounts = [];
        $result = $tenant->run(function () use ($stock, $containers, $users, &$accounts) {
            $manager = User::where('role', 'gerant')->first();
            if (! $manager) {
                return 'Aucun gérant dans ce tenant : lancez d\'abord depot:provision --email=...';
            }
            if (Product::exists()) {
                return 'Ce tenant a déjà des produits : rien fait (ce jeu de données ne s\'ajoute jamais à de vraies données).';
            }

            $this->option('full') ? $this->seedFull($stock, $containers, $manager) : $this->seedBasic($stock, $manager);

            if ($this->option('users')) {
                $accounts = $this->seedUsers($users, $manager);
            }

            return null;
        });

        if ($result) {
            $this->warn($result);

            return self::FAILURE;
        }

        $this->info($this->option('full') ? 'Jeu de données complet créé (produits, casiers, clients, fournisseurs).' : 'Produits de démo créés.');

        foreach ($accounts as [$role, $email, $password]) {
            $this->warn("Compte {$role} : {$email} — mot de passe provisoire : {$password}");
        }
        if ($accounts) {
            $this->line('Ces mots de passe ne seront plus affichés. Désactivez ces comptes (POST users/{id}/deactivate) avant la mise en service réelle.');
        }

        return self::SUCCESS;
    }

    /** Les 3 produits de l'ancienne version (comportement par défaut inchangé). */
    private function seedBasic(StockService $stock, User $manager): void
    {
        $demo = [
            ['Béninoise 65cl', 'Béninoise', 65, 12, 7000, 600, 10],
            ['Castel 65cl',    'Castel',    65, 12, 8000, 700, 10],
            ['Coca-Cola 33cl', 'Coca-Cola', 33, 24, 6000, 300, 10],
        ];

        foreach ($demo as [$name, $brand, $cl, $perCrate, $priceCrate, $priceBottle, $crates]) {
            $p = Product::create([
                'name' => $name, 'brand' => $brand, 'format_cl' => $cl,
                'bottles_per_crate' => $perCrate, 'price_crate' => $priceCrate,
                'price_bottle' => $priceBottle, 'alert_threshold' => $perCrate * 2,
            ]);
            $stock->move($p, 'entree', $crates * $perCrate, $manager, null, 'Stock initial (démo)');
        }
    }

    private function seedFull(StockService $stock, ContainerService $containers, User $manager): void
    {
        $types = [];
        foreach (self::CONTAINERS as $key => [$name, $deposit]) {
            $type = ContainerType::create(['name' => $name, 'deposit_amount' => $deposit]);
            $containers->depotMovement($manager, $type->id, 'from_supplier', 40, 'Stock initial de casiers vides (démo)');
            $types[$key] = $type->id;
        }

        foreach (self::PRODUCTS as [$name, $brand, $cl, $perCrate, $cost, $priceCrate, $priceBottle, $crates, $container]) {
            $p = Product::create([
                'name' => $name, 'brand' => $brand, 'format_cl' => $cl,
                'bottles_per_crate' => $perCrate, 'cost_crate' => $cost, 'price_crate' => $priceCrate,
                'price_bottle' => $priceBottle, 'alert_threshold' => $perCrate * 2,
                'container_type_id' => $container ? $types[$container] : null,
            ]);
            if ($crates > 0) {
                $stock->move($p, 'entree', $crates * $perCrate, $manager, null, 'Stock initial (démo)');
            }
        }

        foreach (self::CUSTOMERS as [$name, $type, $phone, $limit]) {
            Customer::create(['name' => $name, 'type' => $type, 'phone' => $phone, 'credit_limit' => $limit]);
        }

        foreach (self::SUPPLIERS as [$name, $phone]) {
            Supplier::create(['name' => $name, 'phone' => $phone]);
        }
    }

    /** @return array<int, array{0:string,1:string,2:string}> rôle, email, mot de passe provisoire */
    private function seedUsers(UserService $users, User $manager): array
    {
        $accounts = [];

        foreach (['caissier' => 'Caissier test', 'vendeur' => 'Vendeur test', 'magasinier' => 'Magasinier test'] as $role => $name) {
            $email = "{$role}@demo.test";
            if (User::where('email', $email)->exists()) {
                continue;
            }

            [, $password] = $users->create($manager, ['name' => $name, 'email' => $email, 'role' => $role]);
            $accounts[] = [$role, $email, (string) $password];
        }

        return $accounts;
    }
}
