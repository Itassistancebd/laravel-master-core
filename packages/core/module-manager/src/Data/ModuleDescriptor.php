<?php

namespace MasterCore\ModuleManager\Data;

use InvalidArgumentException;

/** Immutable, code-defined allowlist entry; provider identifiers remain server-side only. */
final readonly class ModuleDescriptor
{
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $composerPackage,
        public string $provider,
        public array $dependencies,
        public string $documentation,
    ) {}

    public static function fromDefinition(string $key, array $definition): self
    {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
            throw new InvalidArgumentException('Core module keys must use lowercase letters, numbers, and hyphens.');
        }
        foreach (['name', 'description', 'composer_package', 'provider', 'documentation'] as $field) {
            if (! isset($definition[$field]) || ! is_string($definition[$field]) || trim($definition[$field]) === '') {
                throw new InvalidArgumentException("A Core module is missing valid '{$field}' metadata.");
            }
        }
        if (mb_strlen($definition['name']) > 100 || mb_strlen($definition['description']) > 500) {
            throw new InvalidArgumentException("Core module '{$key}' has display metadata that exceeds its limit.");
        }
        if (! preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $definition['composer_package'])) {
            throw new InvalidArgumentException("Core module '{$key}' has an invalid Composer package name.");
        }
        if (! preg_match('#^(?:packages/core/[a-z0-9-]+/README\.md|docs/[a-z0-9-]+(?:/[a-z0-9-]+)*\.md)$#i', $definition['documentation'])) {
            throw new InvalidArgumentException("Core module '{$key}' has an unsafe documentation reference.");
        }
        if (! isset($definition['dependencies']) || ! is_array($definition['dependencies']) || ! array_is_list($definition['dependencies'])) {
            throw new InvalidArgumentException("Core module '{$key}' dependencies must be a list of Composer package names.");
        }

        $dependencies = [];
        foreach ($definition['dependencies'] as $dependency) {
            if (! is_string($dependency) || ! preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $dependency)) {
                throw new InvalidArgumentException("Core module '{$key}' has an invalid Composer dependency name.");
            }
            if (! in_array($dependency, $dependencies, true)) {
                $dependencies[] = $dependency;
            }
        }

        return new self(
            key: $key,
            name: $definition['name'],
            description: $definition['description'],
            composerPackage: $definition['composer_package'],
            provider: $definition['provider'],
            dependencies: $dependencies,
            documentation: $definition['documentation'],
        );
    }
}
