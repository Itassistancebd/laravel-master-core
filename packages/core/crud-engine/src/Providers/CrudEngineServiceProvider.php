<?php

namespace MasterCore\CrudEngine\Providers;

use Illuminate\Support\ServiceProvider;
use MasterCore\CrudEngine\Registry\CrudResourceRegistry;

final class CrudEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $root = dirname(__DIR__, 2);
        $this->mergeConfigFrom($root.'/config/crud-engine.php', 'master-core-crud-engine');
        $this->app->singleton(CrudResourceRegistry::class, function ($app): CrudResourceRegistry {
            $resources = array_map(fn (string $class) => $app->make($class), config('master-core-crud-engine.resources', []));

            return new CrudResourceRegistry($resources);
        });
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->publishes([$root.'/config/crud-engine.php' => config_path('master-core-crud-engine.php')], 'master-core-crud-engine-config');
    }
}
