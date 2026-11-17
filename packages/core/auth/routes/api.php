<?php

use Illuminate\Support\Facades\Route;
use MasterCore\Auth\Http\Controllers\AuthenticatedUserController;

Route::prefix(config('master-core-auth.api_prefix', 'api/v1/auth'))
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        Route::get('/user', [AuthenticatedUserController::class, 'show'])->name('master-core.auth.user');
        Route::get('/sessions', [AuthenticatedUserController::class, 'sessions'])->name('master-core.auth.sessions.index');
        Route::delete('/sessions/{session}', [AuthenticatedUserController::class, 'revokeSession'])
            ->where('session', '[a-f0-9]{64}')
            ->name('master-core.auth.sessions.destroy');
    });
