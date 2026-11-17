# Phase 5 Proposal: User Management

> **Status: APPROVED FOR IMPLEMENTATION.** The Phase 5 implementation follows this proposal and the decisions recorded in the approved implementation request.

## 1. Objective and boundaries

Add a separately maintainable User Management package that administers the host application's configured authenticatable user model. It will integrate with the Phase 4 Authentication package, use the existing global UI and CRUD standards, and leave Phase 6 Roles & Permissions to a later phase.

The package will not create a duplicate Core User model or a second users table. It will not implement roles, permissions, registration, OAuth, MFA, or business-specific profile fields. It will not grant user-management abilities automatically. Existing Foundation, Settings, Authentication, and Laravel stock migrations remain in place.

## 2. Existing architecture inspection findings

### Host User model and stock schema

The current `App\Models\User` extends Laravel's `Authenticatable`, implements `MustVerifyEmail`, and uses `HasFactory` and `Notifiable`. It is the default Eloquent model for the `users` provider, with the model configurable through `AUTH_MODEL`. Its fillable fields are `name`, `email`, and `password`; `password` and `remember_token` are hidden. It casts `email_verified_at` to datetime and `password` to Laravel's hashed cast.

The host-owned `users` table has an auto-incrementing `id`, `name`, unique `email`, nullable `email_verified_at`, `password`, nullable `remember_token`, and Laravel timestamps. The stock migration also creates `password_reset_tokens` and `sessions`; `sessions.user_id` is nullable and indexed, without a foreign key. The users schema currently has neither an account-status column nor `deleted_at`, and the model does not use `SoftDeletes`.

The stock unique email constraint applies even to any future soft-deleted rows. Thus a trashed user's email remains reserved until permanent deletion. Preserve that constraint unless a separate, safe email-reuse design is approved.

### Authentication and authorization

Phase 4 uses Laravel Fortify for session login/logout, password reset, verification, and confirmation; Sanctum handles same-origin stateful API authentication. `bootstrap/app.php` enables `statefulApi()`. Core Authentication exposes the current user and conditional database-session inventory; there is no User Management route or account-status enforcement today.

Phase 4 does not globally require email verification and does not define roles or a `manage-users` ability. Settings routes demonstrate the current permission-ready pattern: `auth:sanctum` followed by host-defined `can:view-settings` or `can:manage-settings`. User Management should use the same separation between identity and authorization.

### Vue workspace, shared UI, and tests

The root workspace already has Vue 3, TypeScript, Vue Router, Axios, Vite, Vitest, `@master-core/ui`, and `@master-core/auth`. The auth package owns the session-aware Vue Router and the authenticated `/app/*` shell. Core UI exports shared fields, text/password inputs, buttons, checkboxes, searchable selects, confirmation dialogs, and toast feedback.

There is currently **no shared DataTable component**. The Global UI and CRUD Standard requires server-side search/filter/sort/pagination, row selection, bulk actions, column visibility, row striping/hover, page sizes 10/25/50/100, and up to five page links. Phase 5 should add the reusable table primitive to `packages/core/ui` and consume it from User Management rather than build a second table system.

PHPUnit already includes Foundation, Settings, and Authentication suites and uses SQLite `:memory:`. Vitest, `vue-tsc`, and Vite build scripts are present. No production/application database test path is proposed.

## 3. User Management scope

### User list

Display serial number, name, email, verification state, active/inactive state, and created date. Support server-side search across name and email; filters for active state and verification state; allow-listed sorting; server pagination; column visibility; selection; and authorized bulk actions. List and trash results must have separate clear states.

### User lifecycle

- Create a host User with validated name and email.
- View safe user details and edit name/email.
- Activate and deactivate accounts.
- Soft delete, list trash, restore, and permanently delete with an explicit confirmation.
- Apply the same server-side validation and per-record authorization to bulk operations.
- Resend an email verification notification where the configured user model supports Laravel's verification contract.
- Initiate a password reset through Laravel's configured password broker; never display or set a user's plaintext password in an administrative response.

No phone, address, image, biography, last-login field, or other speculative profile data is proposed.

## 4. User model and database recommendation

### Identity and model decision

Use the Laravel configured Eloquent auth provider and its model. In this repository that is the existing `App\Models\User`. Do not introduce `MasterCore\\UserManagement\\Models\\User`, copy the `users` table, or hard-code `App\Models\User` in package controllers/services.

