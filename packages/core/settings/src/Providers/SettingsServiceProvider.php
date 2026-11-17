<?php

namespace MasterCore\Settings\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MasterCore\Settings\Http\Middleware\ApplySettingsForRequest;

final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/settings.php', 'master-core-settings');
    }

    public function boot(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $configPath = $packageRoot.'/config/settings.php';

        $this->publishes([
            $configPath => config_path('master-core-settings.php'),
        ], 'master-core-settings-config');
        $this->loadMigrationsFrom($packageRoot.'/database/migrations');
        $this->loadViewsFrom($packageRoot.'/resources/views', 'master-core-settings');
        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('web', ApplySettingsForRequest::class);
        $router->pushMiddlewareToGroup('api', ApplySettingsForRequest::class);

        if ($this->app->routesAreCached() || Route::has('master-core.settings.public')) {
            return;
        }

        $prefix = collect([
            trim((string) config('master-core.api_prefix', 'api'), '/'),
            trim((string) config('master-core.api_version', 'v1'), '/'),
        ])->filter()->implode('/');

        Route::prefix($prefix)->group($packageRoot.'/routes/api.php');
        Route::middleware('web')->group($packageRoot.'/routes/web.php');
    }
}
