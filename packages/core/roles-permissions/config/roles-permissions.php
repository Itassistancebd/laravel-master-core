<?php

return [
    // Compatibility preserves host Gate decisions; strict mode is an explicit host opt-in.
    'gate_mode' => env('MASTER_CORE_RBAC_MODE', 'compatibility'),
    'api_prefix' => null,
    'guard' => null,
    'permission_cache_ttl' => 300,
    'abilities' => [
        'view-settings' => ['group' => 'Settings', 'description' => 'View application settings.'],
        'manage-settings' => ['group' => 'Settings', 'description' => 'Manage application settings.'],
        'view-users' => ['group' => 'User Management', 'description' => 'View users.'],
        'create-users' => ['group' => 'User Management', 'description' => 'Create users.'],
        'edit-users' => ['group' => 'User Management', 'description' => 'Edit users.'],
        'delete-users' => ['group' => 'User Management', 'description' => 'Move users to trash.'],
        'force-delete-users' => ['group' => 'User Management', 'description' => 'Permanently delete users.'],
        'restore-users' => ['group' => 'User Management', 'description' => 'Restore users from trash.'],
        'manage-user-status' => ['group' => 'User Management', 'description' => 'Change user account status.'],
        'manage-user-roles' => ['group' => 'User Management', 'description' => 'Assign roles to users.'],
        'reset-user-passwords' => ['group' => 'User Management', 'description' => 'Request user password reset links.'],
        'resend-user-verification' => ['group' => 'User Management', 'description' => 'Resend user verification notifications.'],
        'export-users' => ['group' => 'User Management', 'description' => 'Export allowlisted user account fields to CSV.'],
        'import-users' => ['group' => 'User Management', 'description' => 'Preview and import user accounts from CSV.'],
        'view-roles' => ['group' => 'Roles & Permissions', 'description' => 'View roles.'],
        'create-roles' => ['group' => 'Roles & Permissions', 'description' => 'Create roles.'],
        'edit-roles' => ['group' => 'Roles & Permissions', 'description' => 'Edit roles and role grants.'],
        'delete-roles' => ['group' => 'Roles & Permissions', 'description' => 'Delete unassigned roles.'],
        'view-permissions' => ['group' => 'Roles & Permissions', 'description' => 'View registered permissions.'],
        'manage-permissions' => ['group' => 'Roles & Permissions', 'description' => 'Materialize and manage registered permissions.'],
        'view-audit-logs' => ['group' => 'Audit Logs', 'description' => 'View security and administration audit history.'],
        'export-audit-logs' => ['group' => 'Audit Logs', 'description' => 'Reserved for a future approved audit export feature.'],
        'view-modules' => ['group' => 'Module Manager', 'description' => 'View the installed Core module catalog and dependency status.'],
        'view-system-health' => ['group' => 'System Health', 'description' => 'View allowlisted system readiness checks.'],
    ],
    // Host packages may add executable abilities here. Database rows alone never register Gates.
    'extensions' => [],
    'aliases' => [
        'update-users' => 'edit-users',
    ],
];
