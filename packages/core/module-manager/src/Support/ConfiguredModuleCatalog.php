<?php

namespace MasterCore\ModuleManager\Support;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use MasterCore\ModuleManager\Contracts\InstalledPackageInspector;
use MasterCore\ModuleManager\Contracts\ModuleCatalog;
use MasterCore\ModuleManager\Data\ModuleDescriptor;

final class ConfiguredModuleCatalog implements ModuleCatalog
{
    public function __construct(
        private readonly Application $app,
        private readonly InstalledPackageInspector $packages,
        private readonly ?array $definitions = null,
    ) {}

    public function all(): array
    {
        $definitions = $this->definitions ?? CoreModuleRegistry::definitions();
        $hidden = config('master-core-module-manager.hidden_modules', []);
        $overrides = config('master-core-module-manager.display_overrides', []);
        if (! is_array($definitions) || ! is_array($hidden) || ! array_is_list($hidden) || ! is_array($overrides)) {
            throw new InvalidArgumentException('The Core module catalog configuration is invalid.');
        }

        $allowedKeys = array_keys($definitions);
        foreach ($hidden as $key) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                throw new InvalidArgumentException('Only allowlisted Core module keys may be hidden.');
            }
        }
        foreach ($overrides as $key => $override) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true) || ! is_array($override)) {
                throw new InvalidArgumentException('Display overrides must reference an allowlisted Core module.');
            }
            if (array_diff(array_keys($override), ['name', 'description', 'documentation']) !== []) {
                throw new InvalidArgumentException('Only module name, description, and documentation may be overridden.');
            }
        }

        $modules = [];
        $seenPackages = [];
        foreach ($definitions as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                throw new InvalidArgumentException('Each Core module must use a descriptor array and a string key.');
            }
            $definition = array_replace($definition, $overrides[$key] ?? []);
            $descriptor = ModuleDescriptor::fromDefinition($key, $definition);
            if (isset($seenPackages[$descriptor->composerPackage])) {
                throw new InvalidArgumentException("Composer package '{$descriptor->composerPackage}' is assigned to more than one Core module.");
            }
            $seenPackages[$descriptor->composerPackage] = true;

            if (! in_array($key, $hidden, true)) {
                $modules[] = $this->resolve($descriptor);
            }
        }

        return $modules;
    }

    public function find(string $key): ?array
    {
        foreach ($this->all() as $module) {
            if ($module['key'] === $key) {
                return $module;
            }
        }

        return null;
    }

    private function resolve(ModuleDescriptor $descriptor): array
    {
        $installed = $this->packages->inspect($descriptor->composerPackage);
        $dependencies = [];
        foreach ($descriptor->dependencies as $packageName) {
            $dependency = $this->packages->inspect($packageName);
            $dependencies[] = ['package_name' => $packageName, 'installed' => $dependency['installed']];
        }

        $missingDependencies = array_filter($dependencies, static fn (array $dependency): bool => ! $dependency['installed']);
        $providers = $this->app->getLoadedProviders();
        $providerLoaded = (bool) ($providers[$descriptor->provider] ?? false);

        // Package presence and provider loading do not prove migrations or host setup are complete.
        $readiness = match (true) {
            ! $installed['installed'] => 'not_installed',
            $missingDependencies !== [] => 'dependency_missing',
            ! $providerLoaded => 'provider_not_loaded',
            default => 'runtime_not_verified',
        };

        return [
            'key' => $descriptor->key,
            'name' => $descriptor->name,
            'description' => $descriptor->description,
            'composer_package' => $descriptor->composerPackage,
            'installation' => $installed,
            'dependencies' => $dependencies,
            'provider_loaded' => $providerLoaded,
            'readiness_status' => $readiness,
            'documentation' => $descriptor->documentation,
        ];
    }
}