For this module, a supported host model must be Eloquent-backed, authenticatable, persist the standard name/email/password identity fields, support Laravel notifications/password reset, and use `SoftDeletes` once the approved soft-delete column exists. A host using Laravel's database-only auth provider, or a model with incompatible identifiers/fields, needs an explicit adapter and matching migration configuration before enabling this package.

### Smallest proposed schema change

Add only two columns to the configured host user table through a **User Management package-owned additive migration**. The package owns these account-management fields; the host continues to own the table and the stock migration remains unchanged.

| Column | Type and nullability | Default/index | Purpose and behavior |
|---|---|---|---|
| `is_active` | Boolean, not nullable | Default `true`; index | Distinguishes an enabled account from a deactivated one. The status action is server-controlled and is never accepted through generic create/update mass assignment. |
| `deleted_at` | Nullable timestamp | Null means not trashed; index | Laravel soft-delete lifecycle, trash listing, restore, and permanent-delete path. Add the `SoftDeletes` trait to the existing configured model during implementation. |

The existing primary key, unique email, verification timestamp, password/remember fields, and created/updated timestamps remain unchanged. There are no new foreign keys, relationships, Core user table, sidecar table, or audit table. Use a configurable host table name (default `users`) for the migration. A host with pre-existing equivalent columns must opt into its schema adapter rather than let the package migration claim ownership of those fields.

Existing rows become active (`is_active=true`) and not deleted (`deleted_at=null`) when the additive migration is applied. The existing unique email means an email address cannot be re-used while the original row remains in trash.

### Why not a Core status table

A status sidecar would require a stable cross-host user key, model discriminator, orphan cleanup, and separate status lookups on authentication. It would add a table while still requiring an authentication hook. Extending the configured host table with two account-lifecycle fields is smaller, preserves one identity source, and lets Laravel's Eloquent soft-delete scope exclude trashed users from ordinary auth-provider retrieval.

### Migration ownership and rollback

The migration belongs in `packages/core/user-management/database/migrations/`, runs after the host migration has created the configured users table, and alters only `is_active`, `deleted_at`, and their package-owned indexes. It must not edit or replace `database/migrations/0001_01_01_000000_create_users_table.php`.

Rollback would discard activation and trash state. The eventual `down()` should refuse to remove these columns while any inactive or trashed accounts remain, unless an explicitly reviewed rollback procedure first resolves those records. Test both migration directions on isolated SQLite and MySQL test databases when available. Do not run the migration against the current application database as part of Phase 5 planning or implementation without separate explicit authorization.

## 5. Authentication integration and account status

Soft-deleted users are excluded by Eloquent's `SoftDeletes` global scope. Deactivation is separate: the existing Fortify login flow does not currently inspect `is_active`, and an authenticated session can outlive a status change unless explicitly blocked.

The implementation should add a narrow optional login-eligibility contract to Core Authentication, defaulting to the current Phase 4 behavior when User Management is absent. User Management binds it to the configured model's active/not-trashed state. Use it in Fortify's existing authentication pipeline and in a small middleware on stateful web/API requests so deactivated accounts cannot continue through an existing session. Preserve Fortify rate limiting, generic credential failures, CSRF, session rotation, and existing auth routes. Invalidate database-backed sessions when status changes; for other session drivers the eligibility middleware must still deny the next request.

These are additive integration hooks, not a replacement auth guard or a new login flow. Authentication establishes identity; User Management operations still require server-side authorization.

## 6. Proposed package structure

```text
packages/core/user-management/
├── composer.json
├── package.json
├── README.md
├── config/user-management.php
├── database/migrations/                 # additive account-lifecycle columns only
├── routes/api.php
├── routes/web.php                       # authenticated Vue mount if required
├── src/
│   ├── Actions/                         # lifecycle operations and bulk operations
│   ├── Contracts/                      # login/safety integration contracts
│   ├── Events/                          # audit-ready domain events; no audit table
│   ├── Http/Controllers/
│   ├── Http/Requests/                   # allow-listed input validation
│   ├── Http/Resources/                  # safe list/detail response fields
│   ├── Http/Middleware/                 # active-account request enforcement
│   ├── Providers/UserManagementServiceProvider.php
│   └── Support/                         # allow-listed user query/sort behavior
├── resources/views/users.blade.php
├── resources/js/
│   ├── UserManagementApp.vue
│   ├── routes.ts                        # authenticated Vue Router records
│   └── pages/                           # list, details, create/edit, trash
└── tests/
    ├── Feature/
    └── Unit/
```

