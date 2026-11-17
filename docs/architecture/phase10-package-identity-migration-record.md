# Phase 10 - Official Package Identity Migration and Final Verification

**Date:** 2026-11-17
**Status:** **PARTIAL** - local migration and regression checks pass; external npm and Composer identity/consumer gates remain unresolved.

## Identity verification

- **GitHub organization - PASS:** read-only `GET https://api.github.com/orgs/itassistancebd` returned HTTP 200 and login `Itassistancebd` (organization ID 357571101). Read-only `GET https://api.github.com/orgs/itassistancebd/repos?per_page=100` returned HTTP 200 with no public repositories. No project repository name or URL was supplied or discovered; this checkout has no `.git` metadata. No repository URL was added.
- **npm scope - PARTIAL / UNVERIFIED:** read-only `GET https://www.npmjs.com/org/itassistance-bd` returned HTTP 403, so the organization page could not be verified. Registry org endpoints returned HTTP 200 with `{}` and `GET https://registry.npmjs.org/@itassistance-bd%2fui` returned HTTP 404. This confirms no public `@itassistance-bd/ui` package at that endpoint; it does not prove organization ownership or scope unavailability.
- **Composer vendor - UNVERIFIED:** Packagist homepage and read-only vendor search were reachable (HTTP 200). `https://packagist.org/search.json?q=itassistance-bd` returned zero results. This does not establish vendor ownership or unavailability. Composer vendor names remain `example/...`; the candidate `itassistance-bd` was not applied.
- No credentials or tokens were created, exposed, or used. No package was published. PHP namespace `MasterCore` remains unchanged.

## Local npm migration and Windows process diagnosis

Preserved the owner-confirmed npm scope `@itassistance-bd` across ten package names, imports, TypeScript aliases, package build maps, generator profile, package lock, and package manifests. All ten packages specify public npm access and `https://registry.npmjs.org/`. `npm.cmd install --offline --ignore-scripts` reconciled the already-installed workspace links: it added ten new-scope links and removed ten obsolete-scope links without network access or lifecycle scripts.

The initial `spawn EPERM` was reproducible from the default restricted execution context for child processes including `node.exe`, `cmd.exe`, esbuild, and PHP, while direct filesystem access worked. With child-process execution permitted for the requested local checks, npm scripts and Playwright ran successfully. The installed executables resolved to `node.exe` and `npm.cmd`; working directory and user temp directory were writable. Playwright is configured with `fullyParallel: false` and one worker, and Chromium launched. Defender status showed protection enabled; no Defender alert was observed in these checks. No security control was disabled. Evidence points to the restricted process-execution context as the `EPERM` source; no antivirus event-log inspection was performed.

## Composer lock metadata

`composer.lock` contained the locked metapackage's cached `extra.master-core` profile, which duplicated npm package names from `packages/core/master-core/composer.json`. Those npm references were stale after the scope migration. This was stale profile metadata, not a Composer `require`/package identity mismatch: Composer names and requirements remain `example/...` and the generator profile's Composer dependency closure is consistent.

Ran `php composer.phar update --lock --no-install --no-scripts --no-audit --no-interaction --no-progress`. Composer reported **0 installs, 0 updates, 0 removals**. The lock's ten feature npm names and shared UI name now match the current metapackage profile; all 110 locked package entries remain present. No install scripts or application database changes occurred.

## Regression results

| Check | Result |
|---|---|
| `php artisan test --no-ansi` | **PASS** - 198 tests, 980 assertions; PHPUnit uses in-memory SQLite |
| `npm.cmd run test` | **PASS** - 19 files, 79 tests |
| `npm.cmd run typecheck` | **PASS** |
| `npm.cmd run build` | **PASS** - all ten Core packages and 121-module host Vite build |
| `npm.cmd pack --dry-run --json --ignore-scripts --workspaces --cache storage/framework/testing/identity-npm-pack-cache` | **PASS** - all ten `@itassistance-bd/*` packages |
| Composer `validate --no-check-publish` | **PASS** - root and all 12 package manifests (13 total) |
| `php artisan test --filter=ProjectGeneratorTest` | **PASS** - 20 tests, 52 assertions |
| `npm.cmd run test:e2e` | **PASS** - 8 tests, one worker; isolated temporary SQLite; 86 screenshots |
| `npm.cmd run test:e2e:responsive` | **PASS** - 5 tests, one worker; isolated temporary SQLite; 78 screenshots |

Full browser evidence: `artifacts/browser-qa/2026-11-16T230203466Z-b6714f/`. Responsive evidence: `artifacts/browser-qa/2026-11-16T230735192Z-76888a/`. Playwright covered the registered Admin route matrix at desktop, tablet, and mobile, plus responsive navigation/search/filters/dialogs, Categories CRUD and RBAC, Settings persistence, and Users import/export workflows.

## Project Generator and remaining release gate

The generator's npm feature references match the migrated packages. Its Composer dependency references remain aligned with the unchanged `example/...` package manifests and lock dependencies; focused generator tests pass. A real generated Laravel consumer was **not installed** because the Composer vendor/repository and independently verified npm organization/scope are not established. Trusted registry configuration is absent; fake-process tests and dry runs are not consumer-install evidence.

Phase 10 remains **PARTIAL** until a repository URL and npm scope ownership are verifiable, Composer vendor ownership is verified separately, and a clean consumer install succeeds using actual trusted registries. Phase 11 generated-host acceptance remains **PARTIAL**. Do not start Phase 16.
