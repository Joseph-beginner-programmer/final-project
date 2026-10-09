<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::view('/purchasing/dashboard', 'dashboards.placeholder')
        ->name('purchasing.dashboard')
        ->middleware('dashboard.access:purchasing');

    Route::view('/sales/dashboard', 'dashboards.placeholder')
        ->name('sales.dashboard')
        ->middleware('dashboard.access:sales');

    Route::view('/accounting/dashboard', 'dashboards.placeholder')
        ->name('accounting.dashboard')
        ->middleware('dashboard.access:accounting');

    Route::view('/production/dashboard', 'dashboards.placeholder')
        ->name('production.dashboard')
        ->middleware('dashboard.access:production');

    Route::view('/warehouse/dashboard', 'dashboards.placeholder')
        ->name('warehouse.dashboard')
        ->middleware('dashboard.access:warehouse');

    Route::view('/admin/dashboard', 'dashboards.placeholder')
        ->name('admin.dashboard')
        ->middleware('dashboard.access:system_admin');

    Route::view('/executive/dashboard', 'dashboards.placeholder')
        ->name('executive.dashboard')
        ->middleware('dashboard.access:manager');
});

Route::middleware(['auth'])->group(function () {
    Route::livewire('/purchasing/orders/create', 'pages::purchasing.orders.create')
        ->name('purchasing.orders.create');

    Route::livewire('/purchasing/orders', 'pages::purchasing.orders.list')
        ->name('purchasing.orders.list');

    Route::livewire('/purchasing/orders/{purchaseOrder}', 'pages::purchasing.orders.show')
        ->name('purchasing.orders.show');
    
    Route::livewire('/warehouse/inbound/item-receipts', 'pages::warehouse.inbound.item-receipts.list')
        ->name('warehouse.inbound.item-receipts.list');

    Route::livewire('/warehouse/inbound/item-receipts/create/{purchaseOrder?}', 'pages::warehouse.inbound.item-receipts.create')
        ->name('warehouse.inbound.item-receipts.create');

    Route::livewire('/warehouse/inbound/item-receipts/{purchaseOrder}', 'pages::warehouse.inbound.item-receipts.detail')
        ->name('warehouse.inbound.item-receipts.detail');

    // literal /create must stay above the {workOrder} wildcard, or it would be captured as an id
    Route::livewire('/production/work-orders', 'pages::production.work-orders.list')
        ->name('production.work-orders.list');

    Route::livewire('/production/work-orders/create', 'pages::production.work-orders.create')
        ->name('production.work-orders.create');

    // the create page doubles as the edit page for a Draft WO
    Route::livewire('/production/work-orders/{workOrder}/edit', 'pages::production.work-orders.create')
        ->name('production.work-orders.edit');

    Route::livewire('/production/work-orders/{workOrder}', 'pages::production.work-orders.show')
        ->name('production.work-orders.show');

    Route::get('/production/work-orders/{workOrder}/print', \App\Http\Controllers\Production\PrintWorkOrderController::class)
        ->name('production.work-orders.print');

    // literal /create/... and /{result}/edit are declared before the bare {productionResult} wildcard
    Route::livewire('/production/results', 'pages::production.results.list')
        ->name('production.results.list');

    Route::livewire('/production/results/create/{workOrder}', 'pages::production.results.form')
        ->name('production.results.create');

    Route::livewire('/production/results/{productionResult}/edit', 'pages::production.results.form')
        ->name('production.results.edit');

    Route::livewire('/production/results/{productionResult}', 'pages::production.results.show')
        ->name('production.results.show');

    // literal /create/... and /{issue}/edit are declared before the bare {materialIssue} wildcard
    Route::livewire('/warehouse/outbound/material-issues', 'pages::warehouse.outbound.material-issues.list')
        ->name('warehouse.outbound.material-issues.list');

    Route::livewire('/warehouse/outbound/material-issues/create/{workOrder}', 'pages::warehouse.outbound.material-issues.form')
        ->name('warehouse.outbound.material-issues.create');

    Route::livewire('/warehouse/outbound/material-issues/{materialIssue}/edit', 'pages::warehouse.outbound.material-issues.form')
        ->name('warehouse.outbound.material-issues.edit');

    Route::livewire('/warehouse/outbound/material-issues/{materialIssue}', 'pages::warehouse.outbound.material-issues.show')
        ->name('warehouse.outbound.material-issues.show');

    Route::livewire('/management/employees', 'pages::management.employees.list')
        ->name('management.employees.list');

    Route::livewire('/accounting/labor-rates', 'pages::accounting.labor-rates.list')
        ->name('accounting.labor-rates.list');

    Route::livewire('/accounting/overhead-rates', 'pages::accounting.overhead-rates.list')
        ->name('accounting.overhead-rates.list');

});

require __DIR__.'/settings.php';
