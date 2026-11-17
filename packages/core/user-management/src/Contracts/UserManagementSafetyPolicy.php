<?php

namespace MasterCore\UserManagement\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

interface UserManagementSafetyPolicy
{
    /**
     * Decide whether the actor may perform a destructive lifecycle operation.
     *
     * The host policy must protect the last active administrator. Returning false
     * denies the operation; no roles or administrator status are inferred here.
     */
    public function allows(Authenticatable $actor, Model $target, string $operation): bool;
}
