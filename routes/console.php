<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nettoyage quotidien des tokens expirés, dans la base de chaque dépôt (nécessite le cron `php artisan schedule:run`).
Schedule::command('tenants:run sanctum:prune-expired --option="hours=24"')->daily();
