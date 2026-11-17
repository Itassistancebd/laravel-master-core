# Master Core Audit Logs

Audit Logs is an optional Laravel 12 package that stores security and administration history in the package-owned `master_core_audit_events` table. It preserves the configured host user model and Laravel stock migrations. Critical producers synchronously write their audit row inside the business transaction; a failed audit insert rolls that mutation back.

## Install and host integration

Add the Composer path package and `@itassistance-bd/audit-logs` workspace, then run Composer package discovery. The provider registers its one migration but does not run it. Review the target database, backup, credentials, and deployment plan, then apply package migrations only through the host's separately approved deployment process. The package API uses Foundation's API prefix/version and requires Sanctum plus `view-audit-logs`.

The Phase 6 code permission registry contains `view-audit-logs` and `export-audit-logs`. Neither is automatically granted. Only `view-audit-logs` protects the v1 read-only API and Vue route. `export-audit-logs` is a reserved registered ability; v1 has no export API, job, or UI.

Producer packages emit the versioned `master-core.audit.record.v1` Laravel event with a closed catalog event name and allowlisted envelope. The provider synchronously validates and persists it. This event boundary creates no Composer dependency from Settings, Authentication, User Management, or Roles & Permissions to this optional package. Critical Settings/User Management/RBAC events must be dispatched inside their owning transaction. Existing after-commit package events remain available to other listeners.

Authentication lifecycle events are recorded separately. Their persistence errors are caught so they cannot block login, logout, or password-reset flows. Operational logs include only the event name and exception class; credentials, request contents, and exception messages are omitted.

## Data, privacy, and retention

The single audit table has no host-user or business foreign keys, no `updated_at`, and no soft-delete column. Actor/subject identifiers are opaque references. The event catalog accepts only approved names, categories, metadata keys, and snapshot keys. It rejects arbitrary fields and limits payload size. Passwords, tokens, session/CSRF secrets, credentials, raw request bodies, failed-login identifiers, and fingerprints are not stored.

IP address and User-Agent capture are enabled by default. Set `MASTER_CORE_AUDIT_CAPTURE_IP=false` or `MASTER_CORE_AUDIT_CAPTURE_USER_AGENT=false` when host privacy policy requires disabling a field. IP is obtained through Laravel's request IP handling; configure trusted proxies correctly before honoring forwarded addresses. User-Agent is sanitized and capped at 512 bytes. Audit-log readers can see recorded IP/User-Agent, so grant `view-audit-logs` narrowly.

Configure `MASTER_CORE_AUDIT_RETENTION_DAYS` to a positive number of days after the host approves its retention policy. `MASTER_CORE_AUDIT_RETENTION_APPROVED` and `MASTER_CORE_AUDIT_PURGE_ENABLED` default to `false`. The package schedules no deletion. The console command `php artisan master-core:audit-logs:purge-expired` refuses to run unless all three settings are explicitly configured. Verify backups and run it manually under a separate privileged operational process. Do not purge through the API. Audit rows, including their IP/User-Agent, share the same retention period because rows are append-only.

For production, use an application DB principal that can read and insert audit rows but cannot update/delete them. Use a separately controlled migration/maintenance principal where supported. This does not protect against a privileged DBA, who can alter rows or privileges; export/backup controls and organizational review remain necessary.

## API and UI

- `GET /api/v1/audit-logs`: allowlisted search and filters, bounded date range, sort by occurrence time, and 10/25/50/100 pagination.
- `GET /api/v1/audit-logs/{ulid}`: allowlisted event detail and safe change summary.

Both routes require `auth:sanctum` and `view-audit-logs`. The Vue screen is read-only, uses the shared Core UI DataTable, provides filter/loading/empty/error/403 states, and has no export/edit/delete controls. Frontend permission checks only guide navigation; Laravel middleware remains authoritative.

## Verification

Automated package tests use PHPUnit's SQLite `:memory:` database. SQLite does not prove MySQL row-locking, index-size, privilege, JSON, or collation behavior. Before release, validate the migration, least-privilege DB account, approved retention and purge policy, trusted-proxy configuration, and concurrent/transaction behavior against an isolated MySQL instance. Complete authenticated browser acceptance in the host UI. Never run the migration against a configured application database without separate explicit approval.
