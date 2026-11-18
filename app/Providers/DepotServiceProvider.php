<?php

namespace App\Providers;

use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\EnsureUserActive;
use App\Tenancy\AutoProvisioner;
use App\Tenancy\ManualProvisioner;
use App\Tenancy\TenantDatabaseProvisioner;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

class DepotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TenantDatabaseProvisioner::class, fn () =>
            config('depot.provisioner') === 'auto' ? new AutoProvisioner : new ManualProvisioner
        );

    }

    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('tenant.active', EnsureTenantActive::class);
        $this->app['router']->aliasMiddleware('user.active', EnsureUserActive::class);

        Route::prefix('api/v1')
            ->middleware(['api', InitializeTenancyByRequestData::class, 'tenant.active'])
            ->group(base_path('routes/depot.php'));
    }
}
