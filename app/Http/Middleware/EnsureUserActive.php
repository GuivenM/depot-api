<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Un compte désactivé est refusé même s'il détient encore un token. */
class EnsureUserActive
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->active) {
            abort(403, 'Ce compte est désactivé.');
        }

        return $next($request);
    }
}
