<?php

namespace App\Providers;

use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\EnsureUserActive;
use App\Tenancy\AutoProvisioner;
use App\Tenancy\ManualProvisioner;
use App\Tenancy\TenantDatabaseProvisioner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Connexion : limite par IP (toutes adresses email) et par dépôt + email + IP.
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(config('depot.login_attempts_per_ip'))->by('login-ip:'.$request->ip()),
                Limit::perMinute(config('depot.login_attempts'))->by('login:'.tenant('id').'|'.$email.'|'.$request->ip()),
            ];
        });

        // Changement de mot de passe : évite de deviner le mot de passe actuel avec un token volé.
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(config('depot.password_attempts'))
            ->by('password:'.tenant('id').'|'.$request->user()?->id));

        Route::prefix('api/v1')
            ->middleware(['api', InitializeTenancyByRequestData::class, 'tenant.active'])
            ->group(base_path('routes/depot.php'));
    }
}
