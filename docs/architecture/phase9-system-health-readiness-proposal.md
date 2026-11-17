# Phase 9 Proposal: Core System Health & Readiness

> **Status: IMPLEMENTED AND AUTOMATED CHECKS VERIFIED WITH CONDITIONS.** Phase 9 implementation was approved with conditions and is recorded in [the completion report](phase9-system-health-readiness-completion.md). Isolated MySQL and authenticated browser acceptance remain deployment requirements.

## 1. Recommendation

Recommend **Core System Health & Readiness** as Phase 9. Its purpose is to give authorized host operators a narrowly scoped view of whether the running application can reach its configured primary database, while preserving the public, database-independent Foundation health contract.

This recommendation addresses a gap explicitly left by Phase 8: Module Registry & Manager reports package installation, direct dependencies, and provider registration, but does not verify runtime operation, migrations, database access, host configuration, or external services. The proposed module is a read-only operational diagnostic. It is not a monitoring platform, general dashboard, module lifecycle manager, or infrastructure control plane.

## 2. Inspection findings (observed facts)

### Repository and package boundaries

- The repository is a Laravel 12 host plus independently maintained Composer packages under `packages/core/`. Package providers are auto-discovered; packages own their routes, configuration, and migrations when needed.
- The root npm workspace contains Vue 3, TypeScript, Vue Router, Vite, Vitest, and `@master-core/ui`. The Auth SPA is composed explicitly in `resources/js/auth.ts`.
- Foundation exposes `GET /api/v1/core/health` from its own package. Its contract returns configured display name/version and a constant status; it does no database or system inspection. Phase 1 and Phase 2 document that Foundation stays database-independent and owns no Core tables.
- Authentication provides the existing Sanctum session-cookie model and the Vue application shell. Protected package APIs use `auth:sanctum`; Core role abilities are enforced through Laravel Gates.
- Phase 6 is the authority for executable abilities: permissions must be in its code registry before they can be granted. Compatibility Mode is the default; Strict Mode is opt-in. Permission grants are not automatic.
- Phase 7 Audit Logs records explicitly catalogued mutations and is append-only. Read-only inventory requests are not automatically audited; export is not implemented.
- Phase 8 Module Registry & Manager is read-only and has no persistence. It explicitly returns `runtime_not_verified` when package/dependency/provider checks pass, and does not run migrations or inspect database connectivity.
- Phase 8's immutable allowlist currently includes the seven backend Core Composer packages, including Module Manager. Core UI is an npm workspace and is not a Composer runtime package.
- Existing package conventions establish Foundation API prefix/version configuration, server authorization, response allowlists, Core UI reuse, package-owned tests, and isolated SQLite testing. Phase 7 and Phase 8 reports retain separate MySQL and browser acceptance requirements.

### Current health and operational status

- Foundation's public health endpoint is intentionally minimal, public, and database-independent. It is not a readiness check.
- The Module Manager reports package metadata and provider state, not operational readiness.
- No inspected Phase 1–8 package provides an authenticated system-health/readiness API or operator screen.
- Settings, Roles & Permissions, and Audit Logs own their respective tables. User Management owns additive lifecycle fields on the configured host user table. Authentication preserves the host user and Laravel stock tables. These ownership boundaries are approved and are not candidates for a Phase 9 shared table.

## 3. Findings versus recommendations

| Observed fact | Recommendation |
|---|---|
| Foundation's health API is intentionally independent of the database. | Leave its route, payload, accessibility, and behavior unchanged. Add a separate privileged readiness API. |
| Phase 8 correctly reports runtime as unverified. | Add only explicit, bounded readiness checks; do not reinterpret package/provider presence as health. |
| Phase 6 requires abilities to be registered in code before execution. | Add `view-system-health` through the Phase 6 permission registry as an approved integration point; do not grant it automatically. |
| The Auth SPA composes module routes explicitly. | Add the read-only screen to the existing shell and permission-aware route composition; do not create another router or login flow. |
| Core package migrations are owned by their package and applied by host deployment. | Phase 9 v1 needs no new table or migration. The database probe must be read-only and must not run migrations or inspect/mutate migration state. |
| Phase 7 does not automatically audit reads. | Do not write audit events merely because an authorized operator views health results. Any future state-changing diagnostic action requires a separate proposal and audit design. |

