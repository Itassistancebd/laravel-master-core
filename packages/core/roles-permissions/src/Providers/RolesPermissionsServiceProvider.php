<?php

namespace MasterCore\RolesPermissions\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MasterCore\RolesPermissions\Console\CleanupOrphanRoleAssignmentsCommand;
use MasterCore\RolesPermissions\Console\ProvisionSuperAdminCommand;
use MasterCore\RolesPermissions\Console\SyncPermissionsCommand;
use MasterCore\RolesPermissions\Support\AuthorizationResolver;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use MasterCore\RolesPermissions\Support\PermissionRegistry;
use MasterCore\RolesPermissions\Support\RoleAssignmentResolver;
use MasterCore\RolesPermissions\Support\RolesPermissionsUserSafetyPolicy;
use MasterCore\UserManagement\Contracts\UserManagementSafetyPolicy;

final class RolesPermissionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $this->mergeConfigFrom($packageRoot.'/config/roles-permissions.php', 'master-core-roles-permissions');
        $this->app->singleton(PermissionRegistry::class);
        // Sanctum middleware may switch the runtime guard for a request; keep the host default fixed at boot.
        $defaultGuard = (string) config('auth.defaults.guard', 'web');
        $this->app->singleton(HostUserModelResolver::class, static fn (): HostUserModelResolver => new HostUserModelResolver($defaultGuard));
        $this->app->singleton(RoleAssignmentResolver::class);
        $this->app->singleton(AuthorizationResolver::class);
    }

    public function boot(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $this->publishes([
            $packageRoot.'/config/roles-permissions.php' => config_path('master-core-roles-permissions.php'),
        ], 'master-core-roles-permissions-config');
        $this->loadMigrationsFrom($packageRoot.'/database/migrations');

        $mode = config('master-core-roles-permissions.gate_mode', 'compatibility');
        if (! in_array($mode, ['compatibility', 'strict'], true)) {
            throw new \InvalidArgumentException("Unsupported Roles & Permissions Gate mode '{$mode}'.");
        }

        Gate::before(static function ($user, string $ability, array $arguments): ?bool {
            return app(AuthorizationResolver::class)->resolve($user, $ability, $arguments);
        });

        $this->app->singleton(UserManagementSafetyPolicy::class, function ($app): UserManagementSafetyPolicy {
            $configured = config('master-core-user-management.safety_policy');
            $hostPolicy = is_string($configured)
                && $configured !== RolesPermissionsUserSafetyPolicy::class
                && class_exists($configured)
                    ? $app->make($configured)
                    : null;

            return new RolesPermissionsUserSafetyPolicy($hostPolicy);
        });

        if ($mode === 'strict') {
            // Remove User Management's broad legacy Gate fallback; host Gate review is required before strict mode.
            config(['master-core-user-management.abilities.manage' => null]);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupOrphanRoleAssignmentsCommand::class,
                ProvisionSuperAdminCommand::class,
                SyncPermissionsCommand::class,
            ]);
        }

        $prefix = config('master-core-roles-permissions.api_prefix');
        if (! is_string($prefix) || trim($prefix, '/') === '') {
            $prefix = collect([
                trim((string) config('master-core.api_prefix', 'api'), '/'),
                trim((string) config('master-core.api_version', 'v1'), '/'),
            ])->filter()->implode('/');
        }
        Route::prefix(trim($prefix, '/'))->group($packageRoot.'/routes/api.php');
    }
}
