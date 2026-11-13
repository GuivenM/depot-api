<?php

namespace App\Tenancy;

use App\Models\Tenant;

/** Mode VPS : Tenancy crée lui-même la base et l'utilisateur MySQL. */
class AutoProvisioner implements TenantDatabaseProvisioner
{
    public function prepare(Tenant $tenant): void
    {
        $tenant->database()->makeCredentials();
        $tenant->database()->manager()->createDatabase($tenant);
    }
}
