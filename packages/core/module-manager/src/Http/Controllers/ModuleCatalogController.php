<?php

namespace MasterCore\ModuleManager\Http\Controllers;

use Illuminate\Http\JsonResponse;
use MasterCore\ModuleManager\Contracts\ModuleCatalog;
use MasterCore\ModuleManager\Http\Requests\IndexModulesRequest;
use MasterCore\ModuleManager\Http\Resources\ModuleResource;

final class ModuleCatalogController
{
    public function __construct(private readonly ModuleCatalog $catalog) {}

    public function index(IndexModulesRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $modules = $this->catalog->all();
        if (isset($filters['readiness'])) {
            $modules = array_values(array_filter($modules, static fn (array $module): bool => $module['readiness_status'] === $filters['readiness']));
        }
        if (isset($filters['search'])) {
            $needle = mb_strtolower($filters['search']);
            $modules = array_values(array_filter($modules, static function (array $module) use ($needle): bool {
                $searchable = mb_strtolower(implode(' ', [$module['key'], $module['name'], $module['description'], $module['composer_package']]));

                return str_contains($searchable, $needle);
            }));
        }

        $sortBy = $filters['sort_by'] ?? 'name';
        $direction = $filters['sort_direction'] ?? 'asc';
        usort($modules, static fn (array $left, array $right): int => ($direction === 'asc' ? 1 : -1) * strcmp((string) $left[$sortBy], (string) $right[$sortBy]));

        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = (int) ($filters['page'] ?? 1);
        $total = count($modules);
        $items = array_slice($modules, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'success' => true,
            'data' => array_map(ModuleResource::make(...), $items),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function show(string $module): JsonResponse
    {
        $descriptor = $this->catalog->find($module);
        abort_if($descriptor === null, 404);

        return response()->json(['success' => true, 'data' => ModuleResource::make($descriptor)]);
    }
}
