<?php

use Illuminate\Support\Facades\Route;
use MasterCore\UserManagement\Http\Controllers\UserManagementController;

Route::prefix('users')->middleware(['api', 'auth:sanctum'])->group(function (): void {
    Route::get('/capabilities', [UserManagementController::class, 'capabilities'])->name('master-core.user-management.capabilities');
    Route::get('/export.csv', [UserManagementController::class, 'export'])->name('master-core.user-management.export');
    Route::get('/import-template.csv', [UserManagementController::class, 'importTemplate'])->name('master-core.user-management.import-template');
    Route::post('/import/preview', [UserManagementController::class, 'previewImport'])->name('master-core.user-management.import.preview');
    Route::post('/import', [UserManagementController::class, 'import'])->name('master-core.user-management.import');
    Route::get('/trash', [UserManagementController::class, 'trash'])->name('master-core.user-management.trash');
    Route::post('/bulk/{operation}', [UserManagementController::class, 'bulk'])
        ->where('operation', 'activate|deactivate|trash|restore')
        ->name('master-core.user-management.bulk');
    Route::get('/', [UserManagementController::class, 'index'])->name('master-core.user-management.index');
    Route::post('/', [UserManagementController::class, 'store'])->name('master-core.user-management.store');
    Route::get('/{user}', [UserManagementController::class, 'show'])->name('master-core.user-management.show');
    Route::put('/{user}', [UserManagementController::class, 'update'])->name('master-core.user-management.update');
    Route::patch('/{user}/status', [UserManagementController::class, 'updateStatus'])->name('master-core.user-management.status');
    Route::delete('/{user}', [UserManagementController::class, 'destroy'])->name('master-core.user-management.destroy');
    Route::post('/{user}/restore', [UserManagementController::class, 'restore'])->name('master-core.user-management.restore');
    Route::delete('/{user}/force', [UserManagementController::class, 'forceDelete'])
        ->middleware(['web', 'password.confirm'])
        ->name('master-core.user-management.force-delete');
    Route::post('/{user}/password-reset', [UserManagementController::class, 'sendPasswordReset'])->name('master-core.user-management.password-reset');
    Route::post('/{user}/verification-notification', [UserManagementController::class, 'resendVerification'])->name('master-core.user-management.verification');
});
