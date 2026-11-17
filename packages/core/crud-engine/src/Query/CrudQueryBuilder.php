<?php

namespace MasterCore\CrudEngine\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use MasterCore\CrudEngine\Contracts\CrudResource;

/** Applies only registered fields to a resource owner's pre-scoped query. */
final class CrudQueryBuilder
{
    public function __construct(private readonly CrudIndexParameters $parameters) {}

    public function paginate(CrudResource $resource, array $input): LengthAwarePaginator
    {
        $definition = $resource->definition();
        $filters = $this->parameters->validate($input, $definition);
        $query = $resource->query();

        if (($filters['search'] ?? '') !== '' && $definition->searchableFields !== []) {
            $term = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function (Builder $search) use ($definition, $term): void {
                foreach ($definition->searchableFields as $index => $field) {
                    $index === 0 ? $search->where($field, 'like', $term) : $search->orWhere($field, 'like', $term);
                }
            });
        }

        foreach ($filters['filters'] ?? [] as $name => $value) {
            $query->where($definition->filters[$name], '=', $value);
        }

        $sort = $filters['sort_by'] ?? $definition->defaultSort;
        $direction = $filters['sort_direction'] ?? 'asc';
        $key = $query->getModel()->getKeyName();

        $configuredPerPage = (int) config('master-core-crud-engine.default_per_page', 25);
        $safeDefaultPerPage = in_array($configuredPerPage, [10, 25, 50, 100], true) ? $configuredPerPage : 25;
        $perPage = (int) ($filters['per_page'] ?? $safeDefaultPerPage);

        return $query->orderBy($sort, $direction)->orderBy($key)->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));
    }
}
