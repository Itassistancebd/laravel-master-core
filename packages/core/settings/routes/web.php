<?php

use Illuminate\Support\Facades\Route;
use MasterCore\Settings\Http\Controllers\SettingsPageController;

Route::middleware([
    'auth',
    'can:'.config('master-core-settings.authorization.view_ability', 'view-settings'),
])
    ->prefix('admin/settings')
    ->get('/', [SettingsPageController::class, '__invoke'])
    ->name('master-core.settings.page');
