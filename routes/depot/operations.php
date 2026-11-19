<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\ContainerController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
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

// Fournisseurs et achats
Route::middleware(EnsureRole::class.':gerant,magasinier')->group(function () {
    Route::get('suppliers', [SupplierController::class, 'index']);
    Route::post('suppliers', [SupplierController::class, 'store']);
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update']);
    Route::get('suppliers/{supplier}/containers', [SupplierController::class, 'containers']);
    Route::get('purchases', [PurchaseController::class, 'index']);
    Route::post('purchases', [PurchaseController::class, 'store']);
});

// Dettes fournisseurs : l'argent qui sort est réservé au gérant
Route::middleware(EnsureRole::class.':gerant')->group(function () {
    Route::get('payables', [SupplierController::class, 'payables']);
    Route::get('suppliers/{supplier}/ledger', [SupplierController::class, 'ledger']);
    Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'pay']);
    Route::post('suppliers/{supplier}/adjustments', [SupplierController::class, 'adjust']);
});

// Annulations
Route::middleware(EnsureRole::class.':gerant,caissier,vendeur')->post('sales/{sale}/void', [SaleController::class, 'void']);
Route::middleware(EnsureRole::class.':gerant')->post('purchases/{purchase}/void', [PurchaseController::class, 'void']);

// Rapports et tableau de bord (lecture seule)
Route::middleware(EnsureRole::class.':gerant')->prefix('reports')->group(function () {
    Route::get('overview', [ReportController::class, 'overview']);
    Route::get('daily', [ReportController::class, 'daily']);
    Route::get('top-products', [ReportController::class, 'topProducts']);
    Route::get('cash-sessions', [ReportController::class, 'cashSessions']);
});

Route::middleware(EnsureRole::class.':gerant,magasinier')->get('reports/stock-alerts', [ReportController::class, 'stockAlerts']);

// Inventaire physique : le magasinier compte, le gérant valide
Route::middleware(EnsureRole::class.':gerant,magasinier')->group(function () {
    Route::get('inventories', [InventoryController::class, 'index']);
    Route::post('inventories', [InventoryController::class, 'store']);
    Route::get('inventories/{inventory}', [InventoryController::class, 'show']);
    Route::post('inventories/{inventory}/counts', [InventoryController::class, 'count']);
    Route::post('inventories/{inventory}/cancel', [InventoryController::class, 'cancel']);
});

Route::middleware(EnsureRole::class.':gerant')->post('inventories/{inventory}/validate', [InventoryController::class, 'validateInventory']);

// Personnel : comptes du dépôt (gérant uniquement ; jamais de suppression, on désactive)
Route::middleware(EnsureRole::class.':gerant')->prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('{user}', [UserController::class, 'show']);
    Route::put('{user}', [UserController::class, 'update']);
    Route::post('{user}/activate', [UserController::class, 'activate']);
    Route::post('{user}/deactivate', [UserController::class, 'deactivate']);
    Route::post('{user}/reset-password', [UserController::class, 'resetPassword']);
});

// Journal d'audit (lecture seule)
Route::middleware(EnsureRole::class.':gerant')->get('audit-logs', [AuditLogController::class, 'index']);
