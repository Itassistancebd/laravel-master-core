<?php

namespace MasterCore\UserManagement\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MasterCore\Auth\Contracts\LoginEligibility;
use MasterCore\UserManagement\Contracts\UserManagementSafetyPolicy;
use MasterCore\UserManagement\Http\Middleware\EnsureActiveUser;
use MasterCore\UserManagement\Support\DenyDestructiveUserManagementPolicy;
use MasterCore\UserManagement\Support\HostUserLoginEligibility;

final class UserManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/user-management.php', 'master-core-user-management');

        $this->app->singleton(UserManagementSafetyPolicy::class, function ($app): UserManagementSafetyPolicy {
            $policy = config('master-core-user-management.safety_policy');

            return is_string($policy) && class_exists($policy)
                ? $app->make($policy)
                : $app->make(DenyDestructiveUserManagementPolicy::class);
        });
    }

    public function boot(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        // Bind after all providers register so this module's eligibility rule takes precedence
        // over Authentication's allow-all default without changing Authentication itself.
        $this->app->singleton(LoginEligibility::class, HostUserLoginEligibility::class);
        $this->publishes([
            $packageRoot.'/config/user-management.php' => config_path('master-core-user-management.php'),
        ], 'master-core-user-management-config');
        $this->loadMigrationsFrom($packageRoot.'/database/migrations');
        $this->app->booted(function (): void {
            // Extend the finalized groups after Laravel's bootstrap middleware configuration is applied.
            $router = $this->app['router'];
            $router->pushMiddlewareToGroup('web', EnsureActiveUser::class);
            $router->pushMiddlewareToGroup('api', EnsureActiveUser::class);
        });

        if ($this->app->routesAreCached() || Route::has('master-core.user-management.index')) {
            return;
        }

        $prefix = config('master-core-user-management.api_prefix');
        if (! is_string($prefix) || trim($prefix, '/') === '') {
            $prefix = collect([
                trim((string) config('master-core.api_prefix', 'api'), '/'),
                trim((string) config('master-core.api_version', 'v1'), '/'),
            ])->filter()->implode('/');
        }

        Route::prefix(trim($prefix, '/'))->group($packageRoot.'/routes/api.php');
    }
}
