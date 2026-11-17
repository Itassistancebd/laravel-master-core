# Phase 10 — Notifications & Notification Center Completion

**Status:** Implemented and locally verified; production deployment acceptance remains pending.

## Summary

Phase 10 adds the separately maintained `packages/core/notifications/` Composer and npm package. It provides one persistent, recipient-scoped in-app inbox, an authenticated API, a Vue Notification Center, and an unread badge on the existing authenticated `/app` surface. The package does not replace Authentication email notifications, Audit Logs, operational logging, or external delivery.

## Database and event behavior

The package owns one registered migration for `master_core_notifications`. It uses a ULID primary key, polymorphic recipient type and string identifier, a closed event key, allowlisted JSON display data, nullable read timestamp, creation timestamp, and indexes for recipient timeline and unread queries. The table has no foreign key to a host user model and no edit/delete API. The migration refuses to claim an existing table; rollback refuses while records remain.

The sole v1 event is `account.roles.changed`, produced for approved Phase 6 user-role assignment and Super Admin provisioning events after their source transaction commits. Its message is generic and contains no role names or actor details. The Phase 6 event ULID is reused as the inbox ULID for replay idempotency. Notification insertion is best effort after commit and emits a sanitized operational warning on failure; it cannot roll back the already committed role change. A process failure between source commit and inbox insert remains a delivery-loss window because no transactional outbox or queue is part of v1.

## Access and user interface

All API routes require Sanctum authentication and the existing active-user middleware. Every read or state change includes the current recipient's morph type and key in its query; foreign notification IDs return 404. No Phase 6 permission is required, registered, materialized, or granted because the approved v1 inbox is self-service only. The API does not create notices or accept a recipient ID or arbitrary display payload.

The UI supports server-side status filtering, pagination, unread count, mark read/unread, and bounded mark-all operations. A read-all call processes up to the configured `mark_all_batch_size` (default 500) and reports `updated` and `remaining`; one user action makes at most 25 bounded calls and tells the user when items remain. There are no create, edit, delete, dismiss, export, or cross-user screens.

## Retention and orphan handling

Retention is configurable and disabled by default. Purge requires explicit host approval and enablement, deletes only old read rows, and is operator-invoked in a bounded batch; no scheduler or automatic purge was installed. The orphan command reports unresolved recipients for the configured host user model and is read-only. Hosts must set and document a retention period before production and review database access privileges and backup retention.

## Files changed

- Added the package under `packages/core/notifications/`: Composer/npm metadata, provider, configuration, owned migration, model, catalog, storage contract/action, Phase 6 listener, authenticated API, validation/resource classes, operational commands, Vue types/API/routes/page, and backend/frontend tests.
- Integrated the package into root Composer and npm workspaces, PHPUnit autoload/suite configuration, Auth SPA routing and `/app` badge, and the Phase 8 Module Manager code-owned catalog.
- Added the Phase 10 package guide and updated the proposal status and implementation record.
- Updated root README and architecture overview/database documentation with package links and ownership details.

Phase 1–9 package functionality and Laravel stock migrations/User model were preserved. No Git commit or push was made.

## Verification results

All automated database-backed PHP tests use the repository PHPUnit SQLite `:memory:` configuration.

| Check | Result |
|---|---|
| Full backend suite (`php artisan test --no-ansi`) | **Passed: 129 tests, 498 assertions.** |
| Notifications focused suite | **Passed: 15 tests, 109 assertions.** |
| Frontend suite (`npm run test:ui`) | **Passed: 13 files, 45 tests.** |
| TypeScript (`npm run typecheck`) | **Passed.** |
| Vite production build | **Passed: 163 modules transformed**, output written to an isolated `storage/framework/testing/phase10-notifications-final-build-20261115` directory. |
| Pint on `packages/core/notifications/` | **Passed.** |
| Repository-wide Pint | **Not clean due to an existing unrelated `ordered_imports` finding** in `packages/core/settings/tests/Feature/SettingsModuleTest.php`; that out-of-scope file was not modified. |
| Root Composer validation | **Passed.** |
| Notifications package Composer validation | **Passed** (Composer emitted its normal root-version fallback notice for a path package). |
| Laravel package discovery | **Passed; Notifications provider discovered.** |
| API route verification | **Passed: all five routes registered** with Sanctum and active-user middleware; state-changing routes are throttled. |
| Bounded mark-all regression | **Passed:** batch size 1 updates one owned row per request, reports remaining count, and leaves another user's row unread. |

The default sandbox blocked Vitest/esbuild process creation with `spawn EPERM`; the approved test run was repeated with process spawning enabled and passed. Composer could not reach Packagist for advisory metadata, so local package validation and the locked local dependency install completed without refreshing remote advisory data.

## Deployment requirements and limits

- The package migration has been registered but **was not executed against any configured application database**. Back up and review the target database, then schedule the migration through the host's approved deployment process.
- Validate migration/index/JSON behavior against supported MySQL versions on an isolated database. SQLite tests do not verify MySQL locking, query plans, or operational backup/restore behavior.
- Perform browser acceptance for responsive layout, keyboard navigation, CSRF/session behavior, login redirection, count refresh, and large-inbox partial mark-all feedback.
- Verify a host using a custom authenticatable model, morph map, and non-integer key. The implementation uses the configured default-guard Eloquent model and stores recipient keys as strings; a representative custom host model was not part of this run.
- Configure retention only after the host approves the period; confirm operational logging and least-privilege database access.
- Best-effort after-commit delivery has a crash window. Guaranteed delivery requires a separately approved outbox/retry design.

## Phase boundary

Phase 10 is implemented and locally verified. No Phase 11 work has started.
