<?php

use Illuminate\Support\Facades\Route;
use MasterCore\SystemHealth\Http\Controllers\SystemHealthController;

Route::middleware(['api', 'auth:sanctum', 'can:view-system-health', 'throttle:10,1'])
    ->prefix('system-health')
    ->name('master-core.system-health.')
    ->group(function (): void {
        Route::get('/', SystemHealthController::class)->name('show');
    });
