<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'middleware' => ['web'],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => true,
    'home' => '/app',
    'limiters' => [
        'login' => 'master-core-auth-login',
    ],
    // Keep account creation, MFA, passkeys, profile editing and bearer tokens out of this phase.
    'features' => [
        Features::resetPasswords(),
        Features::emailVerification(),
    ],
];
