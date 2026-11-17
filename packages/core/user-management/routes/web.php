<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:'.config('fortify.guard', 'web')])->group(function (): void {
    Route::get('/app/users/{path?}', fn () => view('master-core-user-management::app'))
        ->where('path', '.*')
        ->name('master-core.user-management.application');
});
