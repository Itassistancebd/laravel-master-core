<?php

use Illuminate\Support\Facades\Route;
use MasterCore\AuditLogs\Http\Controllers\AuditEventController;

Route::middleware(['api', 'auth:sanctum', 'can:view-audit-logs'])
    ->prefix('audit-logs')
    ->name('master-core.audit-logs.')
    ->group(function (): void {
        Route::get('/', [AuditEventController::class, 'index'])->name('index');
        Route::get('/{event}', [AuditEventController::class, 'show'])->where('event', '[0-9A-HJKMNP-TV-Z]{26}')->name('show');
    });
