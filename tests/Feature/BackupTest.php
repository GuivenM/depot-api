<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\BackupService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TenantTestCase;

class BackupTest extends TenantTestCase
{
    private string $dir;
    private Tenant $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'depot-backup-test-'.bin2hex(random_bytes(4));
        config(['depot.backup.path' => $this->dir, 'depot.backup.remote_disk' => null]);

        $this->a = $this->makeTenant('depot-a');
        $this->makeProduct($this->a, ['name' => 'Béninoise 65cl'], crates: 5);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    // ---------------------------------------------------------------- aides

    private function svc(): BackupService
    {
        return app(BackupService::class);
    }

    private function backup(array $options = []): int
    {
        return Artisan::call('depot:backup', array_merge(['--tenant' => ['depot-a']], $options));
    }

    private function fileOf(string $name): string
    {
        $files = $this->svc()->files($name);
        $this->assertNotEmpty($files, "Aucune sauvegarde pour {$name}.");

        return $this->svc()->path($name).DIRECTORY_SEPARATOR.$files[0];
    }

    private function productCount(Tenant $tenant): int
    {
        return (int) $tenant->run(fn () => Product::count());
    }

    /** Crée un faux fichier de sauvegarde daté (le contenu n'a pas d'importance pour la rétention). */
    private function fake(string $name, string $stamp, string $ext = 'sql', string $suffix = ''): string
    {
        $dir = $this->svc()->path($name);
        @mkdir($dir, 0750, true);
        $file = "{$name}_{$stamp}{$suffix}.{$ext}.gz";
        file_put_contents($dir.DIRECTORY_SEPARATOR.$file, 'x');

        return $file;
    }

    // ---------------------------------------------------------------- sauvegarde

    public function test_la_sauvegarde_est_un_fichier_gzip_qui_contient_la_base(): void
    {
        $this->assertSame(0, $this->backup());

        $files = $this->svc()->files('depot-a');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^depot-a_\d{8}_\d{6}\.sqlite\.gz$/', $files[0]);

        $raw = gzdecode(file_get_contents($this->fileOf('depot-a')));
        $this->assertStringStartsWith("SQLite format 3\0", $raw);

        // Le contenu est une vraie base, avec nos données.
        $tmp = tempnam(sys_get_temp_dir(), 'bk').'.sqlite';
        file_put_contents($tmp, $raw);
        $pdo = new PDO('sqlite:'.$tmp);
        $this->assertSame(1, (int) $pdo->query('select count(*) from products')->fetchColumn());
        $this->assertGreaterThanOrEqual(1, (int) $pdo->query('select count(*) from users')->fetchColumn());
        $pdo = null;
        @unlink($tmp);
    }

    public function test_une_sauvegarde_par_depot_sans_melange(): void
    {
        $b = $this->makeTenant('depot-b');
        $this->makeProduct($b, ['name' => 'ZzProduitB1'], crates: 1);
        $this->makeProduct($b, ['name' => 'ZzProduitB2'], crates: 1);

        $this->assertSame(0, Artisan::call('depot:backup'));   // tous les dépôts (la base centrale en mémoire est ignorée)
        $this->assertStringContainsString('mémoire', Artisan::output());

        $this->assertCount(1, $this->svc()->files('depot-a'));
        $this->assertCount(1, $this->svc()->files('depot-b'));
        $this->assertNotSame($this->fileOf('depot-a'), $this->fileOf('depot-b'));

        $this->assertStringNotContainsString('ZzProduitB', (string) gzdecode(file_get_contents($this->fileOf('depot-a'))));
        $this->assertStringContainsString('ZzProduitB', (string) gzdecode(file_get_contents($this->fileOf('depot-b'))));
    }

    public function test_un_depot_en_echec_n_empeche_pas_les_autres_et_ne_laisse_aucun_fichier_partiel(): void
    {
        Tenant::create(['id' => 'casse', 'name' => 'Cassé', 'status' => 'active', 'tenancy_db_name' => 'testing_inexistant.sqlite']);

        $code = Artisan::call('depot:backup');

        $this->assertSame(1, $code);                                   // le cron verra l'échec
        $this->assertStringContainsString('ÉCHEC', Artisan::output());
        $this->assertCount(1, $this->svc()->files('depot-a'));        // l'autre dépôt est bien sauvegardé
        $this->assertCount(0, $this->svc()->files('casse'));
        $this->assertSame([], glob($this->dir.'/*/*.partial'));
    }

