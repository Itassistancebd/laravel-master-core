<?php

namespace MasterCore\UserManagement\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\UploadedFile;
use MasterCore\UserManagement\Support\UserCsvAuthorization;
use Tests\TestCase;

class UserCsvAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_gate_authorizes_csv_abilities_without_changing_role_grants(): void
    {
        $actor = User::factory()->create();
        $now = now();
        $roleId = DB::table('master_core_roles')->insertGetId([
            'name' => 'Core Super Admin',
            'role_key' => 'core-super-admin',
            'guard_name' => 'web',
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('master_core_model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => $actor->getMorphClass(),
            'model_id' => (string) $actor->getKey(),
        ]);

        $authorization = app(UserCsvAuthorization::class);
        $this->assertTrue($authorization->allows($actor, 'export-users'));
        $this->assertTrue($authorization->allows($actor, 'import-users'));
        $this->assertDatabaseCount('master_core_role_has_permissions', 0);
        $this->actingAs($actor)->getJson('/api/v1/users/capabilities')
            ->assertOk()
            ->assertJsonPath('data.create', true)
            ->assertJsonPath('data.export', true)
            ->assertJsonPath('data.import', true);

        $ordinary = User::factory()->create();
        $this->assertFalse($authorization->allows($ordinary, 'export-users'));
        $this->assertFalse($authorization->allows($ordinary, 'import-users'));

        foreach (['export-users', 'import-users'] as $permissionName) {
            $permissionId = DB::table('master_core_permissions')->insertGetId([
                'name' => $permissionName,
                'guard_name' => 'web',
                'group_name' => 'User Management',
                'description' => 'Test permission',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('master_core_role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        $this->assertTrue($authorization->allows($actor, 'export-users'));
        $this->assertTrue($authorization->allows($actor, 'import-users'));
    }

    public function test_super_admin_gate_authorizes_create_users_for_import_confirmation(): void
    {
        $actor = User::factory()->create();
        $roleId = DB::table('master_core_roles')->insertGetId([
            'name' => 'Core Super Admin', 'role_key' => 'core-super-admin', 'guard_name' => 'web', 'is_system' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('master_core_model_has_roles')->insert([
            'role_id' => $roleId, 'model_type' => $actor->getMorphClass(), 'model_id' => (string) $actor->getKey(),
        ]);
        Gate::define('import-users', static fn () => true);

        $this->assertFalse(Gate::has('create-users'));
        $file = UploadedFile::fake()->createWithContent('users.csv', "name,email\r\nInactive Import,imported@example.test\r\n");
        $preview = $this->actingAs($actor)->postJson('/api/v1/users/import/preview', ['file' => $file])->assertOk();

        $this->postJson('/api/v1/users/import', [
            'file' => $file,
            'preview_token' => $preview->json('data.preview_token'),
        ])->assertOk()->assertJsonPath('data.imported', 1);

        $this->assertDatabaseHas('users', ['email' => 'imported@example.test', 'is_active' => false]);
        $this->assertDatabaseCount('master_core_role_has_permissions', 0);
    }
}
