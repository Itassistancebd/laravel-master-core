# Master Core Package Installation and Compatibility Guide

> **Distribution readiness:** PARTIAL. Composer `example/` identities remain placeholders pending vendor verification. npm manifests use the owner-confirmed `@itassistance-bd` scope, but external organization ownership was not independently verified. See the [identity migration record](phase10-package-identity-migration-record.md).

> **Global CLI readiness:** The local source includes a Windows installer and `master` command, but these files are not on the published GitHub branch. `master new` uses the Phase 11 monorepo-template installer to copy the existing Laravel app. Internal packages resolve from included relative paths/workspaces; normal Composer/npm registries supply third-party dependencies. Fresh-PC installation requires the reviewed source update to reach GitHub `main`. See the [global CLI and monorepo distribution architecture](global-cli-and-monorepo-distribution.md).

> **Distribution policy update:** Earlier sections describe registry-backed internal package installation and remain reference material for the Phase 11 standalone feature-selection Generator. The global template installer resolves internal Core packages from the monorepo and uses registries for third-party dependencies only.

## Package selection contract

The Composer entry package is `example/laravel-master-core` and is a metapackage. It requires Laravel 12, PHP 8.2+, and the Foundation package. Its `extra.master-core` metadata has profile schema version `1`; it lists the baseline, optional feature package names, npm package names, direct Composer dependencies, and whether the feature owns migrations. Phase 11's Project Generator should read this metadata as the source for package selection and dependency resolution.

The metapackage has no service provider, routes, configuration, migrations, scripts, permissions, or runtime activation behavior. Each installed Composer package registers its own provider through Laravel package discovery. The host selects backend features by its Composer requirements and separately selects frontend features through explicit npm dependencies and route imports. Discovery registers package code; it does not run migrations or configure the host.

## Local monorepo development

The repository root is the all-features development host. It uses local Composer path repositories and npm workspaces so package changes can be tested together. `composer install` performs Composer's configured discovery scripts; it does not execute package migrations. The root's explicit `composer setup` and project-creation scripts are development-host conveniences that include migrations and must not be copied into a consumer installer.

Use the existing repository instructions in the root README to install and test this development host. Its Composer and npm manifests deliberately include all Core packages. A consuming application should require only the features it selects.

## Consumer Composer selection

After an owned Composer vendor and repository have been configured, a consumer selects the baseline and chosen capabilities. For example, these package names are placeholders until the distribution namespace is approved:

```text
example/laravel-master-core
example/laravel-master-core-settings
example/laravel-master-core-auth
example/laravel-master-core-user-management
example/laravel-master-core-roles-permissions
example/laravel-master-core-audit-logs
example/laravel-master-core-module-manager
example/laravel-master-core-system-health
example/laravel-master-core-notifications
example/laravel-master-core-crud-engine
```

Install the metapackage and each chosen optional package with the consumer's Composer command after the actual VCS/private Composer repository is configured. The metapackage's `suggest` list is advisory; Composer does not install suggested features. Hard dependencies of a selected feature are resolved by its own `require` metadata. For example, User Management requires Authentication; Roles & Permissions requires User Management; System Health and Notifications require Roles & Permissions.

Composer auto-discovery registers installed providers unless the host opts out using `extra.laravel.dont-discover`. If discovery is disabled for a package, the host must register that package's provider explicitly according to its package guide. Do not register a provider twice.

## Host frontend composition

Install `@itassistance-bd/ui` and only the feature npm packages whose interfaces the host uses. The local npm package names use the owner-confirmed `@itassistance-bd` scope; registry ownership still requires independent verification before publication. Add route factory imports explicitly in the host's SPA entrypoint; do not import optional modules through an all-features barrel. The existing development host's `resources/js/auth.ts` demonstrates an all-features composition.

The npm workspace uses the `development` export condition to point Vite at TypeScript/Vue source. Normal published imports resolve to compiled JavaScript and TypeScript declarations under `dist/`. Each UI/feature package also exports a stylesheet (`@itassistance-bd/<feature>/style.css`); import the selected package styles explicitly in the host production entrypoint, and import `@itassistance-bd/ui/styles.css` once. In workspace development, Vite loads component styles from the source SFCs. Vue is a peer dependency; package-specific dependencies such as Vue Router and Axios are declared by each feature package.

