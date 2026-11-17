# Phase 8 Proposal: Core Module Registry & Manager

> **Status: IMPLEMENTED AND VERIFIED WITH CONDITIONS.** The approved read-only v1 is implemented and automated checks are recorded in [the Phase 8 completion report](phase8-module-manager-completion.md). Browser acceptance remains a host-level follow-up. No Module Manager migration exists or was run.

## 1. Purpose and recommendation

Phase 8 should establish a safe, host-aware inventory of installed Master Core packages and their declared dependencies. The recommended name is **Core Module Registry & Manager**: “Registry” describes the authoritative catalog; “Manager” describes the authenticated operator view and readiness information.

The Phase 2 completion record explicitly reserves module metadata, registration, and lifecycle persistence as a future responsibility. No module-manager package currently exists. The recommended v1 is an inventory and readiness screen. It must not install, update, migrate, enable, disable, or uninstall packages at runtime. Composer and deployment configuration remain responsible for package installation and provider discovery.

This boundary follows the actual Laravel boot model in this repository: Composer-discovered providers register routes, listeners, bindings, and package migrations during application startup. A database “disabled” flag cannot safely undo those registrations after providers have booted.

## 2. Inspection findings

### Existing repository and package boundaries

- The repository is a Laravel 12 development host and a collection of local Composer packages under `packages/core/`. Each package has its own manifest, provider, configuration, tests, and (where needed) package-owned migrations.
- Current packages are Foundation, Settings, Authentication, User Management, Roles & Permissions, Audit Logs, and the shared Core UI workspace. No `packages/core/module-manager/` directory, Phase 8 proposal, or module catalog implementation was found.
- Composer package discovery is enabled. Each backend package declares its provider under `extra.laravel.providers`; package providers load their own routes and, where applicable, migration directories. Root `composer.json` separately declares each local path repository and requirement.
- The authenticated Vue application is composed in `resources/js/auth.ts`. It imports the Auth shell and explicitly composes User Management, Roles & Permissions, and Audit Logs route records. npm workspaces, aliases, and Vite integration are separately declared in root frontend configuration.
- Foundation supplies API prefix/version conventions and a database-independent health endpoint. It has no Core-owned tables. Settings, Roles & Permissions, and Audit Logs each own their persistence. User Management adds approved account-lifecycle columns to the configured host user table. Authentication preserves Laravel's host identity, stock migrations, and tables.
- Phase 6 is the code authority for executable RBAC abilities. Its permission registry rejects database-invented executable permissions. Compatibility Mode is the default; Strict Mode is opt-in. Super Admin provisioning remains console-only.
- Phase 7 provides append-only audit records for explicitly catalogued security mutations. Its production MySQL/InnoDB and authenticated browser checks remain deployment acceptance items; the Phase 7 completion report does not claim those checks were performed.
- Phase 2 calls Module Manager metadata, registration, and lifecycle persistence a future responsibility. That statement leaves the Module Manager's scope and schema undecided; it does not require that Phase 8 add persistence.

### Current lifecycle behavior and gaps

- Package installation is currently controlled by Composer requirements and path repositories, and package provider discovery is controlled by Laravel/Composer package discovery.
- API route registration is owned by each package provider. Frontend route composition is currently explicit in the Auth host entrypoint. These are two separate integration paths and can drift if packages are added or removed manually.
- There is no shared manifest contract for Core module name, stable key, package identity, version, dependencies, capabilities, or documentation links.
- There is no authenticated module inventory API or interface, no module-specific permission, and no module lifecycle persistence.
- The root README and setup guides describe packages individually, but there is no single runtime view that tells an authorized host operator which Core packages are installed and what dependencies they declare.

## 3. Findings versus recommendations

