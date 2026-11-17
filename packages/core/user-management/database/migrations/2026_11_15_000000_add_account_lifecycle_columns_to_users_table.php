<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function tableName(): string
    {
        return (string) config('master-core-user-management.table', 'users');
    }

    public function up(): void
    {
        $tableName = $this->tableName();
        if (! Schema::hasTable($tableName)) {
            throw new RuntimeException("User Management migration requires the host user table '{$tableName}'.");
        }

        $hasActive = Schema::hasColumn($tableName, 'is_active');
        $hasDeletedAt = Schema::hasColumn($tableName, 'deleted_at');
        if ($hasActive || $hasDeletedAt) {
            throw new RuntimeException("User Management lifecycle columns already exist on '{$tableName}'; configure a host schema adapter instead.");
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index('users_is_active_index');
            $table->softDeletes('deleted_at', precision: 0);
            $table->index('deleted_at', 'users_deleted_at_index');
        });
    }

    public function down(): void
    {
        $tableName = $this->tableName();
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, 'is_active') || ! Schema::hasColumn($tableName, 'deleted_at')) {
            return;
        }

        $hasProtectedState = DB::table($tableName)
            ->where('is_active', false)
            ->orWhereNotNull('deleted_at')
            ->exists();

        if ($hasProtectedState) {
            throw new RuntimeException('Cannot remove User Management columns while inactive or trashed user state exists.');
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropIndex('users_is_active_index');
            $table->dropIndex('users_deleted_at_index');
            $table->dropColumn(['is_active', 'deleted_at']);
        });
    }
};
