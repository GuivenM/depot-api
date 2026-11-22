<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class CreateTenant extends Command
{
    protected $signature = 'depot:tenant:create
        {id : code d\'entreprise (minuscules, chiffres et tirets), utilisé dans l\'en-tête X-Tenant}
        {--name= : nom affiché du dépôt}
        {--db= : nom de la base MySQL créée dans cPanel}
        {--db-user= : utilisateur MySQL de cette base}
        {--db-password= : mot de passe MySQL (sinon demandé, ce qui évite de le laisser dans l\'historique du terminal)}';

    protected $description = 'Enregistre un nouveau dépôt (étape avant depot:provision) sans passer par tinker';

    /** `central` est le nom du dossier de sauvegarde de la base centrale. */
    private const RESERVED = ['central'];

    public function handle(): int
    {
        $id = (string) $this->argument('id');

        if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id) || strlen($id) > 40) {
            $this->error('Code invalide : 40 caractères maximum, minuscules, chiffres et tirets seulement (ex. chez-paul).');

            return self::FAILURE;
        }
        if (in_array($id, self::RESERVED, true)) {
            $this->error("« {$id} » est un nom réservé.");

            return self::FAILURE;
        }
        if (Tenant::find($id)) {
            $this->error("Le dépôt « {$id} » existe déjà.");

            return self::FAILURE;
        }

        $attributes = ['id' => $id, 'name' => $this->option('name') ?: $id, 'status' => 'pending_db'];

        if (config('depot.provisioner') === 'manual') {
            $db = $this->option('db');
            $user = $this->option('db-user');
            if (! $db || ! $user) {
                $this->error('Mode manuel : --db et --db-user sont obligatoires (base et utilisateur créés dans cPanel > Bases de données MySQL).');

                return self::FAILURE;
            }

            $password = $this->option('db-password') ?? $this->secret('Mot de passe de la base MySQL');
            if ($password === null || $password === '') {
                $this->error('Mot de passe de base obligatoire.');

                return self::FAILURE;
            }

            $attributes += ['tenancy_db_name' => $db, 'tenancy_db_username' => $user, 'tenancy_db_password' => $password];
        }

        Tenant::create($attributes);

        $this->info("Dépôt « {$id} » enregistré (statut pending_db).");
        $this->line("Étape suivante : php artisan depot:provision {$id} --email=gerant@exemple.com --name=\"Nom du gérant\"");

        return self::SUCCESS;
    }
}
