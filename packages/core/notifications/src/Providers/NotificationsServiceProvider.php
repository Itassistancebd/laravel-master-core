<?php

namespace MasterCore\Notifications\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MasterCore\Notifications\Actions\StoreCoreNotification;
use MasterCore\Notifications\Commands\PurgeReadNotificationsCommand;
use MasterCore\Notifications\Commands\ReportOrphanNotificationsCommand;
use MasterCore\Notifications\Contracts\NotificationInbox;
use MasterCore\Notifications\Listeners\StoreRoleChangeNotification;
use MasterCore\RolesPermissions\Events\RolesPermissionsChanged;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $root = dirname(__DIR__, 2);
        $this->mergeConfigFrom($root.'/config/notifications.php', 'master-core-notifications');
        $this->app->singleton(NotificationInbox::class, StoreCoreNotification::class);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->publishes([$root.'/config/notifications.php' => config_path('master-core-notifications.php')], 'master-core-notifications-config');
        $this->loadMigrationsFrom($root.'/database/migrations');
        Event::listen(RolesPermissionsChanged::class, StoreRoleChangeNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeReadNotificationsCommand::class, ReportOrphanNotificationsCommand::class]);
        }

        if ($this->app->routesAreCached() || Route::has('master-core.notifications.index')) {
            return;
        }

        $prefix = config('master-core-notifications.api_prefix');
        if (! is_string($prefix) || trim($prefix, '/') === '') {
            $prefix = collect([config('master-core.api_prefix', 'api'), config('master-core.api_version', 'v1')])->filter()->implode('/');
        }
        Route::prefix(trim($prefix, '/'))->group($root.'/routes/api.php');
    }
}