Do not create a Core User Eloquent model. Resolve the configured host model/provider and keep query, validation, serialization, lifecycle actions, and Vue presentation in their respective layers. Keep abstractions limited to explicit authentication and last-administrator safety contracts.

## 7. API endpoint plan

Use the Foundation `master-core.api_prefix` and `master-core.api_version` configuration, defaulting to `/api/v1/users`. All API routes use `api`, `auth:sanctum`, and an explicit host ability. Return a safe resource representation; never serialize password, remember token, password-reset token, session ID/payload, or internal auth provider details.

| Method and path | Proposed access | Behavior |
|---|---|---|
| `GET /api/v1/users/capabilities` | `auth:sanctum` | Return booleans for the current user's relevant Gate abilities; this is UI guidance only and grants nothing. |
| `GET /api/v1/users` | `view-users` | Server search/filter/sort/paginate active and inactive, non-trashed users. |
| `POST /api/v1/users` | `create-users` or `manage-users` | Create allow-listed identity fields, store an unpredictable generated password hash, and initiate the stock password-reset/setup notification. |
| `GET /api/v1/users/{user}` | `view-users` | Return safe details for one non-trashed user. |
| `PUT /api/v1/users/{user}` | `update-users` or `manage-users` | Update only validated name/email fields; preserve unsubmitted values. |
| `PATCH /api/v1/users/{user}/status` | `manage-user-status` or `manage-users` | Activate/deactivate with self and last-manager safety checks. |
| `DELETE /api/v1/users/{user}` | `delete-users` or `manage-users` | Soft-delete with self and last-manager safety checks. |
| `GET /api/v1/users/trash` | `view-users` | List only soft-deleted users using the same server-side query contract. |
| `POST /api/v1/users/{user}/restore` | `restore-users` or `manage-users` | Restore a trashed user without changing their prior active/inactive state. |
| `DELETE /api/v1/users/{user}/force` | `force-delete-users` | Permanently delete after password confirmation, explicit confirmation, and host relationship-safety checks. |
| `POST /api/v1/users/{user}/password-reset` | `reset-user-passwords` or `manage-users` | Ask the configured Laravel broker to email a reset link; never set or return plaintext credentials. |
| `POST /api/v1/users/{user}/verification-notification` | `resend-user-verification` or `manage-users` | Resend the framework verification notification for compatible models. |
| `POST /api/v1/users/bulk/{operation}` | Ability for that operation | Apply a validated operation to distinct selected IDs, capped at 100, with per-record safety checks and a single atomic outcome. |

The list query accepts `search`, `status=active|inactive`, `email_verified=verified|unverified`, allow-listed `sort_by`/`sort_direction`, `page`, and `per_page` restricted to 10, 25, 50, or 100. Search name/email only. Column visibility remains frontend presentation state. Order fixed routes such as `trash` and `bulk` before the dynamic `{user}` route. Use stable ordering with the primary key as a tie-breaker.

For bulk operations, validate every selected record and permission/invariant before writing; use one transaction and fail the whole action rather than silently partially applying an unsafe request. Return affected counts and clear per-operation outcomes. Never accept arbitrary sort columns, model class names, raw query fragments, or a caller-selected user table.

## 8. Authorization plan for Phase 5 and Phase 6

Phase 5 must not define a role table, role assignment UI, permission CRUD, or default administrator. Use Laravel Gate/policy middleware on every route and authorize each row in bulk actions. No frontend-only checks.

Recommended abilities for the host or later Roles & Permissions package are `view-users`, `create-users`, `update-users`, `manage-user-status`, `delete-users`, `restore-users`, `force-delete-users`, `reset-user-passwords`, and `resend-user-verification`. A host may map these to a broader `manage-users` Gate. Phase 6 owns assigning these abilities to roles and administrators. Missing abilities deny access; authentication alone grants none.

Protect the signed-in operator from self-deactivation, self-trash, and self-permanent-deletion. The current host has no role data, so the package cannot infer which account is an administrator or determine the last active administrator. Require a host-supplied `UserManagementSafetyPolicy` that can identify/protect the final active manager; fail closed for manager-destructive actions until that policy is configured. This contract can later be backed by Phase 6 without changing the User Management routes.

## 9. Security and data-integrity requirements

