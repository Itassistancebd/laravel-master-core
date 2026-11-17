# Phase 9 — CRUD Engine Proposal

> **Status: IMPLEMENTED — automated checks passed; manual host acceptance remains.**
> **Roadmap conflict:** this request and its implementation authorization designate CRUD Engine as Phase 9, while the repository already records Phase 9 System Health & Readiness and Phase 10 Notifications as completed. The approved implementation explicitly preserves both historical records. This duplicate Phase 9 label remains a roadmap-documentation issue; no completed phase was renamed or rewritten. See the [implementation report](phase9-crud-engine-implementation-report.md).

## 1. Purpose and goals

Provide reusable, opt-in CRUD infrastructure for Core modules and consuming applications while keeping each resource's business rules and persistence under its existing owner. The engine should reduce repeated list-query, pagination, field-metadata, and Vue page wiring without turning arbitrary host models or database tables into an HTTP API.

The v1 objective is a **registered-resource engine**, not an unrestricted model administrator. A resource must be declared in code by its owning package, and each supported operation must use explicit query scope, request validation, authorization, and action handlers.

## 2. Inspection findings

### Repository and package facts

- **Observed:** the host is Laravel 12. PHP packages live under `packages/core/*`, use Composer path repositories and package-discovered providers, and own their migrations. Frontend packages are npm workspaces. The root Auth SPA explicitly composes package route arrays from `resources/js/auth.ts`.
- **Observed:** the shared UI is the npm-only `@master-core/ui` package. It already exports `CoreDataTable`, `CoreField`, text/color inputs, buttons, searchable single/multiple selects, confirmation dialogs, and toast feedback. The data table provides SL, row selection, local column visibility, sorting events, pagination/page sizes, and loading/error/empty states. It does not own server queries, resource forms, CRUD policies, bulk behavior, or persistence.
- **Observed:** User Management is the repository's richest CRUD implementation. Its package has explicit routes, a controller, Form Requests, `UserManagementQuery`, `UserLifecycleManager`, a host-model resolver, and Vue list/detail/form/trash pages. Its API implements search, allowlisted filters/sorts, server pagination, capabilities, bulk activate/deactivate/trash/restore, and individually guarded permanent deletion. Its safety policy protects account lifecycle behavior and its audit producer records explicit events.
- **Observed:** Roles & Permissions has separate role/permission managers, resource-specific request validation, permission checks, transactional assignment safeguards, and bespoke Vue pages. These operations have domain constraints and are not generic record CRUD.
- **Observed:** Settings has its own update/reset/branding validation and persistence; Audit Logs has an append-only, read-only resource; Module Manager and System Health are read-only; Notifications only changes recipient-owned read state. These packages are not candidates for uniform create/edit/delete behavior.
- **Observed:** API responses generally use `{ success, data, message? }`, with pagination in `meta`; Laravel Form Requests return standard validation errors. APIs derive authorization from Laravel Gates/Policies and package-specific safety checks. Frontend capability checks control visibility only and do not authorize requests.
- **Observed:** Phase 7 exposes the versioned `master-core.audit.record.v1` event and closed catalog. Critical producer mutations write synchronously inside their transaction; authentication logging is fail-open. Arbitrary CRUD-generated audit names or payload fields would be rejected and must not be synthesized without catalog registration.
- **Observed:** Phase 6's code-defined ability registry is the source of executable permissions. Database rows cannot create abilities, no package grants permissions automatically, Compatibility Mode preserves host behavior, and Strict Mode is opt-in.
- **Observed:** no CRUD metadata table, universal resource registry, generic CRUD controller, CRUD form renderer, or shared CRUD state composable exists. Existing package code is feature-specific.
- **Observed:** there are no standalone Phase 1 architecture/completion documents in `docs/architecture/`; Phase 2 has a completion document, Phases 3–9 have proposal/completion records, and Phase 10 has proposal/completion records.

### Current CRUD-like implementations and reuse

