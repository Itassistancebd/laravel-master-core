<?php

namespace MasterCore\UserManagement\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use MasterCore\RolesPermissions\Support\RoleAssignmentResolver;

final class UserCsvAuthorization
{
    public function allows(Authenticatable $actor, string $ability): bool
    {
        // Preserve an explicit host Gate in compatibility mode. Otherwise require a
        // stored grant so registering a new export/import ability does not silently
        // widen access through the existing implicit Super Admin bypass.
        if (Gate::has($ability)) {
            return Gate::forUser($actor)->allows($ability);
        }

        if (class_exists(RoleAssignmentResolver::class)) {
            $assignments = app(RoleAssignmentResolver::class);
            if ($assignments->hasSuperAdmin($actor)) {
                // Delegate the Super Admin decision to the project's Gate so account
                // eligibility and any explicit host Gate remain authoritative.
                return Gate::forUser($actor)->allows($ability);
            }

            return $assignments->hasExplicitPermission($actor, $ability);
        }

        return false;
    }
}
