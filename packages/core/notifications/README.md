# Master Core Notifications

The Notifications package provides a private, recipient-scoped in-app inbox for the host's configured default-guard Eloquent user. It uses one package-owned `master_core_notifications` table, a closed event catalog, authenticated APIs, and a Vue Notification Center. It does not replace Laravel's password-reset or email-verification notifications, Phase 7 audit records, or operational logs.

## Package and installation

- Composer package: `example/laravel-master-core-notifications`.
- Frontend workspace: `@itassistance-bd/notifications`.
- Requires Laravel 12, Core Foundation's API prefix/version contract, and the Roles & Permissions event contract.
- Composer package discovery registers `NotificationsServiceProvider`; the package registers but does not execute its migration.
- The root Auth SPA composes `/app/notifications` and supplies the configured API base. The unread count badge appears on the existing `/app` account surface and the Notification Center.
- Review backups and target database compatibility, then run package migrations only through the host's separately approved deployment process. No application database migration was run as part of Phase 10 implementation.

## Authorization and privacy

All API endpoints require `auth:sanctum`. Each query derives the recipient type and ID from the authenticated Eloquent model and includes both in the SQL predicate. Another user's notification ID returns 404. The route has no `can:` permission because v1 is a self-service inbox; no notification permission is registered, materialized, or granted. Phase 6 behavior and permission compatibility remain unchanged.

There is no HTTP creation endpoint, cross-user listing, deletion/dismissal, export, or arbitrary payload input. Only `account.roles.changed` is registered in v1. It stores the fixed generic message “Your account access was updated.” It does not contain role names, actor details, user data, request bodies, credentials, tokens, or URLs. Repeated delivery of the same role event is idempotent through its event ULID.

The recipient ID is polymorphic and has no host-user foreign key. New notices are created only after resolving an existing, active configured user. `php artisan master-core:notifications:report-orphans` reports missing/unresolvable recipients and never changes data.

## API

The configured Foundation prefix/version defaults to `/api/v1`.

| Method and endpoint | Behavior |
|---|---|
| `GET /api/v1/notifications?status=all|unread|read&per_page=10|25|50|100&page=N` | List the current user's notices, newest first. |
| `GET /api/v1/notifications/unread-count` | Count only the current user's unread notices. |
| `PATCH /api/v1/notifications/{ulid}/read` | Mark one owned notice read. |
| `PATCH /api/v1/notifications/{ulid}/unread` | Mark one owned notice unread. |
| `POST /api/v1/notifications/read-all` | Mark up to the configured batch size of current-user unread notices read; returns `updated` and `remaining`. |

The Center repeats bounded read-all requests up to 25 times per user action. If more notices remain, it reports the remaining count and the user can continue with another action. Each batch is scoped to the authenticated recipient.

Responses use the standard JSON envelope and `Cache-Control: no-store, private`. Message HTML, arbitrary sorting/search, and user-selected recipients are not accepted.

## Role-change event integration

The package listens to the Phase 6 `RolesPermissionsChanged` event only for `user-roles-synced` and `super-admin-provisioned`, which the source package dispatches after commit. It resolves the subject against the configured default-guard host user model and rejects missing, inactive, or trashed recipients. Role and permission catalog changes do not generate notices.

Inbox writes are synchronous and best effort after the business transaction commits. Failures are caught and logged with event ID, registered key, and exception class only. They do not roll back role changes; a process failure between source commit and insert can lose a notice. No queue, outbox, email provider, or external channel is used.

## Retention and operations

Retention is disabled by default. Set these only after the host approves a retention period:

```dotenv
MASTER_CORE_NOTIFICATIONS_RETENTION_DAYS=90
MASTER_CORE_NOTIFICATIONS_RETENTION_APPROVED=true
MASTER_CORE_NOTIFICATIONS_PURGE_ENABLED=true
```

The package schedules no cleanup. An operator may explicitly run `php artisan master-core:notifications:purge-read --limit=1000` after checking the configured policy. The command deletes only read rows older than the configured age, at most the bounded batch limit per invocation. Unread notices and orphan rows are not targeted. A disabled or unapproved policy causes the command to refuse. Use a least-privilege application database account and separately reviewed deployment access.

## Upgrade and verification

The package uses a ULID primary key, bounded polymorphic recipient identifiers, allowlisted event keys, JSON display data, UTC timestamps, and indexes for recipient timeline and unread queries. SQLite `:memory:` tests cover package behavior; supported MySQL versions still require an isolated deployment migration/query check, particularly for composite index lengths and JSON behavior. Test with the host's configured user model/morph map and confirm the package migration is scheduled separately. Never use package discovery or tests as authorization to migrate the configured application database.
