<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupDatabases extends Command
{
    protected $signature = 'depot:backup
        {--tenant=* : id des dépôts à sauvegarder (défaut : tous, plus la base centrale)}
        {--central : sauvegarder uniquement la base centrale}
        {--keep= : nombre de sauvegardes récentes à garder par base}
        {--months= : nombre de mois dont on garde la première sauvegarde}
        {--no-prune : ne supprime aucune ancienne sauvegarde}';

    protected $description = 'Sauvegarde (compressée et vérifiée) la base de chaque dépôt et la base centrale ; code de sortie 1 si une sauvegarde échoue';

    public function handle(BackupService $backups): int
    {
        $base = $backups->path();
        if (! is_dir($base) && ! mkdir($base, 0750, true) && ! is_dir($base)) {
            $this->error("Impossible de créer {$base}.");

            return self::FAILURE;
        }

        // Une seule sauvegarde à la fois (cron + lancement manuel).
        $lock = fopen($base.DIRECTORY_SEPARATOR.'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Une sauvegarde est déjà en cours.');

            return self::FAILURE;
        }

        $keep = $this->option('keep') !== null ? (int) $this->option('keep') : null;
        $months = $this->option('months') !== null ? (int) $this->option('months') : null;
        $remote = $backups->remoteDisk();

        $targets = $this->targets();
        if ($targets === null) {
            return self::FAILURE;
        }

        $rows = [];
        $failed = 0;

        foreach ($targets as $name => $run) {
            try {
                $file = $run();

                if ($file === null) {
                    $rows[] = [$name, 'ignorée', '', 'base en mémoire, rien à sauvegarder'];
                    continue;
                }

                $note = '';
                if ($remote) {
                    $backups->copyOffsite($file, $name);
                    $note = "copiée vers « {$remote} »";
                }

                if (! $this->option('no-prune')) {
                    $removed = count($backups->prune($name, $keep, $months));
                    if ($remote) {
                        $removed += count($backups->pruneOffsite($name, $keep, $months));
                    }
                    $note = trim($note.($removed ? " ; {$removed} ancienne(s) supprimée(s)" : ''), ' ;');
                }

                $rows[] = [$name, 'OK', $this->size((int) filesize($file)), $note];
            } catch (Throwable $e) {
                $failed++;
                $rows[] = [$name, 'ÉCHEC', '', $e->getMessage()];
            }
        }

        flock($lock, LOCK_UN);
        fclose($lock);

        $this->table(['Base', 'Résultat', 'Taille', 'Détail'], $rows);

        if ($failed > 0) {
            $this->error("{$failed} sauvegarde(s) en échec.");

            return self::FAILURE;
        }

        $this->info(count($rows).' base(s) traitée(s).');

        return self::SUCCESS;
    }

    /** @return array<string, callable>|null nom => fonction de sauvegarde ; null si la demande est invalide */
    private function targets(): ?array
    {
        $backups = app(BackupService::class);

        if ($this->option('central')) {
            return ['central' => fn () => $backups->backupCentral()];
        }

        $ids = array_filter((array) $this->option('tenant'));
        $query = Tenant::query()->whereNotIn('status', BackupService::NO_DATABASE)->orderBy('id');

        if ($ids) {
            $tenants = $query->whereIn('id', $ids)->get();
            $unknown = array_diff($ids, $tenants->pluck('id')->all());
            if ($unknown) {
                $this->error('Dépôt(s) introuvable(s) ou sans base : '.implode(', ', $unknown));

                return null;
            }
        } else {
            $tenants = $query->get();
        }

        $targets = [];
        foreach ($tenants as $tenant) {
            $targets[$tenant->getTenantKey()] = fn () => $backups->backupTenant($tenant);
        }
        if (! $ids) {
            $targets['central'] = fn () => $backups->backupCentral();
        }

        return $targets;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' Mo' : max(1, (int) round($bytes / 1024)).' Ko';
    }
}
