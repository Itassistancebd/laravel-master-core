<?php

namespace Tests\Feature;

use Tests\TestCase;

final class MasterCorePackageProfileTest extends TestCase
{
    public function test_composer_entry_is_a_lean_foundation_metapackage(): void
    {
        $manifest = $this->readJson(base_path('packages/core/master-core/composer.json'));

        $this->assertSame('example/laravel-master-core', $manifest['name']);
        $this->assertSame('metapackage', $manifest['type']);
        $this->assertSame('^8.2', $manifest['require']['php']);
        $this->assertSame('^12.0', $manifest['require']['laravel/framework']);
        $this->assertSame('^0.1.0', $manifest['require']['example/laravel-master-core-foundation']);
        $this->assertArrayNotHasKey('autoload', $manifest);
        $this->assertArrayNotHasKey('scripts', $manifest);
        $this->assertArrayNotHasKey('migrations', $manifest);
        $this->assertArrayNotHasKey('laravel', $manifest['extra'] ?? []);
    }

    public function test_generator_profile_matches_optional_composer_and_npm_packages(): void
    {
        $manifest = $this->readJson(base_path('packages/core/master-core/composer.json'));
        $profile = $manifest['extra']['master-core'];
        $this->assertSame(1, $profile['profile_schema']);
        $this->assertSame('master-core-v1', $profile['profile']);
        $this->assertSame(['example/laravel-master-core-foundation'], $profile['baseline']);
        $this->assertFalse($profile['activation']['global_automatic_feature_activation']);
        $this->assertFalse($profile['activation']['automatic_migrations']);
        $this->assertFalse($profile['activation']['automatic_permission_grants']);
        $this->assertFalse($profile['activation']['automatic_super_admin_provisioning']);

        $suggestedPackages = array_keys($manifest['suggest']);
        $profilePackages = [];

        foreach ($profile['optional_features'] as $feature => $definition) {
            $composerManifest = $this->readJson(base_path('packages/core/'.$this->composerDirectory($feature).'/composer.json'));
            $npmManifest = $this->readJson(base_path('packages/core/'.$this->npmDirectory($feature).'/package.json'));

            $this->assertSame($composerManifest['name'], $definition['composer']);
            $this->assertSame($npmManifest['name'], $definition['npm']);
            $this->assertSame('0.1.0', $npmManifest['version']);
            $this->assertFalse($npmManifest['private']);
            $this->assertArrayHasKey('.', $npmManifest['exports']);
            $this->assertStringStartsWith('./dist/', $npmManifest['exports']['.']['import']);
            $this->assertStringStartsWith('./dist/', $npmManifest['exports']['.']['types']);
            $this->assertFileExists(base_path('packages/core/'.$this->npmDirectory($feature).'/'.$npmManifest['exports']['.']['development']));
            $this->assertSame($definition['frontend_styles'], $npmManifest['name'].'/style.css');
            $this->assertSame('./dist/index.css', $npmManifest['exports']['./style.css']['import']);
            $this->assertFileExists(base_path('packages/core/'.$this->npmDirectory($feature).'/'.$npmManifest['exports']['./style.css']['development']));
            $this->assertNotContains('src', $npmManifest['files'], 'Frontend packages must not include package-owned PHP source trees.');

            $this->assertContains($definition['frontend_entrypoint'], ['auth', 'settings', 'resource-owned']);
            $entryFile = base_path('packages/core/'.$this->npmDirectory($feature).'/resources/js/index.ts');
            $entrySource = file_get_contents($entryFile);
            foreach ($definition['frontend_exports'] as $export) {
                $this->assertStringContainsString($export, $entrySource, "Frontend export {$export} for {$feature} must exist.");
            }

            $actualCoreDependencies = array_values(array_filter(
                array_keys($composerManifest['require']),
                static fn (string $package): bool => str_starts_with($package, 'example/laravel-master-core-')
            ));
            sort($actualCoreDependencies);
            $declaredCoreDependencies = $definition['composer_dependencies'];
            sort($declaredCoreDependencies);
            $this->assertSame($actualCoreDependencies, $declaredCoreDependencies, "Profile dependencies for {$feature} must match Composer metadata.");
            $this->assertSame(
                is_dir(base_path('packages/core/'.$this->composerDirectory($feature).'/database/migrations')),
                $definition['has_package_migrations'],
                "Migration ownership for {$feature} must match its package."
            );
            $profilePackages[] = $definition['composer'];
        }

        sort($suggestedPackages);
        sort($profilePackages);
        $this->assertSame($suggestedPackages, $profilePackages);
    }

    public function test_development_host_uses_the_metapackage_and_pinned_local_semver_paths(): void
    {
        $manifest = $this->readJson(base_path('composer.json'));
        $this->assertSame('^0.1.0', $manifest['require']['example/laravel-master-core']);

        $repository = collect($manifest['repositories'])->firstWhere('url', 'packages/core/master-core');
        $this->assertNotNull($repository);
        $this->assertFalse($repository['options']['symlink']);
        $this->assertSame('0.1.0', $repository['options']['versions']['example/laravel-master-core']);
    }

    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function composerDirectory(string $feature): string
    {
        return match ($feature) {
            'auth' => 'auth',
            'roles-permissions' => 'roles-permissions',
            default => $feature,
        };
    }

    private function npmDirectory(string $feature): string
    {
        return match ($feature) {
            'auth' => 'auth',
            'roles-permissions' => 'roles-permissions',
            'settings' => 'settings',
            default => $feature,
        };
    }
}