- Validate every request with Form Requests and explicit allow-lists. Do not make `is_active`, `deleted_at`, `email_verified_at`, `remember_token`, or password hashes generically mass assignable.
- Enforce Gate/policy authorization for reads, writes, status changes, trash, restore, permanent delete, reset links, verification resend, and every selected row in bulk requests.
- Keep login failure behavior generic for inactive users and avoid account-existence leaks on public routes. Administrative endpoints are already privileged and must still avoid returning reset tokens.
- Apply the Phase 4 login-eligibility hook and active-account middleware to stop both new login and existing sessions after deactivation. Clear database-backed sessions and password-reset tokens for a deactivated/trashed account when supported; middleware remains the enforcement for other session stores.
- Hash any generated initial password with Laravel's configured hasher. Never log passwords, reset links, verification links, cookies, session IDs, or reset tokens.
- Require an explicit confirmation and recent password confirmation for permanent deletion. Do not cascade-delete unknown host/business relationships. Foreign-key restrictions should stop unsafe removal and return a controlled error.
- Do not permanently remove a last active administrator. Keep the `manage-users` invariant in the host safety policy until Phase 6 supplies role-aware logic.
- Keep the current unique email constraint. A trashed address stays unavailable until the user is restored or permanently deleted.
- Emit audit-ready Laravel events for create/update/status/trash/restore/permanent-delete/reset-initiation/verification-resend, including actor and target IDs but no secrets. Do not add an audit table; Phase 6/future Audit Logs can subscribe.
- Keep CSRF, Sanctum stateful cookie handling, and auth middleware. User-management APIs remain private and return JSON 401/403.

## 10. Frontend plan

Add a User Management npm workspace package depending on the existing Vue 3, TypeScript, Vue Router, Auth, and Core UI packages. Compose its authenticated route records into the existing Auth router under `/app/users`; preserve the Auth package's session state and route guard. Do not create another router, CSS system, dashboard, or CRUD framework.

Pages:

- **Users list:** standard page title/subtitle; Core DataTable; search/filter/sort/pagination; verified/active badges; column visibility; row selection; View/Edit/Deactivate/Trash actions; permission-aware bulk actions.
- **Create/edit form:** visible labels, standard form controls and field errors; name/email; account status only through its dedicated authorized operation; no password-hash or verification-timestamp inputs. Create uses a one-time setup/reset email rather than displaying a password.
- **Details:** safe identity, verification/account status, created date, and authorized admin actions.
- **Trash:** server-paginated trashed users with restore and confirmed permanent-delete actions.
- **Feedback:** CoreConfirmDialog, CoreToast, loading/error/empty states, keyboard focus, and responsive table/form layouts.

Add a reusable `CoreDataTable` to `packages/core/ui` before using the user list. It should accept server-provided rows/columns, loading/error/empty state, pagination metadata, sortable allow-listed keys, row selection, visibility controls, and named action slots. Keep search/filter/API state in the User Management package. Tests must cover both the shared component and this consumer.

The route guard may hide links/actions using the capabilities response, but the backend remains authoritative. A 401 returns to login, a 403 shows a permission error, and a validation 422 is mapped to the corresponding visible field. Do not put auth credentials in local storage.

## 11. Testing strategy

### Backend

- Migration up/down and existing-row defaults on isolated SQLite `:memory:`; verify no duplicate status/trash fields and preserve the stock unique-email constraint.
- Provider/model configuration and absence of any Core User model/table.
- User list resource safety, search, status/verification filters, allow-listed sorting, stable pagination, page-size validation, and trash scoping.
- Create/view/update validation, unique email including trashed users, mass-assignment protection, and password setup/reset notification behavior.
- Activate/deactivate, login denial for inactive accounts, existing-session rejection, database-session cleanup, soft delete/restore state preservation, and session-driver fallback enforcement.
- Permanent-delete confirmation, host relationship restrictions, no cascade deletion, self-action rejection, and last-active-manager policy behavior.
- Verification resend compatibility, notification assertion, generic response, and authorization denial.
- Bulk bounds, duplicate IDs, mixed valid/invalid selections, per-record abilities, transaction rollback, and all-or-nothing outcome.
- Explicit Gate tests for missing ability (deny), view-only, manage allowed, force-delete, and capabilities output. Never create or grant roles in tests beyond test-defined Gates.
- Phase 4 Authentication, Foundation, and Settings regressions. Use fake notifications/cache and SQLite memory only; do not run migrations against the configured application database.

### Frontend

- Core DataTable row/SL mapping, sorting state, selected rows, bulk selection, column visibility, page-size options, five-link pagination, keyboard/accessibility behavior, and loading/error/empty states.
- User list search/filter/sort/pagination request parameters; safe rendering of user data; 401/403/422 handling; no secret fields in UI models.
- Create/edit validation, status confirmation, trash/restore/permanent-delete confirmation, bulk confirmation, password-reset/resend-verification feedback.
- Authenticated route integration and permission capability display; API denial remains visible and cannot be bypassed by hiding a button.
- Run `vue-tsc`, Vitest across Core UI/Settings/Auth/User Management, and a production Vite build. Keep browser acceptance separate from automated results.

