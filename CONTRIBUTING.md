# Contributing to Laravel Master Core

Keep the existing GitHub monorepo as the source of truth. Routine development must not publish npm or Composer packages, create package repositories, deploy, or change live databases. The ten npm packages are already published at `0.1.0`; do not republish them or change their versions during application releases. Composer packages stay in the monorepo and are not submitted to Packagist.

## Version policy

The application and global CLI follow Semantic Versioning. Set `VERSION` to the bare version and use a `v`-prefixed Git tag: `1.0.0` / `v1.0.0` is the initial stable release, `v1.0.1` is a bug-fix release, `v1.1.0` adds backward-compatible features, and `v2.0.0` contains breaking changes. The current local candidate is `1.0.0`; it is not released until separately authorized, reviewed, tagged, and published. Keep package versions independent from the application version.

## Start here

1. Clone the existing repository and work on a review branch.
2. Follow [local development](docs/development/local-development.md) to configure the checkout-specific XAMPP2 PHP runtime.
3. Read the architecture record for the area being changed and keep package ownership, authorization, migration, and host responsibilities intact.
4. Update the relevant package and architecture documentation with behavior changes.

## Validate and review

```powershell
.\scripts\project-command.ps1 composer test
npm.cmd test
npm.cmd run typecheck
npm.cmd run build
master status
master changes
master publish --dry-run
```

`master changes` filters sensitive paths and suppresses file diffs when its credential scanner detects a possible secret. Review all included and blocked paths yourself before any publication attempt. `master publish --dry-run` does not stage, commit, or push.

## Publish source changes

The interactive `master publish` workflow checks the existing `origin` URL, branch, remote state and changed files. It asks you to enter exact paths, then separately asks for `STAGE`, `COMMIT`, and `PUSH`. It stages only selected paths and uses a normal push to `origin/main`. Cancel at any prompt to stop; selected staged work is preserved. For an authorized release, update `VERSION`, changelog, and release notes consistently before committing. Create the `vMAJOR.MINOR.PATCH` tag only after explicit approval. Do not run npm publication for the already published `0.1.0` packages as part of this application release.

This task does not authorize committing or pushing changes. Obtain explicit approval for a specific publication action before using the final command.

## Project generation and release status

The public global bootstrap and `master new` flow were verified on 2026-11-19. Generation copies this existing Laravel application from the canonical repository archive and delegates to the Phase 11 monorepo-template installer. Internal Core packages resolve from the included relative Composer paths and local npm workspaces; third-party dependencies resolve from the normal registries. Categories is excluded and the Dashboard remains. Generation does not run migrations or seeders. See the [acceptance record](docs/development/github-distribution.md).
