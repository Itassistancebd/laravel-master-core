# Global CLI and monorepo distribution architecture

**Status: PARTIAL.** This implementation keeps `Itassistancebd/laravel-master-core` as the only source repository and reuses the Phase 11 Project Generator's new monorepo-template installation path.

## CLI installation

The PowerShell bootstrap reads the repository's public `main` SHA from GitHub, downloads that immutable ZIP archive, rejects extraction paths outside a temporary workspace, checks required payload files, and installs versioned CLI files beneath `%LOCALAPPDATA%\Programs\MasterCore`. It writes only a managed launcher and current-version pointer at the install root. User configuration remains outside the installation folder. The optional PATH update writes only the current user's Environment key and is idempotent.

The current public branch does not include this implementation. Bootstrap is therefore unavailable until an approved source publication.

## Project generation and dependency resolution

The CLI downloads the canonical monorepo archive and invokes `packages/tools/project-generator/bin/master-core template`. This template mode copies the existing Laravel application, all package sources, and local workspace manifests. It does not create a Laravel skeleton. A staging directory is moved to the requested target only after package resolution and verification succeed; failures retain staging and a recovery marker.

All internal Composer packages are resolved from relative `path` repositories included in the archive. All ten npm packages resolve from local npm workspaces. Composer and npm use their normal registries for Laravel and third-party dependencies. The generator verifies path-repository package names and npm workspace manifests before resolution, prepares `.env` from `.env.example`, generates an application key, validates Composer, runs Artisan/package discovery, and performs frontend installation/build. It does not run migrations or seeders. The standalone Phase 11 feature-selection command continues to enforce its separately configured trusted sources.

## Maintainer publishing boundary

The maintenance CLI is restricted to the canonical existing GitHub remote and `main`. Dry-run uses read-only Git inspection and never stages or uploads. Actual publishing requires explicit paths and separate interactive confirmations before staging, committing, and pushing. Secret and machine-local paths are excluded. The utility does not resolve conflicts or use force operations.

## Release gates

- Publish installer/CLI source to the existing repository only after review and explicit approval.
- Publish the reviewed installer/CLI/generator changes to the existing repository before fresh-PC use.
- Verify third-party Composer and npm access with two clean Windows project generations before claiming complete public acceptance.
- Exercise remote update races, secret exclusions, and confirmed publish behavior in a disposable Git fixture before enabling maintainer publication.

## Local implementation verification - 2026-11-18

- PowerShell parsing passed for the installer and CLI.
- An isolated local-source installation created versioned payloads and launchers; `.env` and `.php-runtime.local.json` were absent from the payload.
- Current-user PATH registration was checked using a temporary install path and the original registry value was restored exactly. Machine/system PATH was not touched.
- A local archive fixture installed successfully. A crafted traversal archive was rejected without creating its escape target or installation directory.
- `master --help` and `master --version` passed through PowerShell and the CMD shim. PowerShell 7 is not installed in the test environment.
- A local version-pointer update switched versions while preserving the old payload and a pointer backup.
- `master status`, `master changes`, and `master publish --dry-run` passed on the Core checkout. Dry-run left the 64-entry worktree status unchanged and reported `git diff --check` PASS.
- A local monorepo-template consumer reached Composer installation. Composer's default dist mode failed because PHP zip and unzip/7z were unavailable; template mode was changed to prefer Git source. The second attempt stalled on outbound HTTPS and was stopped with its staging preserved.
- A complete local successful generated project has not yet been verified. Public archive generation remains unavailable until the installer and CLI are published.
- `master update` downloaded the current public GitHub archive but rejected it because the published baseline lacks `VERSION` and the new installer/CLI payload; the installed version pointer remained unchanged. The raw public `install.ps1` URL returns HTTP 404.

These checks cover local shell and safety behavior. They do not pass the public install, clean project generation, Composer package discovery, frontend install/build, or maintainer commit/push acceptance gates.
