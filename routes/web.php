<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home'); 

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
});

require __DIR__.'/settings.php';
