<?php

/*
| CORS : quels sites web (le front) ont le droit d'appeler l'API depuis un navigateur.
| CORS_ALLOWED_ORIGINS = liste séparée par des virgules, ex. https://app.mondomaine.com
| Vide (défaut) = aucun site tiers autorisé. Jamais « * » en production (vérifié par `depot:check`).
*/
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Tenant', 'X-Requested-With'],

    // Lisibles par le front : nom du fichier des PDF / CSV, délai d'attente après un 429.
    'exposed_headers' => ['Content-Disposition', 'Retry-After'],

    'max_age' => 86400,

    // Authentification par jeton (en-tête Authorization), pas par cookie.
    'supports_credentials' => false,
];
