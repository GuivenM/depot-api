<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class DeploymentTest extends TenantTestCase
{
    private string $heartbeat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->heartbeat = storage_path('framework/schedule-heartbeat');
        @unlink($this->heartbeat);
    }

    protected function tearDown(): void
    {
        @unlink($this->heartbeat);
        @unlink(database_path('testing_vide.sqlite'));

        parent::tearDown();
    }

    private function check(array $options = []): array
    {
        $code = Artisan::call('depot:check', $options);

        return [$code, Artisan::output()];
    }

    private function asProduction(): void
    {
        $this->app['env'] = 'production';
    }

    // ---------------------------------------------------------------- CORS

    public function test_cors_par_defaut_n_autorise_aucun_site(): void
    {
        $this->assertSame([], config('cors.allowed_origins'));
    }

    public function test_cors_autorise_seulement_les_sites_listes(): void
    {
        config(['cors.allowed_origins' => ['https://app.exemple.com', 'https://admin.exemple.com']]);
        $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/v1/login', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type,x-tenant',
        ]);

        $allowed = $preflight('https://app.exemple.com');
        $this->assertSame('https://app.exemple.com', $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsStringIgnoringCase('x-tenant', (string) $allowed->headers->get('Access-Control-Allow-Headers'));
        $this->assertSame('https://admin.exemple.com', $preflight('https://admin.exemple.com')->headers->get('Access-Control-Allow-Origin'));

        // Un site non listé ne reçoit aucune autorisation.
        $this->assertNull($preflight('https://pirate.exemple.org')->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_avec_une_seule_origine_n_autorise_jamais_un_autre_site(): void
    {
        // Avec une seule origine configurée, la bibliothèque répond toujours par cette origine : le navigateur
        // compare alors à l'origine de la page et bloque tout site différent. On vérifie qu'on ne renvoie ni « * »
        // ni l'origine du site pirate.
        config(['cors.allowed_origins' => ['https://app.exemple.com']]);

        $response = $this->call('OPTIONS', '/api/v1/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://pirate.exemple.org',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $header = $response->headers->get('Access-Control-Allow-Origin');
        $this->assertNotSame('https://pirate.exemple.org', $header);
        $this->assertNotSame('*', $header);
    }

    public function test_cors_expose_le_nom_de_fichier_des_documents_au_front(): void
    {
        config(['cors.allowed_origins' => ['https://app.exemple.com']]);

        $response = $this->withHeaders(['Origin' => 'https://app.exemple.com'])->getJson('/api/v1/me');

        $this->assertSame('https://app.exemple.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('Content-Disposition', (string) $response->headers->get('Access-Control-Expose-Headers'));
    }

    // ---------------------------------------------------------------- en-têtes de sécurité

    public function test_en_tetes_de_securite_sur_les_reponses(): void
    {
        $response = $this->getJson('/api/v1/me');   // même une 401 les porte

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertNull($response->headers->get('Strict-Transport-Security'));   // connexion non sécurisée
    }

    public function test_hsts_seulement_en_https(): void
    {
        $response = $this->getJson('https://localhost/api/v1/me');

        $this->assertStringContainsString('max-age=', (string) $response->headers->get('Strict-Transport-Security'));
    }

    // ---------------------------------------------------------------- création d'un dépôt

    public function test_creation_d_un_depot(): void
    {
        $this->artisan('depot:tenant:create', ['id' => 'chez-paul', '--name' => 'Chez Paul', '--db' => 'cp_chezpaul', '--db-user' => 'cp_paul', '--db-password' => 'secret-mysql'])
            ->expectsOutputToContain('enregistré')
            ->assertExitCode(0);

        $tenant = Tenant::find('chez-paul');
        $this->assertSame('pending_db', $tenant->status);
        $this->assertSame('Chez Paul', $tenant->name);
        $this->assertSame('cp_chezpaul', $tenant->tenancy_db_name);
        $this->assertSame('secret-mysql', $tenant->tenancy_db_password);                       // lisible par l'application...
        $this->assertStringNotContainsString('secret-mysql', (string) DB::table('tenants')->where('id', 'chez-paul')->value('data'));   // ...chiffré au repos
    }

    public function test_le_mot_de_passe_de_la_base_peut_etre_saisi_sans_l_ecrire_dans_la_commande(): void
    {
        $this->artisan('depot:tenant:create', ['id' => 'chez-marie', '--db' => 'cp_marie', '--db-user' => 'cp_marie'])
            ->expectsQuestion('Mot de passe de la base MySQL', 'saisi-au-clavier')
            ->assertExitCode(0);

        $this->assertSame('saisi-au-clavier', Tenant::find('chez-marie')->tenancy_db_password);
    }

    public function test_creation_refuse_les_codes_invalides_reserves_ou_deja_pris(): void
    {
        foreach (['Chez Paul', 'MAJUSCULES', '-debut', 'fin-', 'a--b', 'central', str_repeat('a', 41)] as $bad) {
            $this->artisan('depot:tenant:create', ['id' => $bad, '--db' => 'x', '--db-user' => 'y', '--db-password' => 'z'])->assertExitCode(1);
        }
        $this->assertNull(Tenant::find('central'));

        $this->makeTenant('depot-a');
        $this->artisan('depot:tenant:create', ['id' => 'depot-a', '--db' => 'x', '--db-user' => 'y', '--db-password' => 'z'])
            ->expectsOutputToContain('existe déjà')
            ->assertExitCode(1);
    }

    public function test_creation_en_mode_manuel_exige_la_base_et_l_utilisateur(): void
    {
        $this->artisan('depot:tenant:create', ['id' => 'sans-base'])
            ->expectsOutputToContain('--db et --db-user')
            ->assertExitCode(1);

        $this->assertNull(Tenant::find('sans-base'));
    }

    // ---------------------------------------------------------------- contrôle de l'installation

    public function test_le_controle_ne_bloque_pas_en_environnement_de_test(): void
    {
        [$code, $out] = $this->check();

        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('[FAIL]', $out);
        $this->assertStringContainsString('[WARN] CORS : aucun site autorisé', $out);
        $this->assertStringContainsString('[WARN] Planificateur jamais exécuté', $out);
    }

    public function test_le_controle_refuse_le_debug_en_production(): void
    {
        $this->asProduction();
        config(['app.debug' => true]);

        [$code, $out] = $this->check();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('[FAIL] APP_DEBUG=true en production', $out);

        config(['app.debug' => false]);
        [, $out] = $this->check();
        $this->assertStringContainsString('[OK]   APP_DEBUG désactivé', $out);
    }

    public function test_le_controle_refuse_cors_ouvert_a_tous_en_production_seulement(): void
    {
        config(['cors.allowed_origins' => ['*']]);

        [$code, $out] = $this->check();
        $this->assertSame(0, $code);                                   // hors production : simple avertissement
        $this->assertStringContainsString('[WARN] CORS ouvert', $out);

        $this->asProduction();
        config(['app.debug' => false]);
        [$code, $out] = $this->check();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('[FAIL] CORS ouvert à tous les sites', $out);
    }

    public function test_le_controle_refuse_un_fuseau_autre_que_utc_et_un_limiteur_en_base(): void
    {
        config(['app.timezone' => 'Africa/Porto-Novo', 'cache.limiter' => 'database']);

        [$code, $out] = $this->check();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('doit rester UTC', $out);
        $this->assertStringContainsString('Limiteur de débit sur le cache « database »', $out);
    }

    public function test_le_controle_lit_le_temoin_de_vie_du_planificateur(): void
    {
        file_put_contents($this->heartbeat, now()->subMinutes(3)->toIso8601String());
        [, $out] = $this->check();
        $this->assertStringContainsString('[OK]   Planificateur (cron)', $out);

        file_put_contents($this->heartbeat, now()->subHours(3)->toIso8601String());
        [$code, $out] = $this->check();
        $this->assertSame(0, $code);   // avertissement, pas un blocage
        $this->assertStringContainsString('[WARN] Planificateur arrêté', $out);
    }

    public function test_le_controle_des_depots_detecte_une_base_non_migree(): void
    {
        $this->makeTenant('depot-a');

        [$code, $out] = $this->check(['--tenants' => true]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('[OK]   depot-a — base migrée, 1 gérant(s) actif(s)', $out);

        touch(database_path('testing_vide.sqlite'));   // base vide : migrations non lancées
        Tenant::create(['id' => 'vide', 'name' => 'Vide', 'status' => 'active', 'tenancy_db_name' => 'testing_vide.sqlite']);

        [$code, $out] = $this->check(['--tenants' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('[FAIL] vide : tables manquantes', $out);
        $this->assertStringContainsString('tenants:migrate --tenants=vide', $out);
        $this->assertStringContainsString('[OK]   depot-a', $out);   // les autres dépôts sont quand même contrôlés
    }

    // ---------------------------------------------------------------- planification

    public function test_la_planification_contient_la_sauvegarde_et_le_temoin_de_vie(): void
    {
        Artisan::call('schedule:list');
        $out = Artisan::output();

        $this->assertStringContainsString('depot:backup', $out);
        $this->assertStringContainsString('heartbeat', $out);
    }
}
