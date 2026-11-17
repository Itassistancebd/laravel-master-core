# GitHub monorepo distribution status

The existing repository `https://github.com/Itassistancebd/laravel-master-core` remains the only intended source of truth. No package repositories or private distribution server are used.

## Core source delivery

`install.ps1` downloads an immutable archive for the public `main` commit and installs the versioned CLI and Phase 11 Generator. Archive paths, symbolic links, entry counts, and expanded size are checked before extraction. Local `.env`, PHP runtime overrides, user databases, `vendor`, and `node_modules` are not copied into the installed CLI.

## Project dependency resolution

The 12 Composer packages remain inside `packages/` and use `example/` development identities. The template installer verifies each required internal package against the included `packages/**/composer.json` files and resolves them through relative Composer path repositories. Internal npm packages resolve from the ten npm workspaces in the archive. Composer and npm registries are enabled for Laravel and third-party dependencies only. No own package is published to Packagist or npm.

## Acceptance state

- One-repository source-of-truth policy: retained.
- GitHub ZIP download and safe payload extraction: locally implemented; public installer payload is not yet published.
- Twelve internal Composer packages from a clean archive: validated and installed through relative path repositories by the template installer.
- Laravel and third-party dependency resolution: normal Composer/npm registries are used.
- Public `master new` from a fresh PC: BLOCKED until local installer/CLI changes are published to `main`; local template generation is verified separately.

Do not represent the public installer as available until it is published and verified from the public GitHub source. Do not claim a public project generation test until a clean PC runs `master new` from the published CLI.
