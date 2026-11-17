<?php

namespace MasterCore\RolesPermissions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MasterCore\RolesPermissions\Support\PermissionRegistry;

final class CurrentPermissionsController
{
    public function __invoke(Request $request, PermissionRegistry $registry): JsonResponse
    {
        $user = $request->user();
        $allowed = collect(array_keys($registry->all()))
            ->filter(static fn (string $ability): bool => Gate::forUser($user)->allows($ability))
            ->values();

        return response()->json([
            'success' => true,
            'data' => ['permissions' => $allowed],
        ]);
    }
}
