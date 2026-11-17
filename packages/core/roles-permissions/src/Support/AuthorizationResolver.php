<?php

namespace MasterCore\RolesPermissions\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Gate;

final class AuthorizationResolver
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly RoleAssignmentResolver $assignments,
    ) {}

    /**
     * Bridge registered abilities into Laravel Gate without overriding host Gates
     * or contextual Policies in Compatibility mode.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function resolve(?Authenticatable $user, string $ability, array $arguments): ?bool
    {
        $permission = $this->registry->definition($ability);
        if ($permission === null) {
            return null;
        }

        $strict = config('master-core-roles-permissions.gate_mode') === 'strict';
        if ($user === null) {
            return $strict ? false : null;
        }
        // Account eligibility is a hard prerequisite in every rollout mode.
        if (! $this->isEligible($user)) {
            return false;
        }

        $explicitGate = Gate::has($ability);
        $policy = isset($arguments[0]) && is_object($arguments[0])
            ? Gate::getPolicyFor($arguments[0])
            : null;

        if (! $strict && ($explicitGate || $policy !== null)) {
            return null;
        }

        // Strict mode fails closed when a host Gate remains for an ability that Core owns.
        if ($strict && $explicitGate) {
            return false;
        }

        $granted = $this->assignments->hasSuperAdmin($user)
            || $this->assignments->allows($user, $this->registry->canonicalName($ability));

        if (! $granted) {
            return $strict ? false : null;
        }

        // A role grant is an ability-level prerequisite; a registered Policy remains an additional check.
        if ($policy !== null) {
            return null;
        }

        return true;
    }

    private function isEligible(Authenticatable $user): bool
    {
        if ($user instanceof Model) {
            if (in_array(SoftDeletes::class, class_uses_recursive($user), true) && method_exists($user, 'trashed') && $user->trashed()) {
                return false;
            }

            if (in_array($user->getAttribute('is_active'), [false, 0, '0'], true)) {
                return false;
            }
        }

        return true;
    }
}