| Area | Existing behavior | Reuse and limitation |
|---|---|---|
| User Management | Host user CRUD/lifecycle API, scoped query, validated filters/sorting/pagination, Form Requests, safety policy, bulk transactions, audit events, Vue pages | Best example of backend patterns; must remain responsible for user account rules. Its authorization, reset/verification actions, last-admin safeguards, host model compatibility, and bulk lifecycle are not generic. |
| Roles & Permissions | Constrained role/permission CRUD and grant assignment | Reuse API envelope and UI conventions only. Reserved roles, code-registered permissions, and grant-scope checks remain domain-specific. |
| Core UI | Shared table/form/feedback primitives | Reuse directly. Do not duplicate controls in a CRUD package. `CoreDataTable` is presentation-only; resource pages own server query state and action semantics. |
| Audit Logs | Read-only listing/detail and closed audit event catalog | Use its event contract only through owning resource packages and registered event definitions. Audit Logs must remain append-only. |
| Settings, Module Manager, System Health, Notifications | Specialized or read-only APIs | Keep their behavior and UI models out of a generic write surface. |

### Gaps and duplication

- List query parsing, search/filter/sort allowlists, pagination envelopes, capability checks, API client setup/error handling, and page loading states are repeated across packages.
- Forms are resource-specific and use shared controls, but there is no typed common form-field contract or reusable create/edit state flow.
- `CoreDataTable` supplies common table presentation but has no resource-aware filters or action authorization. Those correctly remain in the owning page today.
- Delete, trash, restore, force-delete, bulk atomicity, and audit requirements differ by resource. A blanket lifecycle would be unsafe.

## 3. Recommendation

Create a combined Composer/npm package at `packages/core/crud-engine/` with a narrow backend registry/query layer and Vue 3 + TypeScript composables/rendering primitives. Use **code-owned resource registration and per-resource handlers**. Keep the engine reusable, while requiring an owning package to explicitly supply each resource's query scope, field allowlists, authorization policy/abilities, Form Requests, mutation actions, and audit event definitions.

Do not replace User Management's controller or lifecycle manager in v1. Preserve its existing endpoints and behavior. Add an opt-in adapter boundary that can be piloted in tests or a later explicitly selected low-risk resource. Migration of the current User Management implementation into the engine should be a separate acceptance decision because it has custom identity, session invalidation, safety policy, and role integration.

### Generic HTTP endpoint decision

Do not expose arbitrary model names, table names, columns, SQL, relationship paths, validation rules, or operations from the request. For v1, prefer **resource-owned explicit routes/controllers backed by shared engine contracts**. This keeps middleware, record scope, policies, and package-specific validation visible at the owning boundary. The engine must not infer a model or table from a URL or database row.

Each owning package keeps its route prefix and API resource shape (as User Management does today), while the engine supplies reusable query, pagination, definition, and frontend contracts. If a future implementation chooses a generic route adapter, it must resolve only fixed code-registered resource keys and declared handlers; it needs separate approval and route-cache/middleware tests. No universal `/resources/{model}` endpoint is recommended in v1.

## 4. Package boundaries

### Proposed package structure

```text
packages/core/crud-engine/
├── composer.json
├── package.json
├── config/crud-engine.php
├── src/
│   ├── Contracts/CrudResource.php
│   ├── Data/CrudResourceDefinition.php
│   ├── Registry/CrudResourceRegistry.php
│   ├── Query/CrudQueryBuilder.php
│   ├── Http/Controllers/RegisteredResourceController.php
│   ├── Http/Requests/ResourceIndexRequest.php
│   ├── Http/Resources/CrudResourceResource.php
│   └── Providers/CrudEngineServiceProvider.php
├── resources/js/
│   ├── useCrudResource.ts
│   ├── types.ts
│   └── components/RegisteredCrudPage.vue
└── tests/
```

Names are illustrative. The provider may register an owned route group and config but must not execute migrations. Resource-owning packages register typed definitions through code/provider integration. The engine may depend on Foundation for API prefix/version conventions and Core UI as an npm peer/workspace dependency. Any direct Roles & Permissions or Audit Logs integration must remain optional or use framework contracts/events so CRUD Engine does not force those packages on hosts that do not install them.

### Responsibilities