## 4. Purpose, scope, and boundaries

### Proposed v1 scope

- Add `packages/core/system-health/` as a separately maintainable Laravel 12 Composer package and `@master-core/system-health` npm workspace.
- Provide an authenticated API and an optional read-only page in the existing Auth SPA.
- Use a code-defined, allowlisted check catalog. Proposed initial checks:
  1. **Request/runtime:** the response is only available when Laravel has booted and authorized routing has succeeded; identify this as request availability, not complete application health.
  2. **Primary database connectivity:** use the configured connection and a minimal read-only `SELECT 1`. Report only a safe status and check time. Do not expose connection names, SQL errors, hostnames, DSNs, driver versions, or exception messages.
- Return per-check statuses `healthy`, `unavailable`, or `not_configured`, plus outcomes `successful`, `failed`, or `not_configured`. A failed database probe is represented as `unavailable` / `failed`; missing default connection configuration is `not_configured` and is not probed. The overall summary never claims more than the executed checks establish.
- Provide operator-facing explanations that distinguish checked, not configured, and not verified conditions.
- Use server-side authorization and optionally a conservative rate limit to bound repeated diagnostic queries.
- Add package, host integration, security, and deployment documentation.

### Excluded from v1

- Changing or expanding the public Foundation health endpoint.
- Database migrations, Core tables, persistence, cache-backed results, or historical metrics.
- Running Artisan commands, applying/rolling back migrations, creating seeders, repairing schemas, or changing application state.
- Probes that send email, enqueue jobs, write files, alter cache keys, rotate credentials, or contact arbitrary URLs.
- Operating-system, filesystem-path, environment, dependency-version, PHP configuration, database-server-version, or secret disclosure.
- Module enable/disable/install/update lifecycle operations, arbitrary plugin execution, module self-registration, or scanning arbitrary Composer packages.
- Alerts, notifications, dashboards, exports, generic CRUD, user/role administration, or infrastructure monitoring integrations.

## 5. Package boundaries and dependencies

### Proposed structure

```text
packages/core/system-health/
├── composer.json
├── package.json
├── README.md
├── config/system-health.php
├── routes/api.php
├── src/
│   ├── Contracts/HealthCheck.php
│   ├── Data/HealthCheckResult.php
│   ├── Providers/SystemHealthServiceProvider.php
│   ├── Checks/DatabaseConnectivityCheck.php
│   ├── Support/HealthCheckRegistry.php
│   └── Http/Controllers/SystemHealthController.php
├── resources/js/
│   ├── api.ts
│   ├── routes.ts
│   ├── types.ts
│   └── pages/SystemHealthPage.vue
└── tests/
```

This is an illustrative package boundary, not authorization to create these files. Exact class names may follow the repository's existing package conventions after approval.

### Dependency proposal

- Backend: Laravel 12 and Core Foundation for API prefix/version conventions. Use Laravel's configured database manager for the read-only connectivity check.
- Authorization: Core Roles & Permissions runtime Gate integration; no dependency on role internals beyond the registered ability contract.
- Frontend: Vue 3, TypeScript, existing Core Auth router/shell, and `@master-core/ui` where its controls fit. The page is status-oriented and read-only; it does not require `CoreDataTable` unless multiple checks make a table more usable.
- No direct dependency on Settings, User Management, Audit Logs, or business packages. Do not add those packages as required health-check providers in v1.
- Integration points requiring approval: root Composer/npm workspace manifests, `resources/js/auth.ts`, Phase 6's code-defined ability registry, and Phase 8's immutable module catalog. Keep all feature implementation in the new package except these narrowly scoped registration points and documentation.

## 6. Database impact and ownership

