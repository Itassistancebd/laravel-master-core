# Controlled Package Release Guide

This guide prepares local npm and Composer artifacts. It does not publish packages, create repositories, or assert external identity ownership. The Laravel Master Core npm organization owner was verified as `itassistancebd`; the candidate Composer vendor `itassistance-bd` is still unverified at Packagist.

> **Current distribution policy:** Keep the 12 Composer packages and 10 npm packages inside this monorepo. Do not submit or publish internal packages, create package repositories, or claim Composer vendor ownership. Generated projects resolve internal dependencies from local monorepo sources and use normal Composer/npm registries for third-party dependencies. See the [GitHub monorepo distribution status](github-distribution.md).

## npm packages

The ten workspace packages are version `0.1.0` and use `@itassistance-bd`. Their manifests set `publishConfig.access` to `public`, select `https://registry.npmjs.org/`, include the monorepo URL and workspace path, and provide package-specific `npm install` instructions. The npm account check reported organization role `owner`, developers-team membership, and account 2FA mode `auth-and-writes`. Organization-wide 2FA enforcement still needs confirmation in npm's organization Members settings.

Prepare a release dry-run from the repository root. The script verifies package metadata and internal constraints, builds packages in dependency order, runs `npm pack --dry-run` with lifecycle scripts disabled, checks declared JS/type/style exports and README inclusion, and writes a JSON report to a new path outside this project. It stops on the first build or pack failure; it never runs `npm publish`.

```powershell
$report = Join-Path $env:TEMP 'master-core-npm-release-dry-run.json'
npm.cmd run release:npm:dry-run -- --report=$report
```

The dependency order is derived from workspace `dependencies`. Confirm that all intended names are registered and installable before a separately approved release. In this checkout all ten registry metadata checks returned 404, so none has a public consumer-install PASS.

The clean local consumer check packs the built workspaces into temporary tarballs, installs those tarballs into a new temporary project, and imports each package. It disables lifecycle scripts and does not use workspace links. Third-party dependencies resolve from npmjs.org; the Core packages come from local tarballs.

```powershell
$consumer = Join-Path $env:TEMP 'master-core-npm-consumer-check'
npm.cmd run validate:npm:local-consumer -- --output=$consumer
```

Only after packages are available and a release is separately approved, publish one package at a time from its workspace in dependency order with:

```powershell
npm.cmd publish --access public --registry=https://registry.npmjs.org/
```

Review each result before proceeding. No bulk command in this repository publishes packages.

## Composer package snapshots

The twelve Composer source manifests remain under the monorepo. The export tool copies them to an outside-project folder, rewrites only the configured vendor identity and matching internal package references, and writes a snapshot index. It requires a version compatible with all source internal constraints and refuses to overwrite an existing output path.

```powershell
$snapshots = Join-Path $env:TEMP 'master-core-composer-snapshots-0.1.0'
$report = Join-Path $env:TEMP 'master-core-composer-validation.json'
node scripts/build-composer-distributions.mjs --vendor=itassistance-bd --version=0.1.0 --output=$snapshots
node scripts/validate-composer-distributions.mjs --directory=$snapshots --vendor=itassistance-bd --version=0.1.0 --report=$report
```

Here `itassistance-bd` is only a local candidate namespace supplied for artifact validation. Export success does not verify namespace availability, Packagist ownership, or repository control. The exporter preserves the twelve package source directories and does not add a `version` field; VCS repositories should derive package versions from reviewed tags.

Before a Composer release, verify the vendor and select a source strategy. The current snapshot layout is suitable for one root-manifest package repository per package; it does not preserve package Git history. For that strategy, derive each snapshot from the same reviewed monorepo commit, create an annotated `v0.1.0` tag in each corresponding repository, and verify the Packagist version before consumer installation. Never infer Packagist ownership from the npm organization name.

Validate every exported manifest independently with Composer:

```powershell
$slugs = @('laravel-master-core-foundation','laravel-master-core','laravel-master-core-settings','laravel-master-core-auth','laravel-master-core-user-management','laravel-master-core-roles-permissions','laravel-master-core-audit-logs','laravel-master-core-module-manager','laravel-master-core-system-health','laravel-master-core-notifications','laravel-master-core-crud-engine','laravel-master-core-project-generator')
foreach ($slug in $slugs) {
  .\scripts\project-command.ps1 composer validate --no-check-publish (Join-Path (Join-Path $snapshots $slug) 'composer.json')
}
```

## Generator and trusted sources

The bundled generator profile uses `@itassistance-bd` and its actual ten workspace package names. At installation time, schema 2 trusted-source configuration still requires an explicit Composer vendor and HTTPS repository set; this avoids turning a candidate vendor into an implicit trust decision. Use `@itassistance-bd` as the npm scope after confirming the relevant package releases are available.

The generator pins configured Composer repositories and disables implicit Packagist fallback. Those repositories must serve Laravel's scaffold, Laravel/framework, the selected Core packages, and all transitive dependencies. A public configuration can explicitly list Packagist together with the verified Core Composer source. npm uses its configured HTTPS registry. Credentials remain in Composer/npm credential stores, never in the generator JSON.

The generator never runs migrations or seeders, grants permissions, or provisions Super Admin. Its PHP tests validate generated manifests, staging failure handling, doctor checks, and no-migration behavior with a fake process executor. They do not substitute for a real registry-backed Laravel host install.

## Current evidence and remaining gates

