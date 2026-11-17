# Phase 8 — CRUD Engine Categories Pilot

**Project:** Laravel Master Core
**Date:** 2026-11-17
**Status:** **PASS** (Campaign Phase 8; repository CRUD Engine report remains Phase 9)

## Result

Categories is the first host-owned production-style resource registered with the CRUD Engine. The implementation uses the trusted resource registry, shared allowlisted query builder and response envelope, and the reusable `RegisteredCrudPage` renderer and composable. Category-specific schema, validation, permissions, audit event choice, and mutation lifecycle remain owned by the host module.

The pilot supports list, detail view, create, edit, delete, search by name or slug, status filtering, allowlisted sorting, and server pagination. Slugs generate from the name when omitted on create, can be edited, and are unique. Status defaults to Active. Create, update, and delete each record a closed-catalog audit event synchronously inside the mutation transaction.

## API and permissions

The host's configured prefix currently resolves these authenticated API routes:

| Method | Route | Permission |
|---|---|---|
| GET | `/api/v1/core/categories` | `categories.view` |
| GET | `/api/v1/core/categories/{category}` | `categories.view` |
| POST | `/api/v1/core/categories` | `categories.create` |
| PUT/PATCH | `/api/v1/core/categories/{category}` | `categories.update` |
| DELETE | `/api/v1/core/categories/{category}` | `categories.delete` |

The four abilities are executable host extensions in `config/master-core-roles-permissions.php`. The UI reads current Gate permissions to show the navigation link and individual actions. Route middleware and Form Request authorization protect each API operation independently. No existing grant or Super Admin identity was changed.

## Implementation files

- `database/migrations/2026_11_17_000000_create_categories_table.php` — categories schema and unique slug index.
- `app/Modules/Categories/Models/Category.php`, `Crud/CategoryCrudResource.php`, `Http/Controllers/CategoryController.php`, and `Http/Requests/{StoreCategoryRequest,UpdateCategoryRequest}.php` — host-owned resource contract and handlers.
- `config/master-core-crud-engine.php` and `config/master-core-roles-permissions.php` — trusted resource registration and ability definitions.
- `routes/web.php` — explicit API endpoints inside the existing authenticated `api/v1/core` group.
- `resources/js/admin/CategoriesPage.vue`, `routes.ts`, and `AdminShell.vue` — registered-resource page and permission-aware navigation.
- `packages/core/crud-engine/resources/js/types.ts`, `useCrudResource.ts`, `components/RegisteredCrudPage.vue`, and its tests — reusable detail transport, resource-specific messages, and select defaults.
- `packages/core/audit-logs/src/Support/AuditEventCatalog.php` (and the repository's local copied Composer package under `vendor/example/laravel-master-core-audit-logs/`) — closed category event definitions available to the active runtime.
- `tests/Feature/CategoriesTest.php`, `tests/browser/categories.spec.ts`, and `tests/e2e/prepare-database.php` — isolated API, UI, browser, and seeded test coverage.

## Verification

| Command/check | Result |
|---|---|
| `php artisan test --no-ansi` | **PASS — 191 tests, 972 assertions** |
| `npm.cmd run test:ui -- --reporter=dot` | **PASS — 19 files, 79 tests** |
| `npm.cmd run typecheck` | **PASS** |
| `npm.cmd run build` | **PASS — Core packages and 121 Vite modules** |
| `npm.cmd run test:e2e` | **PASS — 6 Playwright tests** |
| `npm.cmd run test:e2e:responsive` | **PASS — 3 Playwright tests** |

Categories browser acceptance checked listing, page 2 pagination, search, status filter, create with generated slug, detail fetch, edit, delete confirmation, viewer navigation denial, viewer API 403, no document horizontal overflow, and no JavaScript page errors. The target viewports were **1440×900**, **768×1024**, and **390×844**.

Screenshots from the full browser run are in `artifacts/browser-qa/2026-11-16T194632219Z-02c879/`:

- `categories-list-{desktop,tablet,mobile}.png`
- `categories-create-{desktop,tablet,mobile}.png`
- `categories-view-{desktop,tablet,mobile}.png`

The final responsive run is in `artifacts/browser-qa/2026-11-16T195033231Z-784fda/`. The HTML report is `playwright-report/index.html`.

## Database and limitations

The migration file was added but **not run against the configured application database**. PHPUnit ran with SQLite `:memory:`. Playwright used a uniquely named temporary SQLite database and temporary generated accounts/categories, then retained that test DB as reported by the runner. No application seeder was run, no live grants changed, and Super Admin ID 1 was not modified.

MySQL-specific migration/index behavior was not separately exercised. Production adoption still requires the host's normal backup and migration process before applying the new `categories` table. This pilot does not add bulk operations or soft deletes; Delete is a permission-protected hard delete and is audited transactionally.
