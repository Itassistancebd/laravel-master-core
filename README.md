# Laravel Master Core

Laravel Master Core is a modular Laravel 12 codebase with a Laravel development host, reusable Core packages, an admin UI, and a standalone Project Generator. Business features remain host-owned; the package discovery and registry do not automatically migrate databases or grant permissions. Historical phase numbering is documented in the [Master Roadmap](docs/architecture/master-roadmap.md).

## Project status and package identity

- The repository contains ten npm workspaces under the owner-confirmed local scope `@itassistance-bd`. External npm organization ownership has not been independently verified.
- Composer package names still use the `example/` placeholder vendor. The candidate vendor has not been verified, and external Composer distribution is not ready.
- Phase 10 distribution and Phase 11 generated-host acceptance remain **PARTIAL**. See the [identity migration report](docs/architecture/phase10-package-identity-migration-record.md) and [roadmap](docs/architecture/master-roadmap.md).

## Requirements

- PHP 8.2 or later and Composer 2.x. Check `composer.json` for required PHP extensions.
- Laravel 12 for the host application.
- Node.js and npm for the Vue/TypeScript frontend. The repository does not currently declare a minimum Node.js/npm engine version.
- SQLite is used by automated tests. A host may configure MySQL 8+ or another Laravel-supported database after reviewing its environment and migrations.

## Local development setup

From the repository root, install the local path packages and npm workspaces:

```powershell
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
if (-not (Select-String -Path .env -Pattern '^APP_KEY=.+$' -Quiet)) { php artisan key:generate }
npm.cmd install
npm.cmd run build
```

On macOS/Linux, use `test -f .env || cp .env.example .env`, `grep -q '^APP_KEY=.' .env || php artisan key:generate`, `npm install`, and `npm run build`. These commands preserve an existing environment file and application key. If multiple PHP versions are installed, put the intended PHP 8.2+ executable on `PATH` or set `PHP_BINARY` to its full path when invoking browser tests. The Playwright runner falls back to `php` on `PATH`.

Review `.env` and configure a local database before manually running any migrations. Composer package discovery does not run migrations. Automated PHPUnit and Playwright tests use in-memory or uniquely named temporary SQLite databases; they do not use the configured application database. Do not run setup migrations against a production or shared database.

## Tests and checks

```powershell
composer test
npm.cmd run test
npm.cmd run typecheck
npm.cmd run build
npm.cmd run test:e2e
npm.cmd run test:e2e:responsive
```

Use `npm` instead of `npm.cmd` outside Windows. Playwright requires its Chromium browser to be installed (`npx playwright install chromium`). Browser tests start a local PHP server and use isolated temporary SQLite databases; see [Browser QA](docs/browser-qa.md).

## Architecture overview

- `app/` and `resources/` contain the Laravel host, Categories pilot, admin shell, and host frontend composition.
- `packages/core/` contains Foundation, the Composer metapackage, Settings, Authentication, User Management, Roles & Permissions, Audit Logs, Module Manager, System Health, Notifications, and CRUD Engine.
- `packages/core/*/package.json` defines the ten npm workspace packages: `ui`, `settings-ui`, `auth`, `user-management`, `roles-permissions`, `audit-logs`, `module-manager`, `system-health`, `notifications`, and `crud-engine`.
- `packages/tools/project-generator/` contains the standalone Composer CLI. Real external installs remain gated on verified package identities and trusted registries.
- `docs/architecture/` records proposals, implementation results, acceptance status, and known limitations.

## License

Laravel Master Core is licensed under the MIT License. See [LICENSE](LICENSE) for the complete terms. You may use, copy, modify, distribute, sublicense, or sell copies subject to those terms; include the copyright and permission notice with copies or substantial portions. The license also includes its warranty disclaimer. The project has not been published or released.

## Settings module

The Settings package stores code-defined setting overrides in `master_core_settings`. It does not store passwords, tokens, credentials, or API secrets. The public API returns only fields explicitly marked public in the package configuration. Administrative routes require the host application's authentication plus the configured `view-settings` or `manage-settings` authorization ability. Settings persistence stays package-owned and is independent of Foundation.

- [Settings package guide](packages/core/settings/README.md)
- [Core UI package guide](packages/core/ui/README.md)
- [Settings proposal and implementation decisions](docs/architecture/phase3-settings-proposal.md)
- [Phase 3 completion report](docs/architecture/phase3-settings-completion.md)

## Authentication and security

The Authentication package uses Fortify web routes and Sanctum's same-origin session-cookie flow. Registration, personal access tokens, MFA, passkeys, and OAuth are excluded. The existing `App\Models\User` remains the host identity model and supports Laravel email verification. Private Settings and User Management APIs require host-defined abilities; Phase 6 adds the separate Core RBAC package.

- [Authentication package guide](packages/core/auth/README.md)
- [Authentication setup guide](docs/architecture/phase4-auth-setup.md)
- [Host integration guide](docs/architecture/phase4-auth-host-integration.md)
- [Phase 4 proposal and approved decisions](docs/architecture/phase4-auth-security-proposal.md)
- [Phase 4 completion report](docs/architecture/phase4-auth-security-completion.md)

## User Management

User Management operates on the configured host Eloquent user model. It adds `is_active` and `deleted_at` through a package-owned additive migration, preserves the stock users migration and unique email constraint, and integrates active-account checks with Fortify/Sanctum. The host must define user-management abilities and provide a safety policy before deactivation or deletion is allowed.

- [User Management package guide](packages/core/user-management/README.md)
- [User Management setup guide](docs/architecture/phase5-user-management-setup.md)
- [Host integration guide](docs/architecture/phase5-user-management-host-integration.md)
- [Phase 5 proposal](docs/architecture/phase5-user-management-proposal.md)
- [Phase 5 completion report](docs/architecture/phase5-user-management-completion.md)

