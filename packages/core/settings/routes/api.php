<?php

use Illuminate\Support\Facades\Route;
use MasterCore\Settings\Http\Controllers\SettingsController;

Route::prefix('settings')->group(function (): void {
    // Keep the fixed public path ahead of the dynamic group route.
    Route::middleware('api')->get('/public', [SettingsController::class, 'publicSettings'])
        ->name('master-core.settings.public');

    Route::middleware(['api', 'auth:sanctum'])->group(function (): void {
        $viewAbility = (string) config('master-core-settings.authorization.view_ability', 'view-settings');
        $manageAbility = (string) config('master-core-settings.authorization.manage_ability', 'manage-settings');

        Route::get('/', [SettingsController::class, 'index'])
            ->middleware('can:'.$viewAbility)
            ->name('master-core.settings.index');
        Route::get('/{group}', [SettingsController::class, 'show'])
            ->middleware('can:'.$viewAbility)
            ->where('group', '[a-z][a-z0-9_-]*')
            ->name('master-core.settings.show');
        Route::put('/{group}', [SettingsController::class, 'update'])
            ->middleware('can:'.$manageAbility)
            ->where('group', '[a-z][a-z0-9_-]*')
            ->name('master-core.settings.update');
        Route::post('/{group}/reset', [SettingsController::class, 'reset'])
            ->middleware('can:'.$manageAbility)
            ->where('group', '[a-z][a-z0-9_-]*')
            ->name('master-core.settings.reset');
        Route::post('/branding/assets', [SettingsController::class, 'uploadBrandAsset'])
            ->middleware('can:'.$manageAbility)
            ->name('master-core.settings.branding.upload');
    });
});
