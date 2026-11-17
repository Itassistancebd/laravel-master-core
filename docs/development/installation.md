# Windows installation

## Status

The local source tree contains `install.ps1`, but the current published GitHub `main` commit does not. The remote bootstrap command will work only after a separately authorized source update publishes the installer and CLI files. As of this report, the published baseline is `9ec7976c27492ff12fa05f309d7befbc8d8d2592`.

## Install after the installer is published

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

The Windows installer requires Windows PowerShell 5.1 or PowerShell 7 on Windows. Project generation requires PHP 8.4+, Composer 2+, Git 2+, Node.js 20.19+ or 22.12+, and npm 7+ because the bundled Laravel application installs and builds its frontend. Git is required for maintainer source commands. These checks do not make an unavailable package source available.

## Current limitation

The installer and CLI are implemented locally, but the published `main` branch does not yet include the new installer, so fresh-PC bootstrap cannot work until the first authorized source publication. Project generation uses Laravel and third-party dependencies from the normal Composer/npm registries, while resolving all internal Core packages from included monorepo source.

## Installation errors

Record the command, Windows/PowerShell version, PHP/Composer/Git versions, and sanitized error text. Do not attach `.env`, private keys, database files, user data, or logs containing credentials. Use the repository's GitHub Issues page only if the repository has Issues enabled.
