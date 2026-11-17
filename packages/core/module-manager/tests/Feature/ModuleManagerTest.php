<?php

namespace MasterCore\ModuleManager\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use MasterCore\ModuleManager\Contracts\InstalledPackageInspector;
use MasterCore\ModuleManager\Contracts\ModuleCatalog;
use MasterCore\ModuleManager\Providers\ModuleManagerServiceProvider;
use MasterCore\ModuleManager\Support\ConfiguredModuleCatalog;
use MasterCore\RolesPermissions\Models\Permission;
use Tests\TestCase;

final class ModuleManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::define('view-modules', static fn (): bool => true);
    }

    public function test_module_routes_require_authentication_and_view_permission(): void
    {
        $this->getJson('/api/v1/modules')->assertUnauthorized();

        Gate::define('view-modules', static fn (): bool => false);
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/modules')
            ->assertForbidden();
    }

    public function test_index_returns_only_the_allowlisted_catalog_with_pagination(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/modules?per_page=10&page=1&sort_by=key&sort_direction=asc')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 10);

        $firstModule = $response->json('data.0');
        $this->assertSame('core-audit-logs', $firstModule['key']);
        $this->assertArrayNotHasKey('provider', $firstModule);
        $this->assertArrayNotHasKey('install_path', $firstModule);
        $this->assertArrayHasKey('installation', $firstModule);
        $this->assertArrayHasKey('dependencies', $firstModule);
        $this->assertStringNotContainsString(base_path(), $response->getContent());
    }

    public function test_details_are_limited_to_known_module_keys(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/modules/core-foundation')
            ->assertOk()
            ->assertJsonPath('data.key', 'core-foundation')
            ->assertJsonPath('data.composer_package', 'example/laravel-master-core-foundation');

        $this->getJson('/api/v1/modules/not-in-catalog')->assertNotFound();
    }

    public function test_view_permission_is_registered_but_not_materialized_automatically(): void
    {
        $abilities = config('master-core-roles-permissions.abilities');
        $this->assertArrayHasKey('view-modules', $abilities);
        $this->assertFalse(Permission::query()->where('name', 'view-modules')->exists());
    }

    public function test_search_readiness_and_pagination_inputs_are_validated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/modules?per_page=15')->assertUnprocessable();
        $this->getJson('/api/v1/modules?readiness=healthy')->assertUnprocessable();
        $this->getJson('/api/v1/modules?sort_by=provider')->assertUnprocessable();

        $this->getJson('/api/v1/modules?search=foundation')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.key', 'core-foundation');
    }

    public function test_dependency_presence_is_reported_without_claiming_runtime_readiness(): void
    {
        $definitions = [
            'core-test' => [
                'name' => 'Test module',
                'description' => 'A deterministic test descriptor.',
                'composer_package' => 'example/test-module',
                'provider' => 'Example\\Test\\Provider',
                'dependencies' => ['example/missing-dependency'],
                'documentation' => 'packages/core/test/README.md',
            ],
            'core-test-unloaded' => [
                'name' => 'Unloaded test module',
                'description' => 'The dependency is present but the provider is not loaded.',
                'composer_package' => 'example/test-unloaded',
                'provider' => 'Example\\Unloaded\\Provider',
                'dependencies' => ['example/present-dependency'],
                'documentation' => 'packages/core/test-unloaded/README.md',
            ],
            'core-test-verified-dependencies' => [
                'name' => 'Dependency-complete test module',
                'description' => 'Provider loaded but operational readiness remains unknown.',
                'composer_package' => 'example/test-provider-loaded',
                'provider' => ModuleManagerServiceProvider::class,
                'dependencies' => ['example/present-dependency'],
                'documentation' => 'packages/core/test-provider-loaded/README.md',
            ],
        ];
        $inspector = new class implements InstalledPackageInspector
        {
            public function inspect(string $packageName): array
            {
                $installed = in_array($packageName, ['example/test-module', 'example/test-unloaded', 'example/test-provider-loaded', 'example/present-dependency'], true);

                return ['installed' => $installed && $packageName !== 'example/missing-dependency', 'version' => $installed ? '1.2.3' : null];
            }
        };

        $catalog = new ConfiguredModuleCatalog($this->app, $inspector, $definitions);
        $module = $catalog->find('core-test');
        $unloaded = $catalog->find('core-test-unloaded');
        $dependenciesPresent = $catalog->find('core-test-verified-dependencies');

        $this->assertSame('dependency_missing', $module['readiness_status']);
        $this->assertFalse($module['dependencies'][0]['installed']);
        $this->assertSame('provider_not_loaded', $unloaded['readiness_status']);
        $this->assertSame('runtime_not_verified', $dependenciesPresent['readiness_status']);
        $this->assertTrue($dependenciesPresent['provider_loaded']);
    }

    public function test_invalid_module_keys_or_package_metadata_fail_closed(): void
    {
        $definitions = [
            'invalid key' => [
                'name' => 'Invalid',
                'description' => 'Invalid key.',
                'composer_package' => 'vendor/package',
                'provider' => 'Example\\Provider',
                'dependencies' => [],
                'documentation' => 'docs/README.md',
            ],
        ];
        $this->expectException(InvalidArgumentException::class);
        app(ConfiguredModuleCatalog::class, ['definitions' => $definitions])->all();
    }

    public function test_host_configuration_cannot_add_an_arbitrary_module(): void
    {
        config(['master-core-module-manager.display_overrides' => [
            'unlisted-module' => ['name' => 'Unexpected package'],
        ]]);
        $this->expectException(InvalidArgumentException::class);
        app(ModuleCatalog::class)->all();
    }

    public function test_host_configuration_cannot_override_package_identity(): void
    {
        config(['master-core-module-manager.display_overrides' => [
            'core-foundation' => ['composer_package' => 'vendor/unapproved-package'],
        ]]);
        $this->expectException(InvalidArgumentException::class);
        app(ModuleCatalog::class)->all();
    }

    public function test_module_manager_does_not_own_a_database_table(): void
    {
        $this->assertFalse($this->app['db']->getSchemaBuilder()->hasTable('master_core_modules'));
        $this->assertFalse($this->app['db']->getSchemaBuilder()->hasTable('master_core_module_states'));
    }

    public function test_system_health_is_in_the_allowlisted_catalog_without_runtime_health_claims(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/modules/core-system-health')
            ->assertOk()
            ->assertJsonPath('data.key', 'core-system-health')
            ->assertJsonPath('data.name', 'System Health & Readiness')
            ->assertJsonPath('data.readiness_status', 'runtime_not_verified')
            ->assertJsonPath('data.provider_loaded', true)
            ->assertJsonPath('data.composer_package', 'example/laravel-master-core-system-health');
    }

    public function test_notifications_is_in_the_allowlisted_catalog_without_an_operational_readiness_claim(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/modules/core-notifications')
            ->assertOk()
            ->assertJsonPath('data.key', 'core-notifications')
            ->assertJsonPath('data.name', 'Notifications & Notification Center')
            ->assertJsonPath('data.composer_package', 'example/laravel-master-core-notifications')
            ->assertJsonPath('data.readiness_status', 'runtime_not_verified');
    }

    public function test_crud_engine_is_listed_as_opt_in_without_changing_its_authorization_or_routes(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/modules/core-crud-engine')
            ->assertOk()
            ->assertJsonPath('data.key', 'core-crud-engine')
            ->assertJsonPath('data.name', 'CRUD Engine')
            ->assertJsonPath('data.composer_package', 'example/laravel-master-core-crud-engine')
            ->assertJsonPath('data.readiness_status', 'runtime_not_verified');

        $this->assertFalse(collect(app('router')->getRoutes()->getRoutes())->contains(
            static fn ($route): bool => str_contains($route->uri(), 'fixture-accounts')
        ));
    }
}
