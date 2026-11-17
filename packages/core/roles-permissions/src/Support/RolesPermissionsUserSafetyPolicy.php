<?php

namespace MasterCore\RolesPermissions\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use MasterCore\RolesPermissions\Models\Role;
use MasterCore\UserManagement\Contracts\UserManagementSafetyPolicy;

final class RolesPermissionsUserSafetyPolicy implements UserManagementSafetyPolicy
{
    public function __construct(private readonly ?UserManagementSafetyPolicy $hostPolicy = null) {}

    public function allows(Authenticatable $actor, Model $target, string $operation): bool
    {
        // Use the same host-user-first lock order as role assignment and provisioning. Even when
        // the target is not an administrator yet, this serializes lifecycle changes with a new grant.
        $target = $this->lockTarget($target);
        if ((string) $actor->getAuthIdentifier() === (string) $target->getKey()
            && in_array($operation, ['deactivate', 'delete', 'force-delete', 'remove-super-admin'], true)) {
            return false;
        }

        $guard = app(HostUserModelResolver::class)->guardName();
        $role = Role::query()->where('guard_name', $guard)->where('role_key', 'core-super-admin')->lockForUpdate()->first();
        if ($role === null || ! $this->hasSuperAdminRole($target, $role->getKey())) {
            return $this->hostPolicy?->allows($actor, $target, $operation) ?? false;
        }

        $assignmentIds = DB::table('master_core_model_has_roles')
            ->where('role_id', $role->getKey())
            ->where('model_type', $target->getMorphClass())
            ->pluck('model_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $users = app(HostUserModelResolver::class)->query();
        if (method_exists($users->getModel(), 'trashed')) {
            $users->withTrashed();
        }
        $activeCount = $users->whereIn($users->getModel()->getKeyName(), $assignmentIds)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->count();

        if ($activeCount <= 1 && in_array($operation, ['deactivate', 'delete', 'force-delete', 'remove-super-admin'], true)) {
            return false;
        }

        // Role removal has its own locked last-admin rule; ordinary lifecycle actions still require the host policy.
        return $this->hostPolicy?->allows($actor, $target, $operation) ?? ($operation === 'remove-super-admin');
    }

    private function lockTarget(Model $target): Model
    {
        $query = app(HostUserModelResolver::class)->query();
        if (method_exists($query->getModel(), 'trashed')) {
            $query->withTrashed();
        }

        return $query->lockForUpdate()->findOrFail($target->getKey());
    }

    private function hasSuperAdminRole(Model $target, int|string $roleId): bool
    {
        return DB::table('master_core_model_has_roles')
            ->where('role_id', $roleId)
            ->where('model_type', $target->getMorphClass())
            ->where('model_id', (string) $target->getKey())
            ->exists();
    }
}
