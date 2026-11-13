<?php

namespace App\Tenancy;

use App\Models\Tenant;

interface TenantDatabaseProvisioner
{
    /** Prépare la base du tenant (vérification en mode manuel, création en mode auto). */
    public function prepare(Tenant $tenant): void;
}
