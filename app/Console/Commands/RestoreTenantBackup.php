<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

class RestoreTenantBackup extends Command
{
    protected $signature = 'depot:restore
        {tenant : id du dépôt}
        {file : nom du fichier de sauvegarde (dans le dossier du dépôt) ou chemin complet}
        {--force : confirme le remplacement de TOUTES les données actuelles du dépôt}';

    protected $description = 'Restaure la base d\'un dépôt depuis une sauvegarde (une sauvegarde de sécurité est faite juste avant)';

    public function handle(BackupService $backups): int
    {
        $tenant = Tenant::find($this->argument('tenant'));
        if (! $tenant) {
            $this->error('Dépôt introuvable.');

            return self::FAILURE;
        }

        $file = $this->argument('file');
        if (! is_file($file)) {
            $file = $backups->path($tenant->getTenantKey()).DIRECTORY_SEPARATOR.basename($file);
        }
        if (! is_file($file)) {
            $this->error('Fichier de sauvegarde introuvable : '.$this->argument('file'));

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn('Cette commande REMPLACE toutes les données actuelles du dépôt « '.$tenant->getTenantKey().' » par celles de :');
            $this->line('  '.basename($file));
            $this->warn('Une sauvegarde de sécurité est faite juste avant. Relancez avec --force pour confirmer.');

            return self::FAILURE;
        }

        try {
            tenancy()->end();
            $safety = $backups->restoreTenant($tenant, $file);
        } catch (Throwable $e) {
            $this->error('Restauration impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Dépôt restauré depuis '.basename($file).'.');
        $this->line('Sauvegarde de sécurité de l\'état précédent : '.basename($safety));
        $this->line('Si le schéma a changé depuis cette sauvegarde, lancez : php artisan tenants:migrate --tenants='.$tenant->getTenantKey());

        return self::SUCCESS;
    }
}
