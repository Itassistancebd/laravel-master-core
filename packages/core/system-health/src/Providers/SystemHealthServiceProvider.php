<?php

namespace MasterCore\SystemHealth\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MasterCore\SystemHealth\Checks\ApplicationRequestCheck;
use MasterCore\SystemHealth\Checks\DatabaseConnectivityCheck;
use MasterCore\SystemHealth\Support\HealthCheckRegistry;

final class SystemHealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $this->mergeConfigFrom($packageRoot.'/config/system-health.php', 'master-core-system-health');
        $this->app->singleton(HealthCheckRegistry::class, fn ($app): HealthCheckRegistry => new HealthCheckRegistry([
            $app->make(ApplicationRequestCheck::class),
            $app->make(DatabaseConnectivityCheck::class),
        ]));
    }

    public function boot(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $this->publishes([
            $packageRoot.'/config/system-health.php' => config_path('master-core-system-health.php'),
        ], 'master-core-system-health-config');

        if ($this->app->routesAreCached() || Route::has('master-core.system-health.show')) {
            return;
        }

        $prefix = config('master-core-system-health.api_prefix');
        if (! is_string($prefix) || trim($prefix, '/') === '') {
            $prefix = collect([
                trim((string) config('master-core.api_prefix', 'api'), '/'),
                trim((string) config('master-core.api_version', 'v1'), '/'),
            ])->filter()->implode('/');
        }

        Route::prefix(trim($prefix, '/'))->group($packageRoot.'/routes/api.php');
    }
}
