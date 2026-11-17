<?php

namespace MasterCore\Foundation\Tests\Feature;

use MasterCore\Foundation\Contracts\CoreHealthInterface;
use MasterCore\Foundation\Providers\FoundationServiceProvider;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_the_service_provider_is_registered_and_defaults_are_merged(): void
    {
        $this->assertNotNull($this->app->getProvider(FoundationServiceProvider::class));
        $this->assertSame('Master Core', config('master-core.name'));
        $this->assertSame('1.0.0', config('master-core.version'));
        $this->assertSame('api', config('master-core.api_prefix'));
        $this->assertSame('v1', config('master-core.api_version'));
        $this->assertTrue(config('master-core.enable_health_endpoint'));
        $this->assertFalse(config('master-core.enable_debug_information'));
    }

    public function test_the_health_endpoint_returns_the_stable_public_contract_without_database_access(): void
    {
        $this->getJson('/api/v1/core/health')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'name' => 'Master Core',
                    'version' => '1.0.0',
                    'status' => 'healthy',
                ],
            ]);
    }

    public function test_configuration_overrides_are_used_by_the_health_contract(): void
    {
        config([
            'master-core.name' => 'Host Application',
            'master-core.version' => '2.4.0',
        ]);

        $this->assertSame([
            'name' => 'Host Application',
            'version' => '2.4.0',
            'status' => 'healthy',
        ], $this->app->make(CoreHealthInterface::class)->status());
    }

    public function test_the_health_endpoint_can_be_disabled(): void
    {
        config(['master-core.enable_health_endpoint' => false]);

        $this->getJson('/api/v1/core/health')->assertNotFound();
    }

    public function test_package_migration_directory_is_registered(): void
    {
        $packagePath = dirname((new \ReflectionClass(FoundationServiceProvider::class))->getFileName(), 3);
        $migrationPath = realpath($packagePath.'/database/migrations');
        $registeredPaths = array_map('realpath', $this->app->make('migrator')->paths());

        $this->assertContains($migrationPath, $registeredPaths);
    }

    public function test_configuration_is_registered_for_publishing(): void
    {
        $this->assertContains('master-core-config', FoundationServiceProvider::publishableGroups());
    }

    public function test_route_and_configuration_caches_can_be_built_and_cleared(): void
    {
        $this->artisan('route:cache')->assertExitCode(0);
        $this->artisan('route:clear')->assertExitCode(0);
        $this->artisan('config:cache')->assertExitCode(0);
        $this->artisan('config:clear')->assertExitCode(0);
    }
}
