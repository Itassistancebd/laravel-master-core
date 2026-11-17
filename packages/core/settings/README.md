# Master Core Settings

The Settings package owns application-wide setting definitions, database overrides, administrative API routes, and the Vue Settings page. It depends on Laravel 12 and the Core Foundation package but does not add persistence to Foundation.

## Persistence and defaults

The package migration creates only `master_core_settings`, with JSON values and a unique `(group_name, setting_key)` constraint. Defaults, option lists, validation rules, and public visibility are defined in `config/settings.php`; database rows override those defaults. Reset removes the group's override rows so code defaults take effect again.

Ordinary settings records must never contain passwords, API secrets, tokens, credentials, or private keys. The registry rejects secret-like key names. Store deployment secrets in the host's secret or environment configuration instead.

## API and access contract

The route prefix follows the Foundation `master-core.api_prefix` and `master-core.api_version` configuration.

| Method | Path | Access | Purpose |
|---|---|---|---|
| GET | `/api/v1/settings/public` | Public, allowlisted | Returns only values whose definitions explicitly set `public: true`. |
| GET | `/api/v1/settings` | Authenticated + `view-settings` ability | Lists editable setting groups. |
| GET | `/api/v1/settings/{group}` | Authenticated + `view-settings` ability | Reads one known setting group. |
| PUT | `/api/v1/settings/{group}` | Authenticated + `manage-settings` ability | Validates and atomically saves the submitted allowlisted group values. |
| POST | `/api/v1/settings/{group}/reset` | Authenticated + `manage-settings` ability | Removes persisted overrides from a group. |
| POST | `/api/v1/settings/branding/assets` | Authenticated + `manage-settings` ability | Validates and stores a logo or favicon upload. |
| GET | `/admin/settings` | Authenticated + `view-settings` ability | Serves the Vue Settings page. |

The host application must define the two Gate abilities. Their default names can be changed in the published `master-core-settings.php` configuration. The package does not implement authentication or roles. Public responses contain no descriptions, defaults, private fields, or unregistered database keys.

## Cache

The manager uses Laravel's configured cache repository with scoped keys for a group and the public allowlist. Successful group updates, resets, and branding updates invalidate only the affected group's cache and public settings cache. Cache use and TTL are configurable in `master-core-settings.cache`; the package does not flush the whole application cache.

## Frontend

The root npm workspace uses Vue 3 and TypeScript. `@itassistance-bd/ui` owns shared form controls and styles; this package owns only the Settings screen. The UI provides grouped settings forms, accessible labels and errors, searchable select and multiselect fields, save/reset flows, branding upload, loading and empty states, and toast feedback. It does not add general CRUD, dashboard, or DataTable features.

## Local development and verification

Install dependencies and build/type-check the frontend from the repository root:

```powershell
npm install
npm run typecheck
npm run test:ui
npm run build
```

Run the backend suite with the project's test configuration, which sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`:

```powershell
composer test
```

The package migration is registered for the host application but must not be run against an existing application database without explicit authorization.
