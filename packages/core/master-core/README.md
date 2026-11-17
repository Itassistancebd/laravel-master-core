# Master Core Composer Entry Package

This directory contains the lean `example/laravel-master-core` Composer metapackage used by the local monorepo. It requires Foundation and suggests optional capabilities. It contains no runtime code, provider, routes, migrations, configuration, installation scripts, permission grants, or Super Admin provisioning.

The `extra.master-core` profile has a versioned schema consumed by the Phase 11 Project Generator. It lists baseline and optional Composer/npm package identities, direct Composer dependencies, migration ownership, and explicit activation boundaries. The host still chooses backend packages through Composer requirements and frontend routes through explicit SPA imports.

The Composer vendor name is a placeholder. Registry publication must wait until the project owner confirms control of the Composer vendor and source repository. The current package identity and release proposal is in [Phase 10 documentation](../../../docs/architecture/phase10-package-identity-and-release-proposal.md); see also [installation and compatibility](../../../docs/architecture/master-core-package-installation.md).
