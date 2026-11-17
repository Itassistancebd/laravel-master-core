<?php

namespace MasterCore\UserManagement\Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use MasterCore\UserManagement\Contracts\UserManagementSafetyPolicy;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view-users', 'create-users', 'update-users', 'manage-user-status', 'delete-users', 'restore-users', 'force-delete-users', 'reset-user-passwords', 'resend-user-verification', 'manage-users', 'export-users', 'import-users'] as $ability) {
            Gate::define($ability, static fn () => true);
        }

        $this->operator = User::factory()->create();
    }

    public function test_user_list_is_server_paginated_filtered_and_safe(): void
    {
        User::factory()->create(['name' => 'Zara Example', 'email' => 'zara@example.test', 'is_active' => false]);
        User::factory()->create(['name' => 'Ari Example', 'email' => 'ari@example.test']);

        $this->actingAs($this->operator)->getJson('/api/v1/users?search=Ari&status=active&sort_by=name&sort_direction=asc&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('data.0.name', 'Ari Example')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');
    }

    public function test_trash_list_accepts_allowlisted_deleted_at_sorting(): void
    {
        $older = User::factory()->create(['email' => 'older-trash@example.test']);
        $newer = User::factory()->create(['email' => 'newer-trash@example.test']);
        $older->forceFill(['deleted_at' => '2025-01-01 00:00:00'])->save();
        $newer->forceFill(['deleted_at' => '2025-02-01 00:00:00'])->save();

        $this->actingAs($this->operator)
            ->getJson('/api/v1/users/trash?sort_by=deleted_at&sort_direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'older-trash@example.test')
            ->assertJsonPath('data.1.email', 'newer-trash@example.test');
    }

    public function test_user_export_streams_all_filtered_rows_with_safe_utf8_csv(): void
    {
        User::factory()->create(['name' => '=1+1 export-target', 'email' => 'formula@example.test', 'is_active' => true, 'email_verified_at' => now()]);
        User::factory()->create(['name' => 'বাংলা ব্যবহারকারী export-target', 'email' => 'bangla@example.test', 'is_active' => true, 'email_verified_at' => now()]);
        User::factory()->count(30)->sequence(fn ($sequence) => [
            'name' => 'Bulk export-target '.$sequence->index,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->create();
        User::factory()->create(['name' => 'Inactive export-target', 'email' => 'other@example.test', 'is_active' => false]);

        $response = $this->actingAs($this->operator)
            ->get('/api/v1/users/export.csv?search=export-target&status=active&email_verified=verified&sort_by=email&sort_direction=asc')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('বাংলা ব্যবহারকারী', $csv);
        $this->assertSame(33, substr_count($csv, "\r\n"), 'The export should include all 32 matches, including those beyond the list page size.');
        $this->assertStringNotContainsString('password', strtolower($csv));
        $this->assertStringNotContainsString('remember_token', strtolower($csv));
        $this->assertStringNotContainsString('other@example.test', $csv);
        $this->assertDatabaseHas('master_core_audit_events', ['event_name' => 'user.exported']);
    }

    public function test_export_permission_is_required(): void
    {
        Gate::define('export-users', static fn () => false);

        $this->actingAs($this->operator)->get('/api/v1/users/export.csv')->assertForbidden();
    }

    public function test_import_preview_is_read_only_and_confirmation_creates_inactive_accounts_without_email_or_roles(): void
    {
        Notification::fake();
        $trashed = User::factory()->create(['email' => 'reserved@example.test']);
        $trashed->delete();
        $file = UploadedFile::fake()->createWithContent('users.csv', "\xEF\xBB\xBFname,email\r\nNew User,new@example.test\r\nReserved,reserved@example.test\r\nSecond,new@example.test\r\n,bad-name@example.test\r\nSecond Bad Name,bad-name@example.test\r\nBad,bad-email\r\n");
        $usersBefore = User::count();
        $auditBefore = DB::table('master_core_audit_events')->count();

        $preview = $this->actingAs($this->operator)->postJson('/api/v1/users/import/preview', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.summary.total_rows', 6)
            ->assertJsonPath('data.summary.valid', 1)
            ->assertJsonPath('data.summary.skipped', 3)
            ->assertJsonPath('data.summary.failed', 2)
            ->assertJsonMissingPath('data.rows.0.password');

        $this->assertSame($usersBefore, User::count());
        $this->assertSame($auditBefore, DB::table('master_core_audit_events')->count());

        $this->postJson('/api/v1/users/import', [
            'file' => $file,
            'preview_token' => $preview->json('data.preview_token'),
        ])->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.skipped', 3)
            ->assertJsonPath('data.failed', 2);

        $created = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertFalse($created->is_active);
        $this->assertNotSame('password', $created->getAuthPassword());
        $this->assertDatabaseMissing('master_core_model_has_roles', ['model_id' => $created->id, 'model_type' => User::class]);
        Notification::assertNothingSent();
        $this->assertDatabaseHas('master_core_audit_events', ['event_name' => 'user.import.completed']);
    }

    public function test_import_and_export_preserve_bengali_utf8_text(): void
    {
        $bengali = "\u{09AC}\u{09BE}\u{0982}\u{09B2}\u{09BE}";
        $email = 'bengali@example.test';
        $file = UploadedFile::fake()->createWithContent('users.csv', "\xEF\xBB\xBFname,email\r\n{$bengali} User,{$email}\r\n");

        $preview = $this->actingAs($this->operator)
            ->postJson('/api/v1/users/import/preview', ['file' => $file])
            ->assertOk();
        $this->assertSame($bengali.' User', $preview->json('data.rows.0.name'));

        $this->postJson('/api/v1/users/import', [
            'file' => $file,
            'preview_token' => $preview->json('data.preview_token'),
        ])->assertOk()->assertJsonPath('data.imported', 1);

        $this->assertSame($bengali.' User', User::query()->where('email', $email)->value('name'));
        $csv = $this->get('/api/v1/users/export.csv?search='.urlencode($email))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString($bengali, $csv);
    }

    public function test_import_requires_both_import_and_create_permissions(): void
    {
        Gate::define('import-users', static fn () => false);
        $deniedFile = UploadedFile::fake()->createWithContent('users.csv', "name,email\r\nNew User,new@example.test\r\n");
        $this->actingAs($this->operator)->postJson('/api/v1/users/import/preview', ['file' => $deniedFile])->assertForbidden();

        Gate::define('import-users', static fn () => true);
        Gate::define('create-users', static fn () => false);
        $file = UploadedFile::fake()->createWithContent('users.csv', "name,email\r\nNew User,new@example.test\r\n");
        $preview = $this->actingAs($this->operator)->postJson('/api/v1/users/import/preview', ['file' => $file])->assertOk();

        $this->postJson('/api/v1/users/import', ['file' => $file, 'preview_token' => $preview->json('data.preview_token')])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_import_template_is_downloadable_only_with_import_permission(): void
    {
        $response = $this->actingAs($this->operator)->get('/api/v1/users/import-template.csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertSame("\xEF\xBB\xBF\"name\",\"email\"\r\n", $response->getContent());

        Gate::define('import-users', static fn () => false);
        $this->get('/api/v1/users/import-template.csv')->assertForbidden();
    }

    public function test_import_rejects_bad_headers_oversized_files_and_excessive_rows(): void
    {
        $this->actingAs($this->operator);
        $badHeader = UploadedFile::fake()->createWithContent('users.csv', "name,role\r\nAda,admin\r\n");
        $this->postJson('/api/v1/users/import/preview', ['file' => $badHeader])->assertUnprocessable()->assertJsonValidationErrors('file');

        $tooManyRows = "name,email\r\n".str_repeat("Ada,ada@example.test\r\n", 1001);
        $this->postJson('/api/v1/users/import/preview', ['file' => UploadedFile::fake()->createWithContent('users.csv', $tooManyRows)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $tooLarge = UploadedFile::fake()->createWithContent('users.csv', str_repeat('x', (2 * 1024 * 1024) + 1));
        $this->postJson('/api/v1/users/import/preview', ['file' => $tooLarge])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_import_rejects_preview_file_mismatch_expiry_and_revalidates_new_duplicates(): void
    {
        $file = UploadedFile::fake()->createWithContent('users.csv', "name,email\r\nRacing User,race@example.test\r\n");
        $preview = $this->actingAs($this->operator)->postJson('/api/v1/users/import/preview', ['file' => $file])->assertOk();
        $token = $preview->json('data.preview_token');
        $changedFile = UploadedFile::fake()->createWithContent('users.csv', "name,email\r\nChanged User,changed@example.test\r\n");
        $this->postJson('/api/v1/users/import', ['file' => $changedFile, 'preview_token' => $token])->assertUnprocessable();

        $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        $payload['expires_at'] = now()->subMinute()->timestamp;
        $expired = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->postJson('/api/v1/users/import', ['file' => $file, 'preview_token' => $expired])->assertUnprocessable();

        User::factory()->create(['email' => 'race@example.test']);
        $this->postJson('/api/v1/users/import', ['file' => $file, 'preview_token' => $token])
            ->assertOk()->assertJsonPath('data.imported', 0)->assertJsonPath('data.skipped', 1);
        $this->assertSame(1, User::where('email', 'race@example.test')->count());
    }

    public function test_import_rolls_back_rows_when_aggregate_audit_write_fails(): void
    {
        $file = UploadedFile::fake()->createWithContent('users.csv', "name,email\r\nRollback User,rollback@example.test\r\n");
        $preview = $this->actingAs($this->operator)->postJson('/api/v1/users/import/preview', ['file' => $file])->assertOk();
        Event::listen('master-core.audit.record.v1', static function (array $event): void {
            if (($event['event_name'] ?? null) === 'user.import.completed') {
                throw new \RuntimeException('Simulated audit failure.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v1/users/import', ['file' => $file, 'preview_token' => $preview->json('data.preview_token')]);
            $this->fail('The simulated audit failure should have been raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit failure.', $exception->getMessage());
        }
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
    }

    public function test_import_and_export_capabilities_are_reported_from_their_exact_gates(): void
    {
        Gate::define('manage-users', static fn () => true);
        Gate::define('export-users', static fn () => false);
        Gate::define('import-users', static fn () => false);

        $this->actingAs($this->operator)->getJson('/api/v1/users/capabilities')
            ->assertOk()->assertJsonPath('data.export', false)->assertJsonPath('data.import', false);
    }

    public function test_management_requires_host_defined_abilities(): void
    {
        Gate::define('view-users', static fn () => false);
        Gate::define('manage-users', static fn () => false);

        $this->actingAs($this->operator)->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_show_and_update_accept_only_identity_fields(): void
    {
        $target = User::factory()->create(['password' => bcrypt('OriginalPassword123')]);
        $this->actingAs($this->operator)->getJson("/api/v1/users/{$target->id}")
            ->assertOk()->assertJsonPath('data.email', $target->email);

        $this->putJson("/api/v1/users/{$target->id}", [
            'name' => 'Updated Name',
            'email' => 'updated@example.test',
            'is_active' => false,
            'password' => 'ChangedPassword123',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Name');

        $target->refresh();
        $this->assertTrue($target->is_active);
        $this->assertTrue(password_verify('OriginalPassword123', $target->password));
    }

    public function test_create_sends_password_setup_link_without_returning_password(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->operator)->postJson('/api/v1/users', [
            'name' => 'New User',
            'email' => 'new-user@example.test',
            'is_active' => false,
            'password' => 'must-not-be-accepted',
        ])->assertCreated()->assertJsonMissingPath('data.password');

        $created = User::where('email', 'new-user@example.test')->firstOrFail();
        $this->assertTrue($created->is_active);
        $this->assertNotSame('must-not-be-accepted', $created->password);
        Notification::assertSentTo($created, ResetPassword::class);
        $this->assertSame($created->id, $response->json('data.id'));
    }

    public function test_trash_email_stays_unique_and_restore_preserves_inactive_status(): void
    {
        $target = User::factory()->create(['email' => 'reserved@example.test', 'is_active' => false]);
        $target->delete();

        $this->actingAs($this->operator)->getJson('/api/v1/users/trash')
            ->assertOk()->assertJsonPath('data.0.id', $target->id);

        $this->postJson('/api/v1/users', [
            'name' => 'Duplicate',
            'email' => 'reserved@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson("/api/v1/users/{$target->id}/restore")->assertOk();
        $this->assertFalse($target->fresh()->is_active);
        $this->assertNull($target->fresh()->deleted_at);
    }

    public function test_inactive_and_trashed_accounts_cannot_login(): void
    {
        $inactive = User::factory()->create(['email' => 'inactive@example.test', 'is_active' => false]);
        $this->postJson('/login', ['email' => $inactive->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $trashed = User::factory()->create(['email' => 'trashed@example.test']);
        $trashed->delete();
        $this->postJson('/login', ['email' => $trashed->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_deactivated_existing_session_is_rejected_and_logged_out(): void
    {
        $user = User::factory()->create(['password' => bcrypt('StrongPassword123')]);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'StrongPassword123'])->assertOk();
        User::whereKey($user->id)->update(['is_active' => false]);

        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_destructive_actions_fail_closed_without_a_host_safety_policy(): void
    {
        $target = User::factory()->create();
        $this->actingAs($this->operator)->patchJson("/api/v1/users/{$target->id}/status", ['is_active' => false])->assertForbidden();
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_host_safety_policy_can_authorize_status_changes_and_sessions_are_revoked(): void
    {
        $this->allowDestructiveActions();
        config(['session.driver' => 'database']);
        $target = User::factory()->create();
        \DB::table('sessions')->insert([
            'id' => 'user-session-to-revoke',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $this->actingAs($this->operator)->patchJson("/api/v1/users/{$target->id}/status", ['is_active' => false])->assertOk();
        $this->assertFalse($target->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'user-session-to-revoke']);
    }

    public function test_bulk_deactivation_is_atomic_when_host_safety_policy_denies_one_user(): void
    {
        $allowed = User::factory()->create();
        $blocked = User::factory()->create();
        $this->app->instance(UserManagementSafetyPolicy::class, new class($blocked->id) implements UserManagementSafetyPolicy
        {
            public function __construct(private readonly int $blockedId) {}

            public function allows(Authenticatable $actor, Model $target, string $operation): bool
            {
                return $target->getKey() !== $this->blockedId;
            }
        });

        $this->actingAs($this->operator)->postJson('/api/v1/users/bulk/deactivate', ['ids' => [$allowed->id, $blocked->id]])
            ->assertForbidden();

        $this->assertTrue($allowed->fresh()->is_active);
        $this->assertTrue($blocked->fresh()->is_active);
    }

    public function test_verification_resend_uses_the_host_notification_contract(): void
    {
        Notification::fake();
        $target = User::factory()->unverified()->create();

        $this->actingAs($this->operator)->postJson("/api/v1/users/{$target->id}/verification-notification")->assertAccepted();
        Notification::assertSentTo($target, VerifyEmail::class);
    }

    public function test_password_reset_endpoint_sends_a_framework_reset_notification(): void
    {
        Notification::fake();
        $target = User::factory()->create();

        $this->actingAs($this->operator)->postJson("/api/v1/users/{$target->id}/password-reset")->assertAccepted();
        Notification::assertSentTo($target, ResetPassword::class);
    }

    public function test_bulk_restore_restores_all_selected_users_in_one_operation(): void
    {
        $users = User::factory()->count(2)->create();
        foreach ($users as $user) {
            $user->delete();
        }

        $this->actingAs($this->operator)->postJson('/api/v1/users/bulk/restore', ['ids' => $users->modelKeys()])
            ->assertOk()->assertJsonPath('data.affected', 2);

        foreach ($users as $user) {
            $this->assertNull($user->fresh()->deleted_at);
        }
    }

    public function test_soft_delete_blocks_self_removal_and_preserves_recovery_state(): void
    {
        $this->allowDestructiveActions();
        $target = User::factory()->create(['is_active' => false]);

        $this->actingAs($this->operator)->deleteJson("/api/v1/users/{$target->id}")->assertOk();
        $this->assertTrue($target->fresh()->trashed());
        $this->assertFalse($target->fresh()->is_active);
        $this->deleteJson("/api/v1/users/{$this->operator->id}")->assertForbidden();
        $this->assertFalse($this->operator->fresh()->trashed());
    }

    public function test_permanent_delete_requires_matching_email_and_safety_policy(): void
    {
        $this->allowDestructiveActions();
        $target = User::factory()->create();
        $target->delete();
        $this->withSession(['auth.password_confirmed_at' => time()]);

        $this->actingAs($this->operator)->deleteJson("/api/v1/users/{$target->id}/force", ['confirmation' => 'wrong@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->deleteJson("/api/v1/users/{$target->id}/force", ['confirmation' => $target->email])->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    private function allowDestructiveActions(): void
    {
        $this->app->instance(UserManagementSafetyPolicy::class, new class implements UserManagementSafetyPolicy
        {
            public function allows(Authenticatable $actor, Model $target, string $operation): bool
            {
                return true;
            }
        });
    }

    public function test_capabilities_report_gates_without_granting_them(): void
    {
        Gate::define('manage-users', static fn () => false);
        Gate::define('view-users', static fn () => false);

        $this->actingAs($this->operator)->getJson('/api/v1/users/capabilities')
            ->assertOk()->assertJsonPath('data.view', false)->assertJsonPath('data.create', true);
    }
}
