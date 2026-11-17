<?php

namespace MasterCore\Settings\Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use MasterCore\Settings\Models\Setting;
use MasterCore\Settings\Http\Middleware\ApplySettingsForRequest;
use MasterCore\Settings\Services\SettingsManager;
use Tests\TestCase;

class SettingsModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_migration_creates_the_owned_table_and_composite_unique_key(): void
    {
        $this->assertTrue($this->app['db']->getSchemaBuilder()->hasTable('master_core_settings'));
        $this->assertTrue($this->app['db']->getSchemaBuilder()->hasColumns('master_core_settings', [
            'id', 'group_name', 'setting_key', 'value', 'created_at', 'updated_at',
        ]));

        Setting::query()->create([
            'group_name' => 'general',
            'setting_key' => 'application_name',
            'value' => 'First value',
        ]);

        try {
            Setting::query()->create([
                'group_name' => 'general',
                'setting_key' => 'application_name',
                'value' => 'Duplicate value',
            ]);
            $this->fail('The group and key pair must be unique.');
        } catch (QueryException) {
            $this->assertSame(1, Setting::query()->count());
        }
    }

    public function test_package_migration_rollback_is_scoped_to_its_owned_table(): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_14_000000_create_master_core_settings_table.php';
        $migration->down();

        $this->assertFalse(Schema::hasTable('master_core_settings'));
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_code_defaults_are_used_until_a_database_override_exists(): void
    {
        $settings = app(SettingsManager::class);

        $this->assertSame(config('app.name'), $settings->group('general')['settings']['application_name']['value']);

        $settings->updateGroup('general', ['application_name' => 'Portal']);

        $this->assertSame('Portal', $settings->group('general')['settings']['application_name']['value']);
        $this->assertSame('Portal', Setting::query()->where('setting_key', 'application_name')->value('value'));
    }

    public function test_public_api_returns_only_explicitly_public_allowlisted_values(): void
    {
        Setting::query()->create([
            'group_name' => 'system',
            'setting_key' => 'default_page_size',
            'value' => 100,
        ]);

        $response = $this->getJson('/api/v1/settings/public')->assertOk();
        $public = $response->json('data');

        $this->assertSame(['general', 'branding', 'localization', 'date_time', 'currency', 'appearance'], array_keys($public));
        $this->assertArrayNotHasKey('system', $public);
        $this->assertArrayNotHasKey('default_page_size', $public['general'] ?? []);
        $this->assertSame(config('app.name'), $public['general']['application_name']);
    }

    public function test_settings_group_and_public_cache_are_invalidated_after_updates_and_reset(): void
    {
        $settings = app(SettingsManager::class);

        $this->assertSame(config('app.name'), $settings->group('general')['settings']['application_name']['value']);
        $this->assertSame(config('app.name'), $settings->publicSettings()['general']['application_name']);

        $settings->updateGroup('general', ['application_name' => 'Cached Portal']);
        $this->assertSame('Cached Portal', $settings->publicSettings()['general']['application_name']);

        $settings->resetGroup('general');
        $this->assertSame(config('app.name'), $settings->publicSettings()['general']['application_name']);
        $this->assertDatabaseMissing('master_core_settings', [
            'group_name' => 'general',
            'setting_key' => 'application_name',
        ]);
    }

    public function test_ordinary_settings_reject_secret_like_keys(): void
    {
        config(['master-core-settings.groups.unsafe.fields.api_token' => [
            'label' => 'Token',
            'type' => 'text',
            'default' => null,
            'rules' => ['nullable', 'string'],
        ]]);
        config(['master-core-settings.groups.unsafe.label' => 'Unsafe']);

        $this->expectException(\LogicException::class);
        app(SettingsManager::class)->group('unsafe');
    }

    public function test_private_settings_api_requires_authentication_and_the_host_defined_ability(): void
    {
        $this->getJson('/api/v1/settings')->assertUnauthorized();

        Gate::define('view-settings', fn (User $user): bool => false);
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/settings')
            ->assertForbidden();

        Gate::define('view-settings', fn (User $user): bool => true);
        $this->getJson('/api/v1/settings')->assertOk();
    }

    public function test_settings_api_validates_values_and_rejects_unknown_keys(): void
    {
        Gate::define('manage-settings', fn (User $user): bool => true);
        $this->actingAs(User::factory()->create());

        $this->putJson('/api/v1/settings/localization', [
            'settings' => ['locale' => 'xx'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.locale']);

        $this->putJson('/api/v1/settings/general', [
            'settings' => ['application_name' => 'Portal', 'api_token' => 'should-not-persist'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings']);

        $this->assertDatabaseMissing('master_core_settings', ['setting_key' => 'api_token']);
    }

    public function test_branding_upload_validates_and_persists_a_safe_storage_reference(): void
    {
        Gate::define('manage-settings', fn (User $user): bool => true);
        $this->actingAs(User::factory()->create());
        Storage::fake('public');
        config(['master-core-settings.branding.disk' => 'public']);

        $response = $this->postJson('/api/v1/settings/branding/assets', [
            'asset_type' => 'logo',
            'file' => UploadedFile::fake()->createWithContent(
                'logo.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pWQAAAAASUVORK5CYII=', true),
            ),
        ])->assertOk();

        $path = $response->json('data.settings.logo_path.value');
        $this->assertIsString($path);
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('master_core_settings', [
            'group_name' => 'branding',
            'setting_key' => 'logo_path',
            'value' => json_encode($path),
        ]);
    }

    public function test_branding_upload_rejects_non_image_files(): void
    {
        Gate::define('manage-settings', fn (User $user): bool => true);
        $this->actingAs(User::factory()->create());
        Storage::fake('public');

        $this->postJson('/api/v1/settings/branding/assets', [
            'asset_type' => 'favicon',
            'file' => UploadedFile::fake()->create('not-an-image.txt', 2, 'text/plain'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_locale_and_timezone_settings_are_applied_for_one_request_and_then_restored(): void
    {
        $previousLocale = app()->getLocale();
        $previousTimezone = date_default_timezone_get();
        app(SettingsManager::class)->updateGroup('localization', [
            'locale' => 'bn',
            'timezone' => 'Asia/Dhaka',
        ]);

        $this->assertSame('bn', app(SettingsManager::class)->group('localization')['settings']['locale']['value']);
        $this->assertSame('Asia/Dhaka', app(SettingsManager::class)->group('localization')['settings']['timezone']['value']);

        Route::get('/__settings-runtime', fn () => response()->json([
            'locale' => app()->getLocale(),
            'timezone' => date_default_timezone_get(),
        ]))->middleware(ApplySettingsForRequest::class);

        $this->getJson('/__settings-runtime')
            ->assertOk()
            ->assertExactJson(['locale' => 'bn', 'timezone' => 'Asia/Dhaka']);

        $this->assertSame($previousLocale, app()->getLocale());
        $this->assertSame($previousTimezone, date_default_timezone_get());
    }
}
