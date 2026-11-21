<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\BackupService;
use App\Support\Fmt;
use Illuminate\Console\Command;

class BackupStatus extends Command
{
    protected $signature = 'depot:backup:status
        {--tenant=* : id des dépôts à contrôler (défaut : tous, plus la base centrale)}
        {--max-age=26 : âge maximal toléré de la dernière sauvegarde, en heures}';

    protected $description = 'Contrôle que chaque base a une sauvegarde récente ; code de sortie 1 sinon (à brancher sur une supervision)';

    public function handle(BackupService $backups): int
    {
        $maxAge = (int) $this->option('max-age');
        $ids = array_filter((array) $this->option('tenant'));

        $names = Tenant::query()->whereNotIn('status', BackupService::NO_DATABASE)
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->pluck('id')->all();
        if (! $ids) {
            $names[] = 'central';
        }

        $rows = [];
        $problems = 0;

        foreach ($names as $name) {
            $latest = $backups->latest($name);

            if ($latest === null) {
                $rows[] = [$name, '—', '—', 'AUCUNE SAUVEGARDE'];
                $problems++;
                continue;
            }

            $hours = (int) floor($latest['at']->diffInHours(now('UTC'), true));
            $stale = $hours > $maxAge;
            $problems += $stale ? 1 : 0;

            $rows[] = [$name, Fmt::date($latest['at']), $latest['file'], $stale ? "TROP ANCIENNE ({$hours} h)" : "OK ({$hours} h)"];
        }

        $this->table(['Base', 'Dernière sauvegarde', 'Fichier', 'État'], $rows);

        if ($problems > 0) {
            $this->error("{$problems} base(s) sans sauvegarde récente.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
