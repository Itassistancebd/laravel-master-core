# Phase 11 Project Generator CLI

The generator is a standalone Composer executable. It creates a new Laravel 12 host only after the selected feature plan and trusted package sources pass validation.

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

Real installs require a local, secret-free JSON file at `%APPDATA%/MasterCore/ProjectGenerator/trusted-sources.json` on Windows, `$XDG_CONFIG_HOME/master-core/project-generator/trusted-sources.json` when set, or `$USERPROFILE/.config/master-core/project-generator/trusted-sources.json` as the fallback. `--trusted-config=<path>` selects another file.

Schema 1 fields:

```json
{
  "schema_version": 1,
  "composer_repositories": ["https://registry-host.example/verified-composer-repository"],
  "composer_vendors": ["verified-vendor"],
  "npm_registry": "https://registry-host.example/npm/",
  "npm_scopes": ["@verified-scope"]
}
```

The example values are documentation placeholders and are not trusted sources. Replace them only after verifying namespace ownership and repository control. URLs must use HTTPS and cannot contain credentials, query strings, or fragments. Put registry credentials in the package manager's approved credential store; never put credentials in this file. The generator uses an isolated Composer home in the preserved staging directory and suppresses child output to avoid leaking credentials. Generated Composer requirements pin the verified repositories and disable implicit Packagist fallback; generated frontend projects get an `.npmrc` that selects the verified npm registry. The completed host retains the non-secret `.master-core-composer-home` policy file for inspection. Do not add registry credentials to generated files.

Composer packages still use the unverified `example/` vendor and are rejected by the trusted-source gate. The bundled npm profile uses owner-confirmed `@itassistance-bd`; an installer must configure that scope and an HTTPS registry after independent ownership verification. External ownership was not independently verified here. Dry-run and local fixture tests do not prove registry publishing or consumer installation. See the [identity migration record](phase10-package-identity-migration-record.md).

The installed Project Generator package itself has been installed and its autoload/CLI checked in a fresh consumer with a local path repository. That does not install the Core feature packages or create a Laravel host. A real `new` command reaches the trusted-source check and exits before staging when the registry file is absent; keep this behavior until the publisher verifies the package identities and HTTPS registries. See the [Phase 10 package identity proposal](phase10-package-identity-and-release-proposal.md).

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
- Windows process invocation, path length, Unicode, cancellation, trusted registry access, and a real package install still require release acceptance on a clean Windows host.
- No database or browser acceptance is claimed.
