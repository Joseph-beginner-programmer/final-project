<?php

namespace App\Providers;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use App\Http\Responses\LoginResponse;
use Carbon\CarbonImmutable;
use App\Enums\UserRole;
use App\Models\PurchaseOrderReceipt;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services. 
     */
    public function boot(): void
    {
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
        Gate::define('production.view', function ($user) {
            return in_array($user->role, [UserRole::Production, UserRole::Manager], true);
        });
        Gate::define('production.create', function ($user) {
            return in_array($user->role, [UserRole::Production, UserRole::Manager], true);
        });
        // use case: Manajemen — Mengelola Data Karyawan
        Gate::define('employees.manage', function ($user) {
            return in_array($user->role, [UserRole::Manager], true);
        });
        // use case: Akuntansi — Mengelola Biaya Tenaga Kerja
        Gate::define('labor-rates.manage', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager], true);
        });
        // use case: Akuntansi — Mengelola Biaya Overhead
        Gate::define('overhead.manage', function ($user) {
            return in_array($user->role, [UserRole::Accounting, UserRole::Manager], true);
        });

        //morph
        Relation::morphMap([
            'purchase_order_receipt' => PurchaseOrderReceipt::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
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
