# Release and update workflow

## CLI versions and update source

`VERSION` is the source version marker. Installed metadata records the version, GitHub ref, and immutable commit SHA. `master update` delegates to the versioned installer, which reads the current public `main` commit, verifies the source payload and installs into a new version directory. It switches a small managed pointer and keeps earlier versions and pointer backups. User settings live outside the CLI directory and are not rewritten.

The public bootstrap and current `main` CLI have been verified with a clean generated project. The working tree is prepared for application/CLI `1.0.0`, but there is no `v1.0.0` tag or stable application release yet. A branch snapshot is installable but is not a formal tagged release.

## Semantic Versioning

Use Semantic Versioning for the Laravel Master Core application and global CLI:

- `v1.0.0` — initial stable release.
- `v1.0.1` — backward-compatible bug fixes.
- `v1.1.0` — backward-compatible features.
- `v2.0.0` — breaking changes.

Store the bare `1.0.0` form in root `VERSION`; use the `v` prefix only on Git tags. Keep `CHANGELOG.md` under `Unreleased` until an approved publication date is set. The current local `VERSION=1.0.0` is release preparation only. Do not create a tag or publish without explicit authorization.

The ten npm workspaces have their own independent package versions and are publicly available at `0.1.0`. Do not republish or change those versions for the application/CLI `v1.0.0` release. The 12 Composer package sources stay inside the monorepo and resolve by relative path; do not submit them to Packagist for the supported global project-generation flow.

## Core host project updates

`master update` updates only the global CLI. It does not update a generated Laravel host. An existing host must update its Composer/npm dependencies, review lockfile changes and Core release notes, run its tests, and plan package-owned migrations separately. Generated projects record their source repository; there is no automatic host overwrite or migration step.

## Maintainer source release

1. Run `master status`, `master changes`, and `master publish --dry-run`.
2. Review exact included and blocked files, secret-scan results, tests, changelog and compatibility docs.
3. With explicit publication approval, run `master publish`, select paths individually, enter a commit message, and confirm `STAGE`, `COMMIT`, and `PUSH` separately.
4. Verify the GitHub `main` hash and installer/CLI files. The bootstrap and CLI are currently available on the existing public `main` branch.
5. Under explicit release approval, validate `VERSION` is the requested bare SemVer value, finish the matching changelog entry, then create the corresponding `vMAJOR.MINOR.PATCH` tag. Do not tag this prepared candidate before approval.

The 2026-11-19 acceptance verification did not commit, push, tag, or deploy. The public bootstrap URL is `https://raw.githubusercontent.com/Itassistancebd/laravel-master-core/main/install.ps1`.
