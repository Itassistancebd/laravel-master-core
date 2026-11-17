<?php

namespace MasterCore\Foundation\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MasterCore\Foundation\Contracts\CoreHealthInterface;
use MasterCore\Foundation\Support\DefaultCoreHealth;

final class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configPath = dirname(__DIR__, 2).'/config/master-core.php';

        $this->mergeConfigFrom($configPath, 'master-core');
        $this->app->singleton(CoreHealthInterface::class, DefaultCoreHealth::class);
    }

    public function boot(): void
    {
        $configPath = dirname(__DIR__, 2).'/config/master-core.php';
        $routesPath = dirname(__DIR__, 2).'/routes/api.php';
        $migrationsPath = dirname(__DIR__, 2).'/database/migrations';

        $this->publishes([
            $configPath => config_path('master-core.php'),
        ], 'master-core-config');

        $this->loadMigrationsFrom($migrationsPath);

        if ($this->app->routesAreCached() || Route::has('master-core.core.health')) {
            return;
        }

        $prefix = collect([
            trim((string) config('master-core.api_prefix', 'api'), '/'),
            trim((string) config('master-core.api_version', 'v1'), '/'),
            'core',
        ])->filter()->implode('/');

        Route::prefix($prefix)->group($routesPath);
    }
}
