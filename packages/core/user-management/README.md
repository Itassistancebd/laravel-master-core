# Master Core User Management

The User Management package administers the Laravel host's configured Eloquent authentication model. It preserves the host identity source and Laravel stock user migration, and adds only the account lifecycle fields owned by this package.

## Ownership and requirements

- Composer package: `example/laravel-master-core-user-management`.
- npm workspace: `@itassistance-bd/user-management`.
- Requires Laravel 12, Core Foundation, Core Authentication, Vue 3, TypeScript, Vue Router, and Core UI.
- Uses the model configured in the default Laravel Eloquent auth provider. It creates no Core User model or separate users table.
- The configured host model must be Eloquent-backed, implement Laravel authentication/password reset and verification contracts as needed, expose `name`, `email`, `password`, `email_verified_at`, and use `SoftDeletes`.
- The package migration adds `is_active` (default true) and nullable `deleted_at`, plus indexes, to the configured host user table. The original stock migration remains unchanged.
- Unique email remains in force for trashed records. Restore and permanent deletion preserve that uniqueness rule.
- Inactive users cannot log in; subsequent requests for an existing session are rejected. Database sessions and password-reset tokens are invalidated when an account is deactivated or trashed.
- Destructive account operations are denied until the host configures a `UserManagementSafetyPolicy`. The policy must protect the last active administrator. No roles or abilities are created or granted by this package.

## Setup and configuration

Read the [setup guide](../../../docs/architecture/phase5-user-management-setup.md) and [host integration guide](../../../docs/architecture/phase5-user-management-host-integration.md) before enabling the package. Publish `master-core-user-management-config` when the host needs custom model/table settings or its safety policy. Define the proposed `view-users`, `create-users`, `update-users`, `manage-user-status`, `delete-users`, `restore-users`, `force-delete-users`, `reset-user-passwords`, and `resend-user-verification` abilities (or a `manage-users` Gate). Phase 6 maps `update-users` to `edit-users` and adds the separate `manage-user-roles` ability. Missing abilities deny access.

The Auth SPA composes the package route records under `/app/users`; the API defaults to `/api/v1/users` and follows Foundation's configured API prefix and version. The UI consumes `CoreDataTable` from `@itassistance-bd/ui`.

## Verification

The package's feature tests use PHPUnit's SQLite `:memory:` database. The migration-specific rollback check creates its own isolated in-memory SQLite connection. Run `php artisan test --testsuite="Core User Management"`, then the full PHP suite, `npm run typecheck`, `npm run test:ui`, and `npm run build -- --emptyOutDir false` from the project root. These checks do not require migrating the configured application database.

See the [approved Phase 5 proposal](../../../docs/architecture/phase5-user-management-proposal.md) and [completion report](../../../docs/architecture/phase5-user-management-completion.md).


## External npm installation

After this package and its dependencies have been published to the verified public npm scope, install it in a consumer project with:

```sh
npm install @itassistance-bd/user-management
```

The package is not asserted to be published by this source repository. Until the publisher confirms registry availability and releases a compatible version, use the repository workspace setup described in the root README.
