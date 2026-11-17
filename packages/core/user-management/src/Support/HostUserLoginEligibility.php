<?php

namespace MasterCore\UserManagement\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MasterCore\Auth\Contracts\LoginEligibility;

final class HostUserLoginEligibility implements LoginEligibility
{
    public function allows(Authenticatable $user): bool
    {
        if (! $user instanceof Model || ! $this->models->supportsSoftDeletes($user)) {
            return false;
        }

        return (bool) $user->getAttribute('is_active');
    }

    public function __construct(private readonly UserModelResolver $models) {}
}