| Concern | Owner |
|---|---|
| Shared list query syntax, validated pagination, generic response shape, resource metadata contract, and Vue CRUD state/rendering | CRUD Engine |
| Resource model/query scope, field persistence mapping, validation rules, business invariants, lifecycle, and transactions | Owning module/package |
| Authorization policy and contextual checks | Laravel host/owning package, invoked for every request and every affected record |
| Database schema and migration | Owning module/package; CRUD Engine owns none |
| Audit catalog registration and critical transactional event selection | Phase 7 and owning package |
| Shared visual primitives | `@master-core/ui` |
| Executable permission catalog and Gate resolution | Phase 6 Roles & Permissions |

## 5. Resource definition contract

Each code-registered resource should declare:

- Immutable resource key and safe display metadata.
- Owning package/module identity and documentation reference.
- Explicit operations supported; no operation is enabled merely because an Eloquent method exists.
- Read/query scope returning a resource-owned builder or repository contract.
- Explicit searchable fields, filters and their allowed operators/value types, and sortable fields.
- List columns and safe output/resource serializer.
- Form field descriptors for supported simple UI types, separate create/edit field lists, and server-side Form Request class per write action.
- Per-operation policy/ability mapping and handler/action class.
- Optional selection/bulk operation registry, each with maximum batch size, transaction/failure semantics, and record-level authorization.
- Explicit deletion lifecycle: none, soft delete with trash/restore, or guarded permanent delete. No implicit `deleted_at` behavior.
- Optional audit event names/metadata mapping registered independently in the Phase 7 catalog; no generic dynamic event naming.

Definitions are PHP/TypeScript code, not executable metadata loaded from a database. Host config may disable/hide an already registered resource or override safe labels only; it must not add a model class, handler, ability, or field. Do not send PHP class names, SQL, arbitrary validation rules, or private attributes to the browser.

The v1 form renderer should support only well-defined primitives: text, email, number, boolean, enum/searchable select, and multiselect. Complex widgets, uploads, nested aggregates, conditional workflows, and domain-specific editors should use an owning-package page/slot rather than a string-evaluated schema language.

## 6. Backend architecture and API

### Listing and reads

- Resolve resource keys only from the immutable registry; unknown keys return 404.
- Run queries through the resource's scope, including tenant/ownership constraints supplied by that owner when applicable.
- Validate search length, page number, page size (`10`, `25`, `50`, `100`), known filter names/operators, and known sort fields/direction. Bound bulk IDs and query complexity.
- Return the established `{success, data, meta}` shape and only fields in the resource serializer/column allowlist.
- Keep record detail resolution within the same scope used for lists to prevent IDOR.

### Writes and validation

- Call a resource-specific Form Request and handler. Do not infer mass-assignable attributes from submitted keys or table columns.
- Validate relationships through resource-owned option/query endpoints and enforce authorization on the server at write time.
- Use a transaction when the resource operation and its required side effects must commit together. Do not impose a universal transaction around external email or other non-transactional effects; use after-commit handling where appropriate.
- Bulk actions require a declared operation, bounded unique IDs, full resolution within resource scope, authorization for every row, explicit atomic or partial-result semantics, and protection against partial success being reported as complete.
- Return clear validation, conflict, forbidden, and not-found outcomes without leaking foreign record existence or sensitive model data.

### API endpoint plan

The API convention is the resource owner's existing route family (for example, `/api/v1/users`), with shared request/query/response contracts. For a resource that explicitly opts in, the owner may register only the relevant subset of these familiar patterns:

| Method | Owner-defined path pattern | Operation |
|---|---|---|
| GET | `/{resource-prefix}` | Scoped, filtered, sorted, paginated list. |
| GET | `/{resource-prefix}/{id}` | Scoped detail. |
| POST | `/{resource-prefix}` | Create through the resource's Form Request and handler. |
| PUT/PATCH | `/{resource-prefix}/{id}` | Update allowlisted fields through the resource handler. |
| DELETE | `/{resource-prefix}/{id}` | The resource's declared delete/trash behavior only. |
| POST | `/{resource-prefix}/bulk/{registered-operation}` | Bounded, declared bulk action only. |
| POST | `/{resource-prefix}/{id}/restore` | Only for resources with an approved soft-delete lifecycle. |
| DELETE | `/{resource-prefix}/{id}/force` | Only for guarded permanent deletion. |

These are conventions for explicit owner routes, not routes generated from client-selected resource/model names. A resource may expose only the verbs it declares. Do not provide both dynamic and package-owned route styles for one resource in v1. A registered-key generic route adapter remains a future option for separate approval.

