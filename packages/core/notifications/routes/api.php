<?php

use Illuminate\Support\Facades\Route;
use MasterCore\Notifications\Http\Controllers\NotificationInboxController;

Route::prefix('notifications')->middleware(['api', 'auth:sanctum'])->name('master-core.notifications.')->group(function (): void {
    Route::get('/unread-count', [NotificationInboxController::class, 'unreadCount'])->name('unread-count');
    Route::get('/', [NotificationInboxController::class, 'index'])->name('index');
    Route::post('/read-all', [NotificationInboxController::class, 'markAllRead'])->middleware('throttle:30,1')->name('read-all');
    Route::patch('/{notification}/read', [NotificationInboxController::class, 'markRead'])
        ->where('notification', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:60,1')->name('read');
    Route::patch('/{notification}/unread', [NotificationInboxController::class, 'markUnread'])
        ->where('notification', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:60,1')->name('unread');
});
