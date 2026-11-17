<?php

namespace MasterCore\RolesPermissions\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use MasterCore\RolesPermissions\Actions\PermissionCatalogManager;
use MasterCore\RolesPermissions\Actions\RoleManager;
use MasterCore\RolesPermissions\Actions\SuperAdminProvisioner;
use MasterCore\RolesPermissions\Actions\UserRoleAssignmentManager;
use MasterCore\RolesPermissions\Models\Permission;
use MasterCore\RolesPermissions\Models\Role;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use MasterCore\RolesPermissions\Support\PermissionRegistry;
use MasterCore\UserManagement\Actions\UserLifecycleManager;
use MasterCore\UserManagement\Contracts\UserManagementSafetyPolicy;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class RolesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    public function test_package_migration_creates_only_the_four_approved_tables(): void
    {
        $this->assertDatabaseCount('master_core_roles', 0);
        $this->assertDatabaseCount('master_core_permissions', 0);
        $this->assertDatabaseCount('master_core_role_has_permissions', 0);
        $this->assertDatabaseCount('master_core_model_has_roles', 0);
        $this->assertDatabaseMissing('master_core_roles', ['role_key' => 'core-super-admin']);
    }

    public function test_registry_alias_maps_update_users_to_edit_users(): void
    {
        $registry = app(PermissionRegistry::class);

        $this->assertSame('edit-users', $registry->canonicalName('update-users'));
        $this->assertNotNull($registry->definition('update-users'));
        $this->assertNull($registry->definition('invented-ability'));
    }

    public function test_database_permission_cannot_be_materialized_unless_registered_in_code(): void
    {
        $this->expectException(ConflictHttpException::class);

        app(PermissionCatalogManager::class)->materialize('invented-ability');
    }

    public function test_registered_permission_materialization_is_idempotent_and_does_not_grant_it(): void
    {
        $actor = User::factory()->create();

        $first = app(PermissionCatalogManager::class)->materialize('view-users', $actor);
        $again = app(PermissionCatalogManager::class)->materialize('view-users', $actor);

        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertDatabaseCount('master_core_permissions', 1);
        $this->assertDatabaseCount('master_core_role_has_permissions', 0);
    }

    public function test_permission_materialization_requires_authorization_and_rejects_duplicates_and_unknown_abilities(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor)->postJson('/api/v1/roles-permissions/permissions', ['name' => 'view-users'])->assertForbidden();
        $this->assertDatabaseCount('master_core_permissions', 0);

        Gate::define('manage-permissions', static fn () => true);
        $created = $this->actingAs($actor)
            ->postJson('/api/v1/roles-permissions/permissions', ['name' => 'view-users'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'view-users');
        $id = $created->json('data.id');

        $this->postJson('/api/v1/roles-permissions/permissions', ['name' => 'view-users'])->assertUnprocessable();
        $this->postJson('/api/v1/roles-permissions/permissions', ['name' => 'made-up-ability'])->assertUnprocessable();

        $this->assertDatabaseCount('master_core_permissions', 1);
        $this->assertDatabaseCount('master_core_role_has_permissions', 0);
        $this->assertDatabaseHas('master_core_permissions', ['id' => $id, 'name' => 'view-users']);
    }

    public function test_compatibility_mode_keeps_an_explicit_host_gate_authoritative(): void
    {
        config(['master-core-roles-permissions.gate_mode' => 'compatibility']);
        Gate::define('edit-users', static fn () => false);
        $user = User::factory()->create();
        $this->grant($user, 'edit-users');

        $this->assertFalse(Gate::forUser($user)->allows('edit-users'));
    }

    public function test_role_grant_authorizes_registered_ability_when_host_has_no_gate(): void
    {
        config(['master-core-roles-permissions.gate_mode' => 'compatibility']);
        $user = User::factory()->create();
        $this->grant($user, 'view-roles');
        $this->grant($user, 'edit-users');

        $this->assertTrue(Gate::forUser($user)->allows('view-roles'));
        $this->assertTrue(Gate::forUser($user)->allows('update-users'));
        $this->assertFalse(Gate::forUser($user)->allows('unregistered-ability'));
    }

    public function test_host_gate_compatibility_decisions_are_reported_to_the_frontend(): void
    {
        Gate::define('view-roles', static fn () => true);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/roles-permissions/permissions/me')
            ->assertOk();

        $this->assertContains('view-roles', $response->json('data.permissions'));
    }

    public function test_phase_five_manage_users_gate_remains_a_compatibility_fallback(): void
    {
        config(['master-core-roles-permissions.gate_mode' => 'compatibility']);
        Gate::define('manage-users', static fn () => true);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/users')->assertOk();
    }

    public function test_strict_mode_does_not_use_the_phase_five_manage_users_fallback(): void
    {
        config([
            'master-core-roles-permissions.gate_mode' => 'strict',
            'master-core-user-management.abilities.manage' => null,
        ]);
        Gate::define('manage-users', static fn () => true);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_strict_mode_denies_registered_ability_without_core_grant(): void
    {
        config(['master-core-roles-permissions.gate_mode' => 'strict']);
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('view-roles'));
    }

    public function test_role_management_api_requires_registered_permission(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/roles-permissions/roles')->assertForbidden();
    }

    public function test_role_permission_endpoint_grants_and_revokes_selected_permissions(): void
    {
        Gate::define('edit-roles', static fn () => true);
        Gate::define('view-roles', static fn () => true);
        $actor = User::factory()->create();
        $this->grant($actor, 'view-users');
        $permission = app(PermissionCatalogManager::class)->materialize('view-users', $actor);
        $role = (new Role)->forceFill([
            'name' => 'Test grant target', 'role_key' => 'test-grant-target', 'guard_name' => $this->guardName(), 'is_system' => false,
        ]);
        $role->save();

        $this->actingAs($actor)
            ->putJson('/api/v1/roles-permissions/roles/'.$role->getKey().'/permissions', ['permission_ids' => [$permission->getKey()]])
            ->assertOk()
            ->assertJsonPath('data.permission_ids', [$permission->getKey()]);
        $this->assertDatabaseHas('master_core_role_has_permissions', ['role_id' => $role->getKey(), 'permission_id' => $permission->getKey()]);
        $this->actingAs($actor)
            ->getJson('/api/v1/roles-permissions/roles/'.$role->getKey())
            ->assertOk()
            ->assertJsonPath('data.permission_ids', [$permission->getKey()]);

        $this->putJson('/api/v1/roles-permissions/roles/'.$role->getKey().'/permissions', ['permission_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.permission_ids', []);
        $this->assertDatabaseMissing('master_core_role_has_permissions', ['role_id' => $role->getKey(), 'permission_id' => $permission->getKey()]);
        $this->getJson('/api/v1/roles-permissions/roles/'.$role->getKey())
            ->assertOk()
            ->assertJsonPath('data.permission_ids', []);
    }

    public function test_role_permission_endpoint_rejects_unauthorized_and_invalid_permission_ids(): void
    {
        $actor = User::factory()->create();
        $permission = app(PermissionCatalogManager::class)->materialize('view-users', $actor);
        $role = (new Role)->forceFill([
            'name' => 'Test invalid target', 'role_key' => 'test-invalid-target', 'guard_name' => $this->guardName(), 'is_system' => false,
        ]);
        $role->save();

        $this->actingAs($actor)
            ->putJson('/api/v1/roles-permissions/roles/'.$role->getKey().'/permissions', ['permission_ids' => [$permission->getKey()]])
            ->assertForbidden();
        Gate::define('edit-roles', static fn () => true);
        $this->putJson('/api/v1/roles-permissions/roles/'.$role->getKey().'/permissions', ['permission_ids' => [999999]])
            ->assertUnprocessable();
        $this->assertDatabaseCount('master_core_role_has_permissions', 0);
    }

    public function test_system_super_admin_role_permissions_cannot_be_synced(): void
    {
        Gate::define('edit-roles', static fn () => true);
        $actor = User::factory()->create();
        $permission = app(PermissionCatalogManager::class)->materialize('view-users', $actor);
        $role = $this->createSuperAdminRole();
        DB::table('master_core_role_has_permissions')->insert(['role_id' => $role->getKey(), 'permission_id' => $permission->getKey()]);

        $this->actingAs($actor)
            ->putJson('/api/v1/roles-permissions/roles/'.$role->getKey().'/permissions', ['permission_ids' => []])
            ->assertStatus(409);
        $this->assertDatabaseHas('master_core_role_has_permissions', ['role_id' => $role->getKey(), 'permission_id' => $permission->getKey()]);
    }

    public function test_roles_list_accepts_empty_search_and_returns_existing_roles_in_the_expected_envelope(): void
    {
        Gate::define('view-roles', static fn () => true);
        $first = $this->roleWithPermission('support-team', 'view-roles');
        $second = (new Role)->forceFill([
            'name' => 'Operations', 'role_key' => 'operations', 'guard_name' => $this->guardName(), 'is_system' => false,
        ]);
        $second->save();

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/roles-permissions/roles?search=&per_page=25')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2);

        $this->assertEqualsCanonicalizing([$first->name, 'Operations'], collect($response->json('data'))->pluck('name')->all());
    }

    public function test_roles_list_applies_non_empty_search(): void
    {
        Gate::define('view-roles', static fn () => true);
        $matching = (new Role)->forceFill([
            'name' => 'Support Team', 'role_key' => 'support-team', 'guard_name' => $this->guardName(), 'is_system' => false,
        ]);
        $matching->save();
        $other = (new Role)->forceFill([
            'name' => 'Operations', 'role_key' => 'operations', 'guard_name' => $this->guardName(), 'is_system' => false,
        ]);
        $other->save();

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/roles-permissions/roles?search=Support')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertSame([$matching->name], collect($response->json('data'))->pluck('name')->all());
    }

    public function test_permissions_catalog_accepts_empty_search_and_returns_database_catalog_envelope(): void
    {
        Gate::define('view-permissions', static fn () => true);
        $first = $this->catalogPermission('view-users', 'Users');
        $second = $this->catalogPermission('view-roles', 'Roles');

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/roles-permissions/permissions?search=')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['data' => [['id', 'name', 'group_name', 'is_registered']], 'groups']);

        $this->assertEqualsCanonicalizing([$first->name, $second->name], collect($response->json('data'))->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Users', 'Roles'], $response->json('groups'));
    }

    public function test_permissions_catalog_search_and_group_filters_are_combined(): void
    {
        Gate::define('view-permissions', static fn () => true);
        $this->catalogPermission('view-users', 'Users');
        $expected = $this->catalogPermission('edit-users', 'Users');
        $this->catalogPermission('view-roles', 'Roles');

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/roles-permissions/permissions?search=users&group=Users')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->assertEqualsCanonicalizing(['view-users', $expected->name], collect($response->json('data'))->pluck('name')->all());
        $this->assertSame(['Users'], $response->json('groups'));
    }

    public function test_registered_permissions_endpoint_returns_code_registry_without_granting_anything(): void
    {
        Gate::define('manage-permissions', static fn () => true);
        $actor = User::factory()->create();

        $response = $this->actingAs($actor)
            ->getJson('/api/v1/roles-permissions/permissions/available')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['name', 'group_name', 'description']]]);

        $registered = app(PermissionRegistry::class)->all();
        $this->assertNotEmpty($registered);
        $this->assertCount(count($registered), $response->json('data'));
        $this->assertSame([], $response->json('data') ? DB::table('master_core_model_has_roles')->where('model_id', $actor->getKey())->pluck('role_id')->all() : []);
    }

    public function test_permission_catalog_and_registry_endpoints_preserve_authorization(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor)->getJson('/api/v1/roles-permissions/permissions')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/v1/roles-permissions/permissions/available')->assertForbidden();
    }

    public function test_bulk_role_delete_removes_only_eligible_roles(): void
    {
        Gate::define('delete-roles', static fn () => true);
        $first = $this->roleWithPermission('bulk-first', 'view-roles');
        $second = (new Role)->forceFill([
            'name' => 'Bulk Second', 'role_key' => 'bulk-second', 'guard_name' => $this->guardName(), 'is_system' => false,
        ]);
        $second->save();
        $first->permissions()->detach();
        $requestedIds = [$first->getKey(), $second->getKey()];
        $this->assertSame(2, Role::query()->where('guard_name', $this->guardName())->whereIn('id', $requestedIds)->count());

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/roles-permissions/roles/bulk-delete', ['role_ids' => $requestedIds])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertDatabaseMissing('master_core_roles', ['id' => $first->getKey()]);
        $this->assertDatabaseMissing('master_core_roles', ['id' => $second->getKey()]);
    }

    public function test_role_manager_cannot_grant_a_permission_outside_its_own_access(): void
    {
        $actor = User::factory()->create();
        $role = $this->grant($actor, 'view-roles');
        $extra = (new Permission)->forceFill([
            'name' => 'delete-roles', 'guard_name' => $this->guardName(), 'group_name' => 'Test',
            'description' => 'Delete roles', 'is_system' => true,
        ]);
        $extra->save();

        $this->expectException(AccessDeniedHttpException::class);
        app(RoleManager::class)->syncPermissions($role, [$extra->getKey()], $actor);
    }

    public function test_role_assignment_rejects_grants_outside_operators_effective_access(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->grant($actor, 'manage-user-roles');
        $role = $this->roleWithPermission('user-manager-role', 'view-users');

        $this->expectException(AccessDeniedHttpException::class);
        app(UserRoleAssignmentManager::class)->sync($target, [$role->getKey()], $actor);
    }

    public function test_http_role_assignment_cannot_add_the_super_admin_role(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $superRole = (new Role)->forceFill([
            'name' => 'Core Super Admin', 'role_key' => 'core-super-admin', 'guard_name' => $this->guardName(), 'is_system' => true,
        ]);
        $superRole->save();

        $this->expectException(AccessDeniedHttpException::class);
        app(UserRoleAssignmentManager::class)->sync($target, [$superRole->getKey()], $actor);
    }

    public function test_http_role_assignment_accepts_an_empty_set_to_revoke_all_roles(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $superRole = $this->createSuperAdminRole();
        $this->assignRole($actor, $superRole);
        $role = $this->roleWithPermission('revocable-access', 'view-users');
        $this->assignRole($target, $role);

        $this->actingAs($actor)
            ->putJson('/api/v1/roles-permissions/users/'.$target->getKey().'/roles', ['role_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.role_ids', []);

        $this->assertDatabaseMissing('master_core_model_has_roles', [
            'role_id' => $role->getKey(),
            'model_type' => $target->getMorphClass(),
            'model_id' => (string) $target->getKey(),
        ]);
    }

    public function test_super_admin_removal_is_transactionally_allowed_only_when_another_active_admin_remains(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $role = (new Role)->forceFill([
            'name' => 'Core Super Admin', 'role_key' => 'core-super-admin', 'guard_name' => $this->guardName(), 'is_system' => true,
        ]);
        $role->save();
        $this->assignRole($actor, $role);
        $this->assignRole($target, $role);

        app(UserRoleAssignmentManager::class)->sync($target, [], $actor);

        $this->assertDatabaseMissing('master_core_model_has_roles', [
            'role_id' => $role->getKey(), 'model_type' => $target->getMorphClass(), 'model_id' => (string) $target->getKey(),
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        app(UserRoleAssignmentManager::class)->sync($actor, [], User::factory()->create());
    }

    public function test_super_admin_is_not_auto_provisioned_and_requires_interactive_command(): void
    {
        $target = User::factory()->create();

        $this->artisan('master-core:roles:provision-super-admin', ['user' => $target->getKey(), '--no-interaction' => true])
            ->assertExitCode(1);

        $this->assertDatabaseMissing('master_core_roles', ['role_key' => 'core-super-admin']);
        $this->assertDatabaseCount('master_core_model_has_roles', 0);
    }

    public function test_interactive_command_provisions_an_existing_active_user(): void
    {
        $target = User::factory()->create();
        $confirmation = 'ASSIGN core-super-admin TO '.$target->getKey();

        $this->artisan('master-core:roles:provision-super-admin', ['user' => $target->getKey()])
            ->expectsQuestion('Type "'.$confirmation.'" to continue', $confirmation)
            ->assertExitCode(0);

        $role = Role::query()->where('role_key', 'core-super-admin')->firstOrFail();
        $this->assertDatabaseHas('master_core_model_has_roles', [
            'role_id' => $role->getKey(),
            'model_type' => $target->getMorphClass(),
            'model_id' => (string) $target->getKey(),
        ]);
    }

    public function test_http_assignment_rejects_a_user_deactivated_after_the_controller_snapshot(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $role = $this->roleWithPermission('status-race-role', 'view-users');
        $superRole = $this->createSuperAdminRole();
        $this->assignRole($actor, $superRole);

        // Keep the previously fetched Eloquent object active while the database changes before sync().
        $target->setAttribute('is_active', true);
        DB::table('users')->where('id', $target->getKey())->update(['is_active' => false]);
        $this->assertTrue($target->is_active);

        try {
            app(UserRoleAssignmentManager::class)->sync($target, [$role->getKey()], $actor);
            $this->fail('An inactive account must not receive a new role assignment.');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame('Roles cannot be assigned to an inactive or trashed user.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('master_core_model_has_roles', [
            'role_id' => $role->getKey(), 'model_type' => $target->getMorphClass(), 'model_id' => (string) $target->getKey(),
        ]);
    }

    public function test_super_admin_provisioning_rechecks_account_status_inside_its_transaction(): void
    {
        $target = User::factory()->create();
        DB::table('users')->where('id', $target->getKey())->update(['is_active' => false]);

        $this->expectException(AccessDeniedHttpException::class);
        app(SuperAdminProvisioner::class)->provision((string) $target->getKey());
    }

    public function test_command_rejects_deactivation_between_confirmation_target_lookup_and_assignment(): void
    {
        $target = User::factory()->create();
        $confirmation = 'ASSIGN core-super-admin TO '.$target->getKey();
        $deactivatedBetweenSteps = false;

        User::retrieved(function (User $user) use ($target, &$deactivatedBetweenSteps): void {
            if (! $deactivatedBetweenSteps && (string) $user->getKey() === (string) $target->getKey()) {
                $deactivatedBetweenSteps = true;
                DB::table('users')->where('id', $target->getKey())->update(['is_active' => false]);
            }
        });

        $this->artisan('master-core:roles:provision-super-admin', ['user' => $target->getKey()])
            ->expectsQuestion('Type "'.$confirmation.'" to continue', $confirmation)
            ->assertExitCode(1);

        $this->assertTrue($deactivatedBetweenSteps);
        $this->assertDatabaseMissing('master_core_model_has_roles', [
            'model_type' => $target->getMorphClass(), 'model_id' => (string) $target->getKey(),
        ]);
    }

    public function test_lifecycle_policy_protects_the_only_active_super_admin(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $role = $this->createSuperAdminRole();
        $this->assignRole($target, $role);

        $this->assertFalse(app(UserManagementSafetyPolicy::class)->allows($actor, $target, 'deactivate'));
        $this->assertFalse(app(UserManagementSafetyPolicy::class)->allows($actor, $target, 'delete'));
    }

    public function test_user_management_cannot_deactivate_the_only_active_super_admin(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->assignRole($target, $this->createSuperAdminRole());

        try {
            app(UserLifecycleManager::class)->changeStatus($target, false, $actor);
            $this->fail('The only active Super Admin must remain active.');
        } catch (AccessDeniedHttpException) {
            $this->assertTrue($target->fresh()->is_active);
        }
    }

    public function test_orphan_cleanup_reports_by_default_and_removes_only_after_confirmation(): void
    {
        $role = (new Role)->forceFill([
            'name' => 'Orphan test', 'role_key' => 'orphan-test', 'guard_name' => $this->guardName(), 'is_system' => false,
        ]);
        $role->save();
        $orphan = [
            'role_id' => $role->getKey(), 'model_type' => (new User)->getMorphClass(), 'model_id' => '987654321',
        ];
        DB::table('master_core_model_has_roles')->insert($orphan);

        $this->artisan('master-core:roles:cleanup-orphans')
            ->expectsOutputToContain('1 orphan assignment row(s) found')
            ->assertExitCode(0);
        $this->assertDatabaseHas('master_core_model_has_roles', $orphan);

        $confirmation = 'DELETE 1 ORPHAN ROLE LINKS';
        $this->artisan('master-core:roles:cleanup-orphans', ['--apply' => true])
            ->expectsQuestion('Type "'.$confirmation.'" to continue', $confirmation)
            ->assertExitCode(0);
        $this->assertDatabaseMissing('master_core_model_has_roles', $orphan);
    }

    public function test_inactive_super_admin_is_denied_even_if_the_host_gate_allows(): void
    {
        config(['master-core-roles-permissions.gate_mode' => 'compatibility']);
        $user = User::factory()->create(['is_active' => false]);
        $role = (new Role)->forceFill([
            'name' => 'Core Super Admin', 'role_key' => 'core-super-admin', 'guard_name' => $this->guardName(), 'is_system' => true,
        ]);
        $role->save();
        DB::table('master_core_model_has_roles')->insert([
            'role_id' => $role->getKey(), 'model_type' => $user->getMorphClass(), 'model_id' => (string) $user->getKey(),
        ]);
        Gate::define('view-users', static fn () => true);

        $this->assertFalse(Gate::forUser($user)->allows('view-users'));
    }

    public function test_user_management_destructive_actions_remain_fail_closed_without_host_policy(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();

        $this->assertFalse(app(UserManagementSafetyPolicy::class)->allows($actor, $target, 'deactivate'));
    }

    private function grant(User $user, string $ability): Role
    {
        $permission = (new Permission)->forceFill([
            'name' => $ability,
            'guard_name' => $this->guardName(),
            'group_name' => 'Test',
            'description' => 'Test ability',
            'is_system' => true,
        ]);
        $permission->save();
        $role = (new Role)->forceFill([
            'name' => 'Test role '.uniqid(),
            'role_key' => 'test-'.uniqid(),
            'guard_name' => $this->guardName(),
            'is_system' => false,
        ]);
        $role->save();
        $role->permissions()->attach($permission->getKey());
        DB::table('master_core_model_has_roles')->insert([
            'role_id' => $role->getKey(), 'model_type' => $user->getMorphClass(), 'model_id' => (string) $user->getKey(),
        ]);

        return $role;
    }

    private function assignRole(User $user, Role $role): void
    {
        DB::table('master_core_model_has_roles')->insert([
            'role_id' => $role->getKey(), 'model_type' => $user->getMorphClass(), 'model_id' => (string) $user->getKey(),
        ]);
    }

    private function createSuperAdminRole(): Role
    {
        $role = (new Role)->forceFill([
            'name' => 'Core Super Admin', 'role_key' => 'core-super-admin',
            'guard_name' => $this->guardName(), 'is_system' => true,
        ]);
        $role->save();

        return $role;
    }

    private function roleWithPermission(string $roleKey, string $ability): Role
    {
        $permission = (new Permission)->forceFill([
            'name' => $ability, 'guard_name' => $this->guardName(), 'group_name' => 'Test',
            'description' => 'Test ability', 'is_system' => true,
        ]);
        $permission->save();
        $role = (new Role)->forceFill([
            'name' => $roleKey, 'role_key' => $roleKey, 'guard_name' => $this->guardName(), 'is_system' => false,
        ]);
        $role->save();
        $role->permissions()->attach($permission->getKey());

        return $role;
    }

    private function guardName(): string
    {
        return app(HostUserModelResolver::class)->guardName();
    }

    private function catalogPermission(string $name, string $group): Permission
    {
        $permission = (new Permission)->forceFill([
            'name' => $name, 'guard_name' => $this->guardName(), 'group_name' => $group,
            'description' => 'Test ability', 'is_system' => true,
        ]);
        $permission->save();

        return $permission;
    }
}
