# Laravel Master Core

> Categories is not part of the current product. Its Admin page, routes, API, permissions, CRUD registration, and generator defaults are removed. The historical Categories migration is retained for existing database history; no table is dropped and existing records are not changed.

Laravel Master Core is a modular Laravel 12 codebase with a Laravel development host, reusable Core packages, an admin UI, and a standalone Project Generator. Business features remain host-owned; the package discovery and registry do not automatically migrate databases or grant permissions. Historical phase numbering is documented in the [Master Roadmap](docs/architecture/master-roadmap.md).

## Project status and package identity

- The repository contains ten npm workspaces under `@itassistance-bd`. Authenticated npm verification confirmed account `itassistancebd` is an organization owner with account 2FA enabled; all ten package names currently return registry 404.
- Composer package names still use the `example/` placeholder vendor. The candidate vendor has not been verified, and external Composer distribution is not ready.
- Phase 10 external package publication remains **PARTIAL**: Composer vendor ownership is unverified and the ten npm package names are not published. Global project generation avoids publishing internal packages by resolving all 12 Composer packages and 10 npm workspaces from the included monorepo. Phase 11 local generation remains **PARTIAL** until third-party installation and clean Windows generation complete. See the [integrated distribution implementation report](docs/architecture/phase10-11-integrated-distribution.md), [identity migration report](docs/architecture/phase10-package-identity-migration-record.md), and [roadmap](docs/architecture/master-roadmap.md).

## Global CLI readiness

A Windows bootstrap installer and `master` command are now prepared in this source tree. The existing public `main` branch has not been updated with these files, so the bootstrap command is **not yet available from GitHub**. Do not run the remote install command until an authorized source update includes `install.ps1` and `scripts/master-cli.ps1`.

`master new example-app` downloads the canonical monorepo archive and delegates to the Phase 11 Generator's monorepo-template installer. It copies the existing Laravel application rather than creating a Laravel skeleton. All Composer Core packages resolve from the included relative path repositories, and npm Core packages resolve from local workspaces; Composer/npm use their normal registries for third-party dependencies. Generation validates the output, prepares `.env`, creates an application key, runs package discovery, installs/builds frontend dependencies, and does not run migrations or seeders. `master status`, `master changes`, and `master publish --dry-run` inspect this source repository; `master publish` requires path-by-path staging approval followed by separate commit and push confirmations. See the [global CLI guide](docs/development/global-cli.md), [GitHub distribution status](docs/development/github-distribution.md), and [release/update guide](docs/development/release-and-updates.md).

After the installer is included on the public branch, the planned Windows setup and project commands are:

```powershell
irm https://raw.githubusercontent.com/Itassistancebd/laravel-master-core/main/install.ps1 | iex
master new example-app
cd example-app
php artisan serve
```

The public bootstrap and project generation commands become available to fresh PCs only after the installer, CLI, and template-generator changes are published to the existing `main` branch. Local implementation acceptance is being verified separately.

For an existing generated host, review the [release and update guide](docs/development/release-and-updates.md); `master update` updates the CLI only. If installation fails, record the command, Windows and PowerShell versions, PHP/Composer/Git versions, and the error text after removing credentials and private paths. Use the repository's GitHub Issues page only if Issues are enabled; never attach `.env`, logs containing credentials, databases, private keys, or user data.

## Requirements

- PHP 8.4 or later and Composer 2.x. Check `composer.json` for required PHP extensions.
- Laravel 12 for the host application.
- Node.js 20.19+ or 22.12+, and npm 7+, for CLI project generation and frontend builds.
- SQLite is used by automated tests. A host may configure MySQL 8+ or another Laravel-supported database after reviewing its environment and migrations.

## Local development setup

From the repository root, install the local path packages and npm workspaces:

```powershell
.\scripts\project-command.ps1 composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
if (-not (Select-String -Path .env -Pattern '^APP_KEY=.+$' -Quiet)) { .\scripts\project-command.ps1 php artisan key:generate }
npm.cmd install
npm.cmd run build
```

On macOS/Linux, use `test -f .env || cp .env.example .env`, `grep -q '^APP_KEY=.' .env || php artisan key:generate`, `npm install`, and `npm run build`. These commands preserve an existing environment file and application key. On Windows, configure the ignored `.php-runtime.local.json` file and run project PHP and Composer commands through `scripts/project-command.ps1`; see [local development](docs/development/local-development.md). The wrapper checks PHP 8.4+ and adjusts PATH only for that launched command. Playwright reads the same runtime configuration and reports its selected executable.

Review `.env` and configure a local database before manually running any migrations. Composer package discovery does not run migrations. Automated PHPUnit and Playwright tests use in-memory or uniquely named temporary SQLite databases; they do not use the configured application database. Do not run setup migrations against a production or shared database.

## Tests and checks

```powershell
.\scripts\project-command.ps1 composer test
npm.cmd run test
npm.cmd run typecheck
npm.cmd run build
npm.cmd run test:e2e
npm.cmd run test:e2e:responsive
```

On Windows, run Composer and direct PHP commands above through `scripts/project-command.ps1` so Composer's `@php` scripts inherit the configured PHP 8.4+ runtime. The PHP runtime configuration is local to this checkout and is not used in distributed consumer packages.

Use `npm` instead of `npm.cmd` outside Windows. Playwright requires its Chromium browser to be installed (`npx playwright install chromium`). Browser tests start a local PHP server and use isolated temporary SQLite databases; see [Browser QA](docs/browser-qa.md).

## Architecture overview

- `app/` and `resources/` contain the Laravel host, admin shell, and host frontend composition. Categories is excluded from current routes and UI.
- `packages/core/` contains Foundation, the Composer metapackage, Settings, Authentication, User Management, Roles & Permissions, Audit Logs, Module Manager, System Health, Notifications, and CRUD Engine.
- `packages/core/*/package.json` defines the ten npm workspace packages: `ui`, `settings-ui`, `auth`, `user-management`, `roles-permissions`, `audit-logs`, `module-manager`, `system-health`, `notifications`, and `crud-engine`.
- `packages/tools/project-generator/` contains the standalone Composer CLI. Real external installs remain gated on verified package identities and trusted registries.
- `docs/architecture/` records proposals, implementation results, acceptance status, and known limitations.

## External package distribution

The local source uses npm workspaces and Composer path repositories for development. The candidate Composer vendor remains unverified. The generator's trusted registry configuration maps explicitly configured Composer identities and the verified `@itassistance-bd` scope into generated consumer manifests; it does not register or publish packages. See the [controlled package release guide](docs/development/package-release-guide.md) and [Phase 10 and 11 distribution report](docs/architecture/phase10-11-integrated-distribution.md) for release commands, local artifact checks, and remaining external gates.

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

Composer source packages retain local `example/` identities and are resolved only from monorepo path repositories during generation. Packagist is not used for our own packages; normal public registries remain enabled for Laravel and third-party dependencies. No separate package repositories are required. Public global CLI installation still depends on publishing the local installer and CLI changes to the existing repository. See [GitHub distribution status](docs/development/github-distribution.md).
