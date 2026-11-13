<?php

use App\Http\Controllers\CashController;
use App\Http\Controllers\ContainerController;
use App\Http\Controllers\CustomerController;
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

// Clients et crédit
Route::middleware(EnsureRole::class.':gerant,caissier,vendeur')->group(function () {
    Route::get('customers', [CustomerController::class, 'index']);
    Route::post('customers', [CustomerController::class, 'store']);
    Route::put('customers/{customer}', [CustomerController::class, 'update']);
    Route::get('customers/{customer}/ledger', [CustomerController::class, 'ledger']);
    Route::post('customers/{customer}/payments', [CustomerController::class, 'pay']);
});

Route::middleware(EnsureRole::class.':gerant,caissier')->get('receivables', [CustomerController::class, 'receivables']);

// Abandon / correction de créance : gérant uniquement
Route::middleware(EnsureRole::class.':gerant')->post('customers/{customer}/adjustments', [CustomerController::class, 'adjust']);

// Consignes (casiers)
Route::get('containers', [ContainerController::class, 'index']);   // tout utilisateur connecté

Route::middleware(EnsureRole::class.':gerant')->group(function () {
    Route::post('containers', [ContainerController::class, 'store']);
    Route::put('containers/{containerType}', [ContainerController::class, 'update']);
    Route::post('customers/{customer}/containers/adjustments', [ContainerController::class, 'adjustHolding']);
});

Route::middleware(EnsureRole::class.':gerant,magasinier')->group(function () {
    Route::post('containers/depot-movements', [ContainerController::class, 'depotMovement']);
    Route::get('containers/{containerType}/movements', [ContainerController::class, 'movements']);
});

Route::middleware(EnsureRole::class.':gerant,caissier')->get('containers/{containerType}/holders', [ContainerController::class, 'holders']);

// Retours de casiers vides : le magasinier les réceptionne aussi
Route::middleware(EnsureRole::class.':gerant,caissier,vendeur,magasinier')->group(function () {
    Route::get('customers/{customer}/containers', [ContainerController::class, 'customerHoldings']);
    Route::post('customers/{customer}/containers/returns', [ContainerController::class, 'customerReturn']);
});
