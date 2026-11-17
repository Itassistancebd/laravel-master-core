<?php

use Illuminate\Support\Facades\Route;
use MasterCore\Foundation\Http\Controllers\CoreHealthController;

Route::get('/health', CoreHealthController::class)
    ->name('master-core.core.health');
