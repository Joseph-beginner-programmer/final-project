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

        //morph
        Relation::morphMap([
            'purchase_order_receipt' => PurchaseOrderReceipt::class,
        ]);

        //migrations
        $this->loadMigrationsFrom(database_path('migrations/purchase'));
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
