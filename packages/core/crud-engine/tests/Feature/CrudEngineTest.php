<?php

namespace MasterCore\CrudEngine\Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use MasterCore\AuditLogs\Contracts\AuditLogger;
use MasterCore\CrudEngine\Data\CrudResourceDefinition;
use MasterCore\CrudEngine\Query\CrudQueryBuilder;
use MasterCore\CrudEngine\Registry\CrudResourceRegistry;
use MasterCore\CrudEngine\Tests\Fixtures\FixtureAccountResource;
use MasterCore\CrudEngine\Tests\Fixtures\Models\FixtureAccount;
use Tests\TestCase;

final class CrudEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('phase9_crud_fixture_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        $this->app->instance(CrudResourceRegistry::class, new CrudResourceRegistry([new FixtureAccountResource]));
        Gate::define('view-fixture-accounts', fn (User $user): bool => $user->email === 'operator@example.test');
        Gate::define('view-fixture-account', fn (User $user): bool => $user->email === 'operator@example.test');
        Gate::define('create-fixture-account', fn (User $user): bool => $user->email === 'operator@example.test');
        Gate::define('edit-fixture-account', fn (User $user): bool => $user->email === 'operator@example.test');
        Gate::define('edit-fixture-account-record', fn (User $user, FixtureAccount $target): bool => $target->email !== 'locked@example.test');
        Gate::define('delete-fixture-accounts', fn (User $user): bool => $user->email === 'operator@example.test');
        Gate::define('delete-fixture-account-record', fn (User $user, FixtureAccount $target): bool => $target->email !== 'locked@example.test');
        Gate::define('restore-fixture-account', fn (User $user): bool => $user->email === 'operator@example.test');
        Gate::define('force-delete-fixture-account', fn (User $user): bool => $user->email === 'operator@example.test');

        require __DIR__.'/../Fixtures/routes.php';
    }

    public function test_code_registered_resource_is_resolved_and_unknown_or_invalid_resources_fail_closed(): void
    {
        $registry = app(CrudResourceRegistry::class);
        $this->assertSame('fixture-accounts', $registry->get('fixture-accounts')->definition()->key);
        $this->assertCount(1, $registry->all());

        try {
            $registry->get('users');
            $this->fail('Unregistered resource should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('The CRUD resource is not registered.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        new CrudResourceRegistry([new FixtureAccountResource, new FixtureAccountResource]);
    }

    public function test_provider_builds_the_registry_only_from_configured_resource_classes(): void
    {
        config(['master-core-crud-engine.resources' => [FixtureAccountResource::class]]);
        $this->app->forgetInstance(CrudResourceRegistry::class);

        $this->assertSame('fixture-accounts', app(CrudResourceRegistry::class)->get('fixture-accounts')->definition()->key);
    }

    public function test_invalid_resource_key_or_sql_like_fields_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrudResourceDefinition('fixture accounts', 'name', sortableFields: ['name; drop table users']);
    }

    public function test_list_search_filter_sort_and_pagination_use_registered_fields_only(): void
    {
        $operator = $this->operator();
        foreach (range(1, 31) as $number) {
            FixtureAccount::query()->create([
                'name' => sprintf('Account %02d', $number),
                'email' => sprintf('account%02d@example.test', $number),
                'is_active' => $number % 2 === 0,
            ]);
        }

        $response = $this->actingAs($operator)->getJson('/api/v1/crud-fixture/accounts?search=Account&filters[active]=1&sort_by=name&sort_direction=desc&per_page=10&page=1')
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.total', 15);
        $this->assertStringStartsWith('Account 30', $response->json('data.0.name'));
        $this->getJson('/api/v1/crud-fixture/accounts?sort_by=email;drop')->assertUnprocessable();
        $this->getJson('/api/v1/crud-fixture/accounts?filters[is_admin]=1')->assertUnprocessable();
        $this->getJson('/api/v1/crud-fixture/accounts?table=users')->assertUnprocessable();
        $this->getJson('/api/v1/crud-fixture/accounts?per_page=1000')->assertUnprocessable();
    }

    public function test_fixture_crud_validates_input_serializes_allowlisted_fields_and_records_registered_audit_events(): void
    {
        $this->actingAs($this->operator());
        $this->postJson('/api/v1/crud-fixture/accounts', [
            'name' => 'Ada Example', 'email' => 'ada@example.test', 'is_admin' => true,
        ])->assertCreated()->assertJsonPath('data.name', 'Ada Example')->assertJsonMissingPath('data.is_admin');
        $record = FixtureAccount::query()->firstOrFail();
        $this->assertDatabaseHas('master_core_audit_events', [
            'event_name' => 'user.created', 'subject_id' => (string) $record->getKey(),
        ]);

        $this->putJson('/api/v1/crud-fixture/accounts/'.$record->getKey(), [
            'name' => 'Ada Updated', 'email' => 'invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->putJson('/api/v1/crud-fixture/accounts/'.$record->getKey(), ['name' => 'Ada Updated'])->assertOk()
            ->assertJsonPath('data.name', 'Ada Updated')->assertJsonMissingPath('data.is_admin');
        $this->assertDatabaseHas('master_core_audit_events', ['event_name' => 'user.updated']);
    }

    public function test_authentication_and_record_policies_are_enforced(): void
    {
        $this->getJson('/api/v1/crud-fixture/accounts')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['email' => 'no-access@example.test']))
            ->getJson('/api/v1/crud-fixture/accounts')->assertForbidden();

        $operator = $this->operator();
        $locked = FixtureAccount::query()->create(['name' => 'Locked', 'email' => 'locked@example.test']);
        $this->actingAs($operator)->putJson('/api/v1/crud-fixture/accounts/'.$locked->getKey(), ['name' => 'Changed'])->assertForbidden();
    }

    public function test_detail_uses_the_registered_serializer_and_returns_not_found_for_unknown_ids(): void
    {
        $operator = $this->operator();
        $record = FixtureAccount::query()->create(['name' => 'Visible', 'email' => 'visible@example.test']);

        $this->actingAs($operator)->getJson('/api/v1/crud-fixture/accounts/'.$record->getKey())
            ->assertOk()->assertJsonPath('data.name', 'Visible')->assertJsonMissingPath('data.password');
        $this->getJson('/api/v1/crud-fixture/accounts/99999')->assertNotFound();
    }

    public function test_bulk_trash_is_all_or_nothing_when_one_record_policy_denies(): void
    {
        $operator = $this->operator();
        $allowed = FixtureAccount::query()->create(['name' => 'Allowed', 'email' => 'allowed@example.test']);
        $locked = FixtureAccount::query()->create(['name' => 'Locked', 'email' => 'locked@example.test']);

        $this->actingAs($operator)->postJson('/api/v1/crud-fixture/accounts/bulk/trash', [
            'ids' => [$allowed->getKey(), $locked->getKey()],
        ])->assertForbidden();

        $this->assertFalse($allowed->fresh()->trashed());
        $this->assertFalse($locked->fresh()->trashed());
        $this->assertDatabaseMissing('master_core_audit_events', ['event_name' => 'user.trashed']);
    }

    public function test_critical_fixture_mutation_rolls_back_when_the_registered_audit_write_fails(): void
    {
        $logger = \Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit storage unavailable'));
        $this->app->instance(AuditLogger::class, $logger);

        $this->actingAs($this->operator())->postJson('/api/v1/crud-fixture/accounts', [
            'name' => 'Must Roll Back', 'email' => 'rollback@example.test',
        ])->assertServerError();

        $this->assertDatabaseMissing('phase9_crud_fixture_accounts', ['email' => 'rollback@example.test']);
    }

    public function test_soft_delete_restore_and_guarded_permanent_delete_flow(): void
    {
        $operator = $this->operator();
        $record = FixtureAccount::query()->create(['name' => 'Trashable', 'email' => 'trashable@example.test']);
        $this->actingAs($operator)->deleteJson('/api/v1/crud-fixture/accounts/'.$record->getKey())->assertOk();
        $this->assertSoftDeleted('phase9_crud_fixture_accounts', ['id' => $record->getKey()]);
        $this->postJson('/api/v1/crud-fixture/accounts/'.$record->getKey().'/restore')->assertOk();
        $this->assertNotSoftDeleted('phase9_crud_fixture_accounts', ['id' => $record->getKey()]);

        $this->deleteJson('/api/v1/crud-fixture/accounts/'.$record->getKey().'/force', ['confirmation' => (string) $record->getKey()])->assertNotFound();
        $this->deleteJson('/api/v1/crud-fixture/accounts/'.$record->getKey())->assertOk();
        $this->deleteJson('/api/v1/crud-fixture/accounts/'.$record->getKey().'/force', ['confirmation' => 'wrong'])->assertUnprocessable();
        $this->deleteJson('/api/v1/crud-fixture/accounts/'.$record->getKey().'/force', ['confirmation' => (string) $record->getKey()])->assertOk();
        $this->assertDatabaseMissing('phase9_crud_fixture_accounts', ['id' => $record->getKey()]);
    }

    public function test_shared_query_builder_can_be_used_without_database_owned_resource_metadata(): void
    {
        $queries = app(CrudQueryBuilder::class);
        $this->assertInstanceOf(Builder::class, app(CrudResourceRegistry::class)->get('fixture-accounts')->query());
        $this->assertSame(0, $queries->paginate(app(CrudResourceRegistry::class)->get('fixture-accounts'), [])->total());
        $this->assertFalse(Schema::hasTable('master_core_crud_resources'));
    }

    private function operator(): User
    {
        return User::factory()->create(['email' => 'operator@example.test']);
    }
}
