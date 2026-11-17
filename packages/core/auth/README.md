# Master Core Authentication

The Authentication package integrates Laravel Fortify and Sanctum with Master Core's Vue 3 + TypeScript workspace. It provides login, logout, password reset, email verification, current-user state, and optional database-session inventory. It is separate from Foundation and does not depend on Settings persistence.

## Scope and data ownership

- Uses the host application's configured Laravel guard, user provider, password broker, session store, mail transport, and cache limiter.
- Preserves `App\Models\User`; the current host model implements `MustVerifyEmail` without adding another user model.
- Creates no migrations or Core-owned database tables. Laravel's stock `users`, `password_reset_tokens`, and `sessions` tables remain host-owned and unchanged.
- Does not enable registration, personal access tokens, two-factor authentication, passkeys, OAuth, Roles & Permissions, or user management.
- Does not add a `verified` middleware globally. Individual host routes decide whether verification is required.

## Routes and API

Fortify owns the conventional web routes:

| Method and path | Purpose |
| --- | --- |
| `GET /login`, `POST /login` | Show sign-in and authenticate with a session |
| `POST /logout` | Log out, invalidate the session, and rotate the CSRF token |
| `GET/POST /forgot-password` | Request a reset link |
| `GET /reset-password/{token}`, `POST /reset-password` | Set a new password through Laravel's broker |
| `GET /email/verify`, `GET /email/verify/{id}/{hash}` | Show verification instructions and confirm a signed link |
| `POST /email/verification-notification` | Send a fresh verification notification |
| `GET/POST /user/confirm-password` | Confirm the current password for host actions that use Laravel's confirmation middleware |

The package adds `GET /api/v1/auth/user`, `GET /api/v1/auth/sessions`, and `DELETE /api/v1/auth/sessions/{id}`. API endpoints use `auth:sanctum`. The current-user payload includes only the host identifier, name, email, and verification timestamp. Session inventory works only when Laravel uses its stock database session driver and table. Session IDs are returned as SHA-256 references; session payloads and raw IDs are never exposed. The current session and sessions owned by another user cannot be revoked.

The package exposes an optional `LoginEligibility` contract. Its default implementation permits the same users as before; User Management may replace it to reject inactive accounts. A host Fortify authentication callback already configured when this provider boots is wrapped and checked through that contract. Hosts installing a callback later should apply the same eligibility check. User Management also contributes account-status middleware and authenticated Vue routes without replacing Fortify, Sanctum, the host user model, or this package's application shell.

The API prefix defaults to `api/v1/auth` and can be changed with `MASTER_CORE_AUTH_API_PREFIX`. The provider detects exact conflicts with existing host routes before registering its endpoints. Set `master-core-auth.register_fortify_routes` to `false` when the host supplies compatible replacements for all Fortify endpoints, or `master-core-auth.register_application_route` to `false` when the host owns the `/app` path.

## UI

The package's Blade mount uses the host Vite manifest and `@itassistance-bd/ui` controls. Vue Router protects `/app/*` routes, redirects guests to login, returns signed-in users away from guest pages, and validates local return paths. The initial `/app` screen is a small account-security shell for inspecting sessions; it is not a dashboard or a generic CRUD page.

## Host requirements

See [setup](../../../docs/architecture/phase4-auth-setup.md) and [host integration](../../../docs/architecture/phase4-auth-host-integration.md). In brief, the host must enable `statefulApi()` in `bootstrap/app.php`, use same-origin session cookies, allow the UI host in Sanctum's stateful domains, configure mail, and use a supported Laravel user provider. Production deployments must use HTTPS and secure cookies.

## Verification

From the repository root, use the PHP 8.4 CLI and run `php artisan test`, `npm run typecheck`, `npm run test:ui`, and `npm run build -- --emptyOutDir false`. The tests use SQLite `:memory:` and do not touch the configured host database.
