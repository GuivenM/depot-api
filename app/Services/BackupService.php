<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Sauvegardes des bases : une par dépôt + la base centrale.
 *
 * Fichiers : {dossier}/{nom}/{nom}_{AAAAMMJJ}_{HHMMSS}.{sql|sqlite}.gz (heure UTC).
 * Un fichier n'apparaît sous son nom définitif qu'une fois écrit ET vérifié : jamais de sauvegarde à moitié écrite.
 * Les mots de passe ne passent jamais en argument de commande (variable d'environnement du processus).
 */
class BackupService
{
    private const FILE_PATTERN = '/^(?<name>.+)_(?<date>\d{8})_(?<time>\d{6})\.(?<kind>sql|sqlite)\.gz$/';

    /** Statuts de dépôt qui n'ont pas (encore) de base exploitable. */
    public const NO_DATABASE = ['pending_db', 'provisioning', 'failed'];

    // ------------------------------------------------------------------ emplacements

    public function path(?string $name = null): string
    {
        $base = rtrim((string) config('depot.backup.path'), '/\\');

        return $name === null ? $base : $base.DIRECTORY_SEPARATOR.$this->safeName($name);
    }

    public function safeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
    }

    /** Noms de fichiers de sauvegarde d'une base, du plus récent au plus ancien (hors sauvegardes « avant restauration »). */
    public function files(string $name): array
    {
        $dir = $this->path($name);
        if (! is_dir($dir)) {
            return [];
        }

        $files = array_values(array_filter(scandir($dir) ?: [], fn ($f) => $this->parse($f) !== null));
        usort($files, fn ($a, $b) => $this->parse($b)['at'] <=> $this->parse($a)['at']);

        return $files;
    }

    /** @return array{file:string, at:Carbon, size:int}|null */
    public function latest(string $name): ?array
    {
        $file = $this->files($name)[0] ?? null;

        return $file === null ? null : [
            'file' => $file,
            'at'   => $this->parse($file)['at'],
            'size' => (int) filesize($this->path($name).DIRECTORY_SEPARATOR.$file),
        ];
    }

    /** @return array{at:Carbon, kind:string}|null */
    private function parse(string $filename): ?array
    {
        if (! preg_match(self::FILE_PATTERN, $filename, $m)) {
            return null;
        }

        return [
            'at'   => Carbon::createFromFormat('YmdHis', $m['date'].$m['time'], 'UTC'),
            'kind' => $m['kind'],
        ];
    }

    // ------------------------------------------------------------------ sauvegarde

    /** @return string chemin du fichier créé */
    public function backupTenant(Tenant $tenant, string $suffix = ''): string
    {
        return $this->dump($tenant->database()->connection(), (string) $tenant->getTenantKey(), $suffix);
    }

    /** Base centrale ; null si elle est en mémoire (tests) donc sans rien à sauvegarder. */
    public function backupCentral(): ?string
    {
        $config = config('database.connections.'.config('tenancy.database.central_connection', config('database.default')));

        if (($config['driver'] ?? null) === 'sqlite' && ($config['database'] ?? ':memory:') === ':memory:') {
            return null;
        }

        return $this->dump($config, 'central');
    }

    public function dump(array $config, string $name, string $suffix = ''): string
    {
        $driver = $config['driver'] ?? '';
        $kind = match ($driver) {
            'sqlite'          => 'sqlite',
            'mysql', 'mariadb' => 'sql',
            default           => throw new RuntimeException("Pilote de base non géré : {$driver}."),
        };

        $dir = $this->path($name);
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException("Impossible de créer le dossier {$dir}.");
        }

        $final = $dir.DIRECTORY_SEPARATOR.$this->safeName($name).'_'.now('UTC')->format('Ymd_His').$suffix.'.'.$kind.'.gz';
        $partial = $final.'.partial';

        try {
            $kind === 'sqlite' ? $this->gzipSqlite($config, $partial) : $this->gzipMysqlDump($config, $partial);
            $this->verifyContent($partial, $kind);
            if (! rename($partial, $final)) {
                throw new RuntimeException('Impossible de finaliser le fichier de sauvegarde.');
            }
        } finally {
            if (is_file($partial)) {
                @unlink($partial);
            }
        }

        @chmod($final, 0640);

        return $final;
    }

    private function gzipSqlite(array $config, string $dest): void
    {
        $source = (string) ($config['database'] ?? '');
        if ($source === '' || ! is_file($source)) {
            throw new RuntimeException("Fichier de base introuvable : {$source}.");
        }

        $in = fopen($source, 'rb');
        $out = gzopen($dest, 'wb6');
        if ($in === false || $out === false) {
            throw new RuntimeException('Impossible de lire la base ou d\'écrire la sauvegarde.');
        }

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
    }

    private function gzipMysqlDump(array $config, string $dest): void
    {
        $this->assertBinary((string) config('depot.backup.mysqldump'));

        $out = gzopen($dest, 'wb6');
        if ($out === false) {
            throw new RuntimeException('Impossible d\'écrire la sauvegarde.');
        }

        $stderr = '';
        $process = new Process($this->mysqlDumpCommand($config), null, $this->processEnv($config), null, 3600);
        $process->run(function ($type, $buffer) use ($out, &$stderr) {
            $type === Process::OUT ? gzwrite($out, $buffer) : $stderr .= $buffer;
        });
        gzclose($out);

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump a échoué : '.trim($stderr));
        }
    }

    /** Commande mysqldump : jamais de mot de passe dans les arguments (visibles par les autres comptes du serveur). */
    public function mysqlDumpCommand(array $config): array
    {
        return [
            (string) config('depot.backup.mysqldump'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            '--single-transaction', '--quick', '--skip-lock-tables',
            '--no-tablespaces',   // évite l'erreur de privilège PROCESS sur l'hébergement mutualisé
            '--default-character-set=utf8mb4',
            (string) $config['database'],
        ];
    }

    public function mysqlClientCommand(array $config): array
    {
        return [
            (string) config('depot.backup.mysql'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            '--default-character-set=utf8mb4',
            (string) $config['database'],
        ];
    }

    public function processEnv(array $config): array
    {
        $password = (string) ($config['password'] ?? '');

        return $password === '' ? [] : ['MYSQL_PWD' => $password];
    }

    private function assertBinary(string $binary): void
    {
        if (! is_file($binary) && (new ExecutableFinder)->find($binary) === null) {
            throw new RuntimeException("« {$binary} » est introuvable sur ce serveur : renseignez MYSQLDUMP_PATH / MYSQL_CLIENT_PATH dans .env (ou demandez-le à l'hébergeur).");
        }
    }

    // ------------------------------------------------------------------ vérification

    /** Relit tout le fichier (contrôle d'intégrité gzip) et vérifie qu'il ressemble bien à une sauvegarde complète. */
    public function verifyFile(string $file): void
    {
        $parsed = $this->parse(basename($file));
        if ($parsed === null) {
            throw new RuntimeException('Nom de fichier de sauvegarde non reconnu.');
        }

        $this->verifyContent($file, $parsed['kind']);
    }

    private function verifyContent(string $file, string $kind): void
    {
        $in = @fopen($file, 'rb');
        if ($in === false) {
            throw new RuntimeException('Sauvegarde illisible.');
        }

        $head = '';
        $tail = '';
        $size = 0;

        try {
            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            while (! feof($in)) {
                $raw = fread($in, 1 << 20);
                if ($raw === false || $raw === '') {
                    break;
                }
                $chunk = inflate_add($inflate, $raw);
                if ($chunk === false) {
                    throw new RuntimeException('données invalides');
                }
                if ($size === 0) {
                    $head = substr($chunk, 0, 16);
                }
                $size += strlen($chunk);
                $tail = substr($tail.$chunk, -4096);
            }
            $complete = inflate_get_status($inflate) === ZLIB_STREAM_END;   // la somme de contrôle gzip est vérifiée à la fin du flux
        } catch (\Throwable $e) {
            throw new RuntimeException('Sauvegarde corrompue ('.$e->getMessage().').');
        } finally {
            fclose($in);
        }

        if (! $complete) {
            throw new RuntimeException('Sauvegarde incomplète ou tronquée.');
        }
        if ($size === 0) {
            throw new RuntimeException('Sauvegarde vide.');
        }
        if ($kind === 'sqlite' && ! str_starts_with($head, "SQLite format 3\0")) {
            throw new RuntimeException('Ce fichier n\'est pas une base SQLite valide.');
        }
        if ($kind === 'sql' && ! str_contains($tail, 'Dump completed')) {
            throw new RuntimeException('Sauvegarde MySQL incomplète (fin de fichier absente).');
        }
    }

    // ------------------------------------------------------------------ rétention

    /**
     * Fichiers à GARDER : les $keep plus récents, plus la plus ancienne sauvegarde de chacun des $months derniers mois
     * (mois en cours inclus). Le reste est supprimé.
     *
     * @param  string[]  $filenames  noms de fichiers de sauvegarde (reconnus par le motif)
     * @return string[]
     */
    public function selectToKeep(array $filenames, int $keep, int $months, CarbonInterface $now): array
    {
        $dated = [];
        foreach ($filenames as $f) {
            if ($p = $this->parse($f)) {
                $dated[$f] = $p['at'];
            }
        }
        asort($dated);                                   // du plus ancien au plus récent
        $kept = array_slice(array_keys($dated), max(0, count($dated) - max(0, $keep)));

        if ($months > 0) {
            $windowStart = Carbon::instance($now)->utc()->startOfMonth()->subMonths($months - 1);
            $seen = [];
            foreach ($dated as $f => $at) {
                if ($at->lt($windowStart)) {
                    continue;
                }
                $month = $at->format('Ym');
                if (! isset($seen[$month])) {
                    $seen[$month] = true;
                    $kept[] = $f;
                }
            }
        }

        return array_values(array_unique($kept));
    }

    /** @return string[] noms supprimés */
    public function prune(string $name, ?int $keep = null, ?int $months = null, ?CarbonInterface $now = null): array
    {
        $files = $this->files($name);
        $kept = $this->selectToKeep($files, $keep ?? (int) config('depot.backup.keep'), $months ?? (int) config('depot.backup.months'), $now ?? now('UTC'));

        $deleted = [];
        foreach (array_diff($files, $kept) as $f) {
            if (@unlink($this->path($name).DIRECTORY_SEPARATOR.$f)) {
                $deleted[] = $f;
            }
        }

        return $deleted;
    }

    // ------------------------------------------------------------------ copie hors du serveur

    public function remoteDisk(): ?string
    {
        $disk = config('depot.backup.remote_disk');

        return $disk ? (string) $disk : null;
    }

    public function copyOffsite(string $file, string $name): void
    {
        $disk = Storage::disk($this->remoteDisk());
        $target = trim((string) config('depot.backup.remote_path'), '/').'/'.$this->safeName($name).'/'.basename($file);

        $stream = fopen($file, 'rb');
        try {
            if (! $disk->writeStream($target, $stream)) {
                throw new RuntimeException("Envoi vers le disque « {$this->remoteDisk()} » refusé.");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @return string[] noms supprimés sur le disque distant */
    public function pruneOffsite(string $name, ?int $keep = null, ?int $months = null): array
    {
        $disk = Storage::disk($this->remoteDisk());
        $dir = trim((string) config('depot.backup.remote_path'), '/').'/'.$this->safeName($name);

        $files = array_map('basename', $disk->files($dir));
        $files = array_values(array_filter($files, fn ($f) => $this->parse($f) !== null));
        $kept = $this->selectToKeep($files, $keep ?? (int) config('depot.backup.keep'), $months ?? (int) config('depot.backup.months'), now('UTC'));

        $deleted = [];
        foreach (array_diff($files, $kept) as $f) {
            $disk->delete($dir.'/'.$f);
            $deleted[] = $f;
        }

        return $deleted;
    }

    // ------------------------------------------------------------------ restauration

    /** Remplace le contenu de la base du dépôt par celui de la sauvegarde. Une sauvegarde de sécurité est faite avant. */
    public function restoreTenant(Tenant $tenant, string $file): string
    {
        $this->verifyFile($file);

        $config = $tenant->database()->connection();
        $kind = $this->parse(basename($file))['kind'];
        $expected = ($config['driver'] ?? '') === 'sqlite' ? 'sqlite' : 'sql';
        if ($kind !== $expected) {
            throw new RuntimeException("Cette sauvegarde ({$kind}) ne correspond pas au type de base du dépôt ({$expected}).");
        }

        $safety = $this->backupTenant($tenant, '_avant-restauration');

        if ($expected === 'sqlite') {
            $this->restoreSqlite($config, $file);
        } else {
            $this->restoreMysql($config, $file);
        }

        return $safety;
    }

    private function restoreSqlite(array $config, string $file): void
    {
        $target = (string) $config['database'];

        foreach (['tenant', 'tenant_template'] as $connection) {   // ferme les poignées ouvertes sur le fichier
            try {
                DB::purge($connection);
            } catch (\Throwable) {
            }
        }

        $tmp = $target.'.restoring';
        $in = gzopen($file, 'rb');
        $out = fopen($tmp, 'wb');
        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1 << 20));
        }
        gzclose($in);
        fclose($out);

        $ok = copy($tmp, $target);
        @unlink($tmp);
        if (! $ok) {
            throw new RuntimeException('Impossible d\'écrire la base restaurée.');
        }
    }

    private function restoreMysql(array $config, string $file): void
    {
        $this->assertBinary((string) config('depot.backup.mysql'));

        $input = fopen('compress.zlib://'.$file, 'rb');
        $process = new Process($this->mysqlClientCommand($config), null, $this->processEnv($config), $input, 3600);
        $process->run();
        fclose($input);

        if (! $process->isSuccessful()) {
            throw new RuntimeException('La restauration MySQL a échoué : '.trim($process->getErrorOutput()));
        }
    }
}
