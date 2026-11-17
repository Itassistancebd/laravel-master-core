<?php

namespace MasterCore\RolesPermissions\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use MasterCore\RolesPermissions\Actions\UserRoleAssignmentManager;
use MasterCore\RolesPermissions\Events\RolesPermissionsChanged;
use MasterCore\RolesPermissions\Http\Requests\SyncUserRolesRequest;
use MasterCore\RolesPermissions\Models\Role;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use MasterCore\RolesPermissions\Support\RoleAssignmentResolver;

final class UserRoleAssignmentsController
{
    public function show(string $user, HostUserModelResolver $models, UserRoleAssignmentManager $assignments, RoleAssignmentResolver $permissions): JsonResponse
    {
        Gate::authorize('manage-user-roles');
        $record = $this->findUser($models, $user);
        $roles = Role::query()->whereIn('id', $assignments->roleIdsFor($record))->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => ['id' => $record->getKey(), 'name' => $record->getAttribute('name'), 'email' => $record->getAttribute('email')],
                'role_ids' => $roles->modelKeys(),
                'roles' => $roles->map(static fn (Role $role): array => ['id' => $role->getKey(), 'name' => $role->name, 'role_key' => $role->role_key]),
                'effective_permissions' => $permissions->effectivePermissionNames($record),
            ],
        ]);
    }

    public function sync(SyncUserRolesRequest $request, string $user, HostUserModelResolver $models, UserRoleAssignmentManager $assignments): JsonResponse
    {
        Gate::authorize('manage-user-roles');
        $record = $this->findUser($models, $user);
        $roleIds = $request->validated('role_ids');
        // The manager re-fetches and locks this account in its transaction, where status is authoritative.
        $assignments->sync($record, $roleIds, $request->user());
        $event = RolesPermissionsChanged::record('user-roles-synced', $request->user(), $record);
        DB::afterCommit(static fn () => event($event));

        return response()->json(['success' => true, 'data' => ['role_ids' => $assignments->roleIdsFor($record)]]);
    }

    private function findUser(HostUserModelResolver $models, string $id): Model
    {
        $query = $models->query();
        if (method_exists($query->getModel(), 'trashed')) {
            $query->withTrashed();
        }

        return $query->findOrFail($id);
    }
}
