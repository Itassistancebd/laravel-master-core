# GitHub monorepo distribution status

The existing repository `https://github.com/Itassistancebd/laravel-master-core` remains the only intended source of truth. No package repositories or private distribution server are used.

## Core source delivery

The public bootstrap installer is available at `https://raw.githubusercontent.com/Itassistancebd/laravel-master-core/main/install.ps1`. It downloads an immutable archive for the public `main` commit and installs the versioned CLI and Phase 11 Generator. Archive paths, symbolic links, entry counts, and expanded size are checked before extraction. Local `.env`, PHP runtime overrides, user databases, `vendor`, and `node_modules` are not copied into the installed CLI.

## Project dependency resolution

The 12 Composer packages remain inside `packages/` and use `example/` development identities. Generated projects resolve these packages through relative Composer path repositories; Composer uses its normal registry configuration for Laravel and third-party dependencies. All ten npm packages are published at `0.1.0`, and the generator resolves internal workspaces from the downloaded monorepo archive. No Composer packages are published to Packagist or need Packagist submission for this workflow. The application/CLI version is independent; local `VERSION` is prepared as `1.0.0`, pending approval and publication.

## Acceptance state (verified 2026-11-19)

- One-repository source-of-truth policy: retained.
- Public bootstrap URL: HTTP 200; downloaded 18,059-byte installer SHA-256 `2f1bd9afb293478c9af55963a7b21811828259f3456dd99216bfc849123b0a84`.
- Bootstrap installation: PASS from public `main` commit `8d8bb29da811ccfe86b60eee9a2f2a8f23c31f66`, installed to a clean temporary CLI directory without changing PATH.
- `master new example-app`: PASS from a clean temporary working directory using the installed public CLI and downloaded GitHub archive.
- Existing Laravel application template: PASS; no Laravel skeleton was created.
- Twelve internal Composer package path repositories: present in the generated project; package discovery completed. Composer also installed third-party dependencies.
- Ten internal npm workspaces: present; `npm ci` and production frontend build completed successfully.
- `.env`, generated application key, Composer validation, `artisan --version`, and `artisan package:discover`: PASS.
- Categories feature: absent from generated app routes, Admin pages, and permissions. Remaining audit-log `category` fields classify audit events. The existing dashboard and other modules were copied.
- Database migrations and seeders: not run.
- Temporary acceptance workspace: `%TEMP%\MasterCore-phase10-11-94b4cd149faa49cdbe2a73c4d7fb48b1`.

The global installer and project generation workflow are available from the existing public repository. No repository, tag, npm package, commit, or push was created by this verification.
