<?php

namespace MasterCore\ModuleManager\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MasterCore\ModuleManager\Contracts\InstalledPackageInspector;
use MasterCore\ModuleManager\Contracts\ModuleCatalog;
use MasterCore\ModuleManager\Support\ComposerInstalledPackageInspector;
use MasterCore\ModuleManager\Support\ConfiguredModuleCatalog;

final class ModuleManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = dirname(__DIR__, 2).'/config/module-manager.php';
        $this->mergeConfigFrom($config, 'master-core-module-manager');
        $this->app->singleton(InstalledPackageInspector::class, ComposerInstalledPackageInspector::class);
        $this->app->singleton(ModuleCatalog::class, ConfiguredModuleCatalog::class);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->publishes([
            $root.'/config/module-manager.php' => config_path('master-core-module-manager.php'),
        ], 'master-core-module-manager-config');

        if ($this->app->routesAreCached() || Route::has('master-core.modules.index')) {
            return;
        }

        $prefix = config('master-core-module-manager.api_prefix');
        if (! is_string($prefix) || trim($prefix, '/') === '') {
            $prefix = collect([
                trim((string) config('master-core.api_prefix', 'api'), '/'),
                trim((string) config('master-core.api_version', 'v1'), '/'),
            ])->filter()->implode('/');
        }

        Route::prefix(trim($prefix, '/'))->group($root.'/routes/api.php');
    }
}