    public function test_les_depots_sans_base_sont_ignores_et_un_depot_inconnu_est_refuse(): void
    {
        Tenant::create(['id' => 'en-cours', 'name' => 'En cours', 'status' => 'provisioning', 'tenancy_db_name' => 'x.sqlite']);

        $this->assertSame(0, Artisan::call('depot:backup'));
        $this->assertCount(0, $this->svc()->files('en-cours'));

        $this->assertSame(1, Artisan::call('depot:backup', ['--tenant' => ['nimporte-quoi']]));
        $this->assertStringContainsString('introuvable', Artisan::output());
    }

    public function test_les_fichiers_corrompus_sont_detectes(): void
    {
        $this->backup();
        $file = $this->fileOf('depot-a');

        $this->svc()->verifyFile($file);   // sain : pas d'exception

        file_put_contents($file, substr(file_get_contents($file), 0, 40));   // tronqué
        $this->expectException(\RuntimeException::class);
        $this->svc()->verifyFile($file);
    }

    // ---------------------------------------------------------------- rétention

    public function test_selection_des_sauvegardes_a_garder(): void
    {
        $names = array_map(fn ($d) => "depot-a_{$d}_020000.sql.gz", [
            '20261121', '20261120', '20261119', '20261118',   // récentes
            '20261105', '20261101',                           // novembre
            '20261031', '20261015',                           // octobre
            '20260930', '20260905',                           // septembre
            '20260815', '20250101',                           // trop anciennes
        ]);

        $kept = $this->svc()->selectToKeep($names, 3, 3, Carbon::parse('2026-11-21 12:00:00', 'UTC'));

        sort($kept);
        $expected = array_map(fn ($d) => "depot-a_{$d}_020000.sql.gz", ['20260905', '20261015', '20261101', '20261119', '20261120', '20261121']);
        $this->assertSame($expected, $kept);   // 3 plus récentes + la première de nov., oct., sept.
    }

    public function test_prune_supprime_le_reste_et_epargne_les_sauvegardes_de_securite(): void
    {
        $safety = $this->fake('depot-a', '20261101_020000', 'sql', '_avant-restauration');
        foreach (['20261121', '20261120', '20261119', '20261118', '20261031'] as $d) {
            $this->fake('depot-a', "{$d}_020000");
        }

        $deleted = $this->svc()->prune('depot-a', 3, 0, Carbon::parse('2026-11-21', 'UTC'));

        sort($deleted);
        $this->assertSame(['depot-a_20261031_020000.sql.gz', 'depot-a_20261118_020000.sql.gz'], $deleted);
        $this->assertFileExists($this->svc()->path('depot-a').DIRECTORY_SEPARATOR.$safety);   // jamais supprimée
        $this->assertCount(3, $this->svc()->files('depot-a'));
    }

    public function test_la_sauvegarde_nettoie_les_anciennes(): void
    {
        foreach (['20200101', '20200102', '20200103'] as $d) {
            $this->fake('depot-a', "{$d}_020000", 'sqlite');
        }

        $this->assertSame(0, $this->backup(['--keep' => 1, '--months' => 0]));

        $files = $this->svc()->files('depot-a');
        $this->assertCount(1, $files);
        $this->assertStringNotContainsString('2020', $files[0]);   // la nouvelle est gardée
    }

    public function test_no_prune_conserve_tout(): void
    {
        $this->fake('depot-a', '20200101_020000', 'sqlite');

        $this->assertSame(0, $this->backup(['--keep' => 1, '--months' => 0, '--no-prune' => true]));

        $this->assertCount(2, $this->svc()->files('depot-a'));
    }

    // ---------------------------------------------------------------- contrôle

    public function test_le_controle_signale_les_bases_sans_sauvegarde_recente(): void
    {
        $this->assertSame(1, Artisan::call('depot:backup:status', ['--tenant' => ['depot-a']]));   // aucune
        $this->assertStringContainsString('AUCUNE SAUVEGARDE', Artisan::output());

        $this->backup();
        $this->assertSame(0, Artisan::call('depot:backup:status', ['--tenant' => ['depot-a']]));
        $this->assertStringContainsString('OK', Artisan::output());
    }

    public function test_le_controle_signale_une_sauvegarde_trop_ancienne(): void
    {
        $this->fake('depot-a', '20200101_020000', 'sqlite');

        $this->assertSame(1, Artisan::call('depot:backup:status', ['--tenant' => ['depot-a']]));
        $this->assertStringContainsString('TROP ANCIENNE', Artisan::output());

        $this->assertSame(0, Artisan::call('depot:backup:status', ['--tenant' => ['depot-a'], '--max-age' => 999999]));
    }

