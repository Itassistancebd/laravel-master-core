<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('master_core_notifications')) {
            throw new RuntimeException("Notifications migration will not take ownership of an existing 'master_core_notifications' table.");
        }

        Schema::create('master_core_notifications', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('notifiable_type', 191);
            $table->string('notifiable_id', 191);
            $table->string('event_key', 80);
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['notifiable_type', 'notifiable_id', 'created_at', 'id'], 'mc_notifications_timeline_idx');
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'mc_notifications_unread_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('master_core_notifications') && DB::table('master_core_notifications')->exists()) {
            throw new RuntimeException('Cannot roll back Notifications while inbox rows exist; preserve or explicitly purge them first.');
        }

        Schema::dropIfExists('master_core_notifications');
    }
};
