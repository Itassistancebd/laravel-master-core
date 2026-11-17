# Architecture overview

The repository is both a Laravel 12 development host and a set of independently maintainable packages under `packages/`. Foundation remains database-independent; Settings and Authentication own their integration boundaries and do not add persistence to Foundation. The diagram below shows the Foundation package itself.

```text
Host Laravel application
└── Composer path/VCS dependency
    └── Core Foundation
        ├── FoundationServiceProvider
        ├── config/master-core.php
        ├── routes/api.php
        ├── database/migrations/
        └── Contracts + Support + HTTP controller
```

The provider merges defaults, binds the health contract, exposes config publishing, registers the package migration directory, and loads routes only when the route cache is not active and the named route is not already registered. The health route uses an invokable controller, so Laravel can cache routes. Its payload is limited to configured display name/version and a constant healthy status; it performs no database or system inspection.

The local Composer name `example/laravel-master-core-foundation` is intentionally a placeholder. Select an owned namespace before publishing. The package's semantic version is independent of each consuming application's version. Git tags can serve VCS installs; a private Composer repository can provide package metadata and tagged releases. This repository does not claim a public package release.

Phase 4 Authentication uses Laravel's host-owned User model, stock password-reset/session tables, and no Core-owned migration. Registration, User Management, Roles & Permissions, OAuth, MFA, passkeys, bearer tokens, and business modules remain separate future responsibilities. See the [Phase 4 completion report](phase4-auth-security-completion.md) for implementation and verification status.

## Phase 9 System Health & Readiness

System Health is a separately maintained package with an authenticated, permission-protected, read-only endpoint. It checks only that the authenticated diagnostic request was handled and whether the configured primary database answers a constant `SELECT 1`. It owns no table or migration and does not change Foundation's public, database-independent health response. Its result is scoped to those checks and does not establish complete application or infrastructure health. See the [Phase 9 proposal](phase9-system-health-readiness-proposal.md) and [completion report](phase9-system-health-readiness-completion.md).

## Phase 10 Notifications & Notification Center

Notifications is a separate package with one package-owned recipient inbox table, authenticated self-only API, and Vue Notification Center. Its event catalog is explicit and its initial role-change notice is dispatched after the Roles & Permissions transaction commits. Inbox storage is best effort and does not replace Phase 7 audit writes. The package adds no email/external delivery, preferences, or Foundation persistence. See the [Phase 10 proposal](phase10-notifications-proposal.md) and [completion report](phase10-notifications-completion.md).
