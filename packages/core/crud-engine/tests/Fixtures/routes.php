<?php

use Illuminate\Support\Facades\Route;
use MasterCore\CrudEngine\Tests\Fixtures\FixtureAccountController;

Route::prefix('api/v1/crud-fixture/accounts')->middleware(['api', 'auth:sanctum'])->group(function (): void {
    Route::get('/', [FixtureAccountController::class, 'index']);
    Route::post('/', [FixtureAccountController::class, 'store']);
    Route::post('/bulk/trash', [FixtureAccountController::class, 'bulkTrash']);
    Route::get('/{id}', [FixtureAccountController::class, 'show']);
    Route::put('/{id}', [FixtureAccountController::class, 'update']);
    Route::delete('/{id}', [FixtureAccountController::class, 'destroy']);
    Route::post('/{id}/restore', [FixtureAccountController::class, 'restore']);
    Route::delete('/{id}/force', [FixtureAccountController::class, 'forceDelete']);
});
