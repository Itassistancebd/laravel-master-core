<?php

namespace MasterCore\SystemHealth\Tests\Feature;

use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use MasterCore\RolesPermissions\Models\Permission;
use MasterCore\SystemHealth\Checks\DatabaseConnectivityCheck;
use MasterCore\SystemHealth\Enums\HealthCheckStatus;
use PDOException;
use Tests\TestCase;

final class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::define('view-system-health', static fn (): bool => true);
    }

    public function test_diagnostics_require_authentication_and_the_registered_view_ability(): void
    {
        $this->getJson('/api/v1/system-health')->assertUnauthorized();

        Gate::define('view-system-health', static fn (): bool => false);
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/system-health')
            ->assertForbidden();
    }

    public function test_inactive_accounts_cannot_access_diagnostics(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => false]))
            ->getJson('/api/v1/system-health')
            ->assertUnauthorized();
    }

    public function test_view_ability_is_code_registered_without_automatic_materialization(): void
    {
        $this->assertArrayHasKey('view-system-health', config('master-core-roles-permissions.abilities'));
        $this->assertFalse(Permission::query()->where('name', 'view-system-health')->exists());
    }

    public function test_successful_probe_returns_only_allowlisted_check_results(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/system-health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.overall_status', 'healthy')
            ->assertJsonPath('data.checks.0.key', 'application_request')
            ->assertJsonPath('data.checks.0.status', 'healthy')
            ->assertJsonPath('data.checks.0.outcome', 'successful')
            ->assertJsonPath('data.checks.1.key', 'primary_database')
            ->assertJsonPath('data.checks.1.status', 'healthy')
            ->assertJsonPath('data.checks.1.outcome', 'successful')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $response->json('data.checked_at'));
        $this->assertSame(
            ['key', 'name', 'status', 'outcome', 'message'],
            array_keys($response->json('data.checks.1')),
        );
        $this->assertStringNotContainsString(base_path(), $response->getContent());
        $this->assertStringNotContainsString('database.connections', $response->getContent());
    }

    public function test_database_failure_is_reported_without_leaking_exception_details(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('Core system health database probe failed.', [
                'check' => 'primary_database',
                'exception_class' => PDOException::class,
            ]);

        $database = \Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->withNoArgs()
            ->andThrow(new PDOException('secret-host.internal; password=super-secret'));
        $this->app->instance(DatabaseManager::class, $database);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/system-health')
            ->assertOk()
            ->assertJsonPath('data.overall_status', 'unavailable')
            ->assertJsonPath('data.checks.1.status', 'unavailable')
            ->assertJsonPath('data.checks.1.outcome', 'failed')
            ->assertJsonPath('data.checks.1.message', 'The configured primary database did not answer the connectivity probe.');

        $this->assertStringNotContainsString('secret-host.internal', $response->getContent());
        $this->assertStringNotContainsString('super-secret', $response->getContent());
    }

    public function test_missing_default_database_configuration_is_distinguished_from_a_failed_probe(): void
    {
        $database = \Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $config = new Repository(['database' => ['default' => null, 'connections' => []]]);
        $check = new DatabaseConnectivityCheck($database, $config);

        $result = $check->run();

        $this->assertSame(HealthCheckStatus::NotConfigured, $result->status);
        $this->assertSame('not_configured', $result->toArray()['outcome']);
    }

    public function test_request_cannot_select_a_different_connection_or_check(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/system-health?connection=mysql&check=arbitrary')
            ->assertOk()
            ->assertJsonPath('data.checks.1.key', 'primary_database')
            ->assertJsonMissingPath('data.checks.2');
    }

    public function test_diagnostics_add_no_table_or_migration(): void
    {
        $schema = $this->app['db']->getSchemaBuilder();
        $this->assertFalse($schema->hasTable('master_core_system_health'));
        $this->assertFalse($schema->hasTable('master_core_health_checks'));
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/database/migrations');
    }

    public function test_foundation_health_contract_remains_public_and_database_independent(): void
    {
        $database = \Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('connection');
        $this->app->instance(DatabaseManager::class, $database);

        $this->getJson('/api/v1/core/health')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'name' => config('master-core.name'),
                    'version' => config('master-core.version'),
                    'status' => 'healthy',
                ],
            ]);
    }

    public function test_auth_shell_passes_the_foundation_configured_api_prefix_to_the_spa(): void
    {
        config(['master-core.api_prefix' => 'custom-api', 'master-core.api_version' => 'v2']);
        $apiBase = url('/custom-api/v2/system-health');

        $this->withoutVite()
            ->get('/login')
            ->assertOk()
            ->assertSee('data-system-health-api-base="'.$apiBase.'"', false);
    }
}
