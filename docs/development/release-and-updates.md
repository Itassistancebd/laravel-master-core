# Release and update workflow

## CLI versions and update source

`VERSION` is the source version marker. Installed metadata records the version, GitHub ref, and immutable commit SHA. `master update` delegates to the versioned installer, which reads the current public `main` commit, verifies the source payload and installs into a new version directory. It switches a small managed pointer and keeps earlier versions and pointer backups. User settings live outside the CLI directory and are not rewritten.

There is no tagged public CLI release in the current source state. The branch snapshot is used until an approved release/tag policy is established. `0.1.0-dev` identifies this local preparation build; it does not assert a published release.

## Core host project updates

`master update` updates only the global CLI. It does not update a generated Laravel host. An existing host must update its Composer/npm dependencies, review lockfile changes and Core release notes, run its tests, and plan package-owned migrations separately. Generated projects record their source repository; there is no automatic host overwrite or migration step.

## Maintainer source release

1. Run `master status`, `master changes`, and `master publish --dry-run`.
2. Review exact included and blocked files, secret-scan results, tests, changelog and compatibility docs.
3. With explicit publication approval, run `master publish`, select paths individually, enter a commit message, and confirm `STAGE`, `COMMIT`, and `PUSH` separately.
4. Verify the published GitHub `main` hash and installer/CLI files.
5. Create a version tag only under an approved release process; update `VERSION` and changelog before the tag.

This campaign did not commit, push, tag, or deploy. The bootstrap URL becomes usable only after `install.ps1` and the CLI source are pushed to the approved public branch.
