<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

Route::get('/', function () {
    return view('welcome');
});

$apiPrefix = collect([
    trim((string) config('master-core.api_prefix', 'api'), '/'),
    trim((string) config('master-core.api_version', 'v1'), '/'),
    'core',
])->filter()->implode('/');

Route::prefix($apiPrefix)->middleware(['api', 'auth:sanctum'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('master-core.core.dashboard');
});
