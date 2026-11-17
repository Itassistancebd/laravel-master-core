> Final identity verification: the generator profile and local npm workspaces consistently use `@itassistance-bd`; Composer dependencies remain the unchanged `example/...` identities. The focused suite passes 20 tests / 52 assertions. External trusted registries and a real generated consumer remain unverified; see the [identity migration report](phase10-package-identity-migration-record.md).

# Phase 11 Project Generator — Implementation Report

**Status: LOCALLY IMPLEMENTED; GENERATED-HOST ACCEPTANCE PARTIAL**
**Date:** 2026-11-16
**Specification:** [Phase 11 Project Generator proposal](phase11-project-generator-proposal.md)

## Implementation

Added the standalone Composer CLI at `packages/tools/project-generator/`. It uses only PHP `^8.2` and has no runtime dependency on Laravel or third-party libraries. The profile loader validates schema 1 against the bundled package profile and, when run from this checkout, cross-checks the Composer and npm feature manifests, package dependency declarations, exports, compatibility constraints, and styles.

The planning layer distinguishes required Foundation, explicitly requested backend features, frontend features, their Composer/npm dependency closures, route factories, styles, and package migration owners. It rejects unknown/duplicate feature keys, unsupported version constraints, dependency cycles, and frontend selections without matching backend features. CRUD Engine stays resource-owned and receives no generic routes.

The CLI provides:

- `master-core new <target>` for interactive or explicitly non-interactive creation.
- `--dry-run --json` for a process-free, file-free plan.
- `master-core plan <selection.json>` for deterministic selection plans.
- `master-core doctor [project]` for read-only manifest, Composer/npm selection, and generated-file checks.

Non-interactive mode requires both backend and frontend selections; an actual install also requires `--yes`. Core Auth requires an explicit Auth backend selection. Runtime checks enforce PHP `^8.2`, Laravel `^12.0` profile metadata, Composer availability, and Node/npm availability when frontend packages are selected.

## Installation and safety behavior

The installer rejects existing targets and requires an existing writable parent. It checks trusted sources before creating staging output. On success it creates a new Laravel 12 skeleton in a unique sibling staging directory, adds only requested Composer packages and selected npm packages, writes generated instructions and composition files, resolves package constraints, performs explicit Laravel package discovery, writes a manifest, and atomically moves the completed stage to the target.

Composer runs with scripts and plugins disabled. The scaffold command receives only configured HTTPS repositories and disables implicit Packagist fallback. The same repository policy is written into the generated Composer manifest and retained in the non-secret `.master-core-composer-home` directory. Frontend projects receive an `.npmrc` that pins the approved HTTPS npm registry. npm lifecycle scripts are disabled. Child process output is discarded to avoid exposing credentials. A failure writes a sanitized recovery record and preserves the staging directory. The installer does not remove or replace user files.

The generator never invokes migrations, seeders, database reset commands, permission grants, Strict Mode activation, or Super Admin provisioning. Generated setup documentation requires manual configuration and migration review. Settings gets a mount helper, Auth/Core route factories are composed explicitly, and styles are listed in a generator-owned stylesheet. No host entrypoint is overwritten.

## Package and workspace integration

- Added `packages/tools/project-generator/composer.json` with the executable binary and PSR-4 autoloading.
- Added a local path repository and development requirement to root `composer.json`; updated `composer.lock` for package `0.1.0`.
- Registered the generator test suite in `phpunit.xml`.
- Added the CLI guide and troubleshooting instructions at [phase11-project-generator-usage.md](phase11-project-generator-usage.md).
- Updated the proposal status and the Phase 11 README links.

The feature catalog remains sourced from `packages/core/master-core/composer.json` and npm manifests. No feature package, Laravel application, database schema, or historical phase implementation was changed.

## Files created

- `packages/tools/project-generator/bin/master-core`
- `packages/tools/project-generator/composer.json`
- `packages/tools/project-generator/README.md`
- `packages/tools/project-generator/resources/profiles/master-core-v1.json`
- `packages/tools/project-generator/src/Console/Application.php`
- `packages/tools/project-generator/src/Doctor/Doctor.php`
- `packages/tools/project-generator/src/Installation/InstallationException.php`
- `packages/tools/project-generator/src/Installation/NativeProcessExecutor.php`
- `packages/tools/project-generator/src/Installation/Preflight.php`
- `packages/tools/project-generator/src/Installation/ProcessExecutor.php`
- `packages/tools/project-generator/src/Installation/ProcessFailureException.php`
- `packages/tools/project-generator/src/Installation/ProjectInstaller.php`
- `packages/tools/project-generator/src/Installation/TargetPath.php`
- `packages/tools/project-generator/src/Installation/TrustedSources.php`
- `packages/tools/project-generator/src/Planning/PlanException.php`
- `packages/tools/project-generator/src/Planning/ProjectPlan.php`
- `packages/tools/project-generator/src/Planning/ProjectPlanner.php`
- `packages/tools/project-generator/src/Profile/CoreProfile.php`
- `packages/tools/project-generator/src/Profile/ProfileException.php`
- `packages/tools/project-generator/src/Profile/ProfileLoader.php`
- `packages/tools/project-generator/src/Profile/ProfileValidator.php`
- `packages/tools/project-generator/tests/ProjectGeneratorTest.php`
- `packages/tools/project-generator/tests/bootstrap.php`
- `docs/architecture/phase11-project-generator-usage.md`
- `docs/architecture/phase11-project-generator-implementation-report.md`

