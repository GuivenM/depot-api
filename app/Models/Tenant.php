<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    /** Le mot de passe de la base du dépôt est chiffré (APP_KEY) dans `tenants.data`. */
    protected function casts(): array
    {
        return array_merge(parent::casts(), ['tenancy_db_password' => 'encrypted']);
    }

    /** Colonnes réelles de la table `tenants` (le reste va dans la colonne JSON `data`). */
    public static function getCustomColumns(): array
    {
        return array_merge(parent::getCustomColumns(), ['name', 'status', 'plan']);
    }
}