- **Proposed schema:** none. No migration, model, seeder, table, or persistent cache is required.
- The connectivity check uses a read-only query against the host's configured primary database connection only when an authorized request asks for diagnostics. It must not inspect or reveal user data.
- Never use Phase 9 to run migrations or infer that the schema is current. Whether package migrations are installed remains a host deployment responsibility and is not safely established by a generic probe.
- Preserve Laravel stock migrations/models, `App\\Models\\User`, the Phase 2 zero-table Foundation decision, and each existing package's database ownership.
- Automated tests must use fakes or isolated SQLite `:memory:`. Tests must not connect to the configured application database.

## 7. API design

Use Foundation's configured API prefix/version. Proposed endpoints:

| Method | Path (default) | Access | Purpose |
|---|---|---|---|
| GET | `/api/v1/system-health` | `auth:sanctum` + `can:view-system-health` | Execute the approved bounded checks and return their allowlisted results. |

No public endpoint and no write endpoint are proposed. Validate any query options against a fixed allowlist; the preferred v1 contract takes no user-controlled check names or connection names. The API must:

- Return a stable envelope with a generated check timestamp, overall summary, and per-check stable key/status/outcome/short display message. A probe failure is a successful diagnostic response carrying an unavailable check result; it does not return infrastructure exceptions to the caller.
- Return generic safe failure messages. Do not serialize exception messages, traces, SQL, configuration, provider class names, or arbitrary diagnostic metadata.
- Return JSON 401/403 behavior consistent with the existing Auth APIs.
- Avoid exposing host-specific internal paths or secrets in successful and failed responses.
- Keep database-specific latency and connectivity details out of the public response; any operational log entry should use a check key and exception class only.

Potential endpoint name/path and whether checks are synchronous are approval decisions. Recommendation: one synchronous GET endpoint, since the v1 catalog is deliberately small and read-only; do not introduce a queue or pollable job state.

## 8. Frontend requirements

- Add `/app/system-health` under the existing authenticated Vue shell.
- Hide navigation when `view-system-health` is absent; the API remains the authority and must return 403 when denied.
- Reuse existing typography, spacing, responsive layout, loading/error/empty states, accessible status labels, and alert patterns. The page is read-only and must not show edit/delete/bulk controls.
- Explain each result's scope and show `not verified` for any capability not checked. Do not present the overall result as a universal application-health guarantee.
- Handle guest, 403, request failure, successful partial/unavailable checks, and narrow/mobile layouts.
- Do not add generic dashboards, charts, refresh loops, polling, or shared UI components without a demonstrated reusable need.

## 9. Permission requirements

- Register `view-system-health` in the Phase 6 code permission registry before using it in middleware. No automatic materialization or role grant.
- Do not add a `manage-system-health` ability in v1 because no mutation is proposed.
- Compatibility Mode remains the default. Strict Mode remains opt-in under existing Phase 6 host review and grant-provisioning rules.
- The UI's permission check is navigation guidance only; API middleware and Laravel authorization remain mandatory.
- Do not grant access to `core-super-admin` automatically or change Super Admin provisioning safeguards.

## 10. Security and privacy requirements

- Require Sanctum authentication and `view-system-health` for every result. Apply the existing active-account and account-status enforcement.
- Execute a fixed code-defined check catalog. Never accept arbitrary SQL, URL, command, filesystem path, package, provider, database connection, or class name from a request.
- Restrict the database probe to a constant read-only query on the configured primary connection; use short, bounded execution behavior where the Laravel/database driver permits it. Do not use write probes.
- Return only allowlisted check identifiers, statuses, timestamps, and safe operator messages. Do not return `.env` values, credentials, SQL/exception text, server paths, database metadata, user data, or raw configuration.
- Log failures only with a stable check identifier and exception class. Do not include exception messages or request credentials.
- Limit the endpoint to 10 requests per minute. Document that the probe can create a connection and query load.
- Use trusted-proxy handling only if future checks require request IP; v1 need not collect IP or User-Agent.
- No audit record is needed for ordinary read-only diagnostics under the current Phase 7 scope. New state-changing operations must be separately approved and audited.

## 11. Testing strategy

### Backend