| Finding from the repository | Recommendation for Phase 8 |
|---|---|
| Composer and Laravel providers load package behavior at application boot. | Do not implement runtime enable/disable or package installation in v1. Treat package presence and version as deployment facts. |
| Each package owns its own routes and migrations. | Keep those ownership boundaries. Inventory their metadata; do not centralize or run migrations from the manager. |
| The Auth SPA manually composes several package route arrays. | Add a small, explicit Module Manager route integration through the existing Auth composition point; do not make the registry evaluate arbitrary JavaScript or import arbitrary package paths. |
| Phase 6 only executes code-registered abilities. | Register `view-modules` in the Phase 6 code permission registry before protecting the API/UI. Do not auto-grant it. No `manage-modules` ability is needed for read-only v1. |
| Module status persistence is not currently needed to describe Composer-installed packages. | Use a code-defined allowlisted module catalog plus Composer installed-package metadata. Propose any future database-backed lifecycle state separately. |
| Phase 7 only records an approved event catalog and critical mutations. | Do not add audit events for read-only inventory requests. Any future module-state mutation must receive a separate event definition and transaction/audit plan before implementation. |

## 4. Proposed scope and boundaries

### In scope for v1

- A separately maintainable package at `packages/core/module-manager/` with Composer metadata and a Vue/TypeScript workspace entry.
- A code-owned catalog of supported Core modules: stable key, display name, package name, provider identifier, description, declared Core dependencies, and documentation reference.
- An authenticated, permission-protected, read-only inventory that reports installed/not-installed state, installed version when available, declared dependency readiness, and safe documentation links.
- An optional operator page integrated into the existing Auth shell and shared Core UI, showing catalog state with clear unavailable, loading, empty, and forbidden states.
- Host configuration to hide catalog entries or supply safe display metadata, subject to an allowlisted module key and validation. Host configuration must not invent provider classes or executable actions from request data.
- Setup, host integration, package addition, compatibility, security, and deployment documentation.

### Out of scope for v1

- Composer install/update/remove operations, shell execution, package downloads, Git operations, or editing `composer.json` through HTTP.
- Runtime enable/disable, route unregistration, provider unloading, migration execution/rollback, seeders, or database schema changes for other packages.
- Business-module management, tenant-specific modules, module marketplace, arbitrary third-party package discovery, plugin code execution, or dependency resolution UI.
- Module-specific settings screens, dashboards, generic CRUD, or permission editing.
- Persistent per-host module state unless separately approved after a concrete lifecycle requirement is defined.

## 5. Package boundaries and dependency design

### Proposed package

```text
packages/core/module-manager/
├── composer.json
├── package.json
├── README.md
├── config/module-manager.php
├── routes/api.php
├── src/
│   ├── Contracts/ModuleCatalog.php
│   ├── Data/ModuleDescriptor.php
│   ├── Providers/ModuleManagerServiceProvider.php
│   ├── Support/ConfiguredModuleCatalog.php
│   ├── Http/Controllers/ModuleCatalogController.php
│   ├── Http/Requests/IndexModulesRequest.php
│   └── Http/Resources/ModuleResource.php
├── resources/js/
│   ├── api.ts
│   ├── types.ts
│   ├── routes.ts
│   └── pages/ModulesPage.vue
└── tests/
```

The package should depend on Laravel 12 and Core Foundation for the API prefix/version convention. Its UI workspace should use Vue 3, TypeScript, Core Auth route composition, and `@master-core/ui`. It should use Phase 6 Gate authorization at runtime; package-manager services must not be added to Foundation. The audit package is optional and has no v1 write integration because v1 exposes no state-changing operation.

### Catalog source

Use an explicit, code-defined catalog with known module keys and Composer package identifiers. Use Composer's installed-package metadata to determine installation and version. Do not scan arbitrary directories, include arbitrary Composer packages, instantiate provider classes from a request, or infer that installation means runtime readiness. Report provider activation only if Laravel provides a reliable loaded-provider check for the configured provider; otherwise omit that status.

The catalog may contain supported but absent Core modules, so `not-installed` is a valid result. A missing dependency should be reported as a readiness issue with a safe package name, not as a stack trace or filesystem path. The catalog is descriptive; it does not alter Composer or Laravel's package manifest.

## 6. Database impact and ownership