Authorization uses the resource's declared Laravel Gate/Policy checks and Phase 6 abilities. Abilities must be registered in the code-owned Phase 6 registry before use; no ability is automatically granted. UI `can()` metadata is navigation/visibility only. The endpoint and action handler re-authorize, including individual rows in bulk operations. Compatibility Mode/Strict Mode semantics and host Policies remain unchanged.

## 7. Audit Logs integration

The engine should not automatically emit audit events for every generic request. Each owning package declares which operations are audited, provides allowlisted metadata/snapshots, and registers event names with the Phase 7 catalog. Critical mutations dispatch the existing `master-core.audit.record.v1` event synchronously inside the same transaction when Phase 7 requires rollback-on-audit-failure. Authentication-style fail-open behavior is not appropriate for critical CRUD mutations. Noncritical or post-commit notices follow the owner package's approved failure semantics.

If Audit Logs is absent, the CRUD Engine must not bind to a missing service or silently promise persistent audit history. A host that requires audited critical operations must install/configure Audit Logs and satisfy its database/transaction requirements. Generic event names cannot bypass the closed catalog.

## 8. Frontend architecture

- Reuse `CoreDataTable`, `CoreField`, `CoreTextInput`, `CoreSearchSelect`, `CoreMultiSelect`, `CoreButton`, `CoreConfirmDialog`, `CoreToast`, and shared form styles from `@master-core/ui`.
- Add a typed `useCrudResource` composable for query state, server search/filter/sort/pagination, selection, loading/error handling, safe API errors, and capability state.
- Add an opt-in registered-resource list/form renderer for the supported descriptor subset. Resource-specific pages may use slots or remain standalone for domain-specific workflows.
- Use explicit route integration with the Auth SPA. Do not assume a cross-package persistent navigation shell; package entrypoints add route arrays as existing modules do.
- Follow global UI requirements: consistent titles/spacing, labeled accessible fields, validation messages, page sizes, five-page pagination, responsive layout, confirmation, toast, empty/loading/error states, and row actions only when available.
- Keep server-side filtering/pagination authoritative. Persist column preferences only in local browser storage if implemented and approved; do not create a server preference table in v1.
- Escape user content and do not render server-supplied HTML. Handle 401/403 consistently; client capability checks never replace backend enforcement.

## 9. Database and migration impact

**Recommended v1: no CRUD Engine-owned database tables or migrations.** Resource schema belongs to the resource owner. The registry and descriptors are code-defined. Do not persist CRUD definitions or dynamically create tables. Do not add a universal CRUD metadata/preferences table. The engine must not alter Laravel stock migrations or `App\\Models\\User`.

Resources may continue to own their own approved migrations and models. The engine must not add soft-delete columns, generic foreign keys, or timestamps to an existing resource. It must not own resource data, role tables, audit records, or notification records.

## 10. Search, filtering, sorting, pagination, and bulk operations

- Search only explicitly declared fields; escape wildcard characters and apply a configured maximum term length.
- Filters declare type, operator set, validation, and query translation. Reject unknown fields/operators; never accept an SQL fragment or relation path from input.
- Sort only declared sortable fields and append a deterministic key tie-breaker.
- Paginate on the server, cap page size at the Global UI standard, return total/current/last/from/to metadata, and guard expensive unbounded queries.
- Column visibility is a presentation preference over registered columns and cannot reveal unregistered/private fields.
- Selection applies only to the current declared operation. Bound ID count (recommend maximum 100), resolve all IDs within scope, check every row, and record explicit `affected`/failure semantics.
- Bulk delete/restore/permanent deletion is opt-in per resource; permanent delete requires a distinct ability, clear irreversible confirmation, and any package-specific safety policy.

## 11. Soft delete, restore, trash, and permanent deletion

CRUD Engine must not assume `SoftDeletes`. A resource chooses among no deletion, reversible soft deletion with explicit trash/restore routes, or permanent deletion with stronger controls. Restore must confirm the row is trashed. Force deletion should normally require its own ability, fresh record resolution, confirmation/policy checks, bounded bulk scope, and transactionally consistent audit where required. Preserve User Management's existing safety policy and Phase 5/6 abilities. No generic policy can waive last-admin, role-assignment, or ownership checks.