- Check registry allowlisting and stable result contracts.
- Authenticated success; guest 401; unauthorized 403; active/inactive account behavior.
- Successful database probe and generic failure handling with injected fake/check adapter; verify no sensitive error text leaks to responses or logs.
- Unknown or user-supplied check/connection parameters are rejected or ignored according to the final request contract.
- Rate-limit behavior if adopted; configurable API prefix; route cache/package discovery; response envelope and status semantics.
- Assert no migration/table/model registration and no writes during diagnostic requests.
- Foundation health regression: remains public, database-independent, and byte-contract compatible with its approved payload.
- Phase 3–8 regression: Settings, Auth, User Management, RBAC, Audit Logs, and Module Manager behavior remains unchanged; permission registration is code-owned and not automatically granted.
- SQLite `:memory:` and fakes only. Do not point tests at the configured host database.

### Frontend

- Route composition and permission visibility.
- Loading, success, partial/unavailable, empty/not-configured, network error, 401, and 403 states.
- Ensure no mutation or infrastructure-control actions appear.
- Responsive and accessible status labels; use existing shared styles/components.
- Run TypeScript checks, UI tests, and a production build after approval.

### Host acceptance

- Verify with an isolated MySQL 8 test database using the host's least-privilege application account; confirm the read-only probe works and failure output is safe. Do not use a configured user-data database for tests.
- Browser-check the authenticated page and denied-access flow in a host configured for Sanctum and Core RBAC.
- Review query timeouts, DB connection limits, rate limits, response caching policy (recommended: no cache in v1), and deployment logging.

## 12. Upgrade and compatibility considerations

- Target Laravel 12 APIs already used by the repository. Recheck Laravel database connection and route registration APIs against the actual locked framework version during implementation.
- Keep package Composer identity in the current local `example/` convention until the repository selects a published vendor namespace.
- Use Foundation's configurable API prefix/version rather than hard-coding `/api/v1` in implementation.
- Add the new package through Composer path repository/requirements and npm workspaces following existing patterns. Verify `composer.lock`, `package-lock.json`, package discovery, route cache, and Vite aliases as an implementation-stage task.
- Register the package in Module Manager with an immutable descriptor and declared dependency on Foundation. The inventory must still call readiness facts by their exact meaning and must not claim operational health merely because System Health is installed.
- Do not change Foundation's response contract, Phase 6 compatibility behavior, Phase 7 append-only behavior, existing package migrations, or host model/migrations.

## 13. Risks and unresolved decisions

| Decision or risk | Recommendation | Why approval is needed |
|---|---|---|
| Phase 9 name | Core System Health & Readiness | Confirms a focused operator capability and avoids implying package lifecycle control. |
| Initial probe scope | Request availability plus primary database connectivity only | Broader cache, mail, queue, storage, and network probes can have side effects or reveal host internals. |
| Access | Sanctum + `view-system-health`; no public diagnostics route | Readiness failures and infrastructure presence are operationally sensitive. |
| UI | Read-only Auth-shell page | Reuses the current application experience without adding a dashboard or control plane. |
| Database ownership | No new schema/migration | The proposed checks are stateless; persistent history is not required for v1. |
| Timeout / failure semantics | Generic per-check result; probe only where bounded behavior is available | Connection timeout behavior differs by PDO driver and host configuration; SQLite tests cannot prove MySQL network timeouts. |
| Phase 8 catalog registration | Add a descriptor for the package, preserving factual readiness labels | Phase 8 currently uses an immutable allowlist; it must be updated deliberately and tested. |
| Permission registration | Add only `view-system-health` to Phase 6's code registry | Changing the registry is a narrow cross-package integration point and must preserve no-auto-grant behavior. |
| Diagnostics during a total DB outage | Keep diagnostics protected and document that auth/RBAC may depend on the same database | A privileged endpoint cannot diagnose the database if its own session or authorization store is unavailable. |
| Browser/MySQL acceptance | Required before production acceptance | Neither current completion evidence nor SQLite tests establish these host-level behaviors. |

No direct conflict with approved Phase 1–8 decisions is identified. The main compatibility constraint is that Phase 9 must complement, not change, Foundation's public health contract and Phase 8's explicit `runtime_not_verified` semantics. The Phase 6 permission registry and Phase 8 module catalog are intentional integration points, not permission to refactor those packages broadly.

## 14. Implementation sequence

