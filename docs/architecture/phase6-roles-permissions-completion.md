# Phase 6 Roles & Permissions Completion

> **Status: IMPLEMENTED AND VERIFIED.** The configured application database was not migrated.

## Approved scope

Implementation follows the [approved Phase 6 proposal](phase6-roles-permissions-proposal.md): a focused Laravel Gate/Policy resolver, Compatibility Mode by default, code-registered abilities, four package-owned RBAC tables, default guard only, no direct user grants/teams/tenants, and console-only Super Admin provisioning.

## Package and integration

The package lives in `packages/core/roles-permissions`. Root Composer/npm/TypeScript/PHPUnit configuration registers it. The authenticated Vue application composes its screens into the existing Core Auth router. User Management adds the approved `manage-user-roles` capability and preserves the `update-users` to `edit-users` mapping. Strict Mode disables the broad User Management `manage-users` fallback; Compatibility Mode keeps it available.

The package migration owns `master_core_roles`, `master_core_permissions`, `master_core_role_has_permissions`, and `master_core_model_has_roles`. It adds no host-user foreign key and creates no user or Super Admin record. `App\Models\User` and Laravel stock migrations are preserved. The migration is present and discovered but has not been run against the host database.

## Security behavior

- Only code-registered abilities resolve through Core roles; permission catalog UI cannot register new executable keys.
- Core allow decisions are not persistently cached. Permission catalog metadata alone may use cache.
- Compatibility Mode preserves existing host Gates and Policies. Strict Mode is opt-in, fails closed on registered abilities without grants, and requires host review and grant provisioning.
- Inactive and trashed accounts are denied by the Core resolver in every mode. Contextual Policies remain an additional check when applicable.
- Role grant and assignment managers check effective grant scope; Super Admin cannot be assigned through HTTP.
- `core-super-admin` is assigned only by an interactive command to an existing active, non-trashed user. No migration, seeder, registration, or first-user path assigns it.
- User Management destructive operations remain fail-closed without a host `UserManagementSafetyPolicy`; the package adds last active Super Admin safeguards.
- Orphan cleanup retains soft-deleted user assignments and is report-only by default.

## Verification results

- `php artisan test --no-ansi` — **passed after review fixes: 76 tests, 237 assertions**. PHPUnit uses SQLite `:memory:` and includes Foundation, Settings, Authentication, User Management, and Roles & Permissions suites.
- `npm run typecheck` — **passed**.
- `npm run test:ui -- --reporter=dot` — **passed after review fixes: 9 files, 28 tests**.
- `npm run build -- --outDir <temporary-build-output> --emptyOutDir false` — **passed: 140 modules transformed**. Build output was isolated from the repository's existing `public/build` assets.
- `npm run typecheck` — **passed after review fixes**.
- Scoped Pint check for modified PHP source and tests — **passed after formatting**.
- Scoped Pint formatting check for the Roles & Permissions package and approved User Management integration files — **passed**.
- Root and package `composer validate --no-check-publish --no-interaction` — **passed**. The local package was added to Composer/npm workspaces and package discovery. Composer's online advisory feed could not be reached in this environment, so a fresh advisory audit was unavailable.
- `php artisan route:list --path=api/v1/roles-permissions --no-ansi` — **16 routes registered**.
- Security tests verify host Gate decisions in Compatibility Mode, Strict Mode denial and the Phase 5 fallback boundary, code registry enforcement, grant-scope limits, Super Admin console-only assignment, inactive-account denial, protected last-admin removal, and fail-closed User Management destructive operations.

No application migration command was run. Package migration tests use PHPUnit's isolated in-memory SQLite database. No commit or push was made.

## Changed files

