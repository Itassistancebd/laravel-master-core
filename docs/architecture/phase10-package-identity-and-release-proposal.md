# Phase 10 — Package Identity and Release Proposal

**Status:** Internal proposal only; publisher and registry ownership remain unverified.
**Date:** 2026-11-17

> **Update 2026-11-17:** See the [official identity migration record](phase10-package-identity-migration-record.md). The ten npm workspace identities now use the owner-confirmed `@itassistance-bd` scope. Registry ownership could not be independently checked. Composer `example/` names remain unchanged because the `itassistance-bd` candidate is unverified.

## Identity visible in this checkout

The repository identifies the product as **Laravel Master Core** and uses the PHP root namespace `MasterCore`. Composer manifests currently use these placeholder identities:

### Composer packages

| Role | Current package name | Version source |
|---|---|---|
| Entry metapackage | `example/laravel-master-core` | Path repository version `0.1.0` |
| Foundation | `example/laravel-master-core-foundation` | Path repository version `0.1.0` |
| Authentication | `example/laravel-master-core-auth` | Path repository version `0.1.0` |
| Settings | `example/laravel-master-core-settings` | Path repository version `0.1.0` |
| User Management | `example/laravel-master-core-user-management` | Path repository version `0.1.0` |
| Roles & Permissions | `example/laravel-master-core-roles-permissions` | Path repository version `0.1.0` |
| Audit Logs | `example/laravel-master-core-audit-logs` | Path repository version `0.1.0` |
| Module Manager | `example/laravel-master-core-module-manager` | Path repository version `0.1.0` |
| System Health | `example/laravel-master-core-system-health` | Path repository version `0.1.0` |
| Notifications | `example/laravel-master-core-notifications` | Path repository version `0.1.0` |
| CRUD Engine | `example/laravel-master-core-crud-engine` | Path repository version `0.1.0` |
| Project Generator | `example/laravel-master-core-project-generator` | Path repository version `0.1.0` |

The feature namespaces follow package names, for example `MasterCore\Auth`, `MasterCore\Settings`, and `MasterCore\ProjectGenerator`. The metapackage intentionally has no PHP autoload namespace.

### npm packages

At the time of the original proposal, the ten npm manifests used `@master-core`. The 2026-11-17 migration record now documents the owner-confirmed local scope `@itassistance-bd` for `ui`, `settings-ui`, `auth`, `user-management`, `roles-permissions`, `audit-logs`, `module-manager`, `system-health`, `notifications`, and `crud-engine`. External scope ownership remains unverified. Each manifest declares version `0.1.0` and MIT licensing. Vue is a peer dependency where the package needs it; each package exports built JavaScript/types and its stylesheet. PHP manifests do not declare publisher authors, and npm manifests do not declare an author or repository URL. Those fields remain absent until the publisher supplies verifiable details.

## Proposed release policy

- Keep `Master Core` as the internal product label and `MasterCore` as the PHP namespace.
- Keep the unverified Composer `example/` names unchanged until vendor ownership is verified. The local npm scope uses the owner-confirmed `@itassistance-bd` identity, with external ownership verification still outstanding.
- Continue the current `0.1.0` synchronized baseline. Within `0.x`, use patch releases for backward-compatible fixes and minor releases for incompatible API changes. Update the metapackage profile and its dependency constraints in the same reviewed release change.
- Publish Composer and npm artifacts from the same source tag and release notes, with generated `dist/` outputs included in npm tarballs.
- The repository declares PHP `^8.2`, Laravel `^12.0`, and Vue peer compatibility `^3.5.0`. The local campaign ran on PHP 8.4.27, Laravel 12.71.0, Node 24.15.0, and npm 11.12.1. These runs do not validate the minimum versions or every supported range. npm manifests do not declare Node/npm `engines`; choose and test those minimums before promising them.

This is a proposal, not evidence of a legal publisher identity or namespace control.

## Information needed from the publisher

Before replacing placeholders or enabling a real release, the publisher must supply and verify:

1. Legal publisher/maintainer name and authority to publish for the product.
2. Source repository URL and permission to create release tags.
3. Composer vendor namespace ownership, plus the chosen Packagist account or private Composer repository and its exact HTTPS URL.
4. npm organization/scope ownership, registry URL, and package visibility policy.
5. Credential storage and release automation policy; credentials must stay out of manifests and generated projects.
6. Approval of the proposed `0.x` compatibility and Node/npm support policy.

No owner identity, source remote, or trusted-source configuration was found in the checkout or process environment. The working tree has no `.git` metadata. The generator's default trusted-source file is absent. This record intentionally does not invent an organization, domain, account, package name, or repository URL.

## Acceptance levels and current evidence

| Level | Evidence/status |
|---|---|
| Local package manifests and build metadata | **PASS:** 13 Composer manifests validate; 10 npm workspaces pass `npm pack --dry-run --json --ignore-scripts`; the production build passes. |
| Clean local Composer consumer | **PARTIAL PASS:** a fresh temporary consumer installed only the Project Generator from a local path repository with `symlink=false`; its PSR-4 class autoload and Composer binary run. This does not install the Laravel Core feature set. |
| Clean npm consumer | **NOT VERIFIED:** the environment is set to npm offline mode and has no approved scope/registry configuration. Package packing is not an npm dependency installation. |
| Clean generated Laravel host | **NOT VERIFIED:** the installed generator's dry-run passed. A real install stopped before staging because trusted-source configuration is absent. |
| External registry resolution/publication | **BLOCKED:** no verified publisher identity, owned namespace, source remote, or trusted registry is configured. No account creation or publication was attempted. |

Commands and evidence:

- Composer validation: run `php composer.phar validate --no-check-publish <manifest>` for the root and each of the 12 package manifests; all 13 passed.
- npm integrity: `npm.cmd pack --dry-run --json --ignore-scripts --workspaces --cache storage/framework/testing/phase10-npm-pack-cache`; all ten tarball manifests passed. The default global npm cache first returned `EPERM`, so the successful run used the project-local cache path.
- Clean local Composer consumer: `storage/framework/testing/phase10-generator-consumer-20261117-020729/` has its own `vendor`, Composer home/cache, and lockfile. Installing `example/laravel-master-core-project-generator` from the local path with `symlink=false`, autoload smoke, and `vendor/bin/master-core help` passed. No source `vendor` was copied.
- Installed generator dry-run passed. A subsequent install attempt returned `No trusted registry configuration exists` and left `generated-laravel/` absent, confirming the early trust gate. This was not a real Laravel host install.
- Full regressions in the 2026-11-17 Phase 12 campaign: `php artisan test` (**198 tests / 980 assertions**); `npm.cmd run test` (**19 files / 79 tests**); `npm.cmd run typecheck`; `npm.cmd run build`; `npm.cmd run test:e2e` (**8 tests**); `npm.cmd run test:e2e:responsive` (**5 tests**). Responsive Admin evidence and route inventory are in the [Phase 12 report](phase12-admin-ui-refinement-implementation-report.md).

Do not treat local path resolution, package dry-runs, lock metadata, or a public availability lookup as proof of namespace ownership or public distribution readiness.
