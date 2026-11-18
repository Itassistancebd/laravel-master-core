# Phase 11 Project Generator CLI

The generator is a standalone Composer executable. It creates a new Laravel 12 host only after the selected feature plan and trusted package sources pass validation. Generated projects no longer include the Categories feature. The historical database migration remains for existing installations; generator setup does not run migrations automatically.

The Windows global `master new` command uses the Generator's `template` mode to copy the existing Laravel application and bundled Core packages. This public GitHub workflow passed end to end on 2026-11-19; it resolves all 12 internal Composer packages from monorepo path repositories and installs local npm workspaces, with Composer/npm used normally for third-party dependencies. The standalone feature-selection `master-core new` command described below is a separate registry-based mode and retains its trusted-source checks. No Packagist submission is needed for the global workflow. See the [GitHub acceptance record](../development/github-distribution.md), [distribution architecture](global-cli-and-monorepo-distribution.md), and [global CLI guide](../development/global-cli.md).

## Commands

```text
master-core help
master-core new <target> --features=auth,user-management --frontends=auth,user-management --auth-shell=core --dry-run --json
master-core plan <selection.json> --json
master-core doctor [project] --json
```

Feature names come from the bundled profile. Backend and frontend selections are separate. Foundation is always included. Composer dependencies are closed transitively and displayed in the plan. Unknown and duplicate keys fail validation; frontend features must have their backend feature selected. `crud-engine` stays resource-owned and does not receive generic routes.

Non-interactive selection must include both `--features=` and `--frontends=`; use empty values to choose none. A real non-interactive install additionally requires `--yes` after reviewing the dry-run output. Interactive mode asks for selections and an explicit install confirmation. `--auth-shell` accepts `host`, `core`, or `none`; Core Auth requires the Auth backend selection.

Example selection JSON:

```json
{
  "backend_features": ["auth", "user-management"],
  "frontend_features": ["auth", "user-management"],
  "auth_shell": "core",
  "core_version": "^0.1.0"
}
```

`doctor` checks generated files and manifest consistency without starting services, editing files, or contacting a database.

## Trusted registry configuration

Real installs require a local JSON file at `%APPDATA%/MasterCore/ProjectGenerator/trusted-sources.json` on Windows, `$XDG_CONFIG_HOME/master-core/project-generator/trusted-sources.json` when set, or `$USERPROFILE/.config/master-core/project-generator/trusted-sources.json` as the fallback. `--trusted-config=<path>` selects another file. The file stores only identity and registry URLs; never put credentials in it.

Schema 2 fields:

```json
{
  "schema_version": 2,
  "composer_repositories": ["https://registry-host.example/composer/"],
  "composer_vendor": "verified-vendor",
  "npm_registry": "https://registry-host.example/npm/",
  "npm_scope": "@itassistance-bd"
}
```

The ten `@itassistance-bd` npm packages are publicly available at `0.1.0`; the global generator uses the matching local workspaces from its downloaded source archive. The Composer vendor and repository URL above are placeholders for the separate registry-based mode; no Composer package submission to Packagist is part of the global workflow. Do not treat the sample configuration as ready-to-install. URLs must use HTTPS and cannot contain credentials, query strings, or fragments. Put registry credentials in the package manager's approved credential store; never put credentials in this file. The standalone generator uses an isolated Composer home in the preserved staging directory and suppresses child output to avoid leaking credentials. Generated Composer requirements pin configured repositories and disable implicit Packagist fallback; selected frontend packages use their configured npm registry. Do not add registry credentials to generated files.

The bundled profile carries `@itassistance-bd` npm package identities and local `example/` Composer source identities. The registry-based mode can rewrite its Composer vendor and npm scope using explicit schema 2 identities. It does not modify source manifests, register namespaces, or publish packages. Public npm metadata for all ten packages at `0.1.0` was verified on 2026-11-19; the candidate Composer vendor remains unverified. This does not affect the tested global monorepo workflow. See the [controlled package release guide](../development/package-release-guide.md), [integrated distribution implementation report](phase10-11-integrated-distribution.md), and [identity migration record](phase10-package-identity-migration-record.md).

An installed Project Generator package can be tested independently, but a real `new` command requires verified schema 2 identity and repository settings before staging. If a registry lookup fails during installation, the error points to the configured source and preserves its staging directory. See the [integrated distribution implementation report](phase10-11-integrated-distribution.md).