## Files modified

- Root `composer.json` and `composer.lock`
- Root `phpunit.xml`
- Root `README.md` (Phase 11 links/status only)
- `docs/architecture/phase11-project-generator-proposal.md` (implementation status and boundary only)

## Verification results

| Check | Result |
|---|---|
| Generator PHPUnit suite | **Passed:** 20 tests, 52 assertions (2026-11-17) |
| Root PHPUnit/Laravel suite | **Passed:** 198 tests, 980 assertions (2026-11-17); configured by `phpunit.xml` to use SQLite `:memory:` |
| PHP syntax checks | **Passed:** every generator PHP file linted successfully on PHP 8.4.27 |
| Pint | **Passed:** generator source and tests pass `pint --test` |
| Root Composer validation | **Passed:** `composer validate --no-check-publish` |
| Generator Composer manifest validation | **Passed:** `composer validate --no-check-publish packages/tools/project-generator/composer.json` |
| Locked package metadata | **Passed:** Composer reports the local generator package, version `0.1.0`, library type, binary, and autoload metadata |
| CLI smoke check | **Passed:** `help` and a Foundation-only JSON dry run; dry-run target remained absent |
| Native process argument-array test | **Passed:** PHP 8.4 on Windows executed a harmless exit-only child process without a shell string |
| Failure recovery and no-migration checks | **Passed in isolated fake-process tests:** staging and recovery marker are retained; no migration or seeder command is issued |
| Trusted-source rejection | **Passed:** placeholder `example/` package identity is rejected before staging or package-manager execution |

The Composer lock update completed using the local path repository and PHP 8.4.27. Composer reported that Packagist could not be reached for advisory/filter data; no external package installation was attempted. A Composer autoload regeneration attempt stalled during optimized autoload generation and was stopped. The CLI works directly from the checkout using its fallback autoloader, and the test suite uses a package-local bootstrap. A consumer `composer install`/binary invocation remains a release acceptance item.

No generated consumer application was created. Installation tests used isolated temporary directories and fake package-manager processes. No configured application database was migrated or modified.

## Registry and release blockers

Composer package references still use `example/laravel-master-core-*` and are rejected by the trusted-source gate. The generator npm profile and workspace packages use `@itassistance-bd`, but the public npm organization page returned HTTP 403 and scope ownership remains unverified. Real package resolution, package discovery in a new host, npm installation, and release publishing remain blocked until verified identities and registries are available. Placeholder values in trusted-source configuration examples are documentation-only. See the [final identity migration report](phase10-package-identity-migration-record.md).

The approved profile does not specify minimum Node/npm versions. The CLI currently checks that both executables are available when frontend features are selected; publish a tested Node/npm matrix before release. npm install suppresses package lifecycle scripts, so hosts may need an approved manual dependency build step. Windows validation covered PHP's argument-array process invocation, but not real Composer `.bat`/npm `.cmd` execution, external registries, Unicode/path-length edge cases, or browser behavior.

## Acceptance still required

1. Verify and publish package namespace ownership and HTTPS Composer/npm registries.
2. On a clean Windows host, install to a new path with real registries, then run the generated project's Composer/npm checks without running migrations.
3. Review generated Auth/session/CSRF, host authorization, settings, route/style, and migration instructions in a browser and host application.
4. Approve Node/npm minimum versions and test supported PHP, Laravel, Node, and npm combinations.
5. Review staging recovery behavior for real Composer/npm failures and confirm the generated manifest lock-version data.

No Git commit or push was performed. At the time this initial implementation report was written, Phase 12 had not started; its later responsive QA is recorded separately in the Phase 12 report.

## Core Completion Campaign follow-up (2026-11-17)

The generator now also passes a clean local Composer consumer check: package install with scripts/plugins disabled, `MasterCore\ProjectGenerator\Console\Application` autoload, and the installed `master-core help` binary. An installed-binary dry-run succeeds without creating its target. A real install was explicitly attempted in the isolated consumer and stopped at the expected missing trusted-source configuration; the target directory was not created. No Laravel host, Core feature package consumer, npm consumer, or public registry install is claimed.

The generator suite has **20 tests / 52 assertions**, including invalid profile identity/runtime/export/dependency cases, invalid HTTPS registry configuration, staged install failure recovery, missing target safety, and incomplete generated-project diagnosis. The full repository run passed **198 tests / 980 assertions**. The npm UI suite, TypeScript check, package/host build, and six Playwright regressions also passed. External registry setup remains blocked until the publisher supplies owned namespaces and trusted registry URLs. See the [Phase 10 identity/release proposal](phase10-package-identity-and-release-proposal.md) and [campaign report](core-completion-campaign-2026-11-17.md).


## Final verification update - 2026-11-17

The local workspace npm scope and generator profile consistently use `@itassistance-bd`. `npm.cmd run test` passed 19 files / 79 tests; `npm.cmd run typecheck`, production build, and all ten npm package dry runs passed. Full Playwright passed 8 tests and responsive Playwright passed 5 tests, each with one worker and a separate temporary SQLite database. Composer's lock profile metadata now matches current npm package names; all 13 Composer manifests validate. The identity gates and real generated Laravel consumer remain unresolved; see the [Phase 10 identity migration report](phase10-package-identity-migration-record.md).