- npm: ten local builds, pack dry-runs, and local tarball consumer checks pass; all ten registry names returned 404.
- Composer: all twelve local snapshots validate structurally and resolve through a local path repository. Packagist vendor ownership and package availability remain unverified.
- Full external consumer install and registry-backed generated Laravel host remain blocked until packages and trusted Composer identity/repositories are available.
- Do not mark Phase 10 or Phase 11 complete until public registry installs and a real generated host bootstrap pass.

## Final release verification - 2026-11-18

The current npm dry-run report is `%TEMP%\lmcore-npm-release-dry-run-master-command-20261118.json`; all ten packages built and dry-run packed in manifest-derived dependency order. The matching clean consumer report is `%TEMP%\lmcore-npm-local-consumer-master-command-20261118\consumer-validation.json`; it installed and imported all ten local tarballs without workspace links. All ten live registry package requests returned HTTP 404.

The current Composer snapshot validator and manifest checks pass for twelve exported packages and thirteen source manifests. A clean consumer installed all twelve local snapshots using `--prefer-dist`; 96 third-party dist archives were extracted successfully after enabling existing PHP 8.4 ZIP support via ignored local runtime configuration. The candidate vendor remains unverified and was used only in local fixture paths.

The Generator CLI dry-run validates configured Composer/npm identities for planning but states ownership is not verified. A manual Laravel scaffold with local snapshots bootstrapped separately; public trusted-source installation remains blocked. See the [integrated Phase 10/11 report](../architecture/phase10-11-integrated-distribution.md).

## Packagist first-publication gate - 2026-11-18

Packagist reads the `composer.json` at the root of the VCS repository. The published monorepo at `https://github.com/Itassistancebd/laravel-master-core` has a development-app root manifest (`example/laravel-master-core-development-app`, `type: project`); it is not the intended `itassistance-bd/laravel-master-core` package. The Core package is nested at `packages/core/master-core`, and Packagist does not publish it from that subdirectory. Do not submit the monorepo URL for the intended package. Changing the root manifest would misidentify the development host and its local path repositories.

Read-only Git evidence: remote branch `main` points to `9ec7976c27492ff12fa05f309d7befbc8d8d2592`; the remote has no tags. Packagist's vendor listing for `itassistance-bd` returned HTTP 200 with no packages, and the candidate Core package endpoint returned 404. These results do not establish vendor ownership or account permission.

The intended Core package is a metapackage requiring Foundation at `^0.1.0`. Foundation must be resolvable before the Core package can install. Each of the twelve snapshot packages needs a root-manifest VCS repository or a separately reviewed history-preserving split; the current snapshot exporter does not create repositories or preserve per-package history. Release internal dependencies first, then dependent packages in graph order. The snapshot exporter now rewrites copied package README identities along with Composer metadata; validation rejects stale `example/` references.

After Packagist vendor control and package repositories are verified, the manual steps are: (1) prepare and review the standalone package repository with its root manifest, source, README, and annotated `v0.1.0` tag; (2) sign in to Packagist and use **Submit** with that exact public GitHub repository URL; (3) enable/authorize the Packagist GitHub integration or webhook for synchronization; (4) verify Packagist indexed the intended tag and dependency metadata. Repeat for each package. Do not submit the monorepo as twelve packages.

After Foundation and Core are published and indexed, a public consumer can run:

```powershell
composer create-project --no-install laravel/laravel master-core-consumer
Set-Location master-core-consumer
composer require itassistance-bd/laravel-master-core:^0.1 --no-interaction
```

That is a future check only. Current public installation is BLOCKED by the 404 package endpoint.

The inspected internal dependency graph is: Foundation has no internal dependency; Core, Settings, Auth, and Module Manager depend on Foundation; User Management depends on Foundation and Auth; Roles & Permissions depends on Foundation and User Management; System Health and Notifications depend on Foundation and Roles & Permissions; Audit Logs, CRUD Engine, and Project Generator have no internal Composer dependency. This establishes the order constraints, not publication readiness.

## Packagist local validation evidence - 2026-11-18

- Root Composer development manifest: `composer validate --no-check-publish composer.json` passed under XAMPP2 PHP 8.4.27.
- Composer validation: all 13 source manifests and all 12 freshly exported snapshot manifests passed `composer validate --no-check-publish`.
- Snapshot checks: the exporter produced 12 package roots; the validator passed identity, MIT metadata, internal version constraints, README identity, and autoload/bin path checks. The candidate vendor remains unverified.
- Isolated local consumer: `composer update --prefer-dist --no-scripts --no-plugins --no-interaction --no-progress` passed against the local snapshots and Packagist for third-party dependencies. The lock contains all 12 Core packages; 108 packages total were installed, and `vendor/autoload.php` was created. This is local snapshot evidence, not public Core package availability.
- PHP: `artisan test` passed 200 tests / 991 assertions on PHP 8.4.27, with PHPUnit configured for SQLite `:memory:`.
- Frontend: `npm.cmd test` passed 19 files / 79 tests; `npm.cmd run typecheck` and `npm.cmd run check:package-identities` passed (10 npm workspaces and 12 Composer package manifests aligned with the generator profile).
- `git diff --check` passed. No database migration or seeder was run against the configured MySQL database.

The final validated snapshots are in `%TEMP%\lmcore-packagist-final-snapshots-20261118-013936`; the final isolated consumer is `%TEMP%\lmcore-packagist-final-consumer-20261118-014005`. The consumer resolved all twelve Core packages from these local snapshots plus third-party packages from Packagist. It does not verify public availability of any Core package.
