<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\BackupService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class CheckEnvironment extends Command
{
    protected $signature = 'depot:check {--tenants : contrôle aussi la base de chaque dépôt (tables présentes, gérant actif)}';
    protected $description = 'Contrôle l\'installation (PHP, extensions, droits, .env, base, sauvegardes, cron) ; code 1 si un point bloquant est trouvé';

    /** Tables que doit contenir la base de chaque dépôt une fois migrée. */
    private const TENANT_TABLES = ['users', 'personal_access_tokens', 'products', 'customers', 'sales', 'cash_sessions', 'audit_logs'];

    private int $fails = 0;
    private int $warns = 0;

    public function handle(): int
    {
        $this->line('Contrôle de l\'installation — environnement « '.app()->environment().' »');
        $this->newLine();

        $this->checkPhp();
        $this->checkExtensions();
        $this->checkEnvironment();
        $this->checkCors();
        $this->checkCache();
        $this->checkPaths();
        $this->checkCentralDatabase();
        $this->checkPdf();
        $this->checkMysqlTools();
        $this->checkScheduler();
        $this->checkBackups();

        if ($this->option('tenants')) {
            $this->checkTenants();
        }

        $this->newLine();
        if ($this->fails > 0) {
            $this->error("{$this->fails} problème(s) bloquant(s), {$this->warns} avertissement(s).");

            return self::FAILURE;
        }

        $this->info($this->warns > 0 ? "Aucun problème bloquant, {$this->warns} avertissement(s)." : 'Tout est en ordre.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ résultats

    private function ok(string $label, string $detail = ''): void
    {
        $this->line('  <fg=green>[OK]</>   '.$label.($detail !== '' ? " — {$detail}" : ''));
    }

    private function warn_(string $label, string $detail = ''): void
    {
        $this->warns++;
        $this->line('  <fg=yellow>[WARN]</> '.$label.($detail !== '' ? " — {$detail}" : ''));
    }

    /** (Ne pas l'appeler « fail » : Illuminate\Console\Command a déjà une méthode publique de ce nom.) */
    private function problem(string $label, string $detail = ''): void
    {
        $this->fails++;
        $this->line('  <fg=red>[FAIL]</> '.$label.($detail !== '' ? " — {$detail}" : ''));
    }

    private function prod(): bool
    {
        return app()->isProduction();
    }

    // ------------------------------------------------------------------ contrôles

    private function checkPhp(): void
    {
        version_compare(PHP_VERSION, '8.3.0', '>=')
            ? $this->ok('PHP '.PHP_VERSION, php_sapi_name())
            : $this->problem('PHP '.PHP_VERSION, 'PHP 8.3 minimum requis (cPanel > Select PHP Version)');
    }

    private function checkExtensions(): void
    {
        $required = ['pdo', 'mbstring', 'openssl', 'zlib', 'fileinfo', 'ctype', 'tokenizer', 'xml', 'dom'];

        foreach (['database.default', 'tenancy.database.template_tenant_connection'] as $key) {
            $driver = config('database.connections.'.config($key).'.driver');
            if ($driver) {
                $required[] = 'pdo_'.($driver === 'mariadb' ? 'mysql' : $driver);
            }
        }

        $missing = array_values(array_filter(array_unique($required), fn ($e) => ! extension_loaded($e)));

        $missing === []
            ? $this->ok('Extensions PHP', implode(', ', array_unique($required)))
            : $this->problem('Extensions PHP manquantes', implode(', ', $missing).' (cPanel > Select PHP Version > Extensions)');

        function_exists('proc_open')
            ? $this->ok('proc_open disponible', 'nécessaire aux sauvegardes MySQL et au planificateur')
            : $this->warn_('proc_open désactivé', 'sauvegardes MySQL et planificateur impossibles : à demander à l\'hébergeur');
    }

    private function checkEnvironment(): void
    {
        $prod = $this->prod();

        config('app.key')
            ? $this->ok('APP_KEY définie', 'à sauvegarder hors du serveur')
            : $this->problem('APP_KEY vide', 'php artisan key:generate');

        if ($prod) {
            config('app.debug')
                ? $this->problem('APP_DEBUG=true en production', 'expose le code et les accès à la base dans les erreurs : mettre APP_DEBUG=false')
                : $this->ok('APP_DEBUG désactivé');

            str_starts_with((string) config('app.url'), 'https://')
                ? $this->ok('APP_URL en https')
                : $this->warn_('APP_URL n\'est pas en https', (string) config('app.url'));
        } else {
            $this->ok('Environnement non production', 'APP_DEBUG='.(config('app.debug') ? 'true' : 'false'));
        }

        config('app.timezone') === 'UTC'
            ? $this->ok('Fuseau de l\'application : UTC')
            : $this->problem('Le fuseau de l\'application doit rester UTC', 'les dates sont stockées en UTC et converties à l\'affichage');
    }

    private function checkCors(): void
    {
        $origins = (array) config('cors.allowed_origins');

        if (in_array('*', $origins, true)) {
            $this->prod()
                ? $this->problem('CORS ouvert à tous les sites (*)', 'lister le front dans CORS_ALLOWED_ORIGINS')
                : $this->warn_('CORS ouvert à tous les sites (*)');

            return;
        }

        $origins === []
            ? $this->warn_('CORS : aucun site autorisé', 'un front sur un autre domaine sera bloqué par le navigateur : CORS_ALLOWED_ORIGINS')
            : $this->ok('CORS', implode(', ', $origins));
    }

    private function checkCache(): void
    {
        if (config('cache.limiter') === 'database') {
            $this->problem('Limiteur de débit sur le cache « database »', 'inutilisable dans la base d\'un dépôt : RATE_LIMITER_STORE=file');
        } else {
            $this->ok('Limiteur de débit', 'cache « '.config('cache.limiter').' »');
        }

        config('cache.default') === 'database'
            ? $this->warn_('CACHE_STORE=database', 'préférer CACHE_STORE=file (la table cache n\'existe pas dans la base d\'un dépôt)')
            : $this->ok('Cache par défaut', (string) config('cache.default'));
    }

    private function checkPaths(): void
    {
        $paths = [
            'storage'        => storage_path(),
            'storage/logs'   => storage_path('logs'),
            'storage/framework' => storage_path('framework'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $bad = [];
        foreach ($paths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $bad[] = $label;
            }
        }
        $bad === []
            ? $this->ok('Dossiers inscriptibles', implode(', ', array_keys($paths)))
            : $this->problem('Dossiers non inscriptibles', implode(', ', $bad).' (chmod -R ug+rwX)');

        is_dir(storage_path('fonts')) && is_writable(storage_path('fonts'))
            ? $this->ok('storage/fonts (cache des PDF)')
            : $this->warn_('storage/fonts absent ou non inscriptible', 'le rendu des PDF peut échouer');

        $backup = (new BackupService)->path();
        $probe = is_dir($backup) ? $backup : dirname($backup);
        is_dir($probe) && is_writable($probe)
            ? $this->ok('Dossier des sauvegardes', $backup)
            : $this->problem('Dossier des sauvegardes non inscriptible', $backup);

        $public = realpath(public_path());
        $real = realpath($probe);
        if ($public && $real && str_starts_with($real, $public)) {
            $this->problem('Les sauvegardes sont dans le dossier public', 'elles seraient téléchargeables : changer BACKUP_PATH');
        }
    }

    /** Tables qui ne sont pas en InnoDB (MySQL/MariaDB seulement) : sans InnoDB, pas de transactions ni de clés étrangères. */
    public static function nonInnoDbTables(): array
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }

        return array_map(
            fn ($row) => (string) ($row->table_name ?? $row->TABLE_NAME),
            DB::select("select table_name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE' and engine <> 'InnoDB'")
        );
    }

    private function checkCentralDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            if (! Schema::hasTable('tenants')) {
                $this->problem('Base centrale sans table « tenants »', 'php artisan migrate --force');

                return;
            }

            $myisam = self::nonInnoDbTables();
            $myisam === []
                ? $this->ok('Base centrale', 'table des dépôts présente')
                : $this->problem('Base centrale : tables hors InnoDB', implode(', ', $myisam).' (transactions impossibles, voir DEPLOIEMENT.md)');
        } catch (Throwable $e) {
            $this->problem('Base centrale inaccessible', $e->getMessage());
        }
    }

    private function checkPdf(): void
    {
        class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)
            ? $this->ok('Bibliothèque PDF (dompdf)')
            : $this->problem('Bibliothèque PDF absente', 'composer install (ou composer require barryvdh/laravel-dompdf)');
    }

    private function checkMysqlTools(): void
    {
        $driver = config('database.connections.'.config('tenancy.database.template_tenant_connection').'.driver');
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            $this->ok('mysqldump', 'non nécessaire (bases des dépôts non MySQL)');

            return;
        }

        foreach (['mysqldump' => 'fail', 'mysql' => 'warn'] as $key => $severity) {
            $bin = (string) config('depot.backup.'.$key);
            $found = is_file($bin) || (new ExecutableFinder)->find($bin) !== null;

            if ($found) {
                $this->ok($bin.' trouvé', $key === 'mysqldump' ? 'sauvegardes' : 'restauration');
            } else {
                $msg = "« {$bin} » introuvable".($key === 'mysqldump' ? ' : aucune sauvegarde possible' : ' : restauration impossible');
                $hint = 'renseigner '.($key === 'mysqldump' ? 'MYSQLDUMP_PATH' : 'MYSQL_CLIENT_PATH').' (chemin complet)';
                $severity === 'fail' ? $this->problem($msg, $hint) : $this->warn_($msg, $hint);
            }
        }
    }

    private function checkScheduler(): void
    {
        $file = storage_path('framework/schedule-heartbeat');
        $at = is_file($file) ? rescue(fn () => Carbon::parse(trim((string) file_get_contents($file))), null, false) : null;

        if ($at === null) {
            $this->warn_('Planificateur jamais exécuté', 'créer la tâche cron `php artisan schedule:run` toutes les 5 minutes');

            return;
        }

        $minutes = (int) floor($at->diffInMinutes(now(), true));
        $minutes <= 15
            ? $this->ok('Planificateur (cron)', "dernier passage il y a {$minutes} min")
            : $this->warn_('Planificateur arrêté', "dernier passage il y a {$minutes} min : vérifier la tâche cron");
    }

    private function checkBackups(): void
    {
        $backups = new BackupService;
        $latest = $backups->latest('central');

        if (! $this->prod()) {
            $this->ok('Sauvegardes', 'non contrôlées hors production');

            return;
        }

        if ($latest === null) {
            $this->warn_('Aucune sauvegarde de la base centrale', 'php artisan depot:backup');
        } else {
            $hours = (int) floor($latest['at']->diffInHours(now('UTC'), true));
            $hours <= 26
                ? $this->ok('Sauvegarde centrale récente', "il y a {$hours} h")
                : $this->warn_('Sauvegarde centrale ancienne', "il y a {$hours} h");
        }

        $backups->remoteDisk()
            ? $this->ok('Copie hors serveur', 'disque « '.$backups->remoteDisk().' »')
            : $this->warn_('Pas de copie hors serveur', 'définir BACKUP_REMOTE_DISK : sans elle, une panne emporte aussi les sauvegardes');
    }

    private function checkTenants(): void
    {
        $this->newLine();
        $this->line('Dépôts :');

        $tenants = Tenant::query()->whereNotIn('status', BackupService::NO_DATABASE)->orderBy('id')->get();
        if ($tenants->isEmpty()) {
            $this->warn_('Aucun dépôt actif', 'php artisan depot:tenant:create puis depot:provision');

            return;
        }

        foreach ($tenants as $tenant) {
            $id = (string) $tenant->getTenantKey();

            try {
                $report = $tenant->run(function () {
                    $missing = array_values(array_filter(self::TENANT_TABLES, fn ($t) => ! Schema::hasTable($t)));

                    return [
                        'missing'  => $missing,
                        'myisam'   => self::nonInnoDbTables(),
                        'managers' => $missing ? 0 : (int) DB::table('users')->where('role', 'gerant')->where('active', true)->count(),
                    ];
                });
            } catch (Throwable $e) {
                $this->problem("{$id} : base inaccessible", $e->getMessage());
                continue;
            }

            if ($report['myisam']) {
                $this->problem("{$id} : tables hors InnoDB", implode(', ', $report['myisam']).' : sans InnoDB les ventes ne sont pas protégées par des transactions. Base neuve : php artisan tenants:migrate-fresh --tenants='.$id.' (efface tout) ; sinon voir DEPLOIEMENT.md');
            } elseif ($report['missing']) {
                $this->problem("{$id} : tables manquantes", implode(', ', $report['missing']).' → php artisan tenants:migrate --tenants='.$id);
            } elseif ($report['managers'] === 0) {
                $this->warn_("{$id} : aucun gérant actif", 'personne ne peut administrer ce dépôt');
            } else {
                $this->ok($id, 'base migrée, '.$report['managers'].' gérant(s) actif(s)'.($tenant->status !== 'active' ? ' — statut « '.$tenant->status.' »' : ''));
            }
        }
    }
}
