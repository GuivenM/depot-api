<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class SecurityTest extends TenantTestCase
{
    private function loginAs(Tenant $tenant, string $email, string $password)
    {
        return $this->api($tenant)->postJson('/api/v1/login', ['email' => $email, 'password' => $password]);
    }

    private function rawData(string $tenantId): string
    {
        return (string) DB::table('tenants')->where('id', $tenantId)->value('data');
    }

    // ---------------------------------------------------------------- tenant inconnu

    public function test_tenant_absent_ou_inconnu_donne_la_meme_reponse_404_json(): void
    {
        $this->makeTenant('depot-a');

        $missing = $this->api()->getJson('/api/v1/me');
        $unknown = $this->withHeaders(['X-Tenant' => 'nimporte-quoi', 'Accept' => 'application/json'])->getJson('/api/v1/me');

        $missing->assertStatus(404)->assertJsonStructure(['message']);
        $unknown->assertStatus(404);
        $this->assertSame($missing->json('message'), $unknown->json('message'));   // pas d'énumération des dépôts
    }

    // ---------------------------------------------------------------- limitation de débit

    public function test_login_est_limite_par_depot_email_et_ip(): void
    {
        config(['depot.login_attempts' => 3]);

        $a = $this->makeTenant('depot-a');
        $b = $this->makeTenant('depot-b');
        $this->makeUser($a, 'caissier');

        for ($i = 0; $i < 3; $i++) {
            $this->loginAs($a, 'gerant@depot-a.test', 'mauvais-mot-de-passe')->assertStatus(422);
        }

        // 4e tentative : bloquée, même avec le bon mot de passe.
        $blocked = $this->loginAs($a, 'gerant@depot-a.test', self::PASSWORD);
        $blocked->assertStatus(429)->assertHeader('Retry-After');
        $this->assertStringContainsString('Trop de tentatives', $blocked->json('message'));
        $this->assertNull($blocked->json('token'));

        // Un autre compte du même dépôt, et le même email dans un autre dépôt, ne sont pas touchés.
        $this->loginAs($a, 'caissier@depot-a.test', self::PASSWORD)->assertOk();
        $this->loginAs($b, 'gerant@depot-b.test', self::PASSWORD)->assertOk();
    }

    public function test_login_est_limite_par_ip_meme_en_changeant_d_email(): void
    {
        config(['depot.login_attempts_per_ip' => 2]);

        $a = $this->makeTenant('depot-a');

        $this->loginAs($a, 'un@depot-a.test', 'x')->assertStatus(422);
        $this->loginAs($a, 'deux@depot-a.test', 'x')->assertStatus(422);
        $this->loginAs($a, 'trois@depot-a.test', 'x')->assertStatus(429);
    }

    public function test_changement_de_mot_de_passe_est_limite(): void
    {
        config(['depot.password_attempts' => 2]);

        $a = $this->makeTenant('depot-a');
        $token = $this->tokenFor($a, 'vendeur');
        $body = ['current_password' => 'faux', 'password' => 'nouveau-mdp-2', 'password_confirmation' => 'nouveau-mdp-2'];

        $this->api($a, $token)->postJson('/api/v1/me/password', $body)->assertStatus(422);
        $this->api($a, $token)->postJson('/api/v1/me/password', $body)->assertStatus(422);
        $this->api($a, $token)->postJson('/api/v1/me/password', $body)->assertStatus(429);
    }

    // ---------------------------------------------------------------- expiration des tokens

    public function test_un_token_expire_est_refuse(): void
    {
        config(['sanctum.expiration' => 60]);

        $a = $this->makeTenant('depot-a');
        $login = $this->loginAs($a, 'gerant@depot-a.test', self::PASSWORD)->assertOk();

        $this->assertNotNull($login->json('expires_at'));
        $token = $login->json('token');

        $this->travel(30)->minutes();
        $this->api($a, $token)->getJson('/api/v1/me')->assertOk();

        $this->travel(61)->minutes();
        $this->api($a, $token)->getJson('/api/v1/me')->assertUnauthorized();

        $this->travelBack();
    }

    public function test_les_tokens_expirent_par_defaut(): void
    {
        $this->assertGreaterThan(0, (int) config('sanctum.expiration'));
    }

    // ---------------------------------------------------------------- identifiants de base chiffrés

    public function test_le_mot_de_passe_de_la_base_est_chiffre_au_repos_et_lisible_par_l_application(): void
    {
        Tenant::create([
            'id' => 'chez-paul', 'name' => 'Chez Paul', 'status' => 'pending_db',
            'tenancy_db_name' => 'compte_chezpaul', 'tenancy_db_username' => 'compte_paul',
            'tenancy_db_password' => 'mot-de-passe-mysql-secret',
        ]);

        $raw = $this->rawData('chez-paul');
        $this->assertStringNotContainsString('mot-de-passe-mysql-secret', $raw);
        $this->assertStringContainsString('compte_chezpaul', $raw);   // le reste n'est pas chiffré

        $tenant = Tenant::find('chez-paul');
        $this->assertSame('mot-de-passe-mysql-secret', $tenant->tenancy_db_password);
        $this->assertSame('mot-de-passe-mysql-secret', $tenant->database()->getPassword());
        $this->assertSame('compte_paul', $tenant->database()->getUsername());
    }

    public function test_un_mot_de_passe_en_clair_existant_reste_lisible_puis_la_commande_le_chiffre(): void
    {
        Tenant::create(['id' => 'ancien', 'name' => 'Ancien', 'status' => 'active', 'tenancy_db_name' => 'compte_ancien']);

        // État d'avant la mise à jour : mot de passe en clair dans `data`.
        DB::table('tenants')->where('id', 'ancien')->update(['data' => json_encode([
            'tenancy_db_name' => 'compte_ancien', 'tenancy_db_username' => 'u', 'tenancy_db_password' => 'motdepasse-en-clair',
        ])]);

        $this->assertSame('motdepasse-en-clair', Tenant::find('ancien')->tenancy_db_password);   // pas de coupure de service

        Artisan::call('depot:encrypt-credentials');
        $this->assertStringContainsString('1 dépôt(s) chiffré(s), 0 déjà', Artisan::output());
        $this->assertStringNotContainsString('motdepasse-en-clair', $this->rawData('ancien'));
        $this->assertSame('motdepasse-en-clair', Tenant::find('ancien')->tenancy_db_password);

        // Idempotent.
        Artisan::call('depot:encrypt-credentials');
        $this->assertStringContainsString('0 dépôt(s) chiffré(s), 1 déjà', Artisan::output());
        $this->assertSame('motdepasse-en-clair', Tenant::find('ancien')->tenancy_db_password);
    }
}
