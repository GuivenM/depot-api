<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // En-tête X-Tenant absent ou dépôt inconnu : même réponse dans les deux cas (on ne révèle pas quels dépôts existent).
        $exceptions->render(function (TenantCouldNotBeIdentifiedException $e, Request $request) {
            return response()->json(['message' => 'Dépôt introuvable : vérifiez l\'en-tête X-Tenant.'], 404);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $retry = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return response()->json(['message' => "Trop de tentatives. Réessayez dans {$retry} secondes."], 429, $e->getHeaders());
        });
    })->create();
