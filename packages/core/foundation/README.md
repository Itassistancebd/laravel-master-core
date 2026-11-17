# Core Foundation

`example/laravel-master-core-foundation` is the Phase 1 Laravel 12 package for shared foundation behavior. Its Composer vendor name is an explicit placeholder and must be replaced by an owned identity before distribution.

## Included

- `FoundationServiceProvider` with Laravel package auto-discovery
- merged and publishable `master-core` configuration (`master-core-config` publish tag)
- API route registration with configurable prefix and version
- migration path registration for future package-owned migrations
- `CoreHealthInterface` and its small default implementation
- `GET /api/v1/core/health`, with a stable JSON contract and no database dependency

The package does not add migrations in Phase 1 because the foundation owns no tables yet.

## Host application setup

Add the package using a local Composer path repository during development, or a Git/private Composer repository when distributing it. Laravel package discovery registers the provider. Run `php artisan vendor:publish --tag=master-core-config` to copy the optional config into the host application; otherwise package defaults are merged automatically.

Configuration keys are `name`, `version`, `api_prefix`, `api_version`, `enable_health_endpoint`, and `enable_debug_information`. The debug flag is reserved for future internal use and does not expose runtime details through the health API.

## Development tests

From the repository root, run `composer test`. Package feature tests live under this package's `tests/Feature` directory and use the Laravel development application as the host.
