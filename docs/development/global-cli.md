# Global `master` CLI

The CLI is a Windows PowerShell command installed by `install.ps1` (Windows PowerShell 5.1 or PowerShell 7 on Windows). Its repository identity is built in: `Itassistancebd/laravel-master-core`, branch `main`. It never asks users for a package name or repository URL.

## Commands

```powershell
master --help
master --version
master new example-app
master new example-app --no-interaction
master update
master status
master changes
master publish --dry-run
master publish
```

`new` validates the name and toolchain, refuses to overwrite an existing target, downloads the canonical `main` archive, and asks the Phase 11 Generator to copy the Laravel application already in that archive. It does not create or install a separate Laravel skeleton. Generation uses an isolated sibling staging directory and only moves it into place after Composer validation, Artisan/package discovery, npm workspace installation, and production build succeed. Failed staging is preserved for review.

The generated project includes all internal Composer package sources and resolves them from relative `path` repositories. Its npm Core packages remain local npm workspaces. Composer and npm use their normal registries for Laravel and third-party dependencies. The generator excludes `.git`, `.env` and variants, local PHP runtime config, package-manager auth config, local databases, dependency trees, caches, build outputs, test artifacts and backups. It creates `.env` from `.env.example`, sets the project name, generates `APP_KEY`, prepares an empty SQLite file, verifies Artisan and package discovery, and builds frontend assets. It never runs migrations or seeders. After generation, review `.env`, then run any approved migrations manually.

The CLI uses the fixed repository owner, repository, and branch. Project generation requires PHP 8.4+, Composer 2, Git 2, Node.js, and npm. If `php` on PATH is not the compatible runtime, set `MASTER_CORE_PHP` to the desired `php.exe` path for that terminal. A local development source override is used only for isolated maintainer tests; ordinary users need not provide a URL or package list.

`update` downloads the current public `main` snapshot and installs it as a new version. Existing installed versions and user configuration are preserved. This command requires the published branch to contain the installer/CLI payload; the current public baseline still needs the first source update.

The maintainer commands work from inside the original Core repository or a subdirectory. They verify `origin` and `main`, list changes, screen sensitive paths/content, and use GitHub's read-only remote reference lookup. `publish --dry-run` does not modify Git state. Actual `publish` requires interactive path selection and separate stage, commit, and push confirmations. It is not intended for generated consumer projects.
