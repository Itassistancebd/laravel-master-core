<?php

namespace MasterCore\Auth\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface LoginEligibility
{
    /** Allow an optional host module to reject an otherwise valid login. */
    public function allows(Authenticatable $user): bool;
}
