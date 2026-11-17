# Master Core Project Generator

Standalone Composer CLI for planning and scaffolding a new Laravel 12 host with Foundation and explicit optional Core features.

The Composer/npm namespaces in this checkout are unverified placeholders. The generator package itself has been installed in a clean local Composer consumer and its autoload/CLI verified. A Laravel host install still fails closed before staging until verified package vendors, npm scopes, and repositories are configured.

See [CLI usage and troubleshooting](../../../docs/architecture/phase11-project-generator-usage.md), the [Phase 11 implementation report](../../../docs/architecture/phase11-project-generator-implementation-report.md), and the [Phase 10 package identity proposal](../../../docs/architecture/phase10-package-identity-and-release-proposal.md).

The generator accepts only a nonexistent target, uses a sibling staging directory, preserves failed staging output, and never runs application migrations, seeders, permission grants, or Super Admin provisioning.
