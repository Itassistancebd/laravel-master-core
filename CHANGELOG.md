# Changelog

All notable changes are recorded here. This file follows the Keep a Changelog structure; versions are not releases until a reviewed tag is published.

## Unreleased

- Removed the Categories Admin feature and its API, permission, CRUD, generator, and browser-fixture registrations. The historical migration remains in place; no database records or tables are removed.
- Replaced the global CLI's blocked `new` command with archive-based generation from the existing Laravel Master Core application. It resolves internal Composer packages from monorepo path repositories and uses Composer/npm registries only for third-party dependencies.

- Added a Windows current-user bootstrap installer and a versioned `master` CLI installation layout. It downloads immutable GitHub commit archives, validates archive paths and required files, keeps previous CLI versions, and does not copy local `.env` or runtime configuration.
- Hardened reinstall integrity checks, rejected unrelated CLI pointer files, removed a machine-specific PHP path from an error message, and aligned the Windows prerequisites with the PHP 8.4 runtime gate.
- Added CLI commands for help/version, Phase 11 monorepo-template generation, Core repository status/diff review, and a non-mutating publish dry-run. Actual publishing is interactive and limited to explicitly approved files and the existing `origin/main` remote.
- Documented the public bootstrap boundary, local monorepo package resolution, third-party dependency installation, current CLI workflows, maintenance, and release steps.
- Acceptance remains partial until the source is published and clean Windows generation succeeds with reachable third-party package registries.
