# Windows installation

## Status

The bootstrap installer is available from the current public GitHub `main` branch. On 2026-11-19 the raw installer URL returned HTTP 200, and the complete bootstrap plus `master new example-app` workflow passed against commit `8d8bb29da811ccfe86b60eee9a2f2a8f23c31f66` in a clean temporary directory. See [GitHub distribution verification](github-distribution.md).

The local release candidate is prepared as `1.0.0`, but the public branch remains at `0.1.0-dev` until a release is approved and published. Installed CLI version output reflects the `VERSION` file bundled in that specific source snapshot.

## Install

Open PowerShell and run:

```powershell
irm https://raw.githubusercontent.com/Itassistancebd/laravel-master-core/main/install.ps1 | iex
```

The installer reads the public repository's `main` commit through GitHub's API, downloads its ZIP archive, validates archive paths and required source files, and installs under `%LOCALAPPDATA%\Programs\MasterCore`. It adds only that directory to the current user's PATH. It does not edit machine PATH, PHP configuration, XAMPP configuration, or project files.

Open a new terminal and check:

```powershell
master --help
master --version
```

The Windows installer requires Windows PowerShell 5.1 or PowerShell 7 on Windows. Project generation requires PHP 8.4+, Composer 2+, Git 2+, Node.js 20.19+ or 22.12+, and npm 7+ because the bundled Laravel application installs and builds its frontend. Git is required for maintainer source commands.

## Generated project behavior

`master new` copies the existing Laravel application from the GitHub monorepo, installs internal Core packages from local monorepo paths/workspaces, creates `.env`, generates an application key, verifies Artisan and package discovery, and builds the frontend. It does not create a Laravel skeleton or run database migrations or seeders. Existing projects are never overwritten; failed staging output is preserved for inspection.

## Installation errors

Record the command, Windows/PowerShell version, PHP/Composer/Git versions, and sanitized error text. Do not attach `.env`, private keys, database files, user data, or logs containing credentials. Use the repository's GitHub Issues page only if the repository has Issues enabled.
