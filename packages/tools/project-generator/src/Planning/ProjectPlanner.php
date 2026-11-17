<?php

namespace MasterCore\ProjectGenerator\Planning;

use MasterCore\ProjectGenerator\Profile\CoreProfile;

final class ProjectPlanner
{
    public function build(
        CoreProfile $profile,
        array $requestedFeatures,
        array $frontendFeatures,
        string $authShell = 'host',
        ?string $coreVersion = null,
    ): ProjectPlan {
        $available = $profile->features();
        $requestedFeatures = $this->normalizeKeys($requestedFeatures, $available, 'backend');
        $frontendFeatures = $this->normalizeKeys($frontendFeatures, $available, 'frontend');

        if (! in_array($authShell, ['core', 'host', 'none'], true)) {
            throw new PlanException('Auth shell must be one of: core, host, none.');
        }

        if ($authShell === 'core' && ! in_array('auth', $requestedFeatures, true)) {
            throw new PlanException('The Core Auth shell requires the auth backend feature to be selected explicitly.');
        }

        $backend = $this->dependencyClosure($profile, $requestedFeatures);
        foreach ($frontendFeatures as $feature) {
            if (! in_array($feature, $backend, true)) {
                throw new PlanException("Frontend feature '{$feature}' requires its backend feature to be selected.");
            }
        }

        if ($authShell === 'core' && ! in_array('auth', $frontendFeatures, true)) {
            $frontendFeatures[] = 'auth';
        }

        $constraint = $coreVersion ?? $profile->composerConstraint();
        if (! preg_match('/^\^[0-9]+\.[0-9]+\.[0-9]+$/', $constraint)) {
            throw new PlanException('Core version must be an explicit caret SemVer constraint such as ^0.1.0.');
        }

        $composerRequirements = [$profile->packageName() => $constraint];
        foreach ($requestedFeatures as $feature) {
            $composerRequirements[$available[$feature]['composer']] = $constraint;
        }

        $npmPackages = $this->npmDependencyClosure($profile, $frontendFeatures);
        $frontendPackageNames = [];
        foreach ($frontendFeatures as $feature) {
            $frontendPackageNames[$feature] = $profile->npmPackage($feature)['name'];
        }
        $npmDependencies = [];
        foreach ($npmPackages as $packageName => $version) {
            $npmDependencies[$packageName] = $version;
        }

        $toolchain = $profile->data['toolchain']['frontend'];
        if ($frontendFeatures !== []) {
            $npmDependencies['vue'] = $toolchain['vue'];
            $npmDevDependencies = [
                '@vitejs/plugin-vue' => $toolchain['vue_plugin'],
                'typescript' => $toolchain['typescript'],
            ];
        } else {
            $npmDevDependencies = [];
        }

        $routeExports = [];
        $styleImports = [];
        foreach ($frontendFeatures as $feature) {
            $definition = $available[$feature];
            $styleImports[] = $definition['frontend_styles'];
            if ($definition['frontend_entrypoint'] === 'auth' && $feature !== 'auth') {
                foreach ($definition['frontend_exports'] as $export) {
                    if (str_ends_with($export, 'Routes')) {
                        $routeExports[$feature] = $export;
                    }
                }
            }
        }

        if ($frontendFeatures !== []) {
            $styleImports = array_merge([$profile->data['shared_npm']['name'].'/styles.css'], $styleImports);
        }

        $migrationFeatures = array_values(array_filter(
            $backend,
            static fn (string $feature): bool => $available[$feature]['has_package_migrations']
        ));

        return new ProjectPlan(
            profile: $profile->profileName(),
            coreVersion: $constraint,
            requestedFeatures: $requestedFeatures,
            backendFeatures: $backend,
            frontendFeatures: $frontendFeatures,
            authShell: $authShell,
            composerRequirements: $composerRequirements,
            npmDependencies: $npmDependencies,
            npmDevDependencies: $npmDevDependencies,
            routeExports: $routeExports,
            styleImports: array_values(array_unique($styleImports)),
            migrationFeatures: $migrationFeatures,
            baselinePackages: $profile->baseline(),
            frontendPackageNames: $frontendPackageNames,
        );
    }

    /** @param list<string> $keys @param array<string, mixed> $available @return list<string> */
    private function normalizeKeys(array $keys, array $available, string $kind): array
    {
        if (array_filter($keys, static fn ($key): bool => ! is_string($key)) !== []) {
            throw new PlanException("{$kind} selections must be feature keys.");
        }

        if (count(array_unique($keys)) !== count($keys)) {
            throw new PlanException("Duplicate {$kind} feature selection was supplied.");
        }

        foreach ($keys as $key) {
            if (! array_key_exists($key, $available)) {
                throw new PlanException("Unknown {$kind} feature '{$key}'.");
            }
        }

        return array_values($keys);
    }

    /** @param list<string> $requested @return list<string> */
    private function dependencyClosure(CoreProfile $profile, array $requested): array
    {
        $features = $profile->features();
        $packageToFeature = [];
        foreach ($features as $key => $feature) {
            $packageToFeature[$feature['composer']] = $key;
        }

        $resolved = [];
        $visiting = [];
        $visit = function (string $feature) use (&$visit, &$resolved, &$visiting, $features, $packageToFeature): void {
            if (isset($resolved[$feature])) {
                return;
            }
            if (isset($visiting[$feature])) {
                throw new PlanException("Feature dependency cycle detected at '{$feature}'.");
            }

            $visiting[$feature] = true;
            foreach ($features[$feature]['composer_dependencies'] as $dependency) {
                if (isset($packageToFeature[$dependency])) {
                    $visit($packageToFeature[$dependency]);
                }
            }
            unset($visiting[$feature]);
            $resolved[$feature] = true;
        };

        foreach ($requested as $feature) {
            $visit($feature);
        }

        // Preserve the stable profile order so generated manifests are deterministic.
        return array_values(array_filter(array_keys($features), static fn (string $key): bool => isset($resolved[$key])));
    }

    /** @param list<string> $features @return array<string, string> */
    private function npmDependencyClosure(CoreProfile $profile, array $features): array
    {
        $npmIndex = $profile->data['npm_packages'];
        $npmIndex[$profile->data['shared_npm']['name']] = $profile->data['shared_npm'];
        $keyByName = [];
        foreach ($npmIndex as $key => $package) {
            $keyByName[$package['name']] = $key;
        }

        $resolved = [];
        $visiting = [];
        $visitName = function (string $name) use (&$visitName, &$resolved, &$visiting, $npmIndex, $keyByName): void {
            if (isset($resolved[$name])) {
                return;
            }
            if (isset($visiting[$name])) {
                throw new PlanException("npm dependency cycle detected at '{$name}'.");
            }
            if (! isset($keyByName[$name])) {
                throw new PlanException("npm dependency '{$name}' is missing from the validated package profile.");
            }

            $visiting[$name] = true;
            $package = $npmIndex[$keyByName[$name]];
            foreach ($package['dependencies'] as $dependency => $constraint) {
                if (isset($keyByName[$dependency])) {
                    $visitName($dependency);
                } else {
                    $resolved[$dependency] = $constraint;
                }
            }
            unset($visiting[$name]);
            $resolved[$name] = '^'.$package['version'];
        };

        foreach ($features as $feature) {
            $visitName($npmIndex[$feature]['name']);
        }

        foreach ($features as $feature) {
            $package = $npmIndex[$feature];
            foreach ($package['peerDependencies'] as $peer => $constraint) {
                $resolved[$peer] ??= $constraint;
            }
        }

        ksort($resolved);

        return $resolved;
    }
}
