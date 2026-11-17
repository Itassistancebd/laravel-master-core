<?php

namespace MasterCore\RolesPermissions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use MasterCore\RolesPermissions\Actions\PermissionCatalogManager;
use MasterCore\RolesPermissions\Events\RolesPermissionsChanged;
use MasterCore\RolesPermissions\Http\Requests\IndexPermissionsRequest;
use MasterCore\RolesPermissions\Http\Requests\StorePermissionRequest;
use MasterCore\RolesPermissions\Http\Requests\UpdatePermissionRequest;
use MasterCore\RolesPermissions\Models\Permission;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use MasterCore\RolesPermissions\Support\PermissionRegistry;

final class PermissionsController
{
    public function index(IndexPermissionsRequest $request, PermissionCatalogManager $catalog): JsonResponse
    {
        Gate::authorize('view-permissions');
        $filters = $request->validated();
        $items = collect($catalog->cachedCatalog());

        if (($filters['search'] ?? '') !== '') {
            $search = mb_strtolower($filters['search']);
            $items = $items->filter(fn (array $item): bool => str_contains(mb_strtolower($item['name'].' '.$item['description'].' '.$item['group_name']), $search));
        }
        if (($filters['group'] ?? '') !== '') {
            $items = $items->where('group_name', $filters['group']);
        }

        // Registry status and usage counts are derived at read time; only catalog metadata is cached.
        $items = $items->map(function (array $item): array {
            $item['is_registered'] = app(PermissionRegistry::class)->definition((string) $item['name']) !== null;
            $item['roles_count'] = DB::table('master_core_role_has_permissions')
                ->where('permission_id', $item['id'])->count();

            return $item;
        });

        $sort = $filters['sort_by'] ?? 'group_name';
        $direction = $filters['sort_direction'] ?? 'asc';
        $items = $direction === 'desc'
            ? $items->sortByDesc($sort, SORT_NATURAL | SORT_FLAG_CASE)
            : $items->sortBy($sort, SORT_NATURAL | SORT_FLAG_CASE);
        $items = $items->values();
        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);

        return response()->json([
            'success' => true,
            'data' => $items->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage, 'total' => $items->count(),
                'last_page' => max(1, (int) ceil($items->count() / $perPage)),
            ],
            'groups' => $items->pluck('group_name')->unique()->sort()->values(),
        ]);
    }

    public function show(string $permission): JsonResponse
    {
        Gate::authorize('view-permissions');
        $record = $this->findPermission($permission);

        return response()->json(['success' => true, 'data' => $this->resource($record)]);
    }

    public function available(PermissionRegistry $registry): JsonResponse
    {
        Gate::authorize('manage-permissions');
        $registered = $registry->all();
        $guard = app(HostUserModelResolver::class)->guardName();
        $existing = Permission::query()->where('guard_name', $guard)->pluck('name')->all();
        $available = collect($registered)->except($existing)->map(fn (array $definition, string $name): array => [
            'name' => $name,
            'group_name' => $definition['group'],
            'description' => $definition['description'],
        ])->values();

        return response()->json(['success' => true, 'data' => $available]);
    }

    public function store(StorePermissionRequest $request, PermissionCatalogManager $catalog): JsonResponse
    {
        Gate::authorize('manage-permissions');
        $permission = $catalog->materialize($request->validated('name'), $request->user());
        $this->record('permission-materialized', $request, $permission);

        return response()->json(['success' => true, 'data' => $this->resource($permission)], 201);
    }

    public function update(UpdatePermissionRequest $request, string $permission, PermissionCatalogManager $catalog): JsonResponse
    {
        Gate::authorize('manage-permissions');
        $record = $catalog->updateDisplayMetadata(
            $this->findPermission($permission),
            $request->validated('group_name'),
            $request->validated('description'),
            $request->user(),
        );
        $this->record('permission-metadata-updated', $request, $record);

        return response()->json(['success' => true, 'data' => $this->resource($record)]);
    }

    public function destroy(Request $request, string $permission, PermissionCatalogManager $catalog): JsonResponse
    {
        Gate::authorize('manage-permissions');
        $record = $this->findPermission($permission);
        $catalog->deleteAfterDeregistration($record, $request->user());
        $this->record('permission-deregistered', $request, $record);

        return response()->json(['success' => true, 'message' => 'Permission catalog row deleted.']);
    }

    private function findPermission(string $name): Permission
    {
        return Permission::query()
            ->where('guard_name', app(HostUserModelResolver::class)->guardName())
            ->where('name', $name)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function resource(Permission $permission): array
    {
        $registered = app(PermissionRegistry::class)->definition($permission->name) !== null;

        return [
            'id' => $permission->getKey(), 'name' => $permission->name,
            'group_name' => $permission->group_name, 'description' => $permission->description,
            'guard_name' => $permission->guard_name, 'is_system' => $permission->is_system,
            'is_registered' => $registered,
            'roles_count' => $permission->roles()->count(),
        ];
    }

    private function record(string $action, Request $request, Permission $subject): void
    {
        $event = RolesPermissionsChanged::record($action, $request->user(), $subject);
        DB::afterCommit(static fn () => event($event));
    }
}
