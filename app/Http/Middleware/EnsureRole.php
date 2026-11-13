<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    /** Usage : EnsureRole::class.':gerant,caissier' */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        if (! in_array($request->user()?->role, $roles, true)) {
            abort(403, 'Action non autorisée pour votre rôle.');
        }

        return $next($request);
    }
}
