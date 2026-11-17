<?php

namespace MasterCore\AuditLogs\Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use MasterCore\AuditLogs\Contracts\AuditLogger;
use MasterCore\AuditLogs\Listeners\AuthenticationAuditListener;
use MasterCore\AuditLogs\Models\AuditEvent;
use MasterCore\AuditLogs\Services\DatabaseAuditLogger;
use MasterCore\AuditLogs\Support\AuditEventCatalog;
use MasterCore\RolesPermissions\Actions\RoleManager;
use MasterCore\RolesPermissions\Actions\UserRoleAssignmentManager;
use MasterCore\Settings\Models\Setting;
use MasterCore\Settings\Services\SettingsManager;
use MasterCore\UserManagement\Actions\UserLifecycleManager;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class AuditLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::define('view-audit-logs', static fn () => true);
    }

    public function test_schema_has_one_append_only_audit_table_and_record_creation_captures_safe_request_context(): void
    {
        $user = User::factory()->create();
        Route::get('/_audit-test-record', function () use ($user) {
            app(AuditLogger::class)->record(['event_name' => 'user.updated', 'subject' => $user, 'metadata' => ['changed_fields' => ['name', 'email'], 'password' => 'never']]);

            return response()->noContent();
        });
        $this->actingAs($user)->withHeaders(['User-Agent' => str_repeat('a', 800), 'X-Correlation-ID' => 'trace-123'])->get('/_audit-test-record')->assertNoContent();

        $event = AuditEvent::query()->firstOrFail();
        $this->assertSame('user_management', $event->category);
        $this->assertSame((string) $user->getKey(), $event->actor_id);
        $this->assertSame((string) $user->getKey(), $event->subject_id);
        $this->assertSame('trace-123', $event->correlation_id);
        $this->assertSame(512, strlen($event->user_agent));
        $this->assertSame('127.0.0.1', $event->ip_address);
        $this->assertSame('GET', $event->request_method);
        $this->assertSame(['changed_fields' => ['name', 'email']], $event->metadata);
        $this->assertTrue($this->app['db']->getSchemaBuilder()->hasTable('master_core_audit_events'));
    }

    public function test_unknown_events_and_oversized_payloads_are_rejected(): void
    {
        $logger = app(DatabaseAuditLogger::class);
        try {
            $logger->record(['event_name' => 'arbitrary.event']);
            $this->fail('Unknown event names must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(0, AuditEvent::query()->count());
        }

        config(['master-core-audit-logs.max_payload_bytes' => 8]);
        try {
            $logger->record(['event_name' => 'settings.group_updated', 'metadata' => ['group' => 'branding']]);
            $this->fail('Oversized payloads must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(0, AuditEvent::query()->count());
        }
    }

    public function test_database_timestamp_casts_remain_utc_for_hosts_with_a_local_timezone(): void
    {
        config(['app.timezone' => 'Asia/Dhaka']);
        app(AuditLogger::class)->record(['event_name' => 'auth.login.succeeded', 'occurred_at' => '2026-11-16 12:34:56']);

        $event = AuditEvent::query()->firstOrFail();
        $this->assertSame('2026-11-16T12:34:56+00:00', $event->occurred_at->toIso8601String());
    }

    public function test_catalog_contains_both_registered_permissions_but_no_export_route(): void
    {
        $abilities = config('master-core-roles-permissions.abilities');
        $this->assertArrayHasKey('view-audit-logs', $abilities);
        $this->assertArrayHasKey('export-audit-logs', $abilities);
        $this->assertContains('auth.login.failed', app(AuditEventCatalog::class)->names());
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route): bool => str_contains($route->uri(), 'audit-logs/export')));
    }

    public function test_model_rejects_updates_and_deletes(): void
    {
        app(AuditLogger::class)->record(['event_name' => 'auth.login.succeeded']);
        $event = AuditEvent::query()->firstOrFail();

        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_settings_user_management_and_rbac_mutations_emit_catalogued_events(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();

        app(SettingsManager::class)->updateGroup('general', ['application_name' => 'Audit test']);
        app(UserLifecycleManager::class)->update($target, ['name' => 'Updated name'], $actor);
        $inactive = User::factory()->create(['is_active' => false]);
        app(UserLifecycleManager::class)->changeStatus($inactive, true, $actor);
        $role = app(RoleManager::class)->create('Auditor', $actor);
        app(UserRoleAssignmentManager::class)->sync($target, [(int) $role->getKey()], $actor);

        $names = AuditEvent::query()->orderBy('event_name')->pluck('event_name')->all();
        $this->assertContains('settings.group_updated', $names);
        $this->assertContains('user.updated', $names);
        $this->assertContains('role.created', $names);
        $this->assertContains('user.roles_synced', $names);
        $statusEvent = AuditEvent::query()->where('event_name', 'user.activated')->firstOrFail();
        $this->assertSame(['is_active' => false], $statusEvent->before_values);
        $this->assertSame(['is_active' => true], $statusEvent->after_values);
        $metadata = AuditEvent::query()->where('event_name', 'user.updated')->firstOrFail()->metadata;
        $this->assertSame(['name'], $metadata['changed_fields']);
    }

    public function test_critical_settings_mutation_rolls_back_when_audit_write_fails(): void
    {
        $brokenLogger = Mockery::mock(AuditLogger::class);
        $brokenLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('audit storage unavailable'));
        $this->app->instance(AuditLogger::class, $brokenLogger);

        try {
            app(SettingsManager::class)->updateGroup('general', ['application_name' => 'Should roll back']);
            $this->fail('A critical settings mutation must fail if the audit row cannot be written.');
        } catch (RuntimeException) {
            $this->assertFalse(Setting::query()->where('group_name', 'general')->where('setting_key', 'application_name')->exists());
        }
    }

    public function test_critical_role_mutation_rolls_back_when_audit_write_fails(): void
    {
        $brokenLogger = Mockery::mock(AuditLogger::class);
        $brokenLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('audit storage unavailable'));
        $this->app->instance(AuditLogger::class, $brokenLogger);

        try {
            app(RoleManager::class)->create('Must not commit', User::factory()->create());
            $this->fail('A critical role mutation must roll back if the audit row cannot be written.');
        } catch (RuntimeException) {
            $this->assertDatabaseMissing('master_core_roles', ['name' => 'Must not commit']);
        }
    }

    public function test_critical_user_update_rolls_back_when_audit_write_fails(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['name' => 'Before']);
        $brokenLogger = Mockery::mock(AuditLogger::class);
        $brokenLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('audit storage unavailable'));
        $this->app->instance(AuditLogger::class, $brokenLogger);

        try {
            app(UserLifecycleManager::class)->update($target, ['name' => 'After'], $actor);
            $this->fail('A critical user update must roll back if the audit row cannot be written.');
        } catch (RuntimeException) {
            $this->assertSame('Before', $target->fresh()->name);
        }
    }

    public function test_authentication_password_reset_event_is_catalogued_without_secret_values(): void
    {
        $user = User::factory()->create();
        event(new PasswordReset($user));

        $event = AuditEvent::query()->where('event_name', 'auth.password_reset.completed')->firstOrFail();
        $this->assertSame((string) $user->getKey(), $event->actor_id);
        $this->assertNull($event->metadata);
        $this->assertStringNotContainsString($user->password, json_encode($event->toArray()));
    }

    public function test_failed_login_records_failure_without_credentials_or_identifier(): void
    {
        $user = User::factory()->create();
        $credentials = ['email' => $user->email, 'password' => 'must-not-persist'];
        app(AuthenticationAuditListener::class)->failed(new Failed('web', $user, $credentials));

        $event = AuditEvent::query()->where('event_name', 'auth.login.failed')->firstOrFail();
        $this->assertSame('failed', $event->outcome);
        $this->assertNull($event->actor_id);
        $this->assertNull($event->subject_id);
        $serialized = json_encode($event->toArray());
        $this->assertStringNotContainsString($user->email, $serialized);
        $this->assertStringNotContainsString('must-not-persist', $serialized);
    }

    public function test_authenticated_audit_api_filters_and_paginates_and_denies_without_permission(): void
    {
        $user = User::factory()->create();
        app(AuditLogger::class)->record(['event_name' => 'auth.login.succeeded']);
        for ($index = 0; $index < 11; $index++) {
            app(AuditLogger::class)->record(['event_name' => 'auth.logout']);
        }
        $this->actingAs($user)->getJson('/api/v1/audit-logs?category=authentication&search=auth.login&per_page=10')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.event_name', 'auth.login.succeeded');
        $this->actingAs($user)->getJson('/api/v1/audit-logs?category=authentication&per_page=10&page=1')
            ->assertOk()->assertJsonPath('meta.last_page', 2)->assertJsonCount(10, 'data');
        $this->actingAs($user)->getJson('/api/v1/audit-logs?category=authentication&per_page=10&page=2')
            ->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonCount(2, 'data');
        $id = AuditEvent::query()->value('id');
        $this->actingAs($user)->getJson('/api/v1/audit-logs/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->actingAs($user)->getJson('/api/v1/audit-logs?from='.now()->subDays(120)->toDateString())->assertUnprocessable();

        Gate::define('view-audit-logs', static fn () => false);
        $this->actingAs($user)->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->assertSame(405, $this->actingAs($user)->putJson('/api/v1/audit-logs')->status());
        $this->assertSame(405, $this->actingAs($user)->deleteJson('/api/v1/audit-logs/'.$id)->status());
    }

    public function test_retention_purge_is_disabled_until_a_policy_is_explicitly_configured(): void
    {
        app(AuditLogger::class)->record(['event_name' => 'auth.login.succeeded', 'occurred_at' => now()->subYears(2)]);
        config([
            'master-core-audit-logs.retention.purge_enabled' => true,
            'master-core-audit-logs.retention.policy_approved' => false,
            'master-core-audit-logs.retention.period_days' => 30,
        ]);

        $this->assertSame(1, Artisan::call('master-core:audit-logs:purge-expired'));
        $this->assertSame(1, AuditEvent::query()->count());
    }

    public function test_manual_retention_purge_runs_only_after_explicit_policy_approval(): void
    {
        app(AuditLogger::class)->record(['event_name' => 'auth.login.succeeded', 'occurred_at' => now()->subDays(45)]);
        config([
            'master-core-audit-logs.retention.purge_enabled' => true,
            'master-core-audit-logs.retention.policy_approved' => true,
            'master-core-audit-logs.retention.period_days' => 30,
        ]);

        $this->assertSame(0, Artisan::call('master-core:audit-logs:purge-expired'));
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_authentication_listener_swallows_audit_storage_failure(): void
    {
        $brokenLogger = Mockery::mock(AuditLogger::class);
        $brokenLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('must not be logged'));
        $this->app->instance(AuditLogger::class, $brokenLogger);
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Master Core authentication audit write failed.'
                && $context['event_name'] === 'auth.login.succeeded'
                && ! array_key_exists('exception_message', $context);
        });

        app(AuthenticationAuditListener::class)->login(new Login('web', User::factory()->create(), false));
    }

    public function test_authentication_listener_still_fails_open_if_operational_logging_also_fails(): void
    {
        $brokenLogger = Mockery::mock(AuditLogger::class);
        $brokenLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('secret-bearing exception message'));
        $this->app->instance(AuditLogger::class, $brokenLogger);
        Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('logger unavailable'));

        app(AuthenticationAuditListener::class)->login(new Login('web', User::factory()->create(), false));
        $this->assertTrue(true);
    }
}
