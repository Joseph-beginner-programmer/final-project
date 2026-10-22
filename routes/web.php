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

    Route::livewire('/production/dashboard', 'pages::production.dashboard')
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

    Route::get('/purchasing/orders/{purchaseOrder}/pdf', \App\Http\Controllers\Purchasing\DownloadPurchaseOrderPdfController::class)
        ->name('purchasing.orders.pdf');
    
    Route::livewire('/warehouse/inbound/item-receipts', 'pages::warehouse.inbound.item-receipts.list')
        ->name('warehouse.inbound.item-receipts.list');

    Route::livewire('/warehouse/inbound/item-receipts/create/{purchaseOrder?}', 'pages::warehouse.inbound.item-receipts.create')
        ->name('warehouse.inbound.item-receipts.create');

    Route::livewire('/warehouse/inbound/item-receipts/{purchaseOrder}', 'pages::warehouse.inbound.item-receipts.detail')
        ->name('warehouse.inbound.item-receipts.detail');

    Route::livewire('/production/work-orders', 'pages::production.work-orders.list')
        ->name('production.work-orders.list');

    Route::livewire('/production/work-orders/create', 'pages::production.work-orders.create')
        ->name('production.work-orders.create');

    Route::livewire('/production/work-orders/{workOrder}/edit', 'pages::production.work-orders.create')
        ->name('production.work-orders.edit');

    Route::livewire('/production/work-orders/{workOrder}', 'pages::production.work-orders.show')
        ->name('production.work-orders.show');

    Route::get('/production/work-orders/{workOrder}/print', \App\Http\Controllers\Production\PrintWorkOrderController::class)
        ->name('production.work-orders.print');

    Route::livewire('/production/results', 'pages::production.results.list')
        ->name('production.results.list');

    Route::livewire('/production/results/create/{workOrder}', 'pages::production.results.form')
        ->name('production.results.create');

    Route::livewire('/production/results/{productionResult}/edit', 'pages::production.results.form')
        ->name('production.results.edit');

    Route::livewire('/production/results/{productionResult}', 'pages::production.results.show')
        ->name('production.results.show');

    Route::livewire('/accounting/overhead', 'pages::accounting.overhead.index')
        ->name('accounting.overhead.index');

    Route::livewire('/accounting/purchase-invoices', 'pages::accounting.purchase-invoices.list')
        ->name('accounting.purchase-invoices.list');

    Route::livewire('/accounting/purchase-invoices/create/{batch}', 'pages::accounting.purchase-invoices.form')
        ->name('accounting.purchase-invoices.create');

    Route::livewire('/accounting/purchase-invoices/{purchaseInvoice}/edit', 'pages::accounting.purchase-invoices.form')
        ->name('accounting.purchase-invoices.edit');

    Route::livewire('/accounting/purchase-invoices/{purchaseInvoice}', 'pages::accounting.purchase-invoices.show')
        ->name('accounting.purchase-invoices.show');

    Route::livewire('/production/reports', 'pages::production.reports.index')
        ->name('production.reports.index');

    Route::livewire('/production/reports/cost', 'pages::production.reports.cost')
        ->name('production.reports.cost');

    Route::get('/production/reports/cost/export/{format}', \App\Http\Controllers\Production\ExportProductionCostController::class)
        ->name('production.reports.cost.export');

    Route::livewire('/production/reports/inventory', 'pages::production.reports.inventory')
        ->name('production.reports.inventory');

    Route::get('/production/reports/inventory/export/{format}', \App\Http\Controllers\Production\ExportInventoryController::class)
        ->name('production.reports.inventory.export');


    Route::livewire('/warehouse/outbound/material-issues', 'pages::warehouse.outbound.material-issues.list')
        ->name('warehouse.outbound.material-issues.list');

    Route::livewire('/warehouse/outbound/material-issues/create/{workOrder}', 'pages::warehouse.outbound.material-issues.form')
        ->name('warehouse.outbound.material-issues.create');

    Route::livewire('/warehouse/outbound/material-issues/{materialIssue}/edit', 'pages::warehouse.outbound.material-issues.form')
        ->name('warehouse.outbound.material-issues.edit');

    Route::livewire('/warehouse/outbound/material-issues/{materialIssue}', 'pages::warehouse.outbound.material-issues.show')
        ->name('warehouse.outbound.material-issues.show');

    Route::livewire('/warehouse/inbound/material-returns', 'pages::warehouse.inbound.material-returns.list')
        ->name('warehouse.inbound.material-returns.list');

    Route::livewire('/warehouse/inbound/material-returns/create/{productionResult}', 'pages::warehouse.inbound.material-returns.form')
        ->name('warehouse.inbound.material-returns.create');

    Route::livewire('/warehouse/inbound/material-returns/{materialReturn}/edit', 'pages::warehouse.inbound.material-returns.form')
        ->name('warehouse.inbound.material-returns.edit');

    Route::livewire('/warehouse/inbound/material-returns/{materialReturn}', 'pages::warehouse.inbound.material-returns.show')
        ->name('warehouse.inbound.material-returns.show');

    Route::livewire('/warehouse/inbound/production-receipts', 'pages::warehouse.inbound.production-receipts.list')
        ->name('warehouse.inbound.production-receipts.list');

    Route::livewire('/warehouse/inbound/production-receipts/create/{productionResult}', 'pages::warehouse.inbound.production-receipts.form')
        ->name('warehouse.inbound.production-receipts.create');

    Route::livewire('/warehouse/inbound/production-receipts/{productionReceipt}/edit', 'pages::warehouse.inbound.production-receipts.form')
        ->name('warehouse.inbound.production-receipts.edit');

    Route::livewire('/warehouse/inbound/production-receipts/{productionReceipt}', 'pages::warehouse.inbound.production-receipts.show')
        ->name('warehouse.inbound.production-receipts.show');

    Route::livewire('/sales/customers', 'pages::sales.customers.list')
        ->name('sales.customers.list');

    Route::livewire('/sales/orders', 'pages::sales.orders.list')
        ->name('sales.orders.list');

    Route::livewire('/sales/orders/create', 'pages::sales.orders.form')
        ->name('sales.orders.create');

    Route::livewire('/sales/orders/{salesOrder}/edit', 'pages::sales.orders.form')
        ->name('sales.orders.edit');

    Route::livewire('/sales/orders/{salesOrder}', 'pages::sales.orders.show')
        ->name('sales.orders.show');

    Route::livewire('/warehouse/outbound/sales-deliveries', 'pages::warehouse.outbound.sales-deliveries.list')
        ->name('warehouse.outbound.sales-deliveries.list');

    Route::livewire('/warehouse/outbound/sales-deliveries/create/{salesOrder}', 'pages::warehouse.outbound.sales-deliveries.form')
        ->name('warehouse.outbound.sales-deliveries.create');

    Route::livewire('/warehouse/outbound/sales-deliveries/{salesDelivery}/edit', 'pages::warehouse.outbound.sales-deliveries.form')
        ->name('warehouse.outbound.sales-deliveries.edit');

    Route::livewire('/warehouse/outbound/sales-deliveries/{salesDelivery}', 'pages::warehouse.outbound.sales-deliveries.show')
        ->name('warehouse.outbound.sales-deliveries.show');

    Route::livewire('/management/products', 'pages::management.products.list')
        ->name('management.products.list');

    Route::livewire('/purchasing/suppliers', 'pages::purchasing.suppliers.list')
        ->name('purchasing.suppliers.list');

    Route::livewire('/purchasing/suppliers/{supplier}', 'pages::purchasing.suppliers.show')
        ->name('purchasing.suppliers.show');

    Route::livewire('/production/formulas', 'pages::production.formulas.list')
        ->name('production.formulas.list');

    Route::livewire('/production/formulas/create', 'pages::production.formulas.form')
        ->name('production.formulas.create');

    Route::livewire('/production/formulas/{formula}/edit', 'pages::production.formulas.form')
        ->name('production.formulas.edit');

    Route::livewire('/management/employees', 'pages::management.employees.list')
        ->name('management.employees.list');

    Route::livewire('/accounting/labor-rates', 'pages::accounting.labor-rates.list')
        ->name('accounting.labor-rates.list');

    Route::livewire('/accounting/overhead-rates', 'pages::accounting.overhead-rates.list')
        ->name('accounting.overhead-rates.list');

    Route::livewire('/accounting/accounts', 'pages::accounting.accounts.list')
        ->name('accounting.accounts.list');

    Route::livewire('/accounting/journal', 'pages::accounting.journal.list')
        ->name('accounting.journal.list');
    Route::livewire('/accounting/journal/create', 'pages::accounting.journal.create')
        ->name('accounting.journal.create');
    Route::livewire('/accounting/journal/{journalEntry}', 'pages::accounting.journal.show')
        ->name('accounting.journal.show');

});

require __DIR__.'/settings.php';
