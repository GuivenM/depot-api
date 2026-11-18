<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Tests\TenantTestCase;

class PersonnelTest extends TenantTestCase
{
    private Tenant $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->makeTenant('depot-a');
    }

    private function createUser(array $extra = [], string $role = 'gerant')
    {
        return $this->asRole($this->t, $role)->postJson('/api/v1/users', array_merge([
            'name' => 'Awa Kossou', 'email' => 'awa@depot-a.test', 'role' => 'caissier',
        ], $extra));
    }

    private function login(string $email, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->api($this->t)->postJson('/api/v1/login', ['email' => $email, 'password' => $password]);
    }

    private function idOf(string $role): int
    {
        return (int) $this->t->run(fn () => User::where('role', $role)->value('id'));
    }

    // ---------------------------------------------------------------- droits

    public function test_seul_le_gerant_gere_le_personnel(): void
    {
        foreach (['caissier', 'vendeur', 'magasinier'] as $role) {
            $this->asRole($this->t, $role)->getJson('/api/v1/users')->assertForbidden();
            $this->createUser(role: $role)->assertForbidden();
        }

        $this->asRole($this->t)->getJson('/api/v1/users')->assertOk();
    }

    // ---------------------------------------------------------------- création

    public function test_creation_genere_un_mot_de_passe_provisoire_a_changer(): void
    {
        $res = $this->createUser()->assertCreated();

        $this->assertSame('caissier', $res->json('user.role'));
        $this->assertTrue($res->json('user.must_change_password'));
        $this->assertNotEmpty($res->json('temporary_password'));
        $this->assertArrayNotHasKey('password', $res->json('user'));

        $login = $this->login('awa@depot-a.test', $res->json('temporary_password'))->assertOk();
        $this->assertTrue($login->json('user.must_change_password'));
    }

    public function test_creation_avec_mot_de_passe_fourni_ne_le_renvoie_pas(): void
    {
        $res = $this->createUser(['password' => 'choisi-par-gerant-1'])->assertCreated();

        $this->assertArrayNotHasKey('temporary_password', $res->json());
        $this->login('awa@depot-a.test', 'choisi-par-gerant-1')->assertOk();
    }

    public function test_creation_valide_email_unique_et_role(): void
    {
        $this->createUser()->assertCreated();

        $this->createUser(['email' => 'AWA@depot-a.test '])->assertStatus(422);   // même email, casse et espaces ignorés
        $this->createUser(['email' => 'autre@depot-a.test', 'role' => 'patron'])->assertStatus(422);
        $this->createUser(['email' => 'court@depot-a.test', 'password' => 'abc'])->assertStatus(422);
    }

    // ---------------------------------------------------------------- liste et modification

    public function test_liste_avec_filtres(): void
    {
        $this->createUser(['name' => 'Awa', 'email' => 'awa@depot-a.test', 'role' => 'caissier'])->assertCreated();
        $this->createUser(['name' => 'Koffi', 'email' => 'koffi@depot-a.test', 'role' => 'vendeur'])->assertCreated();

        $this->assertCount(3, $this->asRole($this->t)->getJson('/api/v1/users')->json('data'));
        $this->assertCount(1, $this->asRole($this->t)->getJson('/api/v1/users?role=vendeur')->json('data'));
        $this->assertCount(1, $this->asRole($this->t)->getJson('/api/v1/users?q=koffi')->json('data'));
    }

    public function test_modification_du_nom_de_l_email_et_du_role(): void
    {
        $id = $this->createUser()->assertCreated()->json('user.id');

        $res = $this->asRole($this->t)->putJson("/api/v1/users/{$id}", [
            'name' => 'Awa K.', 'email' => 'awa.k@depot-a.test', 'role' => 'magasinier',
        ])->assertOk();

        $this->assertSame('magasinier', $res->json('role'));
        $this->assertSame('awa.k@depot-a.test', $res->json('email'));
    }

    public function test_on_ne_change_pas_son_propre_role(): void
    {
        $this->asRole($this->t)->putJson('/api/v1/users/'.$this->idOf('gerant'), ['role' => 'vendeur'])
            ->assertStatus(422);

        $this->assertSame('gerant', $this->asRole($this->t)->getJson('/api/v1/me')->json('role'));
    }

    public function test_changement_de_role_refuse_avec_une_caisse_ouverte(): void
    {
        $this->openCash($this->t, 'vendeur');

        $this->asRole($this->t)->putJson('/api/v1/users/'.$this->idOf('vendeur'), ['role' => 'magasinier'])
            ->assertStatus(409);
    }

    // ---------------------------------------------------------------- désactivation

    public function test_desactivation_coupe_les_sessions_et_la_connexion_puis_reactivation(): void
    {
        $created = $this->createUser(['password' => 'mot-de-passe-1'])->assertCreated();
        $id = $created->json('user.id');
        $token = $this->login('awa@depot-a.test', 'mot-de-passe-1')->assertOk()->json('token');

        $this->api($this->t, $token)->getJson('/api/v1/me')->assertOk();

        $this->asRole($this->t)->postJson("/api/v1/users/{$id}/deactivate")->assertOk()->assertJsonPath('active', false);

        $this->api($this->t, $token)->getJson('/api/v1/me')->assertUnauthorized();   // token supprimé
        $this->login('awa@depot-a.test', 'mot-de-passe-1')->assertStatus(422);

        $this->asRole($this->t)->postJson("/api/v1/users/{$id}/activate")->assertOk()->assertJsonPath('active', true);
        $this->login('awa@depot-a.test', 'mot-de-passe-1')->assertOk();
    }

    public function test_un_compte_desactive_en_base_est_refuse_meme_avec_un_token(): void
    {
        $token = $this->tokenFor($this->t, 'vendeur');
        $this->t->run(fn () => User::where('role', 'vendeur')->update(['active' => false]));

        $this->api($this->t, $token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_on_ne_se_desactive_pas_soi_meme(): void
    {
        $this->asRole($this->t)->postJson('/api/v1/users/'.$this->idOf('gerant').'/deactivate')->assertStatus(422);
    }

    public function test_desactivation_refusee_avec_une_caisse_ouverte(): void
    {
        $this->openCash($this->t, 'caissier');
        $id = $this->idOf('caissier');

        $this->asRole($this->t)->postJson("/api/v1/users/{$id}/deactivate")->assertStatus(409);

        $this->assertTrue($this->t->run(fn () => (bool) User::find($id)->active));
    }

    public function test_un_gerant_peut_en_desactiver_un_autre(): void
    {
        $second = $this->makeUser($this->t, 'gerant', 'gerant2@depot-a.test');

        $this->asRole($this->t)->postJson("/api/v1/users/{$second->id}/deactivate")->assertOk();
    }

    // ---------------------------------------------------------------- mots de passe

    public function test_reinitialisation_par_le_gerant(): void
    {
        $id = $this->createUser(['password' => 'mot-de-passe-1'])->assertCreated()->json('user.id');
        $oldToken = $this->login('awa@depot-a.test', 'mot-de-passe-1')->json('token');

        $res = $this->asRole($this->t)->postJson("/api/v1/users/{$id}/reset-password")->assertOk();
        $temp = $res->json('temporary_password');

        $this->assertTrue($res->json('user.must_change_password'));
        $this->api($this->t, $oldToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->login('awa@depot-a.test', 'mot-de-passe-1')->assertStatus(422);
        $this->login('awa@depot-a.test', $temp)->assertOk();
    }

    public function test_le_gerant_ne_reinitialise_pas_son_propre_mot_de_passe(): void
    {
        $this->asRole($this->t)->postJson('/api/v1/users/'.$this->idOf('gerant').'/reset-password')->assertStatus(422);
    }

    public function test_changement_de_son_propre_mot_de_passe(): void
    {
        $this->createUser(['password' => 'mot-de-passe-1'])->assertCreated();
        $token = $this->login('awa@depot-a.test', 'mot-de-passe-1')->json('token');
        $other = $this->login('awa@depot-a.test', 'mot-de-passe-1')->json('token');   // autre appareil

        $body = ['current_password' => 'mot-de-passe-1', 'password' => 'nouveau-mdp-2', 'password_confirmation' => 'nouveau-mdp-2'];

        $this->api($this->t, $token)->postJson('/api/v1/me/password', array_merge($body, ['current_password' => 'faux']))
            ->assertStatus(422);
        $this->api($this->t, $token)->postJson('/api/v1/me/password', array_merge($body, ['password' => 'autre-mdp-3']))
            ->assertStatus(422);   // confirmation différente
        $this->api($this->t, $token)->postJson('/api/v1/me/password', array_merge($body, [
            'password' => 'mot-de-passe-1', 'password_confirmation' => 'mot-de-passe-1',
        ]))->assertStatus(422);   // identique à l'actuel

        $this->api($this->t, $token)->postJson('/api/v1/me/password', $body)->assertNoContent();

        $this->api($this->t, $token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('must_change_password', false);
        $this->api($this->t, $other)->getJson('/api/v1/me')->assertUnauthorized();   // autre appareil déconnecté
        $this->login('awa@depot-a.test', 'mot-de-passe-1')->assertStatus(422);
        $this->login('awa@depot-a.test', 'nouveau-mdp-2')->assertOk();
    }

    public function test_changer_son_mot_de_passe_efface_l_obligation_de_le_changer(): void
    {
        $temp = $this->createUser()->assertCreated()->json('temporary_password');
        $token = $this->login('awa@depot-a.test', $temp)->assertJsonPath('user.must_change_password', true)->json('token');

        $this->api($this->t, $token)->postJson('/api/v1/me/password', [
            'current_password' => $temp, 'password' => 'definitif-mdp-1', 'password_confirmation' => 'definitif-mdp-1',
        ])->assertNoContent();

        $this->login('awa@depot-a.test', 'definitif-mdp-1')->assertJsonPath('user.must_change_password', false);
    }

    // ---------------------------------------------------------------- isolation

    public function test_isolation_entre_depots(): void
    {
        $b = $this->makeTenant('depot-b');
        $this->makeUser($b, 'vendeur', 'secret@depot-b.test');
        $this->makeUser($b, 'caissier', 'autre@depot-b.test');   // ids 2 et 3, absents du dépôt A

        $emails = collect($this->asRole($this->t)->getJson('/api/v1/users')->json('data'))->pluck('email');
        $this->assertFalse($emails->contains('secret@depot-b.test'));

        $idB = (int) $b->run(fn () => User::where('email', 'secret@depot-b.test')->value('id'));
        $this->asRole($this->t)->getJson("/api/v1/users/{$idB}")->assertNotFound();
        $this->asRole($this->t)->postJson("/api/v1/users/{$idB}/deactivate")->assertNotFound();
        $this->asRole($this->t)->postJson("/api/v1/users/{$idB}/reset-password")->assertNotFound();

        $this->assertTrue($b->run(fn () => (bool) User::find($idB)->active));
    }
}
