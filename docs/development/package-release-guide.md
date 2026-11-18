# Controlled Package Release Guide

**Status update (2026-11-19):** all ten npm packages have been published at `0.1.0`. Do not republish them or change their published versions as part of Phase 10/11 completion. Composer Core packages remain monorepo path dependencies and are intentionally not submitted to Packagist; the public GitHub generator workflow has passed end to end. The publication procedures below are for a separately approved future npm version only.

> **Current distribution policy:** Keep the 12 Composer package sources inside this monorepo and do not submit them to Packagist. The ten npm package releases are complete. Generated projects resolve internal dependencies from local monorepo sources and use normal Composer/npm registries for third-party dependencies. See the [GitHub monorepo distribution status](github-distribution.md).

## npm packages

The ten workspace packages are version `0.1.0` and use `@itassistance-bd`. Their manifests set `publishConfig.access` to `public`, select `https://registry.npmjs.org/`, include the monorepo URL and workspace path, and provide package-specific `npm install` instructions. The npm account check reported organization role `owner`, developers-team membership, and account 2FA mode `auth-and-writes`. Organization-wide 2FA enforcement still needs confirmation in npm's organization Members settings.

Prepare a release dry-run from the repository root. The script verifies package metadata and internal constraints, builds packages in dependency order, runs `npm pack --dry-run` with lifecycle scripts disabled, checks declared JS/type/style exports and README inclusion, and writes a JSON report to a new path outside this project. It stops on the first build or pack failure; it never runs `npm publish`.

```powershell
$report = Join-Path $env:TEMP 'master-core-npm-release-dry-run.json'
npm.cmd run release:npm:dry-run -- --report=$report
```

The dependency order is derived from workspace `dependencies`. The ten package versions have already been published; do not rerun publication for Phase 10/11 completion. The earlier 404 checks below describe the pre-publication state at the time of the original report. This release guide is repository maintainer documentation; its dry-run commands are not application installation requirements.

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

### Controlled GitHub Actions release

The repository includes `.github/workflows/publish-npm-package.yml`, a manual one-package release workflow. It runs only from `main`, requires the exact `PUBLISH_PUBLICLY` confirmation, runs frontend tests, typecheck, identity validation, and package dry-run checks, then checks that the selected immutable version is unused and every required internal npm dependency is already public. It publishes one selected package with public access and provenance, then creates a package-specific Git tag and GitHub Release with generated release notes. The registry dependency check enforces publication order. This is a separate maintainer release workflow and is not required by the application installer or project generator.

The repository also contains a separate maintainer-only batch publisher at `scripts/publish-npm-batch.mjs`. It is excluded from the Laravel Master Core v1.0.0 application release, and the related root `package.json` command is excluded as well. Application installation and project generation do not depend on `npm.cmd run release:all`. Do not run the publisher without separate release authorization.

Before this workflow can run from GitHub, a maintainer must configure the protected `npm-publication` environment and require its approval. For initial publication, add an npm granular publish token as that environment's `NPM_TOKEN` secret, scoped to the intended packages and with 2FA bypass only if npm requires it for automation. Never put the token in this repository. Once a package exists, npm Trusted Publishing can be configured for that package using organization `Itassistancebd`, repository `laravel-master-core`, workflow filename `publish-npm-package.yml`, and environment `npm-publication`; the workflow requests `id-token: write` and publishes with provenance. Trusted Publisher configuration is per package and must be set in npm's package settings. A local npm session requiring an OTP does not satisfy the Actions secret requirement. The workflow has not been activated or run from GitHub in this checkout.

The following account and publication notes record the state before the completed ten-package publication. They are retained as history and do not authorize another publication. Current project generation resolves internal npm workspaces from the downloaded monorepo source.

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

- npm: ten package publication is complete; the public GitHub generator was also verified to install and build local monorepo workspaces.
- Composer: all twelve local snapshots validate structurally and resolve through a local path repository. Packagist vendor ownership and package availability remain unverified.
- Full external consumer install and registry-backed generated Laravel host remain blocked until packages and trusted Composer identity/repositories are available.
- Do not mark Phase 10 or Phase 11 complete until public registry installs and a real generated host bootstrap pass.

## Final release verification - 2026-11-18

The npm dry-run and local consumer reports below are historical pre-publication artifacts. They must not be used to infer current registry availability or trigger another package publication.

The current Composer snapshot validator and manifest checks pass for twelve exported packages and thirteen source manifests. A clean consumer installed all twelve local snapshots using `--prefer-dist`; 96 third-party dist archives were extracted successfully after enabling existing PHP 8.4 ZIP support via ignored local runtime configuration. The candidate vendor remains unverified and was used only in local fixture paths.

The standalone registry-based Generator mode retains its trusted-source checks. The supported global CLI uses monorepo source resolution and passed public GitHub end-to-end generation. See the [integrated Phase 10/11 report](../architecture/phase10-11-integrated-distribution.md).

## Historical Packagist first-publication gate - 2026-11-18

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

That consumer flow applies only if a separately approved Composer package distribution is established. It is not required for the supported global installer, which resolves all internal packages from the GitHub monorepo.

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
