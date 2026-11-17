# Master Core Module Registry & Manager

The Module Manager is a read-only Laravel 12 package inventory. It lists only Core modules declared in `config/module-manager.php`; it never scans arbitrary Composer packages and does not install, update, enable, disable, migrate, or remove packages.

## Package and setup

- Composer package: `example/laravel-master-core-module-manager`.
- Frontend workspace: `@itassistance-bd/module-manager`.
- Requires Laravel 12 and Core Foundation for API prefix/version conventions.
- Uses Core Authentication's existing Vue shell, Phase 6 Gate authorization, and Core UI.
- Owns no database table, migration, seeder, or lifecycle state.

Composer package discovery registers `ModuleManagerServiceProvider`. The provider loads the package configuration and authenticated API routes. The root Vue Auth entrypoint composes the `/app/modules` route and supplies the API base URL. The route may be hidden from users without `view-modules`; the backend always enforces that ability.

Add `view-modules` through the Phase 6 code permission registry and grant it only through the approved host Roles & Permissions process. The package does not automatically grant the ability. Compatibility Mode and Strict Mode continue to follow the existing Phase 6 behavior.

## Catalog contract

Each code-defined descriptor has an immutable key, display name, description, Composer package name, provider class (internal only), direct Composer dependency package names, and a documentation path. The API returns an explicit allowlist and never returns provider class names, install paths, raw Composer metadata, environment values, or arbitrary host packages.

The Phase 10 Notifications package is included in the code-owned catalog as `core-notifications`; its readiness remains `runtime_not_verified` because the catalog does not run notification delivery probes.

The inventory checks:

- Whether the descriptor's package and each declared Composer dependency are present according to `Composer\InstalledVersions`.
- The package version when Composer reports one.
- Whether Laravel reports the configured provider as loaded.

`readiness_status` reports the most useful verified state: `not_installed`, `dependency_missing`, `provider_not_loaded`, or `runtime_not_verified`. Even when dependency and provider checks pass, runtime operation is not verified. The package does not check migrations, host-specific Gates, mail, storage, or module feature health.

Core UI is a shared npm build workspace, not a PHP runtime package. Its availability is therefore not inferred from Composer or exposed as a Composer module record.

## API

The API uses Foundation's configured prefix and version (default `/api/v1`):

| Method | Path | Access | Purpose |
|---|---|---|---|
| GET | `/modules` | `auth:sanctum` + `can:view-modules` | Searchable, paginated allowlisted Core module inventory. |
| GET | `/modules/{key}` | `auth:sanctum` + `can:view-modules` | Detail for one configured module key. |

Search, readiness filter, sorting, page number, and page size are validated. Sorting is limited to known response fields. Page sizes are 10, 25, 50, and 100. The response has a stable `success`, `data`, and pagination `meta` structure.

## Frontend

The read-only page uses the existing Auth SPA route guard and `CoreDataTable`, with search, pagination, View detail, loading, error, empty, 401, and 403 states. It has no selection/bulk or module lifecycle actions. Navigation checks `view-modules`; server-side authorization remains authoritative.

## Installation and lifecycle limits

Composer and host deployment configuration remain responsible for installation, version upgrades, package discovery, and activation. Do not expose Composer, Artisan, migration, or filesystem operations through HTTP. Runtime provider unloading and module lifecycle persistence are outside v1; changing that boundary requires a separately approved design.

No Module Manager migration is registered. Backend tests use the repository's isolated SQLite `:memory:` test setup for neighboring package regressions; the catalog itself does not need a database. Run the package feature suite, full PHP suite, Pint, Composer validation/package discovery, `npm run typecheck`, `npm run test:ui`, and `npm run build` from the project root. Authenticated browser acceptance remains a separate host deployment check.
