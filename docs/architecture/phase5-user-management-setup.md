# Phase 5 User Management Setup

## Package registration

The development application includes the User Management Composer path repository and npm workspace. Composer auto-discovery registers the service provider, which loads the package migration and routes. The package does not create an alternate user model/table.

For a consuming Laravel 12 application, require the Composer package and the matching npm workspace/package according to its distribution method, then publish configuration only when the host needs custom settings:

```powershell
php artisan vendor:publish --tag=master-core-user-management-config
```

## Host user compatibility

The default model is resolved from the configured default Eloquent authentication provider (`auth.providers.<default>.model`). The model must use Laravel `SoftDeletes`; the stock host `App\Models\User` in this repository does so. It must retain the normal `name`, `email`, `password`, and email-verification fields. Password reset requires Laravel's notification/password reset contract. Verification resend is enabled only when the host model implements `MustVerifyEmail`.

If the host uses another table, set both `master-core-user-management.table` and the host migration schema to that table. The package migration checks for the existing table and refuses to run when either lifecycle column already exists. Hosts with equivalent fields should provide a reviewed schema adapter instead of running this migration.

## Database migration

The package migration adds:

- `is_active`: non-null boolean, default `true`, indexed.
- `deleted_at`: nullable timestamp, indexed and used by Laravel `SoftDeletes`.

The host user migration remains unchanged. New rows are active by default. A trashed account continues to reserve its unique email address. The migration rollback refuses to remove these columns while any inactive or trashed account remains.

Before applying the package migration to a host database, review the target connection and verify a recoverable backup. Apply it through the host's normal migration process only after that host-specific operational approval. This implementation did not run it against the configured application database. Automated migration checks use isolated SQLite memory.

## Access policy

The host must define Laravel Gates for individual operations or a broader `manage-users` Gate. Authentication does not grant management access. `GET /api/v1/users/capabilities` helps the UI show available actions; backend checks remain authoritative.

Configure a host class implementing `MasterCore\UserManagement\Contracts\UserManagementSafetyPolicy` in `master-core-user-management.safety_policy`. The policy decides whether an actor may deactivate, trash, or permanently delete a target and must protect the last active administrator. Until it is configured, these destructive lifecycle actions fail closed. The policy must use the host's Phase 6 role/administrator source when that module is available.

## UI integration

The User Management package contributes `/app/users` route records to the existing Auth Vue Router. The root auth entry passes the API URL built from Foundation's API prefix/version configuration. The authenticated shell, CSRF cookie flow, and sign-out remain owned by Core Authentication. No second router or design system is introduced.
