<?php

return [
    // manual : base créée à la main (mutualisé) | auto : créée par Tenancy (VPS)
    'provisioner' => env('TENANT_PROVISIONER', 'manual'),

    // Limitation de débit (tentatives par minute)
    'login_attempts'        => (int) env('DEPOT_LOGIN_ATTEMPTS', 5),        // par dépôt + email + IP
    'login_attempts_per_ip' => (int) env('DEPOT_LOGIN_ATTEMPTS_IP', 30),    // par IP, tous emails confondus
    'password_attempts'     => (int) env('DEPOT_PASSWORD_ATTEMPTS', 5),     // changement de mot de passe, par utilisateur
];
