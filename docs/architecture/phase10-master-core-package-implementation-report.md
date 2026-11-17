> Identity update: local npm workspace names now use the owner-confirmed `@itassistance-bd` scope. Composer `example/` names remain unverified placeholders. See the [identity migration record](phase10-package-identity-migration-record.md) for current validation and blockers.

# Phase 10 — Master Core Package Implementation Report

**Status: IMPLEMENTED LOCALLY; DISTRIBUTION ACCEPTANCE PARTIAL**
**Date:** 2026-11-15

## Summary

Implemented the approved lean distribution layer while keeping existing Core feature packages independent. The Composer entry package is a metapackage with Foundation as its required baseline and optional feature suggestions. It has no service provider, routes, configuration, migrations, install scripts, permission grants, or Super Admin provisioning.

The versioned `extra.master-core` profile records baseline and optional Composer/npm package identities, declared backend dependencies, migration ownership, frontend route exports, frontend stylesheet exports, and explicit activation rules. It is structured for Phase 11 Project Generator consumption; no Project Generator code was added.

## Package and registration behavior

- Composer packages use `^0.1.0` dependency constraints and local package path versions of `0.1.0`. The root development host now requires the metapackage and retains individually selected package requirements for its all-features workspace.
- Laravel package discovery remains owned by each feature provider. Discovery registers backend code and package migration paths; it does not execute migrations. Host applications can disable discovery or register providers explicitly using their existing Laravel conventions.
- Host API route composition remains in the host. The metapackage does not add a global route registrar or auto-activate optional features.
- The npm workspace remains intact. Core UI and the nine feature UIs have separate manifests, compiled ES module exports, declaration exports, stylesheet exports, Vue peer dependencies, build scripts, and `prepack` builds. Workspace development resolves the `development` source condition; published/default imports use `dist/` artifacts. Package stylesheet imports are explicit in the host entries.
- npm feature artifacts exclude backend PHP `src/` trees. The shared UI package retains its own Vue source for workspace development.
- Module Manager's allowlisted catalog now includes CRUD Engine as informational inventory. Its API authorization, opt-in resource model, and test-only fixture boundary are unchanged.

## Security and compatibility boundaries

- No automatic migrations, permission materialization or grants, Super Admin provisioning, first-user behavior, or global feature activation were introduced.
- Feature packages continue to own configuration, routes, middleware, permission registration, audit integration, and migrations.
- User Management, Roles & Permissions, Audit Logs, Notifications, and CRUD Engine behavior was not replaced or generalized by the distribution layer.
- Current verification is recorded in the [identity migration report](phase10-package-identity-migration-record.md). The ten local npm packages use owner-confirmed `@itassistance-bd`; external organization ownership is unverified. Composer `example/` vendor names remain unchanged because the candidate is unverified. The GitHub organization exists but returned no public repositories; no repository URL is known. External publication remains blocked.
- Phase numbering remains historically consistent with the existing repository records, including the existing Phase 9 and Phase 10 label overlap.

## Files created or modified

- Created `packages/core/master-core/composer.json` and `packages/core/master-core/README.md`.
- Created `docs/architecture/master-core-package-installation.md` and this report.
- Updated root `composer.json` and `composer.lock` for metapackage/local path resolution and SemVer package constraints.
- Updated root `package.json` and `package-lock.json` for the workspace package build workflow.
- Updated npm manifests for `packages/core/ui/` and feature UI packages under `packages/core/{settings,auth,user-management,roles-permissions,audit-logs,module-manager,system-health,notifications,crud-engine}/`.
- Added per-package `tsconfig.build.json` files and `resources/js/dev-styles.css` workspace style condition files for feature UIs.
- Added `scripts/build-core-packages.mjs` and updated host Vite entrypoints `resources/js/auth.ts` and `resources/js/settings.ts`.
- Updated Module Manager's `CoreModuleRegistry` and catalog test for the CRUD Engine descriptor.
- Added `tests/Feature/MasterCorePackageProfileTest.php` and updated the root `.gitignore` for generated package `dist/` artifacts.
- Updated this proposal, the installation guide, and the root README's Phase 10 Master Core Package status and links.

## Verification results

| Check | Result |
|---|---|
| Composer `validate --no-check-publish` for root and all 11 PHP package manifests | Passed |
| Composer dependency resolution, Foundation-only profile | Passed (`update --dry-run --no-install`) |
| Composer dependency resolution, selected Settings/Audit Logs/CRUD Engine profile | Passed (`update --dry-run --no-install`) |
| Laravel `artisan package:discover --ansi` | Passed; feature providers discovered, metapackage has no provider |
| PHP test suite on SQLite `:memory:` | Passed: 144 tests, 724 assertions |
| Pint on changed PHP registry and tests | Passed |
| npm workspace lock refresh, offline | Passed; 0 reported vulnerabilities |
| npm workspace TypeScript check | Passed (`npm run typecheck`) |
| npm frontend tests | Passed: 15 files, 51 tests |
| Package distributable build | Passed for all 10 npm workspaces, including JavaScript, declarations, and stylesheet artifacts |
| Standalone System Health package build | Passed; the build script first built local UI and Auth declaration dependencies, then System Health |
| npm pack dry-run | Passed for all 10 workspaces; feature tarballs no longer include backend PHP source trees |
| Root production Vite build | Passed: 101 modules; output isolated under `storage/framework/testing/phase10-master-core-final-build` |
| CRUD Engine catalog regression | Passed in Module Manager suite; no fixture route was registered by adding the descriptor |

The repository's PHP test configuration sets SQLite `:memory:`. No application database migration or database file modification was performed. Composer dependency matrix checks were resolution dry-runs; no clean external registry install was possible with placeholder namespaces.

## Remaining publishing and acceptance work

Before publishing, select and verify the Composer vendor, npm scope, source repository, and registry access; replace placeholders in a separately reviewed release change; establish release tags and a compatibility matrix; and perform clean consumer installations from the actual registries.

PHP 8.2 CI, supported MySQL migration testing, and browser acceptance in a clean consuming host remain outstanding. Package builds and tests run here used the available PHP 8.4.27, current Vue/Vite workspace, and SQLite in-memory test configuration. These results do not claim external publication or production acceptance.

## Completion boundary

No application migration was run, no production database was changed, no permissions were granted, no Super Admin was provisioned, no commit or push was performed, and Phase 11 was not started. This workspace has no `.git` directory, so Git history and remote publication could not be inspected.

## Core Completion Campaign follow-up (2026-11-17)

Current verification validates all **13 Composer manifests** (root plus 12 packages), runs npm pack dry-runs for all **10 npm packages**, and builds the production artifacts. The Project Generator itself also installs from a local path repository in a fresh isolated Composer consumer; its PSR-4 autoload and binary work there. This is below the clean Laravel host and npm consumer acceptance levels. The full generated-host install is blocked by missing trusted identities/registry configuration; public publication was not attempted. See the [identity/release proposal](phase10-package-identity-and-release-proposal.md) and [campaign report](core-completion-campaign-2026-11-17.md).
