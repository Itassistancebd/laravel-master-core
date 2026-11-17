<?php

namespace MasterCore\Auth\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use MasterCore\Auth\Contracts\LoginEligibility;

final class AllowAllLoginEligibility implements LoginEligibility
{
    public function allows(Authenticatable $user): bool
    {
        // Keep Authentication behavior unchanged when no account-management module is installed.
        return true;
    }
}