## Roles & Permissions

The package adds role-based grants to registered Laravel abilities while preserving the host user model and Laravel stock migrations. Compatibility Mode is the default; Strict Mode requires documented host fallback review and grant provisioning. Super Admin provisioning is interactive and console-only.

- [Roles & Permissions package guide](packages/core/roles-permissions/README.md)
- [Phase 6 proposal and approved decisions](docs/architecture/phase6-roles-permissions-proposal.md)
- [Phase 6 completion report](docs/architecture/phase6-roles-permissions-completion.md)

## Audit Logs

Phase 7 provides an append-only security and administration history package with authenticated, read-only access. Export is registered as a permission only; no export feature is included. Audit retention, database privileges, and trusted proxy settings require host deployment review.

- [Audit Logs package guide](packages/core/audit-logs/README.md)
- [Phase 7 proposal and implementation conditions](docs/architecture/phase7-audit-logs-proposal.md)
- [Phase 7 completion report](docs/architecture/phase7-audit-logs-completion.md)

## Module Registry & Manager

Phase 8 provides a read-only inventory of allowlisted Core packages. Composer and deployment configuration remain responsible for package installation and activation; the inventory does not establish operational readiness.

- [Module Manager package guide](packages/core/module-manager/README.md)
- [Phase 8 proposal and implementation decisions](docs/architecture/phase8-module-manager-proposal.md)
- [Phase 8 completion report](docs/architecture/phase8-module-manager-completion.md)

## System Health & Readiness

Phase 9 adds authenticated read-only checks for the current diagnostic request and the configured primary database. The Foundation public health contract remains database-independent. Module Manager package status remains distinct from runtime check results.

- [System Health package guide](packages/core/system-health/README.md)
- [Phase 9 proposal and implementation decisions](docs/architecture/phase9-system-health-readiness-proposal.md)
- [Phase 9 completion report](docs/architecture/phase9-system-health-readiness-completion.md)

The CRUD Engine was also requested and implemented under the Phase 9 label. This reuses the number already assigned to System Health; both historical records are preserved, and the duplicate numbering remains a roadmap documentation issue.

- [CRUD Engine package guide](packages/core/crud-engine/README.md)
- [CRUD Engine proposal and implementation decisions](docs/architecture/phase9-crud-engine-proposal.md)
- [CRUD Engine implementation report](docs/architecture/phase9-crud-engine-implementation-report.md)

## Notifications & Notification Center

Phase 10 provides a recipient-scoped in-app inbox with read state. It is separate from Authentication email notifications, Audit Logs, and operational logs. The package does not send email or external notifications.

- [Notifications package guide](packages/core/notifications/README.md)
- [Phase 10 proposal and implementation decisions](docs/architecture/phase10-notifications-proposal.md)
- [Phase 10 completion report](docs/architecture/phase10-notifications-completion.md)

## Phase 10 - Master Core Package (IMPLEMENTED LOCALLY; DISTRIBUTION PARTIAL)

- [Master Core Package implementation proposal](docs/architecture/phase10-master-core-package-proposal.md)
- [Installation and compatibility guide](docs/architecture/master-core-package-installation.md)
- [Package identity and release proposal](docs/architecture/phase10-package-identity-and-release-proposal.md)
- [Implementation report](docs/architecture/phase10-master-core-package-implementation-report.md)

## Phase 11 - Project Generator (LOCALLY IMPLEMENTED; GENERATED-HOST ACCEPTANCE PARTIAL)

- [Project Generator inspection and planning proposal](docs/architecture/phase11-project-generator-proposal.md)
- [Project Generator CLI usage and troubleshooting](docs/architecture/phase11-project-generator-usage.md)
- [Project Generator implementation report](docs/architecture/phase11-project-generator-implementation-report.md)

## API

`GET /api/v1/core/health` returns JSON with `success: true` and a `data` object containing the configured name and version plus `status: healthy`. The route path is built from `api_prefix` and `api_version`. Set `enable_health_endpoint` to `false` to return 404. The response never includes credentials, environment values, PHP details, or server paths.

See [Foundation package documentation](packages/core/foundation/README.md), [architecture](docs/architecture/overview.md), and [development guide](docs/development/local-development.md).

## Project standards

- [Master Core roadmap](docs/architecture/master-roadmap.md)
- [Core Completion Campaign report](docs/architecture/core-completion-campaign-2026-11-17.md)

- [Global UI and CRUD standards](docs/standards/global-ui-crud.md)
- [Development standards](docs/standards/development.md)
- [Database architecture principles](docs/architecture/database.md)
- [Phase 2 completion report](docs/architecture/phase2-completion.md)
- [Phase 3 Settings proposal](docs/architecture/phase3-settings-proposal.md)
- [Phase 4 Authentication & Security proposal](docs/architecture/phase4-auth-security-proposal.md)
- [Phase 5 User Management proposal](docs/architecture/phase5-user-management-proposal.md)
- [Phase 5 User Management completion report](docs/architecture/phase5-user-management-completion.md)
- [Phase 6 Roles & Permissions proposal and approved decisions](docs/architecture/phase6-roles-permissions-proposal.md)
- [Phase 6 Roles & Permissions completion report](docs/architecture/phase6-roles-permissions-completion.md)

## Versioning and distribution

The package will be versioned independently from host applications. Until a vendor identity is selected, keep the `example/` Composer name for local path development. A future release can be distributed from a Git VCS repository or private Composer repository after replacing that placeholder name with an owned vendor name and tagging releases using semantic versions. No public distribution is configured.
