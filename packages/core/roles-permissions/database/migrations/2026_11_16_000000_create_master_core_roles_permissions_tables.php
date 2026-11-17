<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'master_core_roles',
        'master_core_permissions',
        'master_core_role_has_permissions',
        'master_core_model_has_roles',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName)) {
                throw new RuntimeException("Roles & Permissions migration found an existing table '{$tableName}'. Review the collision before installing this package.");
            }
        }

        Schema::create('master_core_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('role_key', 64);
            $table->string('guard_name', 64);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['guard_name', 'name'], 'mc_roles_guard_name_unique');
            $table->unique(['guard_name', 'role_key'], 'mc_roles_guard_key_unique');
            $table->index(['guard_name', 'is_system'], 'mc_roles_guard_system_index');
        });

        Schema::create('master_core_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->string('guard_name', 64);
            $table->string('group_name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
            $table->unique(['guard_name', 'name'], 'mc_permissions_guard_name_unique');
            $table->index(['group_name', 'name'], 'mc_permissions_group_name_index');
        });

        Schema::create('master_core_role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id'], 'mc_role_permissions_primary');
            $table->index(['permission_id', 'role_id'], 'mc_role_permissions_reverse_index');
            $table->foreign('role_id', 'mc_role_permissions_role_fk')
                ->references('id')->on('master_core_roles')->restrictOnDelete();
            $table->foreign('permission_id', 'mc_role_permissions_permission_fk')
                ->references('id')->on('master_core_permissions')->restrictOnDelete();
        });

        Schema::create('master_core_model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type', 191);
            $table->string('model_id', 191);
            $table->primary(['role_id', 'model_type', 'model_id'], 'mc_model_roles_primary');
            $table->index(['model_type', 'model_id'], 'mc_model_roles_model_index');
            $table->foreign('role_id', 'mc_model_roles_role_fk')
                ->references('id')->on('master_core_roles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && DB::table($tableName)->exists()) {
                throw new RuntimeException("Cannot roll back Roles & Permissions while '{$tableName}' contains data. Export and review assignments before rollback.");
            }
        }

        Schema::dropIfExists('master_core_model_has_roles');
        Schema::dropIfExists('master_core_role_has_permissions');
        Schema::dropIfExists('master_core_permissions');
        Schema::dropIfExists('master_core_roles');
    }
};
