<?php

namespace MasterCore\RolesPermissions\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MasterCore\RolesPermissions\Models\Role;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use MasterCore\RolesPermissions\Support\PermissionRegistry;
use MasterCore\RolesPermissions\Support\RoleAssignmentResolver;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RoleManager
{
    public function create(string $name, ?Authenticatable $actor = null): Role
    {
        $role = DB::transaction(function () use ($name, $actor): Role {
            $role = new Role;
            $role->forceFill([
                'name' => $name,
                'role_key' => 'custom-'.Str::uuid(),
                'guard_name' => app(HostUserModelResolver::class)->guardName(),
                'is_system' => false,
            ])->save();
            $this->audit('role.created', $actor, $role, ['role_key' => $role->role_key]);

            return $role;
        });

        return $role->refresh();
    }

    public function rename(Role $role, string $name, ?Authenticatable $actor = null): Role
    {
        $this->assertOrdinaryRole($role);
        DB::transaction(function () use ($role, $name, $actor): void {
            $role->forceFill(['name' => $name])->save();
            $this->audit('role.renamed', $actor, $role, ['role_key' => $role->role_key]);
        });

        return $role->refresh();
    }

    public function delete(Role $role, ?Authenticatable $actor = null): void
    {
        $this->assertOrdinaryRole($role);

        DB::transaction(function () use ($role, $actor): void {
            $locked = Role::query()->lockForUpdate()->findOrFail($role->getKey());
            if (DB::table('master_core_model_has_roles')->where('role_id', $locked->getKey())->exists()
                || DB::table('master_core_role_has_permissions')->where('role_id', $locked->getKey())->exists()) {
                throw new ConflictHttpException('Remove the role assignments and permissions before deleting this role.');
            }
            $locked->delete();
            $this->audit('role.deleted', $actor, $locked, ['role_key' => $locked->role_key]);
        });
    }

    /** Replace role grants atomically and prevent the operator expanding their own access. */
    public function syncPermissions(Role $role, array $permissionIds, Authenticatable $actor): void
    {
        $this->assertOrdinaryRole($role);
        $guard = app(HostUserModelResolver::class)->guardName();
        $ids = array_values(array_unique(array_map('intval', $permissionIds)));
        $permissions = DB::table('master_core_permissions')
            ->where('guard_name', $guard)
            ->whereIn('id', $ids)
            ->get(['id', 'name']);

        if ($permissions->count() !== count($ids)) {
            throw new ConflictHttpException('One or more permissions are unavailable for this guard.');
        }

        $registry = app(PermissionRegistry::class);
        if ($permissions->contains(static fn (object $permission): bool => $registry->definition((string) $permission->name) === null)) {
            throw new ConflictHttpException('Only code-registered abilities can be granted to roles.');
        }

        $assignments = app(RoleAssignmentResolver::class);
        $actorCanGrantAll = $assignments->hasSuperAdmin($actor)
            || ! array_diff($ids, $assignments->effectivePermissionIds($actor));
        if (! $actorCanGrantAll) {
            throw new AccessDeniedHttpException('You cannot grant permissions outside your own effective access.');
        }

        DB::transaction(function () use ($role, $ids, $actor, $assignments, $guard, $permissions): void {
            $locked = Role::query()->where('guard_name', $guard)->lockForUpdate()->findOrFail($role->getKey());
            $previousNames = DB::table('master_core_role_has_permissions as links')
                ->join('master_core_permissions as permissions', 'permissions.id', '=', 'links.permission_id')
                ->where('links.role_id', $locked->getKey())->pluck('permissions.name')->all();
            $actorHasRole = DB::table('master_core_model_has_roles')
                ->where('role_id', $locked->getKey())
                ->where('model_type', $this->modelType($actor))
                ->where('model_id', (string) $actor->getAuthIdentifier())
                ->exists();

            if ($actorHasRole && ! $assignments->hasSuperAdmin($actor)) {
                $current = $assignments->effectivePermissionIds($actor);
                if (array_diff($ids, $current)) {
                    throw new AccessDeniedHttpException('Editing this role would expand your own effective access.');
                }
            }

            DB::table('master_core_role_has_permissions')->where('role_id', $locked->getKey())->delete();
            foreach ($ids as $permissionId) {
                DB::table('master_core_role_has_permissions')->insert([
                    'role_id' => $locked->getKey(),
                    'permission_id' => $permissionId,
                ]);
            }
            $this->audit('role.permissions_synced', $actor, $locked, [
                'role_key' => $locked->role_key,
                'granted_permissions' => array_values(array_diff($permissions->pluck('name')->all(), $previousNames)),
                'revoked_permissions' => array_values(array_diff($previousNames, $permissions->pluck('name')->all())),
            ]);
        });
    }

    private function assertOrdinaryRole(Role $role): void
    {
        if ($role->is_system || $role->role_key === 'core-super-admin') {
            throw new ConflictHttpException('System roles cannot be changed through ordinary role management.');
        }
    }

    private function modelType(Authenticatable $actor): string
    {
        return $actor instanceof Model ? $actor->getMorphClass() : $actor::class;
    }

    private function audit(string $name, ?Authenticatable $actor, Role $subject, array $metadata = []): void
    {
        event('master-core.audit.record.v1', [[
            'event_name' => $name, 'actor' => $actor, 'subject' => $subject, 'metadata' => $metadata,
        ]]);
    }
}
