<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Console\Command;

class SeedDemoProducts extends Command
{
    protected $signature = 'depot:demo {tenant : id/code du tenant}';
    protected $description = 'Crée 3 produits de démonstration avec un stock initial (tenant vide uniquement)';

    public function handle(StockService $stock): int
    {
        $tenant = Tenant::findOrFail($this->argument('tenant'));

        $result = $tenant->run(function () use ($stock) {
            $manager = User::where('role', 'gerant')->first();
            if (! $manager) {
                return 'Aucun gérant dans ce tenant : lancez d\'abord depot:provision --email=...';
            }
            if (Product::exists()) {
                return 'Ce tenant a déjà des produits : rien fait.';
            }

            // Prix de démonstration, à remplacer par les vrais.
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

            return null;
        });

        if ($result) {
            $this->warn($result);
            return self::FAILURE;
        }

        $this->info('Produits de démo créés.');
        return self::SUCCESS;
    }
}
