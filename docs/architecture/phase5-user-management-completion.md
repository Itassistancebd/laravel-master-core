# Phase 5 User Management Completion

> **Status: IMPLEMENTED.** Verification results below are from the local package and isolated test environment. The configured host application database was not migrated.

## Scope delivered

- Added `packages/core/user-management` as a separately maintainable Composer and npm package. It operates on the configured host Eloquent user model and does not add a Core User model or users table.
- Added a package-owned additive migration for `is_active` and `deleted_at`, with indexes and collision checks. Rollback refuses to discard inactive or trashed account state. The Laravel stock user migration remains untouched.
- Integrated account eligibility with Core Authentication. Inactive accounts are rejected during login and are checked again on authenticated web/API requests. The existing `App\Models\User` gained `SoftDeletes` and an `is_active` cast; its identity fields and authentication contracts remain in place.
- Added server-side user listing, search/filter/sort/pagination, safe resource serialization, validated create/update, status changes, trash/restore/permanent-delete flows, bounded bulk operations, verification resend, and password-reset initiation. Host authorization Gates remain authoritative. Destructive actions fail closed until a host `UserManagementSafetyPolicy` is configured.
- Added reusable `CoreDataTable` to the Core UI package and composed User Management pages into the existing authenticated Vue 3/TypeScript router.
- Added backend/frontend tests, package documentation, setup and host-integration guides, and this completion record.

## Database and integration state

The package migration is present and discovered by the provider, but **was not run against the Laravel application's configured database**. Backend migration tests use an isolated in-memory SQLite connection. A host deployment must review its target, backup, authorization Gates, safety policy, and model compatibility before applying package migrations.

No Laravel stock migration or `App\Models\User` identity schema was replaced. No roles, permissions, audit-log table, or Phase 6 functionality was added. Roles & Permissions remain a separate upcoming module responsibility.

The implementation preserves the Phase 2 decision that Foundation owns zero database tables. Settings owns its own persistence. User Management owns only its additive lifecycle migration against the host-owned user table.

## API and UI

The API uses the Foundation prefix/version configuration (default `/api/v1/users`), Sanctum authentication, and explicit host-defined abilities. It provides capabilities, user list/detail/create/update, status operations, trash/restore/permanent delete, bounded bulk operations, password reset, and verification resend. List query fields and sort columns are allow-listed; user resources omit password and remember-token fields.

The UI uses the existing Auth application shell and shared Core UI. It provides user list, create/edit, details, and trash views, with server-driven search and pagination, row selection, column visibility, confirmation states, validation feedback, and permission/error handling. Browser acceptance against a running host app was not performed as part of automated verification.

## Verification

Final verification ran locally with PHP 8.4.27 (the default CLI PHP 8.3.35 is below the dependency lock's PHP 8.4.1 requirement):

- `php artisan test --no-ansi` — **passed: 51 tests, 183 assertions**, including Foundation, Settings, Authentication, and User Management. PHPUnit is configured for SQLite `:memory:`.
- `php vendor/bin/pint --test ...` on changed PHP sources and tests — **passed**.
- `npm run typecheck` — **passed**.
- `npm run test:ui -- --reporter=dot` — **passed: 7 files, 21 tests**.
- `npm run build -- --emptyOutDir false` — **passed: 129 modules transformed**.
- `composer validate --no-check-publish --no-interaction` for the root and User Management manifests — **passed**. Composer reports only the local placeholder package version fallback.
- `php artisan route:list --path=api/v1/users --no-ansi` — **13 routes registered**. Authentication and authorization middleware are covered by feature tests.

No migration command was run against the configured application database. Automated migration tests use isolated SQLite memory. MySQL integration and browser acceptance were not run.

## Host setup and limitations

See [the package guide](../../packages/core/user-management/README.md), [setup guide](phase5-user-management-setup.md), and [host integration guide](phase5-user-management-host-integration.md). Before enabling the module, the host must define the documented user-management abilities and a safety policy that protects critical accounts. By default, status and deletion operations that can compromise account safety are denied until that policy is supplied.

The current implementation targets Eloquent host models with the standard identity fields and Laravel notification/password-broker behavior. Custom models with incompatible identifiers, fields, authentication providers, or session storage may require an adapter and separately reviewed integration.

Phase 6 Roles & Permissions has not started.
