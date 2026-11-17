<?php

namespace MasterCore\UserManagement\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class UserManagementMigrationTest extends TestCase
{
    public function test_lifecycle_migration_is_additive_and_refuses_lossy_rollback(): void
    {
        $originalConnection = config('database.default');
        config(['database.connections.phase5_schema_test' => array_merge(
            config('database.connections.sqlite'),
            ['database' => ':memory:', 'prefix' => ''],
        )]);
        config(['database.default' => 'phase5_schema_test']);
        DB::purge('phase5_schema_test');

        try {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamps();
            });
            config(['master-core-user-management.table' => 'users']);
            $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_15_000000_add_account_lifecycle_columns_to_users_table.php';
            $migration->up();

            $this->assertTrue(Schema::hasColumn('users', 'is_active'));
            $this->assertTrue(Schema::hasColumn('users', 'deleted_at'));
            DB::table('users')->insert(['name' => 'Existing', 'email' => 'existing@example.test', 'created_at' => now(), 'updated_at' => now()]);
            $this->assertSame(1, (int) DB::table('users')->value('is_active'));

            DB::table('users')->update(['is_active' => false]);
            try {
                $migration->down();
                $this->fail('Rollback should preserve inactive account state.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('inactive or trashed', $exception->getMessage());
            }
            $this->assertTrue(Schema::hasColumn('users', 'deleted_at'));

            DB::table('users')->update(['is_active' => true]);
            $migration->down();
            $this->assertFalse(Schema::hasColumn('users', 'is_active'));
            $this->assertFalse(Schema::hasColumn('users', 'deleted_at'));
            $this->assertTrue(Schema::hasColumn('users', 'email'));
        } finally {
            config(['database.default' => $originalConnection]);
            DB::purge('phase5_schema_test');
        }
    }
}
