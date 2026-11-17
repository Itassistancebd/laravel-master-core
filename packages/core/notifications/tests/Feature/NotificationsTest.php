<?php

namespace MasterCore\Notifications\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MasterCore\Notifications\Contracts\NotificationInbox;
use MasterCore\Notifications\Models\CoreNotification;
use MasterCore\RolesPermissions\Events\RolesPermissionsChanged;
use MasterCore\RolesPermissions\Models\Role;
use Tests\TestCase;

final class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_owns_one_table_with_the_approved_columns_and_indexes(): void
    {
        $schema = $this->app['db']->getSchemaBuilder();
        $this->assertTrue($schema->hasTable('master_core_notifications'));
        $this->assertSame(
            ['id', 'notifiable_type', 'notifiable_id', 'event_key', 'data', 'read_at', 'created_at'],
            $schema->getColumnListing('master_core_notifications'),
        );
        $this->assertFalse($schema->hasTable('notifications'));
        $this->assertFalse($schema->hasColumn('master_core_notifications', 'updated_at'));
        $this->assertFalse($schema->hasColumn('master_core_notifications', 'deleted_at'));
    }

    public function test_inbox_requires_authentication_and_inactive_users_are_rejected(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_active' => false]))
            ->getJson('/api/v1/notifications')
            ->assertUnauthorized();
    }

    public function test_role_change_event_creates_only_a_generic_notice_for_its_active_recipient(): void
    {
        $actor = User::factory()->create();
        $recipient = User::factory()->create();
        $event = RolesPermissionsChanged::record('user-roles-synced', $actor, $recipient);

        event($event);

        $this->assertDatabaseHas('master_core_notifications', [
            'id' => $event->eventId,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => (string) $recipient->getKey(),
            'event_key' => 'account.roles.changed',
        ]);
        $this->assertSame('Your account access was updated.', CoreNotification::query()->findOrFail($event->eventId)->data['message']);
        $this->assertStringNotContainsString($actor->getKey(), json_encode(CoreNotification::query()->findOrFail($event->eventId)->data));
    }

    public function test_only_approved_role_change_actions_can_create_a_notice_and_replay_is_idempotent(): void
    {
        $actor = User::factory()->create();
        $recipient = User::factory()->create();
        $event = RolesPermissionsChanged::record('role-renamed', $actor, $recipient);
        event($event);
        $this->assertDatabaseCount('master_core_notifications', 0);

        $approved = RolesPermissionsChanged::record('user-roles-synced', $actor, $recipient);
        event($approved);
        event($approved);
        $this->assertDatabaseCount('master_core_notifications', 1);
    }

    public function test_notification_storage_failure_is_logged_and_does_not_fail_a_committed_role_change(): void
    {
        $actor = User::factory()->create();
        $recipient = User::factory()->create();
        $event = RolesPermissionsChanged::record('user-roles-synced', $actor, $recipient);
        $store = \Mockery::mock(NotificationInbox::class);
        $store->shouldReceive('store')->once()->andThrow(new \RuntimeException('private payload must not be logged'));
        $this->app->instance(NotificationInbox::class, $store);
        Log::shouldReceive('warning')->once()->with('Core role-change notification could not be stored.', [
            'event_id' => $event->eventId,
            'event_key' => 'account.roles.changed',
            'exception_class' => \RuntimeException::class,
        ]);

        DB::transaction(function () use ($recipient): void {
            $recipient->forceFill(['name' => 'Committed after notification failure'])->save();
        });
        // The source transaction has committed before its existing after-commit event is delivered.
        event($event);

        $this->assertSame('Committed after notification failure', $recipient->fresh()->name);
        $this->assertDatabaseCount('master_core_notifications', 0);
    }

    public function test_notification_queries_are_recipient_scoped_and_other_ids_return_not_found(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->store($owner);
        $foreign = $this->store($other);

        $this->actingAs($owner)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson('/api/v1/notifications/'.$foreign->id.'/read')->assertNotFound();
    }

    public function test_list_filters_and_pagination_are_validated_and_bounded(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 32) as $_) {
            $this->store($user);
        }

        $this->actingAs($user)->getJson('/api/v1/notifications?status=unread&per_page=10&page=1')
            ->assertOk()->assertJsonPath('meta.total', 32)->assertJsonPath('meta.per_page', 10);
        $this->getJson('/api/v1/notifications?per_page=200')->assertUnprocessable();
        $this->getJson('/api/v1/notifications?sort=secret')->assertUnprocessable();
        $this->postJson('/api/v1/notifications/read-all', ['recipient_id' => 42])->assertUnprocessable();
    }

    public function test_mark_read_unread_all_and_unread_count_are_scoped_to_the_current_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->store($owner);
        $second = $this->store($owner);
        $foreign = $this->store($other);
        $this->actingAs($owner);

        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 2);
        $markedRead = $this->patchJson('/api/v1/notifications/'.$first->id.'/read')->assertOk();
        $this->assertNotNull($markedRead->json('data.read_at'));
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 1);
        $this->patchJson('/api/v1/notifications/'.$first->id.'/unread')->assertOk()->assertJsonPath('data.read_at', null);
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 2);

        $this->assertNotNull(CoreNotification::query()->findOrFail($first->id)->read_at);
        $this->assertNotNull(CoreNotification::query()->findOrFail($second->id)->read_at);
        $this->assertNull(CoreNotification::query()->findOrFail($foreign->id)->read_at);
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
    }

    public function test_mark_all_read_processes_a_bounded_batch_and_reports_remaining_items(): void
    {
        config(['master-core-notifications.mark_all_batch_size' => 1]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->store($owner);
        $second = $this->store($owner);
        $foreign = $this->store($other);
        $this->actingAs($owner);

        $this->postJson('/api/v1/notifications/read-all')->assertOk()
            ->assertJsonPath('data.updated', 1)->assertJsonPath('data.remaining', 1);
        $this->assertSame(1, CoreNotification::query()->where('notifiable_id', (string) $owner->getKey())->whereNotNull('read_at')->count());
        $this->assertNull(CoreNotification::query()->findOrFail($foreign->id)->read_at);

        $this->postJson('/api/v1/notifications/read-all')->assertOk()
            ->assertJsonPath('data.updated', 1)->assertJsonPath('data.remaining', 0);
        $this->assertNotNull(CoreNotification::query()->findOrFail($first->id)->read_at);
        $this->assertNotNull(CoreNotification::query()->findOrFail($second->id)->read_at);
    }

    public function test_registered_catalog_rejects_arbitrary_event_payloads(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(NotificationInbox::class)->store((string) Str::ulid(), 'invented.event', User::factory()->create());
    }

    public function test_only_the_configured_host_user_model_can_be_a_recipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(NotificationInbox::class)->store((string) Str::ulid(), 'account.roles.changed', new Role);
    }

    public function test_manual_retention_purge_is_disabled_by_default_and_only_removes_old_read_rows(): void
    {
        $user = User::factory()->create();
        $oldRead = $this->store($user, true, now()->subDays(45));
        $oldUnread = $this->store($user, false, now()->subDays(45));
        $this->artisan('master-core:notifications:purge-read')->assertExitCode(1);
        $this->assertDatabaseHas('master_core_notifications', ['id' => $oldRead->id]);

        config([
            'master-core-notifications.retention_days' => 30,
            'master-core-notifications.retention_approved' => true,
            'master-core-notifications.purge_enabled' => true,
        ]);
        $this->artisan('master-core:notifications:purge-read', ['--limit' => 1])->assertExitCode(0);
        $this->assertDatabaseMissing('master_core_notifications', ['id' => $oldRead->id]);
        $this->assertDatabaseHas('master_core_notifications', ['id' => $oldUnread->id]);
    }

    public function test_orphan_report_is_read_only_and_reports_unresolvable_recipients(): void
    {
        $id = (string) Str::ulid();
        DB::table('master_core_notifications')->insert([
            'id' => $id, 'notifiable_type' => 'Removed\\Host\\User', 'notifiable_id' => '42',
            'event_key' => 'account.roles.changed', 'data' => '{}', 'read_at' => null, 'created_at' => now()->utc(),
        ]);

        $this->artisan('master-core:notifications:report-orphans')
            ->expectsOutputToContain('Orphan rows: 1')
            ->assertExitCode(0);
        $this->assertDatabaseHas('master_core_notifications', ['id' => $id]);
    }

    /** Create a notice through the only registered v1 event key. */
    private function store(User $user, bool $read = false, mixed $createdAt = null): CoreNotification
    {
        $id = (string) Str::ulid();
        $this->assertTrue(app(NotificationInbox::class)->store($id, 'account.roles.changed', $user));
        $record = CoreNotification::query()->findOrFail($id);
        if ($read || $createdAt !== null) {
            DB::table('master_core_notifications')->where('id', $id)->update([
                'read_at' => $read ? now()->utc() : null,
                'created_at' => $createdAt ?? $record->created_at,
            ]);
            $record->refresh();
        }

        return $record;
    }
}
