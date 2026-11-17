# Local development

## Requirements

Use PHP 8.4 or later, Composer 2.x, Laravel 12, Node.js, and npm. A host application may use MySQL 8+, while the Foundation health route must remain usable without any database connection. Redis is not required.

## Windows PHP runtime

This checkout uses a local PHP runtime configuration so its commands do not depend on the machine-wide PATH. Configure the ignored `.php-runtime.local.json` file at the project root:

```json
{
  "php": "C:\\newserver\\xampp2\\php\\php.exe",
  "extensions": ["zip"]
}
```

The `extensions` list enables existing PHP extensions for this checkout's CLI invocations only. This project's local config requests the XAMPP2 PHP 8.4 `zip` extension, which is present beside that executable. The wrapper verifies that it loads and passes it to PHP and Composer without changing `php.ini`, the global PATH, or XAMPP1.

Run PHP and Composer commands through the wrapper. It verifies PHP 8.4+, invokes PHP by its configured path, and applies the local extension options:

```powershell
.\scripts\project-command.ps1 php --version
.\scripts\project-command.ps1 php artisan --version
.\scripts\project-command.ps1 composer validate --no-check-publish
.\scripts\project-command.ps1 composer test
```

The Playwright E2E runner reads the same local configuration and checks the runtime before starting. `PHP_BINARY` can override the configured path for a one-off run. Do not copy the local config into generated consumer projects. Avoid bare `php` or `composer` commands for this checkout when multiple PHP versions are installed.

## Setup

From the repository root, configure the Windows PHP runtime as described above, then install dependencies and run tests:

```powershell
.\scripts\project-command.ps1 composer install
.\scripts\project-command.ps1 composer test
```

The root Composer manifest uses a local path repository for `packages/core/foundation`. It is configured to copy package files so local development does not require Windows symlink privileges. When package metadata changes, run `composer update example/laravel-master-core-foundation`.

## Useful checks

```powershell
.\scripts\project-command.ps1 php artisan package:discover
.\scripts\project-command.ps1 php artisan route:list --path=core
.\scripts\project-command.ps1 php artisan route:cache
.\scripts\project-command.ps1 php artisan route:clear
.\scripts\project-command.ps1 php artisan config:cache
.\scripts\project-command.ps1 php artisan config:clear
```

Publish config only when a host wants an application-owned copy:

```powershell
.\scripts\project-command.ps1 php artisan vendor:publish --tag=master-core-config
```

Keep package feature tests deterministic and independent of external services. The health tests use the Laravel testing app and do not migrate or connect to a database.