- Package: `packages/core/roles-permissions/` Composer/npm manifests, package guide, configuration, the four-table package migration, API routes, models, authorization/action/support classes, console commands, requests/controllers, Vue/TypeScript API, routes, screens, and feature/UI tests.
- Root integration: `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `tsconfig.json`, and `phpunit.xml`.
- Approved integration points: `resources/js/auth.ts`; Core Auth router and Blade mount data; User Management ability config, controller, middleware, TypeScript capability type, user details screen, and package guide.
- Documentation: `README.md`, this completion report, and the approved-decision/status update in `phase6-roles-permissions-proposal.md`.

## Manual acceptance and limitations

- Review role and permission screens in an authenticated browser and test the host-specific layout and authorization.
- Define host Gate/Policy behavior and configure the host's User Management safety policy before enabling destructive lifecycle actions.
- Review the target database and backup, then apply the package-owned migration through the host deployment process.
- Run `php artisan master-core:permissions:sync` to preview registered catalog rows; apply only after review. Create and assign ordinary roles through the UI.
- Provision the first Super Admin with the interactive `php artisan master-core:roles:provision-super-admin {user}` command and verify the target and structured log.
- Review Compatibility Mode against each host Gate/Policy before deployment.
- Before Strict Mode, document fallback review and provision all required role grants.
- MySQL integration and browser acceptance require a host deployment environment and will be reported separately from isolated automated tests.

Phase 7 has not started.

## Final code review fixes

The follow-up review findings were addressed within the Roles & Permissions package and its Phase 5 safety-policy integration:

- **Active-account assignment race:** HTTP role assignment and console provisioning now re-fetch and lock the host user inside the write transaction, then check active/non-trashed status before inserting links. The User Management safety-policy bridge takes the same user-first lock order before checking administrator status, coordinating lifecycle changes with a new assignment. Role-grant scope checks remain inside the assignment transaction. The interactive command still confirms an existing user's ID and delegates the final write to the transactional provisioner.
- **Frontend capabilities:** the Roles and Permissions pages query the current Laravel Gate decisions and hide mutation controls the user cannot use. The API's `auth:sanctum` and per-route Gate checks remain authoritative; page tests cover capability visibility and rendered 403 failures.
- **Security-sensitive tests:** added coverage for successful and non-interactive provisioning, status changes between preflight and assignment, stale HTTP account snapshots, last active administrator lifecycle protection, and report-only/confirmed orphan cleanup.

Files changed for the follow-up:

- `packages/core/roles-permissions/src/Http/Controllers/UserRoleAssignmentsController.php`
- `packages/core/roles-permissions/src/Actions/UserRoleAssignmentManager.php`
- `packages/core/roles-permissions/src/Actions/SuperAdminProvisioner.php` (new)
- `packages/core/roles-permissions/src/Console/ProvisionSuperAdminCommand.php`
- `packages/core/roles-permissions/src/Support/RolesPermissionsUserSafetyPolicy.php`
- `packages/core/roles-permissions/resources/js/pages/RolesPage.vue`
- `packages/core/roles-permissions/resources/js/pages/PermissionsPage.vue`
- `packages/core/roles-permissions/resources/js/pages/RolesPermissionsPages.test.ts` (new)
- `packages/core/roles-permissions/tests/Feature/RolesPermissionsTest.php`
- This completion report.

### Concurrency limits and remaining acceptance

The new status-change tests create the interleaving deterministically by changing the account after its initial read and before the transactional re-read. SQLite `:memory:` verifies the re-fetch and fail-closed checks, but SQLite does not provide the row-level `FOR UPDATE` behavior relied on for concurrent writers. Parallel transaction/last-admin contention was therefore not claimed as tested. The production database must support transactional row locking (for the documented MySQL deployment, use InnoDB), and host acceptance must exercise concurrent deactivation/provisioning and competing last-admin changes against an isolated MySQL database before rollout. Direct host database writes that bypass the User Management lifecycle/safety policy cannot be serialized by this package.

No migration was run against the configured application database. Browser acceptance, MySQL concurrency validation, host Gate/Policy fallback review, initial role/grant provisioning, and host-specific deployment/backup review remain required before enabling the package in production.

## Core Completion Campaign follow-up (2026-11-17)

The complete isolated RBAC browser workflow passed: registered ability materialization, role creation/grant, viewer assignment, authorized access, permission and assignment revocation, and resulting 403. Campaign coverage found that HTTP user-role synchronization rejected an empty `role_ids` list, which prevented revoking all roles. The request now accepts an empty list, with feature regression coverage. Full results and the live-host boundary are in the [campaign report](core-completion-campaign-2026-11-17.md).
