# Phase 4 Authentication Setup

This guide configures the approved package in this Laravel Master Core host. It does not run migrations. Authentication uses existing Laravel stock tables and the configured host user provider.

## Dependencies and build

Composer declares the local `example/laravel-master-core-auth` package, which requires Laravel Fortify and Sanctum. The npm workspace includes `@master-core/auth` and the required Vue Router dependency. Run Composer package discovery and build Vite assets after installing dependencies. Do not run `artisan migrate` as part of authentication setup; no authentication migration is provided.

The package registers Fortify's conventional web routes and Sanctum's CSRF-cookie route. Confirm those paths do not conflict with another Fortify or authentication package in a consuming host. When the host owns alternative auth controllers, set `master-core-auth.register_fortify_routes` to `false` and supply compatible login, logout, reset, and email-verification routes itself.

## Host configuration

1. Keep the intended session guard/provider in `config/auth.php`. The default `web` guard and Eloquent provider work with the current `App\Models\User`.
2. In Laravel 12 `bootstrap/app.php`, call `$middleware->statefulApi()` so same-origin browser requests to `/api/*` can use the session guard through Sanctum.
3. Set `SANCTUM_STATEFUL_DOMAINS` to the exact hostnames and development ports that serve the SPA. For a same-origin deployment, use the host application's hostname and port; do not use wildcard domains.
4. Configure the host's mail transport and verified `APP_URL` so password-reset and signed verification links reach the right site.
5. In production, use HTTPS and secure HttpOnly cookies. Keep SameSite at `lax` for same-origin use. If a consuming SPA uses a sibling subdomain, review `SESSION_DOMAIN`, SameSite, secure-cookie, and credentialed CORS settings together before deployment.
6. Keep the cache store available for Fortify's login throttle. The package applies five attempts per username/IP per minute plus a fifty-attempt per-IP ceiling. Laravel's password broker also throttles reset-link generation using its existing configuration.

Environment settings are host-specific and are not inspected or changed by the package:

```dotenv
APP_URL=https://app.example.test
SANCTUM_STATEFUL_DOMAINS=app.example.test
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

Use the actual host values; do not copy sample domains literally. The package API base defaults to `/api/v1/auth`; change `MASTER_CORE_AUTH_API_PREFIX` only when host routing requires another path.

## User and database compatibility

The existing model remains the password broker's authenticatable user model and adds Laravel's `MustVerifyEmail` contract. Other host models can be selected through standard auth provider configuration, but need Laravel's authenticatable/password-reset behavior, email/name fields for the current response contract, a password field, and verification notification support if verification is enabled.

Laravel's stock `users`, `password_reset_tokens`, and `sessions` tables remain host-owned. The package adds no migrations. Database session listing/revocation returns `501` when the host uses a different driver or lacks the stock sessions table; login and logout remain available with supported Laravel session stores.

Do not add `HasApiTokens`, issue bearer credentials, or publish Sanctum migrations for this phase. Sanctum session auth does not need personal-access-token persistence.

## Email verification policy

Email verification is available but not globally required. The Core `/app` shell remains available after login so hosts do not unexpectedly lose access. Add Laravel's `verified` middleware to only the host routes that require a verified email. Verification links are signed, expire according to Laravel's verification URL configuration, and are throttled by Fortify. The current-user API returns `email_verified_at` for the frontend state.
