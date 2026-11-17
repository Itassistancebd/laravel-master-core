# Master Core Project Generator

Standalone Composer CLI for planning and scaffolding a new Laravel 12 host with Foundation and explicit optional Core features.

The Generator also exposes a `template` command for the global Windows CLI. This mode copies the existing Laravel application and all 12 internal Composer package sources from the monorepo, validates relative Composer repositories and npm workspaces, and installs only the third-party dependencies through normal Composer/npm registries. It does not create a Laravel skeleton, publish internal packages, run migrations, or seed data. The generated host excludes local `.env` and runtime configuration, credentials, databases, dependency trees, build outputs, and test artifacts.

The standalone feature-selection mode retains local `example/` identities and explicit trusted-source checks. The monorepo `template` mode does not rely on those external internal-package identities: it resolves the included package source directly from the generated project. The npm manifests retain `@itassistance-bd`; internal workspaces resolve locally. The candidate Composer vendor `itassistance-bd` remains unverified at Packagist, and no internal packages are published there.

For controlled release preparation, dependency-ordered npm dry-runs, Composer snapshots, and isolated local artifact consumer checks, see the [package release guide](../../../docs/development/package-release-guide.md) and [integrated distribution report](../../../docs/architecture/phase10-11-integrated-distribution.md). The tools write validation artifacts outside the source project and do not publish packages or create repositories.

See [CLI usage and troubleshooting](../../../docs/architecture/phase11-project-generator-usage.md), the [Phase 11 implementation report](../../../docs/architecture/phase11-project-generator-implementation-report.md), and the [Phase 10 package identity proposal](../../../docs/architecture/phase10-package-identity-and-release-proposal.md).

The generator accepts only a nonexistent target, uses a sibling staging directory, preserves failed staging output, and never runs application migrations, seeders, permission grants, or Super Admin provisioning.