    // ---------------------------------------------------------------- restauration

    public function test_restauration_exige_force_puis_remet_l_etat_de_la_sauvegarde(): void
    {
        $this->backup();
        $name = basename($this->fileOf('depot-a'));

        $this->makeProduct($this->a, ['name' => 'Ajouté après la sauvegarde'], crates: 1);
        $this->assertSame(2, $this->productCount($this->a));

        // Sans --force : refus, rien n'est touché.
        $this->assertSame(1, Artisan::call('depot:restore', ['tenant' => 'depot-a', 'file' => $name]));
        $this->assertSame(2, $this->productCount($this->a));

        $this->assertSame(0, Artisan::call('depot:restore', ['tenant' => 'depot-a', 'file' => $name, '--force' => true]));
        $this->assertSame(1, $this->productCount($this->a));

        // L'état d'avant la restauration est conservé à part.
        $safety = glob($this->svc()->path('depot-a').DIRECTORY_SEPARATOR.'*_avant-restauration.sqlite.gz');
        $this->assertCount(1, $safety);
        $raw = gzdecode(file_get_contents($safety[0]));
        $this->assertStringContainsString('Ajouté après la sauvegarde', $raw);
    }

    public function test_restauration_refuse_un_fichier_ou_un_depot_inconnu(): void
    {
        $this->assertSame(1, Artisan::call('depot:restore', ['tenant' => 'depot-a', 'file' => 'inexistant.sqlite.gz', '--force' => true]));
        $this->assertSame(1, Artisan::call('depot:restore', ['tenant' => 'inconnu', 'file' => 'x', '--force' => true]));

        $this->backup();
        $file = $this->fileOf('depot-a');
        file_put_contents($file, 'pas du gzip');

        $this->assertSame(1, Artisan::call('depot:restore', ['tenant' => 'depot-a', 'file' => basename($file), '--force' => true]));
        $this->assertSame(1, $this->productCount($this->a));   // base intacte
    }

    // ---------------------------------------------------------------- hors du serveur

    public function test_copie_hors_du_serveur_et_nettoyage_distant(): void
    {
        Storage::fake('offsite');
        config(['depot.backup.remote_disk' => 'offsite']);

        Storage::disk('offsite')->put('depot-backups/depot-a/depot-a_20200101_020000.sqlite.gz', 'x');
        Storage::disk('offsite')->put('depot-backups/depot-a/depot-a_20200102_020000.sqlite.gz', 'x');

        $this->assertSame(0, $this->backup(['--keep' => 1, '--months' => 0]));

        $name = basename($this->fileOf('depot-a'));
        Storage::disk('offsite')->assertExists('depot-backups/depot-a/'.$name);
        Storage::disk('offsite')->assertMissing('depot-backups/depot-a/depot-a_20200101_020000.sqlite.gz');
        Storage::disk('offsite')->assertMissing('depot-backups/depot-a/depot-a_20200102_020000.sqlite.gz');
    }

    public function test_un_disque_distant_en_panne_est_signale_mais_la_copie_locale_reste(): void
    {
        config(['depot.backup.remote_disk' => 'disque-qui-n-existe-pas']);

        $this->assertSame(1, $this->backup());

        $this->assertCount(1, $this->svc()->files('depot-a'));
    }

    // ---------------------------------------------------------------- MySQL (commandes, sans serveur)

    public function test_les_commandes_mysql_ne_contiennent_jamais_le_mot_de_passe(): void
    {
        $config = ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'username' => 'u_depot', 'password' => 'S3cr3t!', 'database' => 'db_depot'];

        $dump = $this->svc()->mysqlDumpCommand($config);
        $client = $this->svc()->mysqlClientCommand($config);

        $this->assertStringNotContainsString('S3cr3t', implode(' ', $dump));
        $this->assertStringNotContainsString('S3cr3t', implode(' ', $client));
        $this->assertSame(['MYSQL_PWD' => 'S3cr3t!'], $this->svc()->processEnv($config));
        $this->assertSame([], $this->svc()->processEnv(array_merge($config, ['password' => ''])));

        $this->assertContains('--single-transaction', $dump);
        $this->assertContains('--no-tablespaces', $dump);
        $this->assertContains('--user=u_depot', $dump);
        $this->assertSame('db_depot', end($dump));
        $this->assertSame('db_depot', end($client));
    }
}
