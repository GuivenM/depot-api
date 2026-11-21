<?php

return [
    // manual : base créée à la main (mutualisé) | auto : créée par Tenancy (VPS)
    'provisioner' => env('TENANT_PROVISIONER', 'manual'),

    // Limitation de débit (tentatives par minute)
    'login_attempts'        => (int) env('DEPOT_LOGIN_ATTEMPTS', 5),        // par dépôt + email + IP
    'login_attempts_per_ip' => (int) env('DEPOT_LOGIN_ATTEMPTS_IP', 30),    // par IP, tous emails confondus
    'password_attempts'     => (int) env('DEPOT_PASSWORD_ATTEMPTS', 5),     // changement de mot de passe, par utilisateur

    // Sauvegardes (php artisan depot:backup)
    'backup' => [
        'path'        => env('BACKUP_PATH', storage_path('app/backups')),   // hors du dossier public !
        'keep'        => (int) env('BACKUP_KEEP', 14),                      // les N dernières sauvegardes de chaque base
        'months'      => (int) env('BACKUP_MONTHS', 6),                     // + la première de chacun des N derniers mois
        'mysqldump'   => env('MYSQLDUMP_PATH', 'mysqldump'),
        'mysql'       => env('MYSQL_CLIENT_PATH', 'mysql'),
        'remote_disk' => env('BACKUP_REMOTE_DISK'),                         // disque Laravel hors du serveur (S3, SFTP...), optionnel
        'remote_path' => env('BACKUP_REMOTE_PATH', 'depot-backups'),
    ],
];
