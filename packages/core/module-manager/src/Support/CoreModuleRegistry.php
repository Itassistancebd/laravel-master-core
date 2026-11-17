<?php

namespace MasterCore\ModuleManager\Support;

use MasterCore\AuditLogs\Providers\AuditLogsServiceProvider;
use MasterCore\Auth\Providers\AuthenticationServiceProvider;
use MasterCore\CrudEngine\Providers\CrudEngineServiceProvider;
use MasterCore\Foundation\Providers\FoundationServiceProvider;
use MasterCore\ModuleManager\Providers\ModuleManagerServiceProvider;
use MasterCore\Notifications\Providers\NotificationsServiceProvider;
use MasterCore\RolesPermissions\Providers\RolesPermissionsServiceProvider;
use MasterCore\Settings\Providers\SettingsServiceProvider;
use MasterCore\SystemHealth\Providers\SystemHealthServiceProvider;
use MasterCore\UserManagement\Providers\UserManagementServiceProvider;

/** The only source of executable/reportable module identities and package dependencies. */
final class CoreModuleRegistry
{
    public static function definitions(): array
    {
        return [
            'core-foundation' => [
                'name' => 'Foundation',
                'description' => 'Shared configuration and database-independent Core health endpoint.',
                'composer_package' => 'example/laravel-master-core-foundation',
                'provider' => FoundationServiceProvider::class,
                'dependencies' => ['laravel/framework'],
                'documentation' => 'packages/core/foundation/README.md',
            ],
            'core-settings' => [
                'name' => 'Settings',
                'description' => 'Allowlisted application settings and branding.',
                'composer_package' => 'example/laravel-master-core-settings',
                'provider' => SettingsServiceProvider::class,
                'dependencies' => ['laravel/framework', 'example/laravel-master-core-foundation'],
                'documentation' => 'packages/core/settings/README.md',
            ],
            'core-auth' => [
                'name' => 'Authentication & Security',
                'description' => 'Laravel Fortify and Sanctum session authentication.',
                'composer_package' => 'example/laravel-master-core-auth',
                'provider' => AuthenticationServiceProvider::class,
                'dependencies' => ['laravel/framework', 'example/laravel-master-core-foundation', 'laravel/fortify', 'laravel/sanctum'],
                'documentation' => 'packages/core/auth/README.md',
            ],
            'core-user-management' => [
                'name' => 'User Management',
                'description' => 'Host-user account lifecycle management.',
                'composer_package' => 'example/laravel-master-core-user-management',
                'provider' => UserManagementServiceProvider::class,
                'dependencies' => ['laravel/framework', 'example/laravel-master-core-foundation', 'example/laravel-master-core-auth'],
                'documentation' => 'packages/core/user-management/README.md',
            ],
            'core-roles-permissions' => [
                'name' => 'Roles & Permissions',
                'description' => 'Code-registered Laravel Gate and Policy RBAC integration.',
                'composer_package' => 'example/laravel-master-core-roles-permissions',
                'provider' => RolesPermissionsServiceProvider::class,
                'dependencies' => ['laravel/framework', 'example/laravel-master-core-foundation', 'example/laravel-master-core-user-management'],
                'documentation' => 'packages/core/roles-permissions/README.md',
            ],
            'core-audit-logs' => [
                'name' => 'Audit Logs',
                'description' => 'Append-only security and administration history.',
                'composer_package' => 'example/laravel-master-core-audit-logs',
                'provider' => AuditLogsServiceProvider::class,
                'dependencies' => ['laravel/framework'],
                'documentation' => 'packages/core/audit-logs/README.md',
            ],
            'core-module-manager' => [
                'name' => 'Module Registry & Manager',
                'description' => 'Read-only inventory of approved Core packages and dependency presence.',
                'composer_package' => 'example/laravel-master-core-module-manager',
                'provider' => ModuleManagerServiceProvider::class,
                'dependencies' => ['laravel/framework', 'example/laravel-master-core-foundation'],
                'documentation' => 'packages/core/module-manager/README.md',
            ],
            'core-system-health' => [
                'name' => 'System Health & Readiness',
                'description' => 'Authenticated, read-only application and primary database checks.',
                'composer_package' => 'example/laravel-master-core-system-health',
                'provider' => SystemHealthServiceProvider::class,
                'dependencies' => [
                    'laravel/framework',
                    'example/laravel-master-core-foundation',
                    'example/laravel-master-core-roles-permissions',
                ],
                'documentation' => 'packages/core/system-health/README.md',
            ],
            'core-notifications' => [
                'name' => 'Notifications & Notification Center',
                'description' => 'Recipient-scoped in-app notices and read state.',
                'composer_package' => 'example/laravel-master-core-notifications',
                'provider' => NotificationsServiceProvider::class,
                'dependencies' => [
                    'laravel/framework',
                    'example/laravel-master-core-foundation',
                    'example/laravel-master-core-roles-permissions',
                ],
                'documentation' => 'packages/core/notifications/README.md',
            ],
            'core-crud-engine' => [
                'name' => 'CRUD Engine',
                'description' => 'Opt-in resource contracts and bounded query helpers for owner-defined CRUD APIs.',
                'composer_package' => 'example/laravel-master-core-crud-engine',
                'provider' => CrudEngineServiceProvider::class,
                'dependencies' => ['laravel/framework'],
                'documentation' => 'packages/core/crud-engine/README.md',
            ],
        ];
    }
}
