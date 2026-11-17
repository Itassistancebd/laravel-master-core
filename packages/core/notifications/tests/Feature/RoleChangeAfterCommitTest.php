<?php

namespace MasterCore\Notifications\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use MasterCore\RolesPermissions\Events\RolesPermissionsChanged;
use MasterCore\RolesPermissions\Models\Role;
use Tests\TestCase;

/** This isolated SQLite in-memory test verifies the real outermost commit callback. */
final class RoleChangeAfterCommitTest extends TestCase
{
    use DatabaseMigrations;

    public function test_role_change_notice_waits_for_the_source_transaction_commit(): void
    {
        $recipient = User::factory()->create();
        $event = RolesPermissionsChanged::record('super-admin-provisioned', null, $recipient);
        $connection = DB::connection();
        $delivered = false;

        $connection->beginTransaction();
        DB::afterCommit(static function () use ($event, &$delivered): void {
            event($event);
            $delivered = true;
        });
        $this->assertFalse($delivered);
        $this->assertDatabaseMissing('master_core_notifications', ['id' => $event->eventId]);

        $connection->commit();

        $this->assertTrue($delivered);
        $this->assertDatabaseHas('master_core_notifications', ['id' => $event->eventId]);

        // DatabaseMigrations rolls package tables back after the test; remove guarded inbox data first.
        DB::table('master_core_notifications')->where('id', $event->eventId)->delete();
        DB::table('users')->where('id', $recipient->getKey())->delete();
    }

    public function test_successful_role_assignment_api_writes_a_generic_notice_after_commit(): void
    {
        Gate::define('manage-user-roles', static fn (): bool => true);
        $actor = User::factory()->create();
        $recipient = User::factory()->create();
        $role = new Role;
        $role->forceFill([
            'name' => 'Member',
            'role_key' => 'test-member-role',
            'guard_name' => 'web',
            'is_system' => false,
        ])->save();

        $this->actingAs($actor)->putJson('/api/v1/roles-permissions/users/'.$recipient->getKey().'/roles', [
            'role_ids' => [$role->getKey()],
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('master_core_model_has_roles', [
            'role_id' => $role->getKey(),
            'model_type' => $recipient->getMorphClass(),
            'model_id' => (string) $recipient->getKey(),
        ]);
        $this->assertDatabaseHas('master_core_notifications', [
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => (string) $recipient->getKey(),
            'event_key' => 'account.roles.changed',
        ]);
        $stored = DB::table('master_core_notifications')->where('notifiable_id', (string) $recipient->getKey())->first();
        $this->assertSame('Your account access was updated.', json_decode($stored->data, true)['message']);

        DB::table('master_core_audit_events')->delete();
        DB::table('master_core_notifications')->where('notifiable_id', (string) $recipient->getKey())->delete();
        DB::table('master_core_model_has_roles')->where('model_id', (string) $recipient->getKey())->delete();
        DB::table('master_core_roles')->where('id', $role->getKey())->delete();
        DB::table('users')->whereIn('id', [$actor->getKey(), $recipient->getKey()])->delete();
    }
}
