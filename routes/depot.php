<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Préfixe /api/v1 + identification du tenant (X-Tenant) ajoutés par DepotServiceProvider.
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'user.active'])->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('me/password', [AuthController::class, 'changePassword'])->middleware('throttle:password');
    Route::post('logout', [AuthController::class, 'logout']);
    require __DIR__.'/depot/operations.php';
});
