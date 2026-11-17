<?php

use Illuminate\Support\Facades\Route;
use MasterCore\RolesPermissions\Http\Controllers\CurrentPermissionsController;
use MasterCore\RolesPermissions\Http\Controllers\PermissionsController;
use MasterCore\RolesPermissions\Http\Controllers\RolesController;
use MasterCore\RolesPermissions\Http\Controllers\UserRoleAssignmentsController;

Route::prefix('roles-permissions')->middleware(['api', 'auth:sanctum'])->group(function (): void {
    Route::get('/permissions/me', CurrentPermissionsController::class)
        ->name('master-core.roles-permissions.me');

    Route::get('/roles', [RolesController::class, 'index'])->middleware('can:view-roles')->name('master-core.roles.index');
    Route::post('/roles', [RolesController::class, 'store'])->middleware('can:create-roles')->name('master-core.roles.store');
    Route::post('/roles/bulk-delete', [RolesController::class, 'bulkDestroy'])->middleware('can:delete-roles')->name('master-core.roles.bulk-destroy');
    Route::get('/roles/{role}', [RolesController::class, 'show'])->middleware('can:view-roles')->name('master-core.roles.show');
    Route::put('/roles/{role}', [RolesController::class, 'update'])->middleware('can:edit-roles')->name('master-core.roles.update');
    Route::delete('/roles/{role}', [RolesController::class, 'destroy'])->middleware('can:delete-roles')->name('master-core.roles.destroy');
    Route::put('/roles/{role}/permissions', [RolesController::class, 'syncPermissions'])
        ->middleware('can:edit-roles')->name('master-core.roles.permissions.sync');

    Route::get('/permissions', [PermissionsController::class, 'index'])->middleware('can:view-permissions')->name('master-core.permissions.index');
    Route::get('/permissions/available', [PermissionsController::class, 'available'])->middleware('can:manage-permissions')->name('master-core.permissions.available');
    Route::post('/permissions', [PermissionsController::class, 'store'])->middleware('can:manage-permissions')->name('master-core.permissions.store');
    Route::get('/permissions/{permission}', [PermissionsController::class, 'show'])->middleware('can:view-permissions')->name('master-core.permissions.show');
    Route::put('/permissions/{permission}', [PermissionsController::class, 'update'])->middleware('can:manage-permissions')->name('master-core.permissions.update');
    Route::delete('/permissions/{permission}', [PermissionsController::class, 'destroy'])->middleware('can:manage-permissions')->name('master-core.permissions.destroy');

    Route::get('/users/{user}/roles', [UserRoleAssignmentsController::class, 'show'])
        ->middleware('can:manage-user-roles')->name('master-core.user-roles.show');
    Route::put('/users/{user}/roles', [UserRoleAssignmentsController::class, 'sync'])
        ->middleware('can:manage-user-roles')->name('master-core.user-roles.sync');
});
