<?php

use App\Http\Controllers\CashController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockController;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;

// Chargé depuis routes/depot.php, donc déjà sous /api/v1 + X-Tenant + auth:sanctum.

Route::get('products', [ProductController::class, 'index']);

// Catalogue et stock : gérant + magasinier
Route::middleware(EnsureRole::class.':gerant,magasinier')->group(function () {
    Route::post('products', [ProductController::class, 'store']);
    Route::put('products/{product}', [ProductController::class, 'update']);
    Route::get('products/{product}/movements', [StockController::class, 'movements']);
    Route::post('stock/receipts', [StockController::class, 'receipt']);
    Route::post('stock/adjustments', [StockController::class, 'adjust']);
});

// Caisse et ventes : chaque vendeur/caissier a sa propre session de caisse
Route::middleware(EnsureRole::class.':gerant,caissier,vendeur')->group(function () {
    Route::get('cash/current', [CashController::class, 'current']);
    Route::post('cash/open', [CashController::class, 'open']);
    Route::post('cash/close', [CashController::class, 'close']);
    Route::post('cash/expenses', [CashController::class, 'expense']);

    Route::get('sales', [SaleController::class, 'index']);
    Route::post('sales', [SaleController::class, 'store']);
});