**Recommended v1: no database tables and no migrations.** The inventory is derived from code-owned descriptors and Composer's installed-package metadata. It does not need a host-user foreign key, a module-state table, seeders, or cache persistence. Foundation remains database-independent, and the Phase 2 zero-table Foundation decision remains unchanged.

If a later approved phase requires operators to persist runtime module state, that proposal must explain how state is applied before Laravel provider boot, how route and frontend composition follow the same state, how module migrations are handled, and what happens in long-lived workers and multi-node deployments. That future schema would belong to the Module Manager package, never Foundation, and must not be assumed by this v1 proposal.

## 7. API design

Use Foundation's configured API prefix and version. Proposed endpoints:

| Method and path | Purpose | Access |
|---|---|---|
| `GET /api/v1/modules` | Return the allowlisted Core module catalog with installed/version/dependency status. | `auth:sanctum` and `can:view-modules` |
| `GET /api/v1/modules/{module}` | Return one catalog descriptor by immutable key. | `auth:sanctum` and `can:view-modules` |

The request validator accepts only catalog keys, known search fields, known sort fields, and shared page-size values if the list is paginated. The endpoint returns a predictable envelope and does not expose local absolute paths, environment variables, Composer credentials, host configuration values, provider exceptions, raw manifests, or arbitrary package metadata. No write route, migration action, install action, or export route is proposed.

The catalog is small and bounded. The implementation may return all configured entries without pagination if it also provides bounded text search; if the global DataTable is used, use server-side search/sort/pagination and the standard 10/25/50/100 page sizes. This detail is listed for approval in §13.

## 8. Frontend requirements

- Add a read-only Modules page to the existing authenticated Core Auth shell and route composition. Do not create a second authentication shell.
- Use shared Core UI styles and controls. If the catalog is displayed in a table, use `CoreDataTable`, standard page sizing, searchable fields, visible sort affordances, row hover/striping, and responsive behavior.
- Display module name, safe stable key, package version when installed, installation state, dependency readiness, and a link to package documentation where available.
- Provide loading, transport error, empty/not-installed, and 403 states. Unauthorized API responses remain authoritative even when navigation is hidden.
- There are no create/edit/delete, enable/disable, update, install, migrate, or bulk actions in v1.
- Escape all metadata and render documentation links only from validated, configured local documentation paths or approved URLs. Never render server-supplied HTML.

## 9. Permission and authorization requirements

- Add `view-modules` to the Phase 6 code permission registry before routes/UI depend on the ability. Database permission catalog rows alone must never make an unregistered Gate ability executable.
- Require `auth:sanctum` plus server-side `can:view-modules` for both API endpoints. Navigation/page visibility is only a convenience and never replaces backend checks.
- Do not assign `view-modules` automatically to any role or Super Admin. Host grant provisioning follows existing Phase 6 procedures.
- Compatibility Mode remains the default and preserves existing host authorization behavior. Strict Mode remains opt-in and requires the existing documented fallback review and grant provisioning. The Module Manager must not special-case or bypass Gate/Policy resolution.
- `manage-modules` is deferred. It should be registered only if a later approved version introduces a real state-changing module operation.

## 10. Security and compatibility

- Treat Composer package names, versions, keys, dependencies, and documentation references as server-controlled metadata. Never accept a provider class, package name, command, filesystem path, or URL from a client as an instruction to execute or load code.
- The API is administrative because the inventory reveals installed capabilities. Return only the allowlisted catalog fields; avoid disclosing unrelated host packages or deployment details.
- Do not call Composer, Artisan migration commands, filesystem writes, or package discovery from an HTTP request.
- Preserve Laravel `App\\Models\\User`, stock migrations, Auth/Fortify/Sanctum behavior, Phase 5 account safety, Phase 6 Gate/Policy and Super Admin protections, and Phase 7 append-only audit semantics.
- Avoid assuming all host applications use the repository's exact local path packages. Package IDs, absent optional modules, package version APIs, disabled Composer discovery, cached routes, and custom API prefixes require explicit tests.
- Keep Foundation health behavior independent from Composer metadata inspection or Module Manager persistence.

