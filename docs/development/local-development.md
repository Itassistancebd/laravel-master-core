# Local development

## Requirements

Use PHP 8.2 or later (the current validation runtime is PHP 8.4), Composer 2.x, Laravel 12, Node.js, and npm. A host application may use MySQL 8+, while the Foundation health route must remain usable without any database connection. Redis is not required.

## Setup

From the repository root, ensure the intended PHP 8.2+ runtime is on `PATH`, then install dependencies and run tests:

```powershell
composer install
composer test
```

The root Composer manifest uses a local path repository for `packages/core/foundation`. It is configured to copy package files so local development does not require Windows symlink privileges. When package metadata changes, run `composer update example/laravel-master-core-foundation`.

## Useful checks

```powershell
php artisan package:discover
php artisan route:list --path=core
php artisan route:cache
php artisan route:clear
php artisan config:cache
php artisan config:clear
```

Publish config only when a host wants an application-owned copy:

```powershell
php artisan vendor:publish --tag=master-core-config
```

Keep package feature tests deterministic and independent of external services. The health tests use the Laravel testing app and do not migrate or connect to a database.