1. Add the package manifests, provider, configuration, code-defined check catalog, and route registration without changing Foundation.
2. Implement the authenticated endpoint and safe response/logging contract; register `view-system-health` without materializing or granting it.
3. Add backend tests for auth, permission, check outcomes, no disclosure, and no-write behavior.
4. Integrate the read-only page into Auth SPA and shared UI conventions; add page tests.
5. Register the package in Composer/npm integration and the Phase 8 allowlist; verify discovery and route composition.
6. Update package and deployment documentation; run backend/frontend checks. Record isolated MySQL and browser acceptance as outstanding until performed.
7. No application migration was run; v1 adds no migration.

## 15. Acceptance criteria

- The Foundation health endpoint remains unchanged and database-independent.
- The new API is authenticated, permission-protected, read-only, and returns only allowlisted, non-sensitive check outcomes.
- The v1 check catalog is fixed and bounded; no arbitrary command, query, URL, or host setting can be executed through HTTP.
- No Core table, migration, model, seeder, or persistent cache is added.
- Permission is code-registered and never auto-granted; Compatibility Mode remains default and Strict Mode behavior is unchanged.
- UI navigation and API authorization agree; backend checks remain authoritative.
- Existing package boundaries and Phase 1–8 behavior pass regression tests.
- SQLite tests do not connect to the configured application database; MySQL and browser acceptance are reported separately.
- Documentation states the exact checks, response limitations, deployment requirements, and facts that remain unverified.

## 16. Approved implementation decisions

The user approved Phase 9 implementation with conditions, directing implementation to follow this proposal. The approved selections and implementation details are:

- [x] **Name:** Core System Health & Readiness.
- [x] **Package:** `packages/core/system-health/` plus `@master-core/system-health` workspace.
- [x] **Probe scope:** request availability and primary configured database connectivity via a fixed read-only `SELECT 1`; no mail, cache, queue, storage, arbitrary network, or migration checks.
- [x] **Access:** one authenticated `GET /api/v1/system-health` endpoint protected by `view-system-health`; no public diagnostics endpoint.
- [x] **Permission:** add `view-system-health` to Phase 6's code registry; no automatic materialization or grant, and no mutation ability.
- [x] **UI:** a read-only `/app/system-health` page in the existing Auth SPA with scoped result labels and 401/403/error states.
- [x] **Persistence:** no new tables, migrations, models, seeders, or cache in v1.
- [x] **Phase 8 integration:** add a fixed Module Manager descriptor without changing lifecycle behavior or claiming operational health.
- [x] **Operational behavior:** a synchronous probe, 10-per-minute API rate limit, no result cache, sanitized logs, and safe generic failure responses. Isolated MySQL and browser acceptance remain required before production.

## 17. Implementation approval and outcome

The user approved Phase 9 implementation with conditions. The implementation follows the approved package, probe, endpoint, permission, UI, and no-persistence scope. Actual implementation details and verification evidence are recorded in [the Phase 9 completion report](phase9-system-health-readiness-completion.md). The package returns only the two approved checks and does not claim full infrastructure readiness. MySQL and browser deployment acceptance remain pending.

## References

- [Architecture overview](overview.md)
- [Database architecture principles](database.md)
- [Phase 2 completion](phase2-completion.md)
- [Phase 3 Settings proposal](phase3-settings-proposal.md) and [completion](phase3-settings-completion.md)
- [Phase 4 Authentication proposal](phase4-auth-security-proposal.md) and [completion](phase4-auth-security-completion.md)
- [Phase 5 User Management proposal](phase5-user-management-proposal.md) and [completion](phase5-user-management-completion.md)
- [Phase 6 Roles & Permissions proposal](phase6-roles-permissions-proposal.md) and [completion](phase6-roles-permissions-completion.md)
- [Phase 7 Audit Logs proposal](phase7-audit-logs-proposal.md) and [completion](phase7-audit-logs-completion.md)
- [Phase 8 Module Manager proposal](phase8-module-manager-proposal.md) and [completion](phase8-module-manager-completion.md)
- [Development standards](../standards/development.md)
- [Global UI and CRUD standards](../standards/global-ui-crud.md)
