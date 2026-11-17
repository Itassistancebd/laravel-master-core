<?php

use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    if (config('master-core-auth.register_application_route', true)) {
        // The SPA shell stays behind the host application's existing session guard.
        $path = trim((string) config('master-core-auth.application_path', '/app'), '/');

        Route::get('/'.$path.'/{path?}', fn () => view('master-core-auth::app', ['page' => 'application']))
            ->where('path', '.*')
            ->middleware('auth:'.config('fortify.guard', 'web'))
            ->name('master-core.auth.application');
    }
});
