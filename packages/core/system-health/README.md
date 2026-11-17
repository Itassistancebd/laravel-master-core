# Core System Health & Readiness

This optional Laravel 12 package provides authenticated, read-only checks for the request that reached the diagnostic endpoint and the host's configured default database. It does not modify Foundation's public health contract or claim to report complete infrastructure health.

## Package and setup

- Composer package: `example/laravel-master-core-system-health`.
- npm workspace: `@itassistance-bd/system-health`.
- The Laravel service provider is registered through Composer package discovery.
- The API prefix follows Foundation's `api_prefix` and `api_version` settings. An optional package-specific prefix can be published in `master-core-system-health.php`.
- The root Auth SPA composes the read-only `/app/system-health` route and supplies the API URL.
- No table, migration, model, seeder, or cache is owned by this package. There is no migration to apply.

The host must have Core Authentication and Roles & Permissions installed and configured. The route requires Sanctum authentication and the registered `view-system-health` ability. Register/materialize that code-owned permission using the documented Phase 6 workflow and grant it only through the host's approved role process. The package never grants it or assigns Super Admin.

## Checks and response semantics

`GET /api/v1/system-health` executes a fixed code-defined list:

- `application_request`: confirms only that this authenticated request reached and was handled by the diagnostic controller.
- `primary_database`: when a default connection is configured, obtains that connection's PDO handle and runs the constant read-only query `SELECT 1`. It does not accept a connection name or SQL from the request, write data, inspect migration state, or retry the query through Laravel's query retry path.

Each check returns an allowlisted key, display name, status, outcome, and safe message. A successful check is `healthy` / `successful`; a probe that cannot connect or returns no expected value is `unavailable` / `failed`; missing default connection configuration is `not_configured`. The overall status summarizes only these checks. It is not a general health guarantee. Results are not cached and carry `Cache-Control: private, no-store`.

Database exception messages, SQL, DSNs, hostnames, credentials, server paths, and raw configuration are not returned or logged. Operational warnings include only the stable check key and exception class. The endpoint is rate-limited to 10 requests per minute. Connection establishment timeouts remain controlled by the host's PDO/driver configuration; this package adds no retries and cannot guarantee a network timeout consistently across drivers.

The endpoint itself needs authentication and authorization. If the same unavailable database is required for the host's session or RBAC lookup, a user may be unable to reach the diagnostic result during a complete database outage. Use Foundation's public database-independent health response for basic process/routing availability; it intentionally does not report database readiness.

## Security and Module Manager

The API is `auth:sanctum` plus `can:view-system-health`; the frontend route permission is navigation guidance only. Laravel authorization is enforced for every API request. No public diagnostics route, mutation action, arbitrary probe registration, or infrastructure control is provided.

Module Manager includes this package as an explicit allowlisted catalog entry. Its package installation/provider signals remain separate from actual runtime checks and continue to report `runtime_not_verified` when only catalog-level facts are established.

## Verification

Backend feature tests use the PHPUnit SQLite `:memory:` database and fakes for database failure cases. They do not connect to the configured application database and do not run its migrations. From the repository root, run:

```powershell
php artisan test --testsuite='Core System Health'
npm run typecheck
npm run test:ui
npm run build -- --emptyOutDir false
```

Before production acceptance, test the read-only probe and failure handling against an explicitly isolated MySQL 8 database using the target least-privilege application account. Review the DB driver's connect timeout, connection limits, proxy/session/RBAC dependencies, and complete authenticated browser checks. Do not point acceptance tests at configured user data or run application migrations for this package.
