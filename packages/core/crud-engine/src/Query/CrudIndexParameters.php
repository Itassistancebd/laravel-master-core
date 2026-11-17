<?php

namespace MasterCore\CrudEngine\Query;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MasterCore\CrudEngine\Data\CrudResourceDefinition;

/** Validates the shared list contract against one trusted resource definition. */
final class CrudIndexParameters
{
    public function validate(array $input, CrudResourceDefinition $definition): array
    {
        $unknown = array_diff(array_keys($input), ['search', 'page', 'per_page', 'sort_by', 'sort_direction', 'filters']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['query' => ['Unknown CRUD query parameters are not allowed.']]);
        }

        $filters = $input['filters'] ?? [];
        if (! is_array($filters)) {
            throw ValidationException::withMessages(['filters' => ['Filters must be an object of registered filter values.']]);
        }

        $unknownFilters = array_diff(array_keys($filters), array_keys($definition->filters));
        if ($unknownFilters !== []) {
            throw ValidationException::withMessages(['filters' => ['One or more filters are not registered for this resource.']]);
        }

        $validated = Validator::make($input, [
            'search' => ['sometimes', 'nullable', 'string', 'max:'.min(255, max(1, (int) config('master-core-crud-engine.max_search_length', 150)))],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
            'sort_by' => ['sometimes', 'string', 'in:'.implode(',', $definition->sortableFields)],
            'sort_direction' => ['sometimes', 'string', 'in:asc,desc'],
            'filters' => ['sometimes', 'array'],
        ])->validate();

        foreach ($filters as $name => $value) {
            if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                throw ValidationException::withMessages(["filters.{$name}" => ['Filter values must be scalar.']]);
            }
            if (is_string($value) && mb_strlen($value) > 255) {
                throw ValidationException::withMessages(["filters.{$name}" => ['Filter values may not exceed 255 characters.']]);
            }
        }

        return $validated;
    }
}
