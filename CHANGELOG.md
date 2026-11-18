# Changelog

All notable changes are recorded here. This file follows the Keep a Changelog structure; entries remain unreleased until an authorized `vMAJOR.MINOR.PATCH` tag and publication.

## Unreleased — prepared for v1.0.0 (not published)

### Release policy

- Application and global CLI use Semantic Versioning: `v1.0.0` initial stable release, `v1.0.1` bug fixes, `v1.1.0` backward-compatible features, and `v2.0.0` breaking changes.
- Root `VERSION` uses the bare version (`1.0.0`); the Git tag uses the `v` prefix. The local `1.0.0` candidate is not a published release.
- Ten npm workspaces remain independently versioned at `0.1.0`; do not republish or change them for this release. Internal Composer packages remain monorepo path dependencies; no Packagist submission is required.

### Prepared initial stable release

- Removed the Categories Admin feature and its API, permission, CRUD, generator, and browser-fixture registrations. The historical migration remains in place; no database records or tables are removed.
- Confirmed all ten npm package publications at `0.1.0`; no published package version was changed.
- Replaced the global CLI's blocked `new` command with archive-based generation from the existing Laravel Master Core application. It resolves internal Composer packages from monorepo path repositories and uses Composer/npm registries only for third-party dependencies.

- Added a Windows current-user bootstrap installer and a versioned `master` CLI installation layout. It downloads immutable GitHub commit archives, validates archive paths and required files, keeps previous CLI versions, and does not copy local `.env` or runtime configuration.
- Hardened reinstall integrity checks, rejected unrelated CLI pointer files, removed a machine-specific PHP path from an error message, and aligned the Windows prerequisites with the PHP 8.4 runtime gate.
- Added CLI commands for help/version, Phase 11 monorepo-template generation, Core repository status/diff review, and a non-mutating publish dry-run. Actual publishing is interactive and limited to explicitly approved files and the existing `origin/main` remote.
- Documented the public bootstrap boundary, local monorepo package resolution, third-party dependency installation, current CLI workflows, maintenance, and release steps.
- Verified the public `install.ps1` bootstrap URL and ran the installed public `main` CLI through `master new example-app` in a clean temporary directory. Composer resolved all 12 internal packages from monorepo path repositories; npm installed local workspaces and built the frontend; `.env`, application key, Artisan, and package discovery were verified. No migrations or seeders ran.
