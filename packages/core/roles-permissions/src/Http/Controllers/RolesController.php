<?php

namespace MasterCore\RolesPermissions\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use MasterCore\RolesPermissions\Actions\RoleManager;
use MasterCore\RolesPermissions\Events\RolesPermissionsChanged;
use MasterCore\RolesPermissions\Http\Requests\BulkDeleteRolesRequest;
use MasterCore\RolesPermissions\Http\Requests\IndexRolesRequest;
use MasterCore\RolesPermissions\Http\Requests\StoreRoleRequest;
use MasterCore\RolesPermissions\Http\Requests\SyncRolePermissionsRequest;
use MasterCore\RolesPermissions\Http\Requests\UpdateRoleRequest;
use MasterCore\RolesPermissions\Models\Role;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;

final class RolesController
{
    public function index(IndexRolesRequest $request): JsonResponse
    {
        Gate::authorize('view-roles');
        $filters = $request->validated();
        $query = Role::query()->where('guard_name', app(HostUserModelResolver::class)->guardName())
            ->withCount('permissions')
            ->addSelect(['members_count' => DB::table('master_core_model_has_roles')
                ->selectRaw('count(*)')
                ->whereColumn('master_core_model_has_roles.role_id', 'master_core_roles.id')]);

        if (($filters['search'] ?? '') !== '') {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        $sort = $filters['sort_by'] ?? 'name';
        $direction = $filters['sort_direction'] ?? 'asc';
        $query->orderBy($sort, $direction)->orderBy('id');
        $paginator = $query->paginate((int) ($filters['per_page'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()->map(fn (Role $role): array => $this->resource($role))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'total' => $paginator->total(), 'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $role): JsonResponse
    {
        Gate::authorize('view-roles');
        $record = $this->findRole($role);
        $data = $this->resource($record);
        $data['permission_ids'] = $record->permissions()->orderBy('name')->pluck('master_core_permissions.id')->map(static fn ($id): int => (int) $id)->all();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(StoreRoleRequest $request, RoleManager $manager): JsonResponse
    {
        Gate::authorize('create-roles');
        $role = $manager->create($request->validated('name'), $request->user());
        $this->record('role-created', $request->user(), $role);

        return response()->json(['success' => true, 'data' => $this->resource($role)], 201);
    }

    public function update(UpdateRoleRequest $request, string $role, RoleManager $manager): JsonResponse
    {
        Gate::authorize('edit-roles');
        $updated = $manager->rename($this->findRole($role), $request->validated('name'), $request->user());
        $this->record('role-renamed', $request->user(), $updated);

        return response()->json(['success' => true, 'data' => $this->resource($updated)]);
    }

    public function destroy(Request $request, string $role, RoleManager $manager): JsonResponse
    {
        Gate::authorize('delete-roles');
        $record = $this->findRole($role);
        $manager->delete($record, $request->user());
        $this->record('role-deleted', $request->user(), $record);

        return response()->json(['success' => true, 'message' => 'Role deleted.']);
    }

    public function bulkDestroy(BulkDeleteRolesRequest $request, RoleManager $manager): JsonResponse
    {
        Gate::authorize('delete-roles');
        $guard = app(HostUserModelResolver::class)->guardName();
        $roles = Role::query()->where('guard_name', $guard)->whereIn('id', $request->validated('role_ids'))->get();
        if ($roles->count() !== count($request->validated('role_ids'))) {
            abort(404, 'One or more roles do not exist for the default guard.');
        }

        DB::transaction(function () use ($roles, $manager, $request): void {
            foreach ($roles as $role) {
                $manager->delete($role, $request->user());
                $event = RolesPermissionsChanged::record('role-deleted', $request->user(), $role);
                DB::afterCommit(static fn () => event($event));
            }
        });

        return response()->json(['success' => true, 'data' => ['deleted' => $roles->count()]]);
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, string $role, RoleManager $manager): JsonResponse
    {
        Gate::authorize('edit-roles');
        $record = $this->findRole($role);
        $manager->syncPermissions($record, $request->validated('permission_ids'), $request->user());
        $this->record('role-permissions-synced', $request->user(), $record);

        return response()->json(['success' => true, 'data' => ['permission_ids' => $record->permissions()->pluck('master_core_permissions.id')->map(static fn ($id): int => (int) $id)->all()]]);
    }

    private function findRole(string $id): Role
    {
        return Role::query()->where('guard_name', app(HostUserModelResolver::class)->guardName())->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function resource(Role $role): array
    {
        return [
            'id' => $role->getKey(), 'name' => $role->name, 'role_key' => $role->role_key,
            'guard_name' => $role->guard_name, 'is_system' => $role->is_system,
            'permissions_count' => $role->permissions_count ?? $role->permissions()->count(),
            'members_count' => $role->members_count ?? 0,
            'created_at' => $role->created_at?->toISOString(),
        ];
    }

    private function record(string $action, ?Authenticatable $actor, Role $subject): void
    {
        $event = RolesPermissionsChanged::record($action, $actor, $subject);
        DB::afterCommit(static fn () => event($event));
    }
}
