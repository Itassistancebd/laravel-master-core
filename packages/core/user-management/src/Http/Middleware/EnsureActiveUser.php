<?php

namespace MasterCore\UserManagement\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        // Refresh the persisted flag so long-lived workers and tests cannot reuse a stale user instance.
        $freshUser = $user instanceof Model
            ? $user->newQuery()->find($user->getAuthIdentifier())
            : null;
        if ($freshUser !== null && (bool) $freshUser->getAttribute('is_active')) {
            return $next($request);
        }

        // A status change revokes database sessions immediately; this also rejects the next
        // request for cookie, file, Redis, and other session drivers.
        Auth::guard(config('fortify.guard', 'web'))->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect('/login');
    }
}
