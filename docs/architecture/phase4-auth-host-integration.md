# Phase 4 Host Application Integration

The Core Authentication package is a separately maintainable Composer package. A consuming host provides the user model/provider, database tables, mail transport, cache/session drivers, cookie/domain policy, and authorization policy.

## Session SPA contract

The Vue app makes same-origin credentialed requests and does not store session credentials in local storage. Before login, reset, or other state-changing requests it initializes the Sanctum CSRF cookie. Laravel's web middleware validates CSRF for Fortify POST routes. The host must keep the `web` guard enabled, call `statefulApi()` for API requests, and include the SPA origin in `SANCTUM_STATEFUL_DOMAINS`.

API requests use `auth:sanctum`; a missing or expired session returns JSON `401` when the client sends `Accept: application/json`. Authorization remains a separate host concern. In particular, keep the Settings `view-settings` and `manage-settings` abilities defined by the host or future Roles & Permissions module. This package grants neither ability.

The Vue Router protects `/app/*`, sends guests to `/login`, and sends authenticated users away from login/reset pages. Return paths are restricted to local paths so an attacker cannot redirect a successful login to an external site. A stale session returns the user to login. A CSRF `419` is shown as a recoverable request error; credentials are not automatically resubmitted.

## User model and database ownership

The host's configured authenticatable model is authoritative. Do not replace `App\Models\User`, rename the stock tables, or add a Core-owned user table. The current implementation adds only `MustVerifyEmail` to the existing host model. Custom host models should expose the identity fields used by the current-user response and support Laravel's password broker and verification notification contract.

Laravel stock `users`, `password_reset_tokens`, and `sessions` tables remain unchanged. The package has no migration and does not run migrations. Account registration and account provisioning belong to the host or a future User Management module.

## Mail and session operations

Configure Laravel mail and `APP_URL` before enabling reset and verification emails. Reset requests return a generic message whether or not an account exists. Reset tokens are issued and verified through the stock Laravel password broker. A reset requires at least 12 characters, mixed case, and numbers, rotates the remember token, and deletes database-backed sessions for that user when the configured stock session table is available.

Login rotates the session ID through Fortify's session preparation. Logout invalidates the session and rotates the CSRF token. The default session lifetime and cookie settings come from the host's Laravel session configuration. Production should require HTTPS and secure HttpOnly cookies.

When `SESSION_DRIVER=database`, the UI can list safe metadata for the authenticated user's sessions and revoke a selected other session. It does not display the session payload or raw session identifier. It cannot revoke the current session from that inventory screen. Other session drivers receive a `501` capability response; hosts may add a dedicated adapter in a later approved change.

## Route ownership

Fortify registers its conventional `/login`, `/logout`, `/forgot-password`, `/reset-password`, and email-verification routes. Review for route-name/path conflicts before adding the package to an existing app. `master-core-auth.register_fortify_routes=false` disables Fortify route registration for hosts that supply replacements; custom routes must preserve the web guard, CSRF, rate limits, and response contracts. The Core `/app/{path?}` route is protected by the host's configured `auth` middleware.

## Security boundaries

- Do not expose private Settings values through public APIs.
- Do not grant Roles, permissions, or Settings abilities during login.
- Do not log passwords, reset/verification links, cookies, or raw session IDs.
- Do not expose bearer-token endpoints or add Sanctum token tables in this phase.
- Keep API authorization checks on the server; frontend route guards are for navigation only.
- For sibling-subdomain SPAs, configure exact CORS origins, stateful domains, cookie domain, SameSite, and `Secure` together. Never combine credentialed requests with wildcard origins.
