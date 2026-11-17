<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use MasterCore\AuditLogs\Models\AuditEvent;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_an_authenticated_user(): void
    {
        $this->getJson('/api/v1/core/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_returns_only_sections_the_user_can_view(): void
    {
        $user = User::factory()->create();
        Gate::define('view-users', static fn () => true);

        $this->actingAs($user)->getJson('/api/v1/core/dashboard')
            ->assertOk()
            ->assertJsonPath('data.access.users', true)
            ->assertJsonPath('data.metrics.users', 1)
            ->assertJsonPath('data.metrics.active_users', 1)
            ->assertJsonPath('data.metrics.roles', null)
            ->assertJsonPath('data.metrics.permissions', null)
            ->assertJsonPath('data.recent_activity', [])
            ->assertJsonPath('data.health', null);
    }

    public function test_dashboard_returns_null_for_metrics_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/core/dashboard')
            ->assertOk()
            ->assertJsonPath('data.access.users', false)
            ->assertJsonPath('data.metrics.users', null)
            ->assertJsonPath('data.user_growth', [])
            ->assertJsonPath('data.access.audit', false)
            ->assertJsonPath('data.recent_activity', []);
    }

    public function test_recent_activity_is_permission_gated_and_omits_sensitive_audit_fields(): void
    {
        $user = User::factory()->create();
        Gate::define('view-audit-logs', static fn () => true);
        AuditEvent::query()->forceCreate([
            'event_name' => 'user.created',
            'category' => 'administration',
            'outcome' => 'successful',
            'source' => 'http',
            'actor_type' => User::class,
            'actor_id' => (string) $user->getKey(),
            'ip_address' => '192.0.2.1',
            'user_agent' => 'test-agent',
            'occurred_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/v1/core/dashboard')
            ->assertOk()
            ->assertJsonPath('data.access.audit', true)
            ->assertJsonPath('data.recent_activity.0.event_name', 'user.created')
            ->assertJsonPath('data.recent_activity.0.actor.id', (string) $user->getKey())
            ->assertJsonMissingPath('data.recent_activity.0.ip_address')
            ->assertJsonMissingPath('data.recent_activity.0.user_agent');
    }

    public function test_system_status_uses_the_existing_health_checks_when_authorized(): void
    {
        $user = User::factory()->create();
        Gate::define('view-system-health', static fn () => true);

        $this->actingAs($user)->getJson('/api/v1/core/dashboard')
            ->assertOk()
            ->assertJsonPath('data.access.health', true)
            ->assertJsonPath('data.health.overall_status', 'healthy')
            ->assertJsonPath('data.health.checks.1.status', 'healthy');
    }
}
