<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Préfixe /api/v1 + identification du tenant (X-Tenant) ajoutés par DepotServiceProvider.
Route::post('login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    require __DIR__.'/depot/operations.php';
});