## 12. Dependencies and host integration

Proposed Composer package `example/laravel-master-core-user-management` depends on Laravel 12, Core Foundation for API prefix/version conventions, and Core Authentication for the optional login-eligibility contract. Proposed npm package `@master-core/user-management` depends on Vue 3, Vue Router, `@master-core/auth`, and `@master-core/ui`.

The host integration adds local Composer/npm workspace entries, provider discovery, the package migration path, a configured user-table name, and a small additive `SoftDeletes`/cast integration to the existing `App\Models\User`. It must provide Gate definitions and a last-manager safety policy. This does not replace `App\Models\User` or edit its stock migration. The UI routes are composed into the existing Auth router. No dependency installation or migration is authorized by this proposal.

## 13. Implementation sequence after approval

1. Confirm schema, host-user compatibility, ability names, and last-manager safety policy.
2. Scaffold the separate Composer/npm package and config; register provider, API routes, package migration, and collision checks. Add no Core User model/table.
3. Add the two additive user lifecycle columns and SoftDeletes host-model integration. Verify migration defaults/rollback only on isolated SQLite; prepare an isolated MySQL test plan.
4. Add Auth login-eligibility integration and active-account middleware with regression tests. Preserve Fortify/Sanctum cookies, CSRF, login throttling, reset/verification routes, and Settings abilities.
5. Add Form Requests, safe User API resources, server-side query filters, CRUD actions, lifecycle/status enforcement, bulk operations, broker reset, verification resend, capability response, events, and authorization checks.
6. Add the reusable Core DataTable, User Management pages, and authenticated route composition using the existing shared design system.
7. Add isolated backend/frontend tests and package/host/Phase 5 completion documentation.
8. Run Composer/PHP checks, backend suite, typecheck, UI tests, Vite build, route/middleware inspection, and integration checks. Do not run application database migrations without separate explicit authorization.

## 14. Acceptance criteria

- The User Management package uses the configured host Eloquent User and creates no second users table/model.
- Laravel stock migrations remain byte-for-byte unchanged; account lifecycle schema is a separate, package-owned additive migration.
- Existing users migrate as active and not trashed; status is not mass-assignable; trash/restore and email uniqueness behavior are explicit.
- Inactive and soft-deleted accounts cannot log in or continue using an existing session; account state operations are tested.
- API list search/filter/sort/pagination is server-side and allow-listed; responses contain no secret fields.
- All management operations require backend abilities/policies, and no abilities or roles are auto-granted.
- Self-protection, last active manager, permanent deletion, bulk atomicity, password reset, and verification resend are enforced and tested.
- UI uses existing Vue Router and Core UI, with one reusable shared DataTable, standard responsive states, and correct five-link pagination.
- Phase 1–4 behavior remains covered by the regression suite. Tests use isolated databases; no configured application database is migrated.
- Package setup, custom host-model constraints, migration rollback, authorization, and future Phase 6 integration are documented.

## 15. Approved implementation decisions and host requirements

1. **Schema extension:** approved: add `is_active` and `deleted_at` through a User Management package migration against the configured host user table and add `SoftDeletes` to the existing model. The stock migration is not edited.
2. **Last active administrator:** no roles exist yet. Destructive actions fail closed until the host provides `UserManagementSafetyPolicy`; the host policy must protect the last active administrator. Phase 6 will supply role-aware protection.
3. **Initial account credentials:** approved: create with a generated, undisclosed password hash and request a Laravel password setup/reset link.
4. **Permission contract:** use the documented granular abilities, allowing a host to map them to a broader `manage-users` Gate. Phase 6 owns role assignment and permission persistence.
5. **Soft-delete email behavior:** approved: retain the unique email constraint so a trashed account reserves its email until restored or permanently deleted.
6. **Custom host user models:** hosts must provide a compatible Eloquent model and configure the package migration table to match it. Hosts with incompatible identity fields need an adapter before enabling this package.

See the [development standards](../standards/development.md), [global UI and CRUD standards](../standards/global-ui-crud.md), [database architecture](database.md), [Phase 3 completion](phase3-settings-completion.md), and [Phase 4 Authentication proposal/completion](phase4-auth-security-proposal.md) / [completion report](phase4-auth-security-completion.md).
