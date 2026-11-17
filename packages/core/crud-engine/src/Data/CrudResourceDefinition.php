<?php

namespace MasterCore\CrudEngine\Data;

use InvalidArgumentException;

/** Immutable, code-owned query and presentation metadata; never built from request data. */
final readonly class CrudResourceDefinition
{
    /** @param list<string> $searchableFields @param array<string, string> $filters @param list<string> $sortableFields */
    public function __construct(
        public string $key,
        public string $defaultSort,
        public array $searchableFields = [],
        public array $filters = [],
        public array $sortableFields = [],
    ) {
        if (! preg_match('/^[a-z][a-z0-9-]{1,63}$/', $key)) {
            throw new InvalidArgumentException('CRUD resource keys must be stable lowercase identifiers.');
        }

        foreach (array_merge($searchableFields, array_values($filters), $sortableFields, [$defaultSort]) as $field) {
            if (! is_string($field) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
                throw new InvalidArgumentException('CRUD fields must be simple code-defined column names.');
            }
        }

        if (! in_array($defaultSort, $sortableFields, true)) {
            throw new InvalidArgumentException('The default CRUD sort field must be allowlisted as sortable.');
        }

        foreach (array_keys($filters) as $filter) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) $filter)) {
                throw new InvalidArgumentException('CRUD filter names must be stable lowercase identifiers.');
            }
        }
    }
}
