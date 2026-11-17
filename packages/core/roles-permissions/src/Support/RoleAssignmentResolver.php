<?php

namespace MasterCore\RolesPermissions\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RoleAssignmentResolver
{
    public function hasSuperAdmin(Authenticatable $user): bool
    {
        return $this->baseUserRoles($user)
            ->where('master_core_roles.role_key', 'core-super-admin')
            ->exists();
    }

    public function allows(Authenticatable $user, string $ability): bool
    {
        if ($this->hasSuperAdmin($user)) {
            return true;
        }

        $guard = app(HostUserModelResolver::class)->guardName();

        return DB::table('master_core_model_has_roles as assignments')
            ->join('master_core_roles as roles', 'roles.id', '=', 'assignments.role_id')
            ->join('master_core_role_has_permissions as grants', 'grants.role_id', '=', 'roles.id')
            ->join('master_core_permissions as permissions', 'permissions.id', '=', 'grants.permission_id')
            ->where('assignments.model_type', $this->modelType($user))
            ->where('assignments.model_id', (string) $user->getAuthIdentifier())
            ->where('roles.guard_name', $guard)
            ->where('permissions.guard_name', $guard)
            ->where('permissions.name', $ability)
            ->exists();
    }

    /** Check a stored role grant without applying the implicit Super Admin bypass. */
    public function hasExplicitPermission(Authenticatable $user, string $ability): bool
    {
        $guard = app(HostUserModelResolver::class)->guardName();

        return DB::table('master_core_model_has_roles as assignments')
            ->join('master_core_roles as roles', 'roles.id', '=', 'assignments.role_id')
            ->join('master_core_role_has_permissions as grants', 'grants.role_id', '=', 'roles.id')
            ->join('master_core_permissions as permissions', 'permissions.id', '=', 'grants.permission_id')
            ->where('assignments.model_type', $this->modelType($user))
            ->where('assignments.model_id', (string) $user->getAuthIdentifier())
            ->where('roles.guard_name', $guard)
            ->where('permissions.guard_name', $guard)
            ->where('permissions.name', $ability)
            ->exists();
    }

    /** @return list<string> */
    public function effectivePermissionNames(Authenticatable $user): array
    {
        $registry = app(PermissionRegistry::class);
        if ($this->hasSuperAdmin($user)) {
            return array_keys($registry->all());
        }

        $guard = app(HostUserModelResolver::class)->guardName();

        return DB::table('master_core_model_has_roles as assignments')
            ->join('master_core_roles as roles', 'roles.id', '=', 'assignments.role_id')
            ->join('master_core_role_has_permissions as grants', 'grants.role_id', '=', 'roles.id')
            ->join('master_core_permissions as permissions', 'permissions.id', '=', 'grants.permission_id')
            ->where('assignments.model_type', $this->modelType($user))
            ->where('assignments.model_id', (string) $user->getAuthIdentifier())
            ->where('roles.guard_name', $guard)
            ->where('permissions.guard_name', $guard)
            ->orderBy('permissions.name')
            ->distinct()
            ->pluck('permissions.name')
            ->filter(fn (string $name): bool => $registry->definition($name) !== null)
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function effectivePermissionIds(Authenticatable $user): array
    {
        $guard = app(HostUserModelResolver::class)->guardName();

        return DB::table('master_core_model_has_roles as assignments')
            ->join('master_core_roles as roles', 'roles.id', '=', 'assignments.role_id')
            ->join('master_core_role_has_permissions as grants', 'grants.role_id', '=', 'roles.id')
            ->join('master_core_permissions as permissions', 'permissions.id', '=', 'grants.permission_id')
            ->where('assignments.model_type', $this->modelType($user))
            ->where('assignments.model_id', (string) $user->getAuthIdentifier())
            ->where('roles.guard_name', $guard)
            ->where('permissions.guard_name', $guard)
            ->distinct()
            ->pluck('permissions.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @return Builder */
    private function baseUserRoles(Authenticatable $user)
    {
        $guard = app(HostUserModelResolver::class)->guardName();

        return DB::table('master_core_model_has_roles as assignments')
            ->join('master_core_roles', 'master_core_roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $this->modelType($user))
            ->where('assignments.model_id', (string) $user->getAuthIdentifier())
            ->where('master_core_roles.guard_name', $guard);
    }

    private function modelType(Authenticatable $user): string
    {
        return $user instanceof Model ? $user->getMorphClass() : $user::class;
    }
}
