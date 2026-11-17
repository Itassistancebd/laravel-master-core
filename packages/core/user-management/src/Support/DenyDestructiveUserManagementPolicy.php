<?php

namespace MasterCore\UserManagement\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MasterCore\UserManagement\Contracts\UserManagementSafetyPolicy;

final class DenyDestructiveUserManagementPolicy implements UserManagementSafetyPolicy
{
    public function allows(Authenticatable $actor, Model $target, string $operation): bool
    {
        // No roles exist yet, so it is unsafe to guess which account is the last administrator.
        return false;
    }
}
