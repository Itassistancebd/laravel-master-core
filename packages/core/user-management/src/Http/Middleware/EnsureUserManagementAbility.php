<?php

namespace MasterCore\UserManagement\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserManagementAbility
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $specific = config('master-core-user-management.abilities.'.$action);
        $manage = config('master-core-user-management.abilities.manage');
        $abilities = array_values(array_filter([$specific, $manage], 'is_string'));

        if ($request->user() === null || $abilities === [] || ! Gate::forUser($request->user())->any($abilities)) {
            throw new AuthorizationException('You are not authorized to manage users.');
        }

        return $next($request);
    }
}
