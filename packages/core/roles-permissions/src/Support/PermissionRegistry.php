<?php

namespace MasterCore\RolesPermissions\Support;

use InvalidArgumentException;

final class PermissionRegistry
{
    /** @var array<string, array{group: string, description: string, system: bool}> */
    private array $definitions = [];

    /** @var array<string, string> */
    private array $aliases = [];

    public function __construct()
    {
        $this->aliases = (array) config('master-core-roles-permissions.aliases', []);
        $this->definitions = $this->normalize((array) config('master-core-roles-permissions.abilities', []), true);
        $this->definitions += $this->normalize((array) config('master-core-roles-permissions.extensions', []), false);
    }

    /**
     * Resolve executable permissions only from code-owned definitions.
     * Database rows never register an ability on their own.
     *
     * @return array{group: string, description: string, system: bool}|null
     */
    public function definition(string $ability): ?array
    {
        $canonical = $this->canonicalName($ability);

        return $this->definitions[$canonical] ?? null;
    }

    public function canonicalName(string $ability): string
    {
        return (string) ($this->aliases[$ability] ?? $ability);
    }

    /** @return array<string, array{group: string, description: string, system: bool}> */
    public function all(): array
    {
        return $this->definitions;
    }

    /** @param array<string, mixed> $definitions
     * @return array<string, array{group: string, description: string, system: bool}>
     */
    private function normalize(array $definitions, bool $system): array
    {
        $normalized = [];
        foreach ($definitions as $name => $definition) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/', $name)) {
                throw new InvalidArgumentException('Permission registry keys must be lower-case ability identifiers.');
            }
            if (! is_array($definition)) {
                throw new InvalidArgumentException("Permission definition '{$name}' must be an array.");
            }

            $group = trim((string) ($definition['group'] ?? 'Other'));
            $description = trim((string) ($definition['description'] ?? ''));
            if ($group === '' || mb_strlen($group) > 100 || mb_strlen($description) > 255) {
                throw new InvalidArgumentException("Permission definition '{$name}' has invalid display metadata.");
            }

            $normalized[$name] = ['group' => $group, 'description' => $description, 'system' => $system];
        }

        return $normalized;
    }
}
