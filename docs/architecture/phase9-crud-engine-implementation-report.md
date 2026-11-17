# Phase 9 — CRUD Engine Implementation Report

> **Current product note:** This report documents the earlier Categories host pilot. Categories has since been removed from the application; the generic CRUD Engine and its resource-registration APIs remain.

**Status:** Implemented, adopted by the host Categories resource, and locally browser accepted (Campaign Phase 8; repository Phase 9 duplicate label preserved).

## Implementation summary

Added `packages/core/crud-engine/` as an opt-in Composer package and npm workspace. The package provides a code-owned resource registry, a validated search/filter/sort/pagination helper, a standard Core JSON response helper, a Vue 3 composable, and a limited descriptor-driven list/form renderer using `@master-core/ui`.

Resources continue to own their routes, query scope, validation, serialization, policies/abilities, mutations, transactions, audit event selection, and delete lifecycle. The package registers no generic production routes, migrations, tables, models, seeders, or permissions. User Management and all existing resource APIs remain unchanged.

## Package structure and registration contract

- `CrudResource` requires a resource definition, an already scoped Eloquent query, and an explicit serializer.
- `CrudResourceDefinition` validates stable resource keys and simple field identifiers. It declares searchable fields, filter-name-to-column mappings, sortable fields, and a registered default sort.
- `CrudResourceRegistry` accepts only configured code classes and rejects invalid implementations, duplicate keys, and lookups for unknown resources.
- `CrudIndexParameters` accepts only search, page, page size, sort, direction, and declared filters. Page size is limited to 10/25/50/100; search length is capped; filter values must be scalar and bounded.
- `CrudQueryBuilder` applies search and equality filters only to registered columns, orders only by declared fields, adds a primary-key tie-breaker, and paginates the owner's scoped query.
- `CrudResponse` emits the Core `{ success, data, meta? }` response structure.
- `CrudEngineServiceProvider` merges/publishes package configuration and binds the registry. It loads no routes and runs no migrations.

The host config lists resource classes. Request input cannot choose a PHP class, model, table, relation, SQL expression, handler, ability, or writable field.

## Backend test fixture and API contract

The test-only account-style resource demonstrates explicit owner routes for list, detail, create, update, soft-delete, bounded bulk trash, restore, and confirmed permanent deletion. Its test controller owns validation and Gate checks while using shared query and response helpers. The host now also registers the Categories owner resource through `config/master-core-crud-engine.php`; its API and Vue page use the shared query, response, and registered-page contracts.

The fixture table `phase9_crud_fixture_accounts` is created through Laravel Schema Builder in test setup against PHPUnit's SQLite `:memory:` connection. No fixture route, business table, or migration exists in production. The fixture demonstrates allowlisted fields, unknown resource rejection, search, filtering, sort validation, page-size limits, server pagination, authorization failure, atomic bulk rollback, SoftDeletes lifecycle, and registered Phase 7 audit events.

Critical fixture create/update/delete operations dispatch existing catalog event names inside the same transaction as the fixture mutation. A mocked audit storage failure returns an error and leaves no inserted fixture record. A denied bulk row rolls back prior row changes and audit inserts. The test fixture uses a user-like schema only to exercise already registered User Management audit event definitions; it does not integrate with the host User model or Phase 5 routes.

## Frontend composable and renderer

- `useCrudResource` manages server list state, search/filter/sort/page requests, selection, form API errors, loading state, success/error notices, and optional owner-provided delete/bulk transport methods.
- `RegisteredCrudPage` renders a trusted local descriptor with the shared DataTable, fields, text input, searchable select/multiselect, buttons, toast, confirmation dialog, and optional owner-provided detail fetch.
- Supported field descriptors are text, email, number, boolean, select, and multiselect. The owner supplies the transport, fields, filters, columns, and capabilities.
- Capability visibility only affects the UI. The owning API remains responsible for authentication, Gate/Policy checks, validation, and scoped record access.
- The Categories route is mounted in the authenticated Admin SPA and uses the engine descriptor/transport. Action visibility comes from `getCurrentPermissions`; server Gate checks remain authoritative.

## Security, permissions, and audit

- No unrestricted model/table selection, SQL input, dynamic route, auto-discovered mass assignment, or arbitrary serializer is provided.
- Resource definitions come from host code configuration. Search/filter/sort identifiers are allowlisted and validated before query construction.
- The engine does not issue abilities or grants. Categories registers four host extension abilities (`categories.view/create/update/delete`) and enforces each independently in its owning API.
- The engine does not automatically generate audit events. Categories adds closed catalog names (`category.created/updated/deleted`) and synchronously records changes inside each mutation transaction.
- Permanent deletion is not enabled globally. It appears only as a fixture operation with its own Gate, trash-only lookup, explicit matching confirmation, and transactional audit event.

