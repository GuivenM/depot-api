<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTenantActive
{
    public function handle(Request $request, Closure $next)
    {
        if (tenant('status') !== 'active') {
            abort(403, 'Ce dépôt est inactif ou suspendu.');
        }

        return $next($request);
    }
}