Each workspace package's `prepack` script builds its distributable entrypoint before `npm pack` or publication. The root `npm run build` first builds all package artifacts, then builds the development host. A consuming app installs only selected packages and must use its own explicit route imports and stylesheet imports; the example host's `resources/js/auth.ts` and `resources/js/settings.ts` include the currently selected packages for this repository.

The published frontend route import is a host configuration decision. It does not secure routes or APIs; Laravel middleware, Gates, Policies, validation, account-status checks, and resource-owned query scopes remain authoritative.

## Provider, config, API, and migration setup

- Keep each package's provider, routes, middleware, config, and migration ownership unchanged.
- Review each selected package README before publishing its configuration. Use package-specific `vendor:publish` tags; do not force-publish over host-owned config.
- Composer install and package discovery do not run migrations. Review migrations and database backups, then run the selected package migrations as a separate host deployment step.
- User Management extends the configured host user table through its package migration; do not edit Laravel's stock user migration or replace `App\Models\User`.
- Do not materialize or grant permissions merely because a package was installed. Register abilities through the Phase 6 code registry and use the approved host role workflow.
- Do not provision Super Admin during installation, migrations, seeders, or first-user creation.
- Package presence and provider loading do not prove that host configuration, migrations, permissions, mail, storage, or operational readiness are complete.

## Version compatibility and upgrades

The local release profile uses SemVer-compatible `^0.1.0` constraints across Core packages, Laravel `^12.0`, PHP `^8.2`, and Vue `^3.5.0`. These are pre-1.0 constraints: Composer/npm treat them as incompatible at the next minor version. Package tags and npm package versions must be published together according to the Core release compatibility matrix.

For a host upgrade:

1. Review the Core release notes and supported PHP/Laravel/Vue matrix.
2. Update the selected package constraints and lockfiles in a non-production branch.
3. Review Composer's dependency plan and frontend lockfile changes.
4. Run package and host regression tests against an isolated database.
5. Review package-owned migrations and backup/rollback implications before a deployment migration.
6. Rebuild route/config caches and frontend assets through the host's normal deployment process.

Removing a package does not roll back its migrations or erase its stored data. Perform any rollback only using that package's documented migration and data-retention policy.

## Troubleshooting

| Symptom | Checks |
|---|---|
| Provider is missing | Confirm the package is installed, inspect `composer show`, check `dont-discover`, run `php artisan package:discover`, and review Laravel's cached service-provider manifest. |
| Duplicate package routes | Check provider registration for both auto-discovery and manual registration; clear and rebuild route cache after correcting the host setup. |
| Feature API responds 401/403 | Confirm Sanctum/session and CSRF setup, account activity, required host Gate/Policy, Phase 6 mode, and the feature's documented ability. Frontend route visibility is not authorization. |
| Feature config appears ignored | Check the package's config key and publish tag, host overrides, config cache, and whether the package provider is loaded. |
| Migration is missing | Confirm the selected Composer package is installed and its provider loaded. Review that package's migration ownership; discovery registers migrations but does not run them. |
| Frontend module cannot resolve | Confirm its npm package and dependencies are installed, Vue/Vite plugin versions are supported, the host imported its route factory, and the Vite cache/build is current. |
| Generated Project Generator profile is unknown | Read `extra.master-core.profile_schema` from the metapackage and reject unsupported schema versions instead of guessing feature metadata. |

## Namespace and publication gate

Before registry publication, the project owner must provide the Composer vendor identity, source repository URL/permissions, npm organization/scope ownership, and registry visibility. A successful availability lookup does not prove namespace ownership. Replace placeholders only after the owner confirms control and package/repository names are consistent. Do not publish from this checkout until that gate is satisfied.

See the [Phase 10 package identity and release proposal](phase10-package-identity-and-release-proposal.md) for the current package inventory, proposed 0.x release policy, verified compatibility evidence, and the distinction between local package checks, clean consumer installation, external registry distribution, and publication.
