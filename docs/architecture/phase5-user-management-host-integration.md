# Phase 5 User Management Host Integration

## Identity and model

User Management resolves the default configured Eloquent auth provider model. In this host that remains `App\Models\User`. The package does not define a Core model or modify Laravel's stock user migration. The host model must use `SoftDeletes`, support Laravel authentication/password resets, and expose the identity fields used by the package. `MustVerifyEmail` is required only for verification resend.

The user table stays host-owned. The package migration owns only `is_active`, `deleted_at`, and their named indexes. For a custom host table, configure `master-core-user-management.table` to match the model and schema before migrating. Do not set a table name based on request input.

## Authentication integration

Core Authentication exposes `LoginEligibility`. Authentication's default implementation allows the same user set as before; User Management binds an implementation that requires an active, SoftDeletes-compatible host user. The package adds middleware to the Laravel `web` and `api` groups. Thus inactive accounts cannot sign in and an already-authenticated inactive user is logged out/rejected on the next request. Soft-deleted users are excluded by Eloquent's SoftDeletes global scope.

The package invalidates database-backed sessions when deactivating or trashing users and removes their password-reset tokens. The request middleware also enforces the status for non-database session drivers. Fortify, Sanctum, CSRF, session rotation, login throttling, password reset, and verification remain owned by Core Authentication.

## Authorization contract

Define host Laravel Gates for `view-users`, `create-users`, `update-users`, `manage-user-status`, `delete-users`, `restore-users`, `force-delete-users`, `reset-user-passwords`, and `resend-user-verification`; a host can instead use a broader `manage-users` Gate. The API returns capability flags for presentation only. No ability or role is assigned by User Management.

The default `DenyDestructiveUserManagementPolicy` blocks deactivation, trash, and permanent deletion. A host must configure a policy that protects itself from self-removal and prevents deactivating/trashing/deleting the last active administrator. Authorization and last-administrator decisions must be enforced server-side. Phase 6 owns role and permission storage/assignment.

## Routes and responses

The package API uses Sanctum's same-origin session authentication and Foundation's API prefix/version. It returns safe user resources containing only ID, name, email, email-verification timestamp, account status, creation date, and deletion timestamp. Passwords, reset tokens, remember tokens, session content, and provider internals are never serialized.

The Auth SPA mounts the list, create, edit, details, and trash routes beneath `/app/users`. It uses the existing Core UI fields, feedback controls, confirmation dialog, and shared DataTable. UI checks do not replace API authorization.

## Migration and rollback

The package migration must run after the host creates its user table. It checks for column collisions and preserves the stock migration. Rollback is refused if any user is inactive or trashed because dropping the columns would discard account state. A host should resolve or back up that state before a separately reviewed rollback.

## Testing and operations

Run the user-management tests and cross-package regression tests with SQLite `:memory:`. Migration tests use a dedicated isolated in-memory connection. Do not point automated tests at a host database. For production rollout, separately verify the host's user model, table configuration, Gate definitions, safety policy, mail delivery, session driver, backup, and migration plan.