## 11. Testing strategy

### Backend

- Catalog descriptor schema, immutable keys, duplicate-key rejection, and configured allowlist behavior.
- Installed/not-installed/version reporting using Composer installed-package metadata, including absent optional package and unsupported/missing dependency cases.
- Provider-state reporting only if included; verify disabled package discovery and route-cache behavior.
- API authentication (`401`), authorization (`403`), per-record access, query validation, search/sort/page allowlists, and response field allowlist.
- Prove responses never expose filesystem paths, environment data, Composer credentials, arbitrary package metadata, or exception details.
- Verify no migration directory/table is registered or required by the v1 package and no database query is required to build the catalog.
- Verify compatibility with Phase 6 Compatibility Mode and Strict Mode, registered `view-modules`, ungranted-by-default status, and host Gate/Policy behavior.
- Run regression tests across Phases 1–7 using PHPUnit's isolated SQLite `:memory:` configuration; do not run the configured application database migrations.

### Frontend and package checks

- Page tests for permission-aware route/navigation visibility, loading/error/empty/not-installed states, authorized catalog rendering, and API 403 handling.
- Assert no mutation actions or installation controls appear.
- Run TypeScript checks, frontend tests, production Vite build, scoped Pint, Composer validation/package discovery, and API route/permission checks.
- Browser acceptance against an authenticated host application remains distinct from automated component tests.

## 12. Dependencies and integration points

- **Laravel 12 / Composer:** installed package/version information and service provider lifecycle.
- **Core Foundation:** API prefix/version conventions only; no database service dependency.
- **Core Authentication:** existing session-authenticated Vue shell and route composition.
- **Roles & Permissions:** registered `view-modules` Gate ability and compatibility behavior.
- **Core UI:** standard table, forms/feedback, spacing, and responsive styles.
- **Existing packages:** catalog descriptors for the server-side Composer packages Foundation, Settings, Auth, User Management, Roles & Permissions, Audit Logs, and Module Manager. Optional package absence must be represented safely. Core UI is a shared npm build workspace rather than a PHP runtime module; its availability is not inferred by this server catalog.
- **Host application:** Composer package installation/discovery and the target application's grants, API base, and deployment controls.

Avoid a dependency on Audit Logs in v1. If Module Manager later gains critical module-state mutations, first approve a bounded state machine, transaction ownership, event catalog entry, and audit-failure behavior.

## 13. Risks, conflicts, and unresolved decisions

### Conflicts found

No direct conflict with approved Phase 1–7 decisions was found. The implementation preserves Foundation's database independence, package-owned migrations, host User model and stock migrations, Phase 6 registry/Gate protections, and Phase 7 audit behavior. The implementation approval confirmed catalog/readiness only; runtime lifecycle remains outside v1.

### Risks

- A UI labelled “Manager” may imply lifecycle controls. The UI and docs must state that package installation and activation remain deployment-time Composer/provider concerns.
- Composer-installed does not necessarily mean provider booted, routes loaded, migrations applied, or feature operational. Do not report an overall “healthy” status without explicit verifiable checks; describe each signal accurately.
- Maintaining module descriptors and the host's Composer/npm/Auth route composition can drift unless tests verify those lists together.
- A broad module catalog can disclose operational capabilities. Gate access and strict response allowlists are required.
- Runtime activation in a later phase could conflict with provider boot, route caching, migrations, browser bundles, multi-node deployments, long-lived workers, and audit obligations.

### Approved scope and implementation conditions

The implementation approval selected the read-only catalog, no database persistence, explicit allowlisting, authenticated API and Auth-shell page, registered `view-modules` permission without grants, and factual dependency/provider checks that never claim operational readiness. Runtime lifecycle controls remain outside scope. One implementation detail is documented in §17: Core UI is a build-time npm workspace, not a server-side Composer module.

## 14. Approved implementation decisions

