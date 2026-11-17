<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Categories\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use MasterCore\AuditLogs\Models\AuditEvent;
use MasterCore\CrudEngine\Registry\CrudResourceRegistry;
use Tests\TestCase;

final class CategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function allow(string $ability): void
    {
        Gate::define($ability, static fn (User $user): bool => true);
    }

    public function test_categories_endpoints_enforce_each_permission_independently(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/core/categories')->assertForbidden();
        $this->allow('categories.create');
        $this->postJson('/api/v1/core/categories', ['name' => 'A'])->assertCreated();
        $this->allow('categories.view');
        $category = Category::firstOrFail();
        $this->getJson('/api/v1/core/categories')->assertOk();
        $this->allow('categories.update');
        $this->putJson('/api/v1/core/categories/'.$category->id, ['name' => 'B', 'slug' => 'b', 'status' => 'inactive'])->assertOk();
        $this->allow('categories.delete');
        $this->deleteJson('/api/v1/core/categories/'.$category->id)->assertOk();
        $eventNames = AuditEvent::query()->pluck('event_name')->all();
        sort($eventNames);
        $this->assertSame(['category.created', 'category.deleted', 'category.updated'], $eventNames);
    }

    public function test_each_categories_permission_is_required_for_its_api_operation(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Protected', 'slug' => 'protected', 'status' => 'active']);
        $this->actingAs($user)
            ->getJson('/api/v1/core/categories')->assertForbidden();
        $this->postJson('/api/v1/core/categories', ['name' => 'Denied'])->assertForbidden();
        $this->putJson('/api/v1/core/categories/'.$category->id, ['name' => 'Denied', 'slug' => 'denied', 'status' => 'active'])->assertForbidden();
        $this->deleteJson('/api/v1/core/categories/'.$category->id)->assertForbidden();
        $this->assertSame('Protected', $category->refresh()->name);
        $this->assertSame('categories', app(CrudResourceRegistry::class)->get('categories')->definition()->key);
    }

    public function test_create_validates_fields_generates_slug_and_audits_success(): void
    {
        $this->allow('categories.create');
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/core/categories', ['name' => 'Study Skills'])
            ->assertCreated()->assertJsonPath('data.slug', 'study-skills')->assertJsonPath('data.status', 'active');
        $this->postJson('/api/v1/core/categories', ['name' => 'Another', 'slug' => 'study-skills'])->assertUnprocessable();
        $this->postJson('/api/v1/core/categories', ['name' => ''])->assertUnprocessable();
        $this->assertDatabaseHas('master_core_audit_events', ['event_name' => 'category.created', 'subject_id' => '1']);
    }

    public function test_registered_resource_drives_search_filter_and_pagination(): void
    {
        $this->allow('categories.view');
        $this->actingAs(User::factory()->create());
        foreach (range(1, 31) as $i) {
            Category::create(['name' => 'Category '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'slug' => 'category-'.$i, 'status' => $i % 2 ? 'active' : 'inactive']);
        }
        $this->getJson('/api/v1/core/categories?search=Category%2030&filters[status]=inactive')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'category-30');
        $this->getJson('/api/v1/core/categories?per_page=10&page=2')->assertOk()->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.total', 31)->assertJsonCount(10, 'data');
        $this->getJson('/api/v1/core/categories?filters[unknown]=active')->assertUnprocessable();
    }
}
