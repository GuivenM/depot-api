<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** En-têtes de sécurité sur toutes les réponses de l'API. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // HSTS seulement sur une connexion déjà sécurisée (sinon l'en-tête est ignoré, voire trompeur).
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
