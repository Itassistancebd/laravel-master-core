# Core repository maintainer workflow

Use this workflow only for the original Laravel Master Core checkout. Generated host applications have their own Git history and must not be published through the Core CLI.

## Inspect

```powershell
master status
master changes
master publish --dry-run
```

The CLI checks the Git root, canonical GitHub origin, `main` branch, local HEAD, remote `main`, modified/added/deleted/untracked files, and likely sensitive content. Remote checks use `git ls-remote`; they do not fetch or change refs in dry-run mode. Review excluded paths and the exact eligible list.

## Publish, after approval

```powershell
master publish
```

The command fetches the authorized remote before any staging. It stops if remote history is ahead or diverged. You must enter each exact path to stage, provide one commit message, and separately type `STAGE`, `COMMIT`, and `PUSH`. It uses `git add -- <selected paths>`, commits only those paths, then pushes normally to `origin/main` if the remote tip is unchanged.

No reset, clean, force push, `git add .`, or automatic conflict resolution is used. If a push fails, the local commit remains and the CLI reports its hash. Resolve remote changes manually and review the next action.

## Testing and release notes

Run the PHP and frontend tests described in [local development](local-development.md), plus relevant browser and package checks. Use isolated SQLite for tests. Do not run migrations/seeds against the configured MySQL database. Update `CHANGELOG.md`, version compatibility and installation docs. The project currently has no published CLI release; do not describe the local source update as publicly available.
