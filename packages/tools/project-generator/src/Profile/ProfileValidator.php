<?php

namespace MasterCore\ProjectGenerator\Profile;

use JsonException;

final class ProfileValidator
{
    private const FEATURE_KEY = '/^[a-z][a-z0-9-]*$/';

    public function validate(CoreProfile $profile): void
    {
        $data = $profile->data;
        $meta = $data['metapackage'] ?? null;

        if (! is_array($meta) || ! is_string($meta['name'] ?? null) || ! preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $meta['name'])) {
            throw new ProfileException('Profile metapackage identity is missing or invalid.');
        }

        if (($data['generator_profile_schema'] ?? null) !== 1) {
            throw new ProfileException('Unsupported generator profile schema; supported schema is 1.');
        }

        if (($meta['extra']['profile_schema'] ?? null) !== 1 || ($meta['extra']['profile'] ?? null) !== 'master-core-v1') {
            throw new ProfileException('Unsupported Master Core package profile schema or name.');
        }

        foreach (['php' => '^8.2', 'laravel/framework' => '^12.0'] as $requirement => $expected) {
            $actual = $requirement === 'php'
                ? ($meta['require']['php'] ?? null)
                : ($meta['require']['laravel/framework'] ?? null);
            if ($actual !== $expected) {
                throw new ProfileException("Unsupported compatibility constraint for {$requirement}; expected {$expected}.");
            }
        }

        if (! is_string($meta['version_constraint'] ?? null) || ! $this->isConstraint($meta['version_constraint'])) {
            throw new ProfileException('A valid pinned Core release constraint is required.');
        }

        $features = $meta['extra']['optional_features'] ?? null;
        $baseline = $meta['extra']['baseline'] ?? null;
        if (! is_array($features) || $features === [] || ! is_array($baseline) || count($baseline) !== 1) {
            throw new ProfileException('Profile must define optional features and one Foundation baseline package.');
        }

        $baselinePackage = $baseline[0] ?? null;
        if (! is_string($baselinePackage) || ($meta['require'][$baselinePackage] ?? null) !== '^0.1.0') {
            throw new ProfileException('The Composer metapackage must require the Foundation baseline at the approved version.');
        }

        foreach ($features as $key => $feature) {
            if (! is_string($key) || ! preg_match(self::FEATURE_KEY, $key) || ! is_array($feature)) {
                throw new ProfileException('Profile contains an invalid feature key or descriptor.');
            }

            foreach (['composer', 'npm', 'frontend_entrypoint'] as $field) {
                if (! is_string($feature[$field] ?? null) || $feature[$field] === '') {
                    throw new ProfileException("Feature '{$key}' is missing its {$field} identity.");
                }
            }

            if (! preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $feature['composer'])
                || ! preg_match('/^@[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $feature['npm'])) {
                throw new ProfileException("Feature '{$key}' has an invalid Composer or npm package name.");
            }

            if (! in_array($feature['frontend_entrypoint'], ['auth', 'settings', 'resource-owned'], true)) {
                throw new ProfileException("Feature '{$key}' has an unsupported frontend entrypoint.");
            }

            if (! is_array($feature['frontend_exports'] ?? null)
                || array_filter($feature['frontend_exports'], static fn ($export): bool => ! is_string($export) || $export === '') !== []) {
                throw new ProfileException("Feature '{$key}' has invalid frontend exports.");
            }

            if (! is_array($feature['composer_dependencies'] ?? null)
                || array_filter($feature['composer_dependencies'], static fn ($dependency): bool => ! is_string($dependency)) !== []) {
                throw new ProfileException("Feature '{$key}' has invalid Composer dependency metadata.");
            }

            if (! is_bool($feature['has_package_migrations'] ?? null)) {
                throw new ProfileException("Feature '{$key}' must declare whether it owns migrations.");
            }

            $npm = $data['npm_packages'][$key] ?? null;
            if (! is_array($npm) || ($npm['name'] ?? null) !== $feature['npm'] || ! $this->isVersion($npm['version'] ?? null)) {
                throw new ProfileException("Feature '{$key}' does not match the bundled npm package index.");
            }

            $entrypoint = $npm['exports']['.'] ?? null;
            if (! is_array($entrypoint)
                || ! is_string($entrypoint['import'] ?? null)
                || ! is_string($entrypoint['types'] ?? null)
                || ! is_string($entrypoint['development'] ?? null)
                || ! str_starts_with($entrypoint['import'], './dist/')
                || ! str_starts_with($entrypoint['types'], './dist/')
                || ! str_starts_with($entrypoint['development'], './resources/js/')) {
                throw new ProfileException("Feature '{$key}' is missing a complete npm entrypoint export map.");
            }

            $styleExport = $npm['exports']['./style.css']['import'] ?? null;
            if (! is_string($feature['frontend_styles'] ?? null)
                || $feature['frontend_styles'] !== $feature['npm'].'/style.css'
                || $styleExport !== './dist/index.css') {
                throw new ProfileException("Feature '{$key}' is missing its explicit stylesheet export.");
            }
        }

