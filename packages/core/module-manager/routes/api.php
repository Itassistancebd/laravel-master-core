<?php

use Illuminate\Support\Facades\Route;
use MasterCore\ModuleManager\Http\Controllers\ModuleCatalogController;

Route::middleware(['api', 'auth:sanctum', 'can:view-modules'])
    ->prefix('modules')
    ->name('master-core.modules.')
    ->group(function (): void {
        Route::get('/', [ModuleCatalogController::class, 'index'])->name('index');
        Route::get('/{module}', [ModuleCatalogController::class, 'show'])
            ->where('module', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('show');
    });
