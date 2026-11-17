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
    Route::get('/categories', [App\Modules\Categories\Http\Controllers\CategoryController::class, 'index'])->middleware('can:categories.view');
    Route::get('/categories/{category}', [App\Modules\Categories\Http\Controllers\CategoryController::class, 'show'])->middleware('can:categories.view');
    Route::post('/categories', [App\Modules\Categories\Http\Controllers\CategoryController::class, 'store'])->middleware('can:categories.create');
    Route::match(['put', 'patch'], '/categories/{category}', [App\Modules\Categories\Http\Controllers\CategoryController::class, 'update'])->middleware('can:categories.update');
    Route::delete('/categories/{category}', [App\Modules\Categories\Http\Controllers\CategoryController::class, 'destroy'])->middleware('can:categories.delete');
});
