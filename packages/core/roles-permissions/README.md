# Core Roles & Permissions

This package provides focused role-based authorization for Laravel 12. It integrates registered Core abilities with Laravel Gates and Policies, uses the host application's configured Eloquent user model, and owns four RBAC tables. It does not replace `App\Models\User`, Laravel stock migrations, or Foundation persistence.

## Installation and setup

The package is discovered through Composer. Review the host database target and backup, then apply package migrations as part of a separately approved deployment. Do not use the root Composer `setup` script for an existing database because that script runs all host migrations.

1. Configure `MASTER_CORE_RBAC_MODE=compatibility` (the default).
2. Review the registered ability list in `config/roles-permissions.php` and add host-module abilities in the code-owned `extensions` section.
3. Review the host's existing Gate and Policy behavior and retain the default guard.
4. Apply the package migrations only after deployment database review.
5. Run `php artisan master-core:permissions:sync --apply` to materialize code-registered abilities. This command does not assign grants.
6. Create ordinary roles and grant only registered permissions through the UI/API.
7. Assign roles through the User Management details screen. `manage-user-roles` is a separate ability.

## Host integration and compatibility mode

In Compatibility Mode, unregistered abilities remain entirely host-owned. For registered abilities, existing explicit host Gates and contextual Policies continue to decide. A positive Core role grant acts as an additional Gate-level allow only when there is no existing host Gate or Policy; a missing Core grant returns `null` and defers to Laravel's normal authorization behavior. As a result, a legacy host Gate such as `manage-users` can remain an authorization fallback during rollout.

The default guard's Eloquent user model is resolved from Laravel's auth provider. The package supports integer, UUID, and ULID identifiers through a polymorphic assignment table without a host-user foreign key. It does not support additional guards, non-Eloquent providers, teams, tenants, or direct user permission grants in v1.

## Strict mode

Strict Mode is opt-in with `MASTER_CORE_RBAC_MODE=strict`. Before enabling it, document a host Gate/Policy fallback review and provision every required role grant. For registered abilities, a positive Core role grant is required; an explicit host Gate conflict fails closed, and contextual Policies remain additional checks. The broad User Management `manage-users` fallback is disabled in strict mode. Unknown abilities continue to host authorization. Test the target host before rollout.

## Super Admin

The reserved `core-super-admin` role is never created by migrations, seeders, registration, or first-user logic. Assign it only with the interactive `master-core:roles:provision-super-admin {user}` command, targeting an existing active, non-trashed user and confirming the target ID. Email verification is not required. The API cannot grant this role. Account status and contextual Policies still apply, and User Management destructive operations retain last-active-Super-Admin protections.

## Permission registry and cache

Only code-registered permissions can execute. The UI may materialize those definitions and edit display metadata, but cannot invent Gate abilities. Permission catalog metadata may be cached; per-user allow decisions are resolved from current database assignments and are never persistently cached. Assignment changes are therefore immediately reflected by Laravel's request-level authorization flow.

## Orphan cleanup

Because host users have no foreign key, deleting users may leave assignment rows. Soft-deleted accounts retain assignments for potential restore. `master-core:roles:cleanup-orphans` reports by default; `--apply` prompts and removes only orphan assignments for the configured host model morph type. Review output and backups before cleanup.

## Tests

From the project root, run the `Core Roles & Permissions` PHPUnit suite, then the full suite, `npm run typecheck`, `npm run test:ui`, and `npm run build -- --emptyOutDir false`. PHPUnit uses the test environment's isolated SQLite `:memory:` database; these tests do not apply migrations to the configured host database.


## External npm installation

After this package and its dependencies have been published to the verified public npm scope, install it in a consumer project with:

```sh
npm install @itassistance-bd/roles-permissions
```

The package is not asserted to be published by this source repository. Until the publisher confirms registry availability and releases a compatible version, use the repository workspace setup described in the root README.
