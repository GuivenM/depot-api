<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** La base est créée à la main (cPanel) : on vérifie seulement que les identifiants marchent. */
class ManualProvisioner implements TenantDatabaseProvisioner
{
    public function prepare(Tenant $tenant): void
    {
        config(['database.connections.tenant_check' => array_merge(
            config('database.connections.mysql'),
            [
                'database' => $tenant->tenancy_db_name,
                'username' => $tenant->tenancy_db_username,
                'password' => $tenant->tenancy_db_password,
            ]
        )]);

        try {
            DB::connection('tenant_check')->getPdo(); // lève une exception si la base/les identifiants sont faux
        } finally {
            DB::purge('tenant_check');
        }
    }
}