## Generated project and safety

The target directory must not exist and its parent must already exist and be writable. Composer and npm run only after target validation, source validation, explicit install confirmation, and trusted-source checks. Files are built in a unique sibling staging directory and moved to the destination only after successful completion. A failure preserves the staging directory and writes `master-core-generator-recovery.json`; review it and copy only needed files into a new target. The generator never removes staging output automatically.

Composer scaffold and dependency commands use argument arrays with scripts/plugins disabled. Laravel package discovery runs explicitly to register providers. npm lifecycle scripts are disabled during installation. Review the trusted package set before manually enabling any package build/install scripts required by the host toolchain.

Generated files include `master-core.project.json`, `docs/master-core-installation.md`, explicit frontend route and stylesheet composition under `resources/js/master-core/` and `resources/css/`, and selected Composer/npm requirements. Settings UI gets a mount helper rather than an invented route. CRUD Engine remains resource-owned. Existing Laravel entrypoints and host policies are not replaced.

## Database and host setup

Read `docs/master-core-installation.md` inside the generated host. Review package configuration, host model requirements, provider discovery, permission registration, and each package-owned migration before acting. Back up and verify the intended database before a separately approved manual migration. The generator does not run migrations or seeders, create `.env` secrets, grant permissions, enable Strict Mode, or provision Super Admin.

## Troubleshooting

| Message or symptom | Safe next step |
|---|---|
| Target already exists | Choose a new path. The generator will not merge or overwrite an existing project. |
| Unsupported/mismatched profile | Update the generator and profile from the same release; do not hand-edit profile package identities. |
| Trusted registry configuration is missing or incomplete | Verify the package owners, HTTPS repositories, Composer vendors, npm scope, and registry before editing the local trust file. |
| Placeholder namespace rejected | Wait for approved namespace ownership and publishing; do not bypass the rejection or map the placeholder to an unrelated package. |
| Child process failed | Inspect the preserved staging recovery file and package-manager logs managed by your environment. Never run root `composer setup` or retry against an existing target. |
| Frontend build reports missing platform binary | npm scripts were suppressed. Review the selected package source and trusted registry, then follow the host's approved manual dependency-build steps. |
| Doctor reports missing files | Treat the project as incomplete. Preserve staging, compare its manifest with expected selections, and create a separate new target after resolving the cause. |

## Current release limits

- Supported Core profile: schema 1, Laravel `^12.0`, PHP `^8.2`.
- Node/npm minimum versions are not encoded in the approved profile; the generator checks that Node and npm are present when frontend packages are selected. Verify the packages' current engine fields before release.
- Public Windows global CLI invocation, monorepo Composer/npm installation, Artisan/package discovery, and production frontend build passed on 2026-11-19. Long paths, unusual Unicode targets, cancellation, and the separate registry-based feature-selection mode have not all been exercised.
- No database migration or browser acceptance of the generated app is claimed; generation does not run migrations or seeders.

## Historical release campaign installation evidence - 2026-11-18

Local artifact checks now include installation of all twelve exported Composer snapshots in an isolated consumer with `--prefer-dist`. The XAMPP2 PHP 8.4.27 ZIP extension was loaded through project-local configuration; Composer extracted 96 third-party archives and generated autoload files. The local npm consumer installed and imported all ten tarballs without workspace links. These tests do not use public Core package records.

The installed local Generator CLI help and identity-selected `new --dry-run` passed without creating a target. A separate manually scaffolded Laravel 12.73.0 fixture with the local Composer snapshots completed package discovery and `artisan --version`. No generated project was installed from verified public package repositories because the npm packages return 404 and Packagist vendor ownership is not confirmed. Do not treat the fixture as public registry or Generator install acceptance.

Playwright's safety preflight now refuses to migrate unless the process environment explicitly selects SQLite, the database is the newly created regular file under that run's unique temporary directory, DB_URL is blank, and framework caches use the run's unique cache location. See [Phase 12 browser evidence](phase12-admin-ui-refinement-implementation-report.md).

This 2026-11-18 campaign evidence is historical. On 2026-11-19, the installed public global CLI successfully ran `master new example-app` from a clean temporary directory and built a generated host. See the [current GitHub acceptance record](../development/github-distribution.md). The standalone registry-based feature-selection mode has separate trusted-source requirements and is not the supported global generation path.