## 12. Testing strategy

Backend tests should cover resource registration/unknown keys, route caching, response allowlists, scoped reads and IDOR, validation and unknown input rejection, per-operation Gates/Policies, compatibility-mode behavior, inactive users, filter/search/sort allowlists, deterministic pagination, mass-assignment protection, transaction rollback, bulk limits/atomicity, unsupported lifecycle actions, soft-delete/restore/force-delete policy, audit allowlist and critical rollback behavior, and absence of CRUD Engine-owned tables.

Frontend tests should cover descriptor rendering, reused Core UI components, field errors, search/filter/sort/page requests, loading/error/empty states, capability visibility, 401/403 behavior, confirmation flows, bulk selection/outcomes, safe text rendering, responsive/accessibility semantics, and unsupported actions being absent.

Run full PHP tests with SQLite `:memory:`, targeted package tests, Pint on changed PHP files, TypeScript, frontend tests, production build to an isolated directory, Composer validation, package discovery, and route inspection. Use isolated MySQL integration tests for target-engine index/JSON/query behavior when available. Do not run configured application migrations during implementation verification.

## 13. Security, performance, and compatibility

- Registry is immutable/code-defined at runtime; request input cannot choose models, tables, relations, abilities, SQL, handlers, or output fields.
- Enforce authentication, active-account middleware, resource scope, Gate/Policy, and handler checks on the server. Deny by default for missing definitions/abilities.
- Guard mass assignment and prevent sensitive fields (password/hash, tokens, secrets, private settings) from serialization or forms unless a separately designed workflow explicitly permits them.
- Avoid N+1 queries through owner-defined eager loading; cap page sizes, search lengths, filter counts, relation selectors, and bulk IDs. Add only indexes the owning package justifies.
- Keep resource keys and API contracts stable; version breaking metadata/behavior and provide deprecation notes. The Composer and npm package versions should evolve independently of host app version.
- Keep Core UI as the primitive package. Do not make UI components depend on Laravel or database details.
- Preserve existing endpoints and output contracts by default. Any User Management adoption must have compatibility/regression tests for `update-users`/`edit-users`, delete/force-delete distinctions, password-reset and verification permissions, role assignment ability, host safety policy, session invalidation, and audit transaction behavior.
- For the future Master Core package/project generator, make package optionality and required resource registrations explicit. Do not let a generator assume all modules expose CRUD or alter host databases without operator approval.

## 14. Recommended v1 scope and exclusions

### Include

1. Separate Composer/npm package with package discovery and typed, code-owned resource registry.
2. Reusable backend query/response/request primitives with strict search/filter/sort/page allowlists.
3. An opt-in adapter/route strategy with per-resource scoped queries, Form Requests, actions, Gate/Policy checks, and serializers.
4. Reusable Vue list state and a limited descriptor-driven form/list renderer composed from existing Core UI primitives.
5. Explicit delete lifecycle and bulk action declarations; no assumed soft deletion.
6. Permission and audit integration contracts, with no automatic permission grants and no dynamic audit event names.
7. Tests, setup/registration docs, security boundaries, and integration examples.
8. No new tables or migrations.

### Exclude

- Arbitrary model/table/SQL endpoints or UI-driven resource creation.
- Automatic migrations/schema generation, dynamic relationship discovery, code execution from metadata, or database-stored CRUD definitions.
- Forced rewrite of existing User Management, Settings, Roles & Permissions, Audit Logs, Notifications, Module Manager, or System Health APIs.
- Universal soft-delete/restore/force-delete semantics.
- Nested aggregate editors, workflow builders, imports/exports, file/media manager, reporting, and generated application modules.
- Automatic Gate/permission grants or bypass of Phase 6 compatibility and host policies.

## 15. Dependencies and integration sequence

1. Preserve the existing System Health Phase 9 and Notifications Phase 10 records; record the duplicate Phase 9 label for a future roadmap decision.
2. Add a package with Laravel 12-compatible Composer metadata/provider, registry/config, and no production migration.
3. Define and test the resource contract and API query validation in isolation.
4. Define the Vue workspace, typed API client/composable, and optional page renderer using `@master-core/ui`.
5. Add explicit Phase 6 permission registration instructions and Phase 7 catalog/audit integration requirements without automatic grants or optional-package hard dependencies.
6. Demonstrate with one isolated test-only fixture; do not adopt User Management or another business resource in v1.
7. Add full regression, static, build, package-discovery and documentation checks.

