<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantDatabaseProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

class ProvisionTenant extends Command
{
    protected $signature = 'depot:provision {tenant : id/code du tenant} {--email= : email du gérant} {--name=Gérant}';
    protected $description = 'Prépare la base d\'un dépôt, lance ses migrations et crée le compte gérant';

    public function handle(TenantDatabaseProvisioner $provisioner): int
    {
        $tenant = Tenant::findOrFail($this->argument('tenant'));
        $tenant->update(['status' => 'provisioning']);

        try {
            $provisioner->prepare($tenant);

            Artisan::call('tenants:migrate', ['--tenants' => [$tenant->id], '--force' => true]);

            $password = null;
            if ($email = $this->option('email')) {
                $name = $this->option('name');
                $password = $tenant->run(function () use ($email, $name) {
                    if (User::where('email', $email)->exists()) {
                        return null; // commande rejouable : on ne recrée pas le gérant
                    }
                    $plain = Str::password(12, symbols: false);
                    (new User)->forceFill([
                        'name' => $name, 'email' => $email,
                        'password' => $plain, 'role' => 'gerant', 'active' => true,
                    ])->save();
                    return $plain;
                });
            }

            $tenant->update(['status' => 'active']);
            $this->info("Tenant {$tenant->id} actif.");
            if ($password) {
                $this->warn("Gérant {$email} — mot de passe initial : {$password}");
            }
            return self::SUCCESS;
        } catch (Throwable $e) {
            $tenant->update(['status' => 'failed']);
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
