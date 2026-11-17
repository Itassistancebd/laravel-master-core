<?php

namespace MasterCore\CrudEngine\Registry;

use InvalidArgumentException;
use MasterCore\CrudEngine\Contracts\CrudResource;

/** Resolves only code-registered resources; request input can never name a model or table. */
final class CrudResourceRegistry
{
    /** @var array<string, CrudResource> */
    private array $resources = [];

    /** @param iterable<CrudResource> $resources */
    public function __construct(iterable $resources = [])
    {
        foreach ($resources as $resource) {
            if (! $resource instanceof CrudResource) {
                throw new InvalidArgumentException('Every CRUD resource registration must implement CrudResource.');
            }

            $key = $resource->definition()->key;
            if (isset($this->resources[$key])) {
                throw new InvalidArgumentException("Duplicate CRUD resource key [{$key}].");
            }

            $this->resources[$key] = $resource;
        }
    }

    public function get(string $key): CrudResource
    {
        return $this->resources[$key] ?? throw new InvalidArgumentException('The CRUD resource is not registered.');
    }

    /** @return list<CrudResource> */
    public function all(): array
    {
        return array_values($this->resources);
    }
}
