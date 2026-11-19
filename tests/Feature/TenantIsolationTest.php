<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

class TenantIsolationTest extends TenantTestCase
{
    public function test_requete_sans_tenant_est_refusee(): void
    {
        $this->makeTenant('depot-a');

        $response = $this->api()->postJson('/api/v1/login', [
            'email' => 'gerant@depot-a.test', 'password' => self::PASSWORD,
        ]);

        $response->assertStatus(404)->assertJsonPath('message', 'Dépôt introuvable : vérifiez l\'en-tête X-Tenant.');
        $this->assertNull($response->json('token'));
    }

    public function test_tenant_inconnu_est_refuse(): void
    {
        $a = $this->makeTenant('depot-a');
        $token = $this->tokenFor($a);

        $response = $this->withHeaders(['X-Tenant' => 'nimporte-quoi', 'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/v1/me');

        $response->assertStatus(404)->assertJsonPath('message', 'Dépôt introuvable : vérifiez l\'en-tête X-Tenant.');   // même réponse qu'un en-tête absent
        $this->assertNull($response->json('email'));
    }

    public function test_token_dun_depot_est_inutilisable_sur_un_autre(): void
    {
        $a = $this->makeTenant('depot-a');
        $b = $this->makeTenant('depot-b');

        $tokenB = $this->tokenFor($b);   // garantit qu'un token d'id 1 existe aussi dans B
        $tokenA = $this->tokenFor($a);

        $this->api($a, $tokenA)->getJson('/api/v1/me')->assertOk();
        $this->api($b, $tokenB)->getJson('/api/v1/me')->assertOk();
        $this->api($b, $tokenA)->getJson('/api/v1/me')->assertUnauthorized();
        $this->api($a, $tokenB)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_les_donnees_ne_fuient_pas_entre_depots(): void
    {
        $a = $this->makeTenant('depot-a');
        $b = $this->makeTenant('depot-b');

        $this->asRole($a)->postJson('/api/v1/products', [
            'name' => 'Castel 65cl', 'price_crate' => 8000, 'bottles_per_crate' => 12,
        ])->assertCreated();

        $this->assertCount(1, $this->asRole($a)->getJson('/api/v1/products')->json());
        $this->assertCount(0, $this->asRole($b)->getJson('/api/v1/products')->json());
    }

    public function test_un_meme_email_dans_deux_depots_reste_separe(): void
    {
        $a = $this->makeTenant('depot-a');
        $b = $this->makeTenant('depot-b');

        $this->makeUser($a, 'caissier', 'shared@test.local', 'mot-de-passe-A');
        $this->makeUser($b, 'caissier', 'shared@test.local', 'mot-de-passe-B');

        $this->api($a)->postJson('/api/v1/login', ['email' => 'shared@test.local', 'password' => 'mot-de-passe-A'])->assertOk();
        $this->api($a)->postJson('/api/v1/login', ['email' => 'shared@test.local', 'password' => 'mot-de-passe-B'])->assertStatus(422);
        $this->api($b)->postJson('/api/v1/login', ['email' => 'shared@test.local', 'password' => 'mot-de-passe-B'])->assertOk();
    }

    public function test_depot_suspendu_est_refuse(): void
    {
        $a = $this->makeTenant('depot-a');
        $token = $this->tokenFor($a);

        $a->update(['status' => 'suspended']);

        $this->api($a, $token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_utilisateur_desactive_ne_peut_pas_se_connecter(): void
    {
        $a = $this->makeTenant('depot-a');
        $this->makeUser($a, 'vendeur');
        $a->run(fn () => User::where('role', 'vendeur')->update(['active' => false]));

        $this->api($a)->postJson('/api/v1/login', [
            'email' => 'vendeur@depot-a.test', 'password' => self::PASSWORD,
        ])->assertStatus(422);
    }

    public function test_roles_sont_appliques(): void
    {
        $a = $this->makeTenant('depot-a');

        $this->asRole($a, 'vendeur')->postJson('/api/v1/products', [
            'name' => 'X', 'price_crate' => 1000,
        ])->assertForbidden();

        $this->asRole($a, 'magasinier')->postJson('/api/v1/cash/open', ['opening_amount' => 0])->assertForbidden();
        $this->asRole($a, 'caissier')->postJson('/api/v1/containers', ['name' => 'X', 'deposit_amount' => 1])->assertForbidden();
    }
}
