# Contributing to Laravel Master Core

Keep the existing GitHub monorepo as the source of truth. Do not publish npm or Composer packages, create package repositories, deploy, or change live databases as part of routine development.

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

The interactive `master publish` workflow checks the existing `origin` URL, branch, remote state and changed files. It asks you to enter exact paths, then separately asks for `STAGE`, `COMMIT`, and `PUSH`. It stages only selected paths and uses a normal push to `origin/main`. Cancel at any prompt to stop; selected staged work is preserved. Review the GitHub result and update the changelog/version documentation as part of the release.

This task does not authorize committing or pushing changes. Obtain explicit approval for a specific publication action before using the final command.

## Project generation and release status

The global bootstrap is implemented locally but still requires publication to `main` before fresh computers can install it. `master new` copies this existing Laravel application from the canonical repository archive and delegates to the Phase 11 monorepo-template installer. Internal Core packages resolve from the included relative Composer paths and local npm workspaces; third-party dependencies resolve from the normal registries. Categories is not included. Generation does not run migrations or seeders.