Dependencies: Laravel 12, PHP package discovery/Composer path conventions, Vue 3 + TypeScript + Vue Router, Core UI npm workspace, Foundation API prefix/version, Auth SPA route composition, optional Phase 6 registry and Phase 7 event contract. No queue, scheduler, new external service, or database table is required.

## 16. Risks and tradeoffs

| Risk | Mitigation / decision |
|---|---|
| Phase number conflicts with completed Phase 9 and 10 records | Preserve current history and flag the duplicate label for a later roadmap decision; implementation can remain package-isolated. |
| Generic endpoint becomes a mass-assignment or IDOR surface | Registry allowlist, scoped resolution, explicit serializer, Form Request/handler, Gate/Policy per action and test fixture security tests. |
| Resource-specific rules leak into shared engine | Keep business invariants, queries, transactions, deletion and audit mapping with owner package. |
| Metadata renderer cannot represent complex forms | Limit v1 field types and allow resource-owned Vue slots/pages. |
| Migration of User Management regresses established behavior | Do not rewrite it in v1; opt-in adapter must pass parity tests before adoption. |
| Audit package is optional but critical operations require audit writes | Declare event support and transaction requirements per resource; fail closed for required critical audit, do not promise audit when Phase 7 is absent. |
| Dynamic ability names weaken Phase 6 registry guarantees | Register every ability in code before use; no grants/materialization from resource metadata at runtime. |
| Very broad registry/API creates versioning and performance pressure | Stable resource keys, bounded query complexity, opt-in registration, explicit package versioning. |

## 17. Approved implementation decisions

The Phase 9 implementation authorization confirmed these choices:

1. **Roadmap/history:** preserve the completed System Health Phase 9 and Notifications Phase 10 records. Implement CRUD Engine under the requested Phase 9 filename without rewriting those records. The duplicate numbering remains visible for future roadmap reconciliation.
2. **API style:** explicit resource-owned routes/controllers backed by shared contracts. No generic production CRUD routes were registered.
3. **Initial integration:** one isolated test-only fixture; no User Management or other business resource adoption.
4. **Frontend scope:** shared Vue CRUD composable and limited descriptor-driven list/form renderer using Core UI primitives.
5. **Bulk semantics:** all-or-nothing within one database transaction, with record-level authorization and bounded IDs.
6. **Permissions:** abilities remain code-registered by the owning resource package; no permissions or grants were added by the engine. Fixture Gates exist only in tests.
7. **Audit:** fixture writes use already registered Phase 7 events and write synchronously inside the fixture transaction. Audit failure rolls back critical fixture mutations; the engine does not automatically audit all CRUD operations.

## 18. Implementation acceptance criteria and status

- Completed Phase 9 System Health and Phase 10 Notifications history remains unchanged; CRUD Engine's duplicate Phase 9 label is documented for later roadmap reconciliation.
- No request can select a model/table/query/handler or access unregistered fields/resources.
- Every supported action has resource scope, backend validation, Gate/Policy authorization, and ownership-specific handler behavior.
- Search/filter/sort/page and bulk constraints are bounded and tested; data is paginated server-side.
- CRUD Engine owns no database tables; resource schema remains with its owner.
- Existing Phase 3–10 endpoints, permissions, migration ownership, audit guarantees, and frontend routes remain compatible.
- Shared UI primitives are reused; accessibility, responsive design, error/empty/loading states, confirmations, and capability visibility are tested.
- PHP/frontend tests, TypeScript, production build, Pint, Composer/package discovery, route checks, and isolated database tests passed as recorded in the implementation report.
- Documentation covers host resource registration, permission catalog setup, audit configuration, API behavior, upgrade policy, and deployment limits.

No production resource migration or database table was added. Future adoption by a real resource package requires its own explicit route, permission, policy, audit, lifecycle, and compatibility review. The roadmap numbering conflict remains documented and does not change the historical Phase 9/10 records.
