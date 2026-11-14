<?php

namespace Tests;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StockService;
use App\Tenancy\AutoProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Base des tests multi-tenant.
 *
 * - Base centrale : celle de phpunit (SQLite en mémoire, DB_CONNECTION=sqlite dans phpunit.xml).
 * - Chaque dépôt de test a sa propre base SQLite (fichier database/testing_*.sqlite), créée puis migrée
 *   avec les vraies migrations tenant, comme en production. Pas besoin de MySQL pour lancer la suite.
 * - Les requêtes passent par l'API réelle (X-Tenant + token Sanctum), donc routes, validation,
 *   rôles, services et isolation sont exercés ensemble.
 */
abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'secret-pass-123';

    /** @var string[] */
    private array $dbFiles = [];

    /** @var array<string,string> */
    private array $tokens = [];

    /** Base tenant migrée une seule fois par exécution, copiée ensuite pour chaque dépôt de test (bien plus rapide). */
    private static ?string $templatePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(database_path('testing_*.sqlite')) ?: [] as $leftover) {
            @unlink($leftover);
        }

        // Les bases de dépôts de test sont des fichiers SQLite.
        config(['tenancy.database.template_tenant_connection' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        tenancy()->end();

        try {
            DB::purge('tenant');
        } catch (\Throwable) {
            // rien à purger
        }

        foreach ($this->dbFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- tenants & utilisateurs

    protected function makeTenant(string $id, array $attributes = []): Tenant
    {
        $dbName = 'testing_'.str_replace('-', '_', $id).'_'.bin2hex(random_bytes(4)).'.sqlite';

        // Copie de la base déjà migrée : même contenu qu'un `tenants:migrate`, sans rejouer toutes les migrations.
        $file = database_path($dbName);
        copy($this->templatePath(), $file);
        $this->dbFiles[] = $file;

        $tenant = Tenant::create(array_merge([
            'id'              => $id,
            'name'            => "Dépôt {$id}",
            'status'          => 'active',
            'tenancy_db_name' => $dbName,
        ], $attributes));

        $this->makeUser($tenant, 'gerant');

        return $tenant;
    }

    /** Construit (une fois par exécution) une base tenant SQLite entièrement migrée et renvoie son chemin. */
    private function templatePath(): string
    {
        if (self::$templatePath !== null && file_exists(self::$templatePath)) {
            return self::$templatePath;
        }

        foreach (glob(database_path('testtpl_*.sqlite')) ?: [] as $old) {
            @unlink($old);
        }

        $name = 'testtpl_'.getmypid().'.sqlite';
        $path = database_path($name);

        $tenant = Tenant::create([
            'id' => 'tpl-'.bin2hex(random_bytes(3)), 'name' => 'template', 'status' => 'active', 'tenancy_db_name' => $name,
        ]);

        app(AutoProvisioner::class)->prepare($tenant);   // crée le fichier SQLite vide
        Artisan::call('tenants:migrate', ['--tenants' => [$tenant->id], '--force' => true]);

        tenancy()->end();
        DB::purge('tenant');   // libère le fichier avant de le copier (Windows)
        $tenant->delete();

        register_shutdown_function(static fn () => @unlink($path));

        return self::$templatePath = $path;
    }

    protected function makeUser(Tenant $tenant, string $role, ?string $email = null, string $password = self::PASSWORD): User
    {
        return $tenant->run(function () use ($tenant, $role, $email, $password) {
            $user = new User;
            $user->forceFill([
                'name'     => ucfirst($role),
                'email'    => $email ?? "{$role}@{$tenant->id}.test",
                'password' => Hash::make($password),
                'role'     => $role,
                'active'   => true,
            ])->save();

            return $user;
        });
    }

    /** Token Sanctum obtenu par un vrai login (créé à la demande pour le rôle). */
    protected function tokenFor(Tenant $tenant, string $role = 'gerant'): string
    {
        $key = $tenant->id.'|'.$role;

        if (! isset($this->tokens[$key])) {
            $email = "{$role}@{$tenant->id}.test";

            if (! $tenant->run(fn () => User::where('email', $email)->exists())) {
                $this->makeUser($tenant, $role);
            }

            $response = $this->api($tenant)->postJson('/api/v1/login', [
                'email' => $email, 'password' => self::PASSWORD, 'device' => 'test',
            ])->assertOk();

            $this->tokens[$key] = $response->json('token');
        }

        return $this->tokens[$key];
    }

    // ---------------------------------------------------------------- requêtes HTTP

    protected function api(?Tenant $tenant = null, ?string $token = null): static
    {
        // Plusieurs requêtes dans un même test : on simule une requête « fraîche », comme en production
        // (un processus par requête) : tenant résolu à nouveau, authentification et en-têtes vierges.
        tenancy()->end();
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        $headers = ['Accept' => 'application/json'];
        if ($tenant) {
            $headers['X-Tenant'] = $tenant->id;
        }
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->withHeaders($headers);
    }

    protected function asRole(Tenant $tenant, string $role = 'gerant'): static
    {
        $token = $this->tokenFor($tenant, $role);

        return $this->api($tenant, $token);
    }

    // ---------------------------------------------------------------- données de test

    protected function makeProduct(Tenant $tenant, array $attributes = [], int $crates = 0): Product
    {
        return $tenant->run(function () use ($attributes, $crates) {
            $product = Product::create(array_merge([
                'name'              => 'Béninoise 65cl',
                'bottles_per_crate' => 12,
                'price_crate'       => 7000,
                'price_bottle'      => 600,
                'alert_threshold'   => 0,
            ], $attributes));

            if ($crates > 0) {
                $manager = User::where('role', 'gerant')->firstOrFail();
                app(StockService::class)->move($product, 'entree', $crates * $product->bottles_per_crate, $manager, null, 'test');
            }

            return $product->fresh();
        });
    }

    protected function stockOf(Tenant $tenant, int $productId): int
    {
        return $tenant->run(fn () => (int) Product::findOrFail($productId)->stock_bottles);
    }

    protected function openCash(Tenant $tenant, string $role = 'gerant', int $opening = 50000): void
    {
        $this->asRole($tenant, $role)->postJson('/api/v1/cash/open', ['opening_amount' => $opening])->assertCreated();
    }

    protected function makeCustomer(Tenant $tenant, int $creditLimit = 100000, string $name = 'Bar Chez Paul'): int
    {
        return (int) $this->asRole($tenant)
            ->postJson('/api/v1/customers', ['name' => $name, 'type' => 'bar', 'credit_limit' => $creditLimit])
            ->assertCreated()
            ->json('id');
    }

    protected function saleBody(array $items, string $method = 'cash', array $extra = []): array
    {
        return array_merge(['uuid' => (string) Str::uuid(), 'payment_method' => $method, 'items' => $items], $extra);
    }
}
