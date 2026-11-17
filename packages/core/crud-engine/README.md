# Master Core CRUD Engine

The CRUD Engine is an opt-in Composer package and npm workspace for sharing safe query, response, and Vue CRUD behavior between resource-owning packages. It does not expose a model/table selection API, add routes for business resources, own database tables, or replace resource-specific rules.

## Package boundaries

- Composer package: `example/laravel-master-core-crud-engine`.
- npm workspace: `@itassistance-bd/crud-engine`.
- Laravel 12 service provider discovery registers configuration and the code-owned resource registry.
- No migration, model, seeder, generic production route, or database table is included.
- Resource-owning packages continue to own routes, models, query scope, Form Requests, handlers, policies/abilities, transactions, and deletion lifecycle.
- The package's only runtime dependency is Laravel. Vue components reuse `@itassistance-bd/ui`.

## Registering a resource

Implement `MasterCore\CrudEngine\Contracts\CrudResource` in the owning package. Its `definition()` returns a code-owned `CrudResourceDefinition` with an immutable key and explicit search/filter/sort fields. `query()` must return an already scoped Eloquent Builder. `serialize()` must return only public fields intended for that resource's callers.

Add the resource class to the host's published `master-core-crud-engine.php` configuration:

```php
return [
    'resources' => [
        App\Catalog\Crud\CatalogItemResource::class,
    ],
    'default_per_page' => 25,
    'max_search_length' => 150,
    'max_bulk_ids' => 100,
];
```

The registry is built only from these trusted classes. A request cannot supply a class, table, SQL fragment, relation path, writable field, Gate ability, or handler. Duplicate/invalid keys and invalid field identifiers are rejected.

## Routes and authorization

Register explicit routes in the owning package. Inject the registered resource, use `CrudQueryBuilder` for allowlisted list query parameters, and use `CrudResponse` for the standard `{ success, data, meta? }` response shape. The package does not add generic routes, policies, or permission grants.

The owner must enforce authentication, active-account rules, Laravel Gate/Policy authorization, resource scoping, validation, and business invariants on every endpoint and every affected bulk row. Phase 6 abilities must be registered in code before use; UI capability visibility is not authorization. Preserve the owning resource's current endpoints and compatibility contracts.

`CrudIndexParameters` accepts only `search`, `page`, `per_page`, `sort_by`, `sort_direction`, and `filters`. Page sizes are 10/25/50/100. Searchable, filterable, and sortable columns come only from the definition. Filters use equality against the registered filter-to-column map. Sorting has a deterministic primary-key tie-breaker. The owner supplies the query scope and record serializer.

For mutation endpoints, continue using resource-specific Form Requests and action classes. Validate only explicit writable fields; never infer mass assignment from database columns. Bulk actions must cap unique IDs, resolve every record through the owner's scoped query, authorize each affected row, and apply the approved all-or-nothing transaction behavior when partial results are not explicitly supported.

## Vue usage

The npm package exports:

- `useCrudResource(transport, defaultSort)` for server pagination, search/filter/sort state, form/API errors, notifications, and optional delete/bulk actions.
- `RegisteredCrudPage` for an opt-in list/form screen based on a trusted TypeScript descriptor and resource-owned transport.

The renderer supports text, email, number, boolean, enum/searchable select, and multiselect fields. It reuses Core UI fields, inputs, selects, data table, buttons, toast, and confirmation dialog. Complex workflows should use an owning package's Vue page or slots. The transport must call explicit resource-owned routes and must not trust browser-side capabilities.

## Audit integration

Do not automatically audit every generic engine operation. The resource owner declares event names in the Phase 7 closed catalog and emits `master-core.audit.record.v1` with only allowed metadata. Critical resource writes and their required audit inserts must run synchronously within the same transaction so audit failure rolls back the mutation. The engine itself does not force-install or silently bypass Audit Logs.

## Test fixture and verification

`tests/Fixtures/` contains an isolated account-style fixture and explicit test routes. Its `phase9_crud_fixture_accounts` table is created by the test setup on PHPUnit's SQLite `:memory:` database; there is no package migration and no fixture HTTP route in production. The fixture demonstrates list/detail/create/update, validation, authorization, allowlisted search/filter/sort/pagination, atomic bulk trash, restore, guarded permanent deletion, safe serialization, and Phase 7 registered audit events.

From the repository root:

```powershell
php artisan test --testsuite="Core CRUD Engine"
npm run typecheck
npm run test:ui
npm run build -- --outDir storage/framework/testing/crud-engine-build --emptyOutDir false
```

These commands do not migrate the configured application database. The build output directory should be unique for each verification run.


## External npm installation

After this package and its dependencies have been published to the verified public npm scope, install it in a consumer project with:

```sh
npm install @itassistance-bd/crud-engine
```

The package is not asserted to be published by this source repository. Until the publisher confirms registry availability and releases a compatible version, use the repository workspace setup described in the root README.
