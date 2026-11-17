<?php

namespace MasterCore\RolesPermissions\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use MasterCore\RolesPermissions\Models\Role;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use MasterCore\RolesPermissions\Support\RoleAssignmentResolver;
use MasterCore\RolesPermissions\Support\RolesPermissionsUserSafetyPolicy;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class UserRoleAssignmentManager
{
    /** Replace a user's roles without allowing the operator to expand their own access. */
    public function sync(Model $target, array $roleIds, Authenticatable $actor): void
    {
        $guard = app(HostUserModelResolver::class)->guardName();
        $ids = array_values(array_unique(array_map('intval', $roleIds)));
        $assignmentResolver = app(RoleAssignmentResolver::class);
        DB::transaction(function () use ($target, $ids, $actor, $assignmentResolver, $guard): void {
            // Lock and re-fetch the host account before checking status or changing its links.
            // The row lock serializes this write with concurrent status changes on supported databases.
            $lockedTarget = $this->lockTarget($target);
            $targetType = $lockedTarget->getMorphClass();
            $targetId = (string) $lockedTarget->getKey();

            // Acquire the shared administrator mutex before any ordinary assignment reads. Under
            // MySQL REPEATABLE READ, doing those reads first could preserve a stale snapshot while waiting.
            $superRole = Role::query()->where('guard_name', $guard)->where('role_key', 'core-super-admin')->lockForUpdate()->first();
            $currentRoleIds = $this->roleIdsFor($lockedTarget);
            $addingRoles = array_diff($ids, $currentRoleIds);
            if ($addingRoles && (! $this->isActive($lockedTarget) || $this->isTrashed($lockedTarget))) {
                throw new AccessDeniedHttpException('Roles cannot be assigned to an inactive or trashed user.');
            }

            $hasSuperAdmin = $superRole !== null && DB::table('master_core_model_has_roles')
                ->where('role_id', $superRole->getKey())->where('model_type', $targetType)->where('model_id', $targetId)->exists();
            $keepsSuperAdmin = $superRole !== null && in_array((int) $superRole->getKey(), $ids, true);

            // Recheck inside the lock so a concurrent removal cannot turn a stale API snapshot into a new grant.
            if ($keepsSuperAdmin && ! $hasSuperAdmin) {
                throw new AccessDeniedHttpException('The Super Admin role can only be assigned through the console provisioning command.');
            }

            if ($hasSuperAdmin && ! $keepsSuperAdmin) {
                if ((string) $actor->getAuthIdentifier() === $targetId) {
                    throw new AccessDeniedHttpException('You cannot remove the Super Admin role from your own account.');
                }
                $this->assertLastSuperAdminSafe($actor, $lockedTarget, 'remove-super-admin');
            }

            if ($hasSuperAdmin && ! $assignmentResolver->hasSuperAdmin($actor)) {
                throw new AccessDeniedHttpException('Only a Super Admin can change a Super Admin assignment.');
            }

            $roles = Role::query()->where('guard_name', $guard)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($roles->count() !== count($ids)) {
                throw new ConflictHttpException('One or more roles are unavailable for this guard.');
            }

            foreach ($roles as $role) {
                if ($role->role_key === 'core-super-admin' && ! in_array((int) $role->getKey(), $currentRoleIds, true)) {
                    throw new AccessDeniedHttpException('The Super Admin role can only be assigned through the console provisioning command.');
                }
            }

            $grantableIds = $assignmentResolver->effectivePermissionIds($actor);
            if (! $assignmentResolver->hasSuperAdmin($actor)) {
                foreach ($roles as $role) {
                    $rolePermissions = $role->permissions()->pluck('master_core_permissions.id')->map(static fn ($id): int => (int) $id)->all();
                    if (array_diff($rolePermissions, $grantableIds)) {
                        throw new AccessDeniedHttpException('You cannot assign a role that grants permissions outside your own effective access.');
                    }
                }
            }

            DB::table('master_core_model_has_roles')
                ->where('model_type', $targetType)->where('model_id', $targetId)->delete();
            foreach ($ids as $roleId) {
                DB::table('master_core_model_has_roles')->insert([
                    'role_id' => $roleId,
                    'model_type' => $targetType,
                    'model_id' => $targetId,
                ]);
            }
            $roleKeys = Role::query()->whereIn('id', $ids)->pluck('role_key')->all();
            $removedRoleKeys = Role::query()->whereIn('id', $currentRoleIds)->whereNotIn('id', $ids)->pluck('role_key')->all();
            event('master-core.audit.record.v1', [[
                'event_name' => 'user.roles_synced',
                'actor' => $actor,
                'subject' => $lockedTarget,
                'metadata' => ['assigned_roles' => $roleKeys, 'removed_roles' => $removedRoleKeys],
            ]]);
        });
    }

    private function lockTarget(Model $target): Model
    {
        $query = app(HostUserModelResolver::class)->query();
        if (method_exists($query->getModel(), 'trashed')) {
            $query->withTrashed();
        }

        return $query->lockForUpdate()->findOrFail($target->getKey());
    }

    private function isActive(Model $user): bool
    {
        return ! in_array($user->getAttribute('is_active'), [false, 0, '0'], true);
    }

    private function isTrashed(Model $user): bool
    {
        return method_exists($user, 'trashed') && $user->trashed();
    }

    /** @return list<int> */
    public function roleIdsFor(Model $user): array
    {
        return DB::table('master_core_model_has_roles as assignments')
            ->join('master_core_roles as roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $user->getMorphClass())
            ->where('assignments.model_id', (string) $user->getKey())
            ->where('roles.guard_name', app(HostUserModelResolver::class)->guardName())
            ->orderBy('roles.name')->pluck('roles.id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function assertLastSuperAdminSafe(Authenticatable $actor, Model $target, string $operation): void
    {
        $policy = app(RolesPermissionsUserSafetyPolicy::class);
        if (! $policy->allows($actor, $target, $operation)) {
            throw new AccessDeniedHttpException('The last active Super Admin must retain protected access.');
        }
    }
}