1. **Name and purpose:** Core Module Registry & Manager; an inventory and dependency-readiness view.
2. **Lifecycle:** read-only v1. Composer and deployment own install, update, activation, migration, and removal.
3. **Persistence:** no v1 tables, migrations, seeders, or module-state records.
4. **Catalog:** explicit code allowlist of Core Composer packages only; no arbitrary package discovery. Host config may hide entries or override safe display text only.
5. **API/UI:** authenticated read-only endpoints and a page integrated into the existing Auth SPA.
6. **Authorization:** register and require `view-modules` through Phase 6. Do not automatically materialize or grant it. Defer `manage-modules`.
7. **Readiness:** report Composer installation/version, declared dependency presence, and Laravel provider-loaded state. Use `runtime_not_verified` when those checks pass; do not infer migration or application health.

## 15. Implementation sequence (executed)

1. Reconfirm the approved lifecycle boundary and inspect exact Composer/Laravel APIs for the supported host matrix.
2. Add the separate package manifest, service provider, allowlisted catalog contract/configuration, and package tests. No migrations in the recommended v1.
3. Register `view-modules` through the Phase 6 code permission registry without granting it.
4. Add authenticated, validated read-only API endpoints and tests for response disclosure and Gate behavior.
5. Add the Vue/TypeScript catalog page using the existing Auth shell and Core UI; test authorization and all request states.
6. Integrate package and frontend workspace through minimal host changes, documenting each optional-package behavior.
7. Run full regression tests, Pint, Composer/package discovery, TypeScript/UI checks, build, route review, and browser acceptance. Do not run migrations unless a later approved schema exists and the target database is explicitly authorized.

## 16. Acceptance criteria

- Module Manager exists as a separate package and does not add persistence to Foundation.
- The v1 catalog reports only allowlisted Core package metadata and clearly distinguishes installed package/version from unverified runtime readiness.
- API access is authenticated and requires the registered `view-modules` ability; unauthorized requests receive 403.
- No automatic permission grants, Composer/Artisan execution, installation/update controls, migration controls, or runtime provider toggles exist in v1.
- Auth shell integration uses existing route composition and Core UI, with loading/error/empty/403 states and automated tests.
- Host package absence, custom API prefix, route cache, package discovery behavior, and Phase 6 Compatibility/Strict modes are covered by tests or documented limitations.
- Phases 1–7 behavior remains unchanged. Automated database tests use isolated SQLite `:memory:`; no configured application database migration is run.
- Documentation explains installation, supported host behavior, lifecycle limitations, package discovery, permissions, and future change gates.

## References

- [Development standards](../standards/development.md)
- [Global UI and CRUD standards](../standards/global-ui-crud.md)
- [Architecture overview](overview.md)
- [Database architecture and Phase 2 completion](database.md) / [Phase 2 completion](phase2-completion.md)
- [Phase 3 Settings completion](phase3-settings-completion.md)
- [Phase 4 Auth & Security completion](phase4-auth-security-completion.md)
- [Phase 5 User Management completion](phase5-user-management-completion.md)
- [Phase 6 Roles & Permissions proposal and completion](phase6-roles-permissions-proposal.md) / [completion](phase6-roles-permissions-completion.md)
- [Phase 7 Audit Logs proposal and completion](phase7-audit-logs-proposal.md) / [completion](phase7-audit-logs-completion.md)

## 17. Implementation notes

- The immutable module identities and package dependencies live in `CoreModuleRegistry`. Published host configuration can hide known entries or override only name, description, and a validated documentation reference; it cannot introduce package names, dependencies, or provider classes.
- The server catalog includes the seven allowlisted Composer packages (including itself). Core UI is omitted from the server runtime list because it is an npm build workspace that may not exist in a deployed PHP host. It remains a Module Manager frontend dependency.
- The detail page shows repository documentation paths as text. No public documentation URL was configured, so the package does not expose an assumed or broken link to private host source files.
- The exact implementation, test results, package-discovery verification, and deployment/browser limitations are in the [Phase 8 completion report](phase8-module-manager-completion.md).
