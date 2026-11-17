<?php

return [
    // The auth provider remains the source of truth for the host's User model.
    'user_model' => null,
    'table' => env('MASTER_CORE_USER_TABLE', 'users'),
    'api_prefix' => null,
    'per_page' => 25,
    'max_per_page' => 100,
    'abilities' => [
        'view' => 'view-users',
        'create' => 'create-users',
        'update' => 'update-users',
        'status' => 'manage-user-status',
        'delete' => 'delete-users',
        'restore' => 'restore-users',
        'force_delete' => 'force-delete-users',
        'password_reset' => 'reset-user-passwords',
        'verification' => 'resend-user-verification',
        'export' => 'export-users',
        'import' => 'import-users',
        'manage_roles' => 'manage-user-roles',
        'manage' => 'manage-users',
    ],
    // Hosts must bind a policy before allowing destructive lifecycle operations.
    'safety_policy' => null,
];
