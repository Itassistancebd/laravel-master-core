<?php

namespace MasterCore\AuditLogs\Providers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MasterCore\AuditLogs\Console\PurgeExpiredAuditEventsCommand;
use MasterCore\AuditLogs\Contracts\AuditLogger;
use MasterCore\AuditLogs\Listeners\AuthenticationAuditListener;
use MasterCore\AuditLogs\Services\DatabaseAuditLogger;
use MasterCore\AuditLogs\Support\AuditEventCatalog;

final class AuditLogsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $root = dirname(__DIR__, 2);
        $this->mergeConfigFrom($root.'/config/audit-logs.php', 'master-core-audit-logs');
        $this->app->singleton(AuditEventCatalog::class);
        $this->app->singleton(AuditLogger::class, DatabaseAuditLogger::class);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->publishes([$root.'/config/audit-logs.php' => config_path('master-core-audit-logs.php')], 'master-core-audit-logs-config');
        $this->loadMigrationsFrom($root.'/database/migrations');
        Event::listen('master-core.audit.record.v1', function (array $envelope): void {
            app(AuditLogger::class)->record($envelope);
        });

        $listener = AuthenticationAuditListener::class;
        Event::listen(Login::class, [$listener, 'login']);
        Event::listen(Failed::class, [$listener, 'failed']);
        Event::listen(Lockout::class, [$listener, 'lockout']);
        Event::listen(Logout::class, [$listener, 'logout']);
        Event::listen(PasswordReset::class, [$listener, 'passwordReset']);
        Event::listen(Verified::class, [$listener, 'verified']);

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExpiredAuditEventsCommand::class]);
        }

        $prefix = config('master-core-audit-logs.api_prefix');
        if (! is_string($prefix) || trim($prefix, '/') === '') {
            $prefix = collect([config('master-core.api_prefix', 'api'), config('master-core.api_version', 'v1')])->filter()->implode('/');
        }
        Route::prefix(trim($prefix, '/'))->group($root.'/routes/api.php');
    }
}