        $activation = $meta['extra']['activation'] ?? null;
        foreach (['global_automatic_feature_activation', 'automatic_permission_grants', 'automatic_migrations', 'automatic_super_admin_provisioning'] as $flag) {
            if (! is_array($activation) || ($activation[$flag] ?? null) !== false) {
                throw new ProfileException("Profile must keep {$flag} disabled.");
            }
        }

        if (! is_array($data['shared_npm'] ?? null)
            || ($data['shared_npm']['name'] ?? null) !== ($meta['extra']['shared_npm'] ?? null)
            || count($data['npm_packages']) !== count($features)) {
            throw new ProfileException('Profile npm package index contains missing or unrecognized feature entries.');
        }
    }

    public function validateAgainstSource(CoreProfile $profile, string $sourceRoot): void
    {
        $root = realpath($sourceRoot);
        if ($root === false || ! is_file($root.'/packages/core/master-core/composer.json')) {
            throw new ProfileException('Local Core source root is not a recognized Master Core checkout.');
        }

        try {
            $manifest = json_decode((string) file_get_contents($root.'/packages/core/master-core/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProfileException('Local metapackage manifest is invalid.', previous: $exception);
        }

        if (($manifest['name'] ?? null) !== $profile->packageName()
            || ($manifest['extra']['master-core'] ?? null) !== $profile->data['metapackage']['extra']) {
            throw new ProfileException('Bundled feature profile does not match the local Composer metapackage.');
        }

        foreach ($profile->features() as $feature => $definition) {
            $packageDirectory = match ($feature) {
                'settings' => 'settings',
                default => $feature,
            };
            $composerPath = $root.'/packages/core/'.$packageDirectory.'/composer.json';
            $npmPath = $root.'/packages/core/'.$packageDirectory.'/package.json';
            if (! is_file($composerPath) || ! is_file($npmPath)) {
                throw new ProfileException("Local package manifests are missing for '{$feature}'.");
            }

            try {
                $composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
                $npm = json_decode((string) file_get_contents($npmPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new ProfileException("Local package manifest is invalid for '{$feature}'.", previous: $exception);
            }

            $coreDependencies = array_values(array_filter(
                array_keys($composer['require'] ?? []),
                static fn (string $name): bool => str_starts_with($name, 'example/laravel-master-core-')
            ));
            sort($coreDependencies);
            $expectedDependencies = $definition['composer_dependencies'];
            sort($expectedDependencies);

            if (($composer['name'] ?? null) !== $definition['composer']
                || ($npm['name'] ?? null) !== $definition['npm']
                || $coreDependencies !== $expectedDependencies
                || ($composer['require']['php'] ?? null) !== $profile->data['metapackage']['require']['php']
                || ($composer['require']['laravel/framework'] ?? null) !== $profile->data['metapackage']['require']['laravel/framework']
                || ($npm['dependencies'] ?? []) !== ($profile->npmPackage($feature)['dependencies'] ?? [])
                || ($npm['peerDependencies'] ?? []) !== ($profile->npmPackage($feature)['peerDependencies'] ?? [])
                || ($npm['exports'] ?? []) !== ($profile->npmPackage($feature)['exports'] ?? [])) {
                throw new ProfileException("Local Composer/npm manifests disagree with the profile for '{$feature}'.");
            }
        }

        $sourceComposer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $sourceNpm = json_decode((string) file_get_contents($root.'/package.json'), true, 512, JSON_THROW_ON_ERROR);
        $sourceVue = $sourceNpm['dependencies']['vue'] ?? $sourceNpm['devDependencies']['vue'] ?? null;
        if (($sourceComposer['require']['php'] ?? null) !== $profile->data['metapackage']['require']['php']
            || ($sourceComposer['require']['laravel/framework'] ?? null) !== $profile->data['metapackage']['require']['laravel/framework']
            || $sourceVue !== $profile->data['toolchain']['frontend']['vue']
            || ($sourceNpm['devDependencies']['typescript'] ?? null) !== $profile->data['toolchain']['frontend']['typescript']) {
            throw new ProfileException('Bundled runtime compatibility does not match the current host workspace.');
        }
    }

    private function isConstraint(string $constraint): bool
    {
        return preg_match('/^\^[0-9]+\.[0-9]+\.[0-9]+$/', $constraint) === 1;
    }

    private function isVersion(mixed $version): bool
    {
        return is_string($version) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) === 1;
    }
}
