<?php

return [
    'api_prefix' => env('MASTER_CORE_AUTH_API_PREFIX', 'api/v1/auth'),
    'login_path' => '/login',
    'logout_path' => '/logout',
    'application_path' => '/app',
    'register_fortify_routes' => true,
    'register_application_route' => true,
    'session_inventory' => [
        'enabled' => true,
        'limit' => 20,
    ],
];