## Dependencies and Phase compatibility

The package is added to root Composer/npm workspaces, PHPUnit's Core CRUD Engine suite, and TypeScript workspace paths. It has no direct dependency on Foundation, Roles & Permissions, Audit Logs, Authentication, or a host user model; the provider itself registers no API routes. Vue imports existing `@master-core/ui` components.

No User Management implementation, Phase 3–10 package implementation, Laravel stock migration, `App\\Models\\User`, Auth SPA composition, existing permission registry, or existing endpoint was modified for CRUD behavior. Phase 10 Notifications remains unchanged. No Phase 11 work was started.

The requested Phase 9 label conflicts with the existing completed Phase 9 System Health and Phase 10 Notifications records. The implementation authorization instructed preserving both historical records; this report and the proposal document the duplicate label rather than renumbering prior work.

## Files created and modified

### New package files

- `packages/core/crud-engine/composer.json`, `package.json`, and `config/crud-engine.php`.
- Contracts, definition, registry, query validation/builder, response helper, and service provider under `src/`.
- Vue types, `useCrudResource`, `RegisteredCrudPage`, exports, and component/composable tests under `resources/js/`.
- Test-only fixture model/resource/controller/routes and `tests/Feature/CrudEngineTest.php`.
- `packages/core/crud-engine/README.md`.

### Integration and documentation changes

- Root `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `phpunit.xml`, and `tsconfig.json` register the package and suite.
- Updated `docs/architecture/phase9-crud-engine-proposal.md` with implementation status and approved decisions.
- Created this implementation report and updated root `README.md` with package and report links plus the duplicate phase-number note.

No application database or production schema file was changed. The only schema created is test-only in-memory fixture setup.

## Test and verification results

| Check | Result |
|---|---|
| CRUD Engine focused PHP suite | **Passed: 11 tests, 52 assertions.** |
| Full PHP suite (`php artisan test --no-ansi`) | **Passed: 140 tests, 550 assertions.** All tests use the configured SQLite `:memory:` test connection. |
| Frontend tests | **Passed: 15 files, 51 tests.** |
| TypeScript (`npm run typecheck`) | **Passed.** |
| Vite production build | **Passed: 163 modules transformed**, output isolated at `storage/framework/testing/crud-engine-final-build-20261115/`. |
| Pint scoped to CRUD Engine PHP | **Passed.** |
| Root Composer validation | **Passed.** |
| CRUD Engine package Composer validation | **Passed** (Composer printed the normal path-package root-version fallback notice). |
| Composer package discovery | **Passed; CRUD Engine provider discovered.** |
| Fixture production route check | **No CRUD fixture routes registered outside tests.** |

Vitest required process spawning beyond the default sandbox after an initial `spawn EPERM`; the approved rerun passed. Packagist was unreachable for remote advisory metadata during local Composer resolution. No remote dependency version upgrade was requested or performed.

## Limitations and manual acceptance

- No real resource package has adopted the engine. The isolated fixture proves the contracts and integrations without changing Phase 5 behavior.
- Browser acceptance was not performed. A host integration should verify responsive behavior, keyboard/accessibility flows, server validation display, state refresh, and the resource's own 401/403 navigation handling.
- No MySQL integration test was run. Check target MySQL query/index behavior using a host-owned resource and an isolated database before adoption.
- The generic query helper supports simple column search, equality filters, allowlisted sorting, and standard pagination. It does not implement relation filters, complex operators, aggregate workflows, uploads, or arbitrary server-driven schemas.
- Hosts remain responsible for configuring resource classes, explicit routes, middleware, resource policies, executable Phase 6 abilities, and Phase 7 audit catalog entries.
- Phase numbering remains ambiguous across the project history and needs a future roadmap decision; completed Phase 9/10 records were preserved as authorized.

## Database and Git confirmation

No configured application database migration or database mutation was performed. No production table or migration was added. The fixture schema existed only in the PHPUnit SQLite `:memory:` test process. No Git commit or push was made.

## Core Completion Campaign follow-up (2026-11-17)

The Categories pilot completes the host adoption. Campaign Phase 8 passes the backend/frontend regression suites, production build, full Playwright run, and requested three-viewport responsive browser QA. See [Phase 8 Categories pilot report](phase8-crud-engine-categories-pilot.md) for exact evidence and the no-live-migration boundary.
