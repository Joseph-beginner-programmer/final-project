<?php

namespace App\Providers;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use App\Http\Responses\LoginResponse;
use Carbon\CarbonImmutable;
use App\Enums\UserRole;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\MaterialReturn;
use App\Models\MaterialReturnItem;
use App\Models\ProductionReceipt;
use App\Models\ProductionResult;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\PurchaseOrderReceipt;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(glob(database_path('migrations/*'), GLOB_ONLYDIR));

        Gate::define('view-dashboard', function ($user, UserRole $dashboardRole) {
            return $user->role === $dashboardRole
                || in_array($user->role, [UserRole::Manager, UserRole::SystemAdmin], true);
        });

        Gate::define('purchasing.create', function ($user) {
            return in_array($user->role, [UserRole::Purchasing, UserRole::Manager], true);
        });
        Gate::define('purchasing.view', function ($user) {
            return in_array($user->role, [UserRole::Purchasing, UserRole::Manager], true);
        });
        Gate::define('purchasing.approve', function ($user) {
            return in_array($user->role, [UserRole::Manager], true);
        });
        Gate::define('purchasing.cancel', function ($user) {
            return in_array($user->role, [UserRole::Purchasing, UserRole::Manager], true);
        });
        Gate::define('purchasing.open', function ($user) {
            return in_array($user->role, [UserRole::Purchasing, UserRole::Manager], true);
        });
        Gate::define('purchasing.close', function ($user) {
            return in_array($user->role, [UserRole::Purchasing, UserRole::Manager], true);
        });
        Gate::define('warehouse.receive', function ($user) {
            return in_array($user->role, [UserRole::Warehouse, UserRole::Manager], true);
        });
        Gate::define('warehouse.issue', function ($user) {
            return in_array($user->role, [UserRole::Warehouse, UserRole::Manager], true);
        });
        Gate::define('warehouse.return', function ($user) {
            return in_array($user->role, [UserRole::Warehouse, UserRole::Manager], true);
        });
        Gate::define('production.view', function ($user) {
            return in_array($user->role, [UserRole::Production, UserRole::Manager], true);
        });
        Gate::define('production.create', function ($user) {
            return in_array($user->role, [UserRole::Production, UserRole::Manager], true);
        });
        Gate::define('employees.manage', function ($user) {
            return in_array($user->role, [UserRole::Manager], true);
        });
        Gate::define('labor-rates.manage', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager], true);
        });
        Gate::define('overhead.manage', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager], true);
        });
        Gate::define('products.manage', function ($user) {
            return in_array($user->role, [UserRole::Manager], true);
        });
        Gate::define('suppliers.manage', function ($user) {
            return in_array($user->role, [UserRole::Purchasing, UserRole::Manager], true);
        });
        Gate::define('formulas.manage', function ($user) {
            return in_array($user->role, [UserRole::Production, UserRole::Manager], true);
        });
        Gate::define('sales.manage', function ($user) {
            return in_array($user->role, [UserRole::Sales, UserRole::Manager], true);
        });
        Gate::define('warehouse.deliver', function ($user) {
            return in_array($user->role, [UserRole::Warehouse, UserRole::Manager], true);
        });
        Gate::define('costs.view', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager, UserRole::Sales], true);
        });
        Gate::define('accounts.manage', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager], true);
        });
        Gate::define('journal.manage', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager], true);
        });
        Gate::define('reports.production', function ($user) {
            return in_array($user->role, [UserRole::Production, UserRole::Manager, UserRole::Accounting], true);
        });
        Gate::define('reports.finance', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager], true);
        });
        Gate::define('reports.inventory-value', function ($user) {
            return in_array($user->role, [UserRole::Manager, UserRole::Accounting], true);
        });
        Gate::define('requisitions.create', function ($user) {
            return in_array($user->role, [UserRole::Production, UserRole::Manager], true);
        });
        Gate::define('requisitions.process', function ($user) {
            return in_array($user->role, [UserRole::Purchasing, UserRole::Manager], true);
        });
        Gate::define('requisitions.view', function ($user) {
            return in_array($user->role, [UserRole::Production, UserRole::Purchasing, UserRole::Manager], true);
        });
        Gate::define('purchasing.invoice', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager], true);
        });
        Gate::define('sales.invoice', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager], true);
        });
        Gate::define('purchasing.return', function ($user) {
            return in_array($user->role, [UserRole::Purchasing, UserRole::Warehouse, UserRole::Manager], true);
        });
        Gate::define('purchasing.return.view', function ($user) {
            return in_array($user->role, [UserRole::Purchasing, UserRole::Warehouse, UserRole::Accounting, UserRole::Manager], true);
        });
        Gate::define('sales.return', function ($user) {
            return in_array($user->role, [UserRole::Sales, UserRole::Warehouse, UserRole::Manager], true);
        });
        Gate::define('sales.return.view', function ($user) {
            return in_array($user->role, [UserRole::Sales, UserRole::Warehouse, UserRole::Accounting, UserRole::Manager], true);
        });
        Gate::define('wages.pay', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager], true);
        });
        Gate::define('sales.invoice.view', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager, UserRole::Sales], true);
        });

        Relation::morphMap([
            'purchase_order_receipt' => PurchaseOrderReceipt::class,
            'material_issue_item' => MaterialIssueItem::class,
            'material_return_item' => MaterialReturnItem::class,
            'production_receipt' => ProductionReceipt::class,
            'sales_delivery_item' => SalesDeliveryItem::class,
            'material_issue' => MaterialIssue::class,
            'production_result' => ProductionResult::class,
            'material_return' => MaterialReturn::class,
            'sales_delivery' => SalesDelivery::class,
            'purchase_invoice' => \App\Models\PurchaseInvoice::class,
            'purchase_payment' => \App\Models\PurchasePayment::class,
            'sales_invoice' => \App\Models\SalesInvoice::class,
            'sales_payment' => \App\Models\SalesPayment::class,
            'wage_payment' => \App\Models\WagePayment::class,
            'purchase_return' => \App\Models\PurchaseReturn::class,
            'purchase_return_item' => \App\Models\PurchaseReturnItem::class,
            'sales_return' => \App\Models\SalesReturn::class,
            'sales_return_item' => \App\Models\SalesReturnItem::class,
        ]);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
