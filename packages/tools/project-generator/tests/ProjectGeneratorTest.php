<?php

namespace MasterCore\ProjectGenerator\Tests;

require __DIR__.'/bootstrap.php';

use MasterCore\ProjectGenerator\Console\Application;
use MasterCore\ProjectGenerator\Doctor\Doctor;
use MasterCore\ProjectGenerator\Installation\InstallationException;
use MasterCore\ProjectGenerator\Installation\NativeProcessExecutor;
use MasterCore\ProjectGenerator\Installation\ProcessExecutor;
use MasterCore\ProjectGenerator\Installation\ProjectInstaller;
use MasterCore\ProjectGenerator\Installation\TargetPath;
use MasterCore\ProjectGenerator\Installation\TrustedSources;
use MasterCore\ProjectGenerator\Planning\PlanException;
use MasterCore\ProjectGenerator\Planning\ProjectPlanner;
use MasterCore\ProjectGenerator\Profile\CoreProfile;
use MasterCore\ProjectGenerator\Profile\ProfileLoader;
use MasterCore\ProjectGenerator\Profile\ProfileException;
use MasterCore\ProjectGenerator\Profile\ProfileValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProjectGeneratorTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'master-core-generator-test-'.bin2hex(random_bytes(5));
        mkdir($this->temporary);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporary);
    }

    public function test_bundled_profile_matches_live_composer_and_npm_sources(): void
    {
        $profile = $this->loadProfile();
        (new ProfileValidator)->validateAgainstSource($profile, dirname(__DIR__, 4));
        self::assertSame(['example/laravel-master-core-foundation'], $profile->baseline());
    }

    public function test_profile_rejects_missing_identity_and_unsupported_runtime_versions(): void
    {
        $validator = new ProfileValidator;
        $data = $this->loadProfile()->data;
        $data['metapackage']['name'] = '';
        $this->expectException(ProfileException::class);
        $validator->validate(new CoreProfile($data));
    }

    public function test_profile_rejects_unsupported_php_compatibility(): void
    {
        $data = $this->loadProfile()->data;
        $data['metapackage']['require']['php'] = '^8.1';
        $this->expectExceptionMessage('Unsupported compatibility constraint for php');
        (new ProfileValidator)->validate(new CoreProfile($data));
    }

    public function test_profile_rejects_a_missing_npm_package_dependency(): void
    {
        $data = $this->loadProfile()->data;
        unset($data['npm_packages']['crud-engine']);
        $this->expectExceptionMessage("Feature 'crud-engine' does not match the bundled npm package index.");
        (new ProfileValidator)->validate(new CoreProfile($data));
    }

    public function test_profile_rejects_an_invalid_stylesheet_export(): void
    {
        $data = $this->loadProfile()->data;
        $data['npm_packages']['settings']['exports']['./style.css']['import'] = './dist/missing.css';
        $this->expectExceptionMessage("Feature 'settings' is missing its explicit stylesheet export.");
        (new ProfileValidator)->validate(new CoreProfile($data));
    }

    public function test_profile_rejects_incomplete_export_metadata(): void
    {
        $data = $this->loadProfile()->data;
        $data['npm_packages']['auth']['exports']['.']['development'] = './src/index.ts';
        $this->expectExceptionMessage("Feature 'auth' is missing a complete npm entrypoint export map.");
        (new ProfileValidator)->validate(new CoreProfile($data));
    }

    public function test_trusted_registry_rejects_non_https_sources_before_staging(): void
    {
        $profile = $this->trustedProfile();
        $plan = (new ProjectPlanner)->build($profile, [], []);
        $trusted = new TrustedSources(['http://packages.invalid'], ['acme', 'laravel'], 'https://registry.acme.test', ['@acme']);
        $this->expectExceptionMessage('Only explicit HTTPS Composer repositories are accepted.');
        $trusted->assertTrusted($profile, $plan);
    }

    public function test_feature_dependency_closure_and_frontend_selection_are_explicit(): void
    {
        $profile = $this->loadProfile();
        $plan = (new ProjectPlanner)->build($profile, ['roles-permissions'], ['roles-permissions']);
        self::assertSame(['auth', 'user-management', 'roles-permissions'], $plan->backendFeatures);
        self::assertSame(['roles-permissions'], $plan->frontendFeatures);
        self::assertSame(['example/laravel-master-core-foundation'], $plan->baselinePackages);
        self::assertArrayHasKey('@itassistance-bd/ui', $plan->npmDependencies);
    }

    public function test_invalid_or_implicit_feature_selection_fails_strictly(): void
    {
        $profile = $this->loadProfile();
        $this->expectException(PlanException::class);
        (new ProjectPlanner)->build($profile, ['everything'], []);
    }

    public function test_core_auth_shell_requires_explicit_auth_backend(): void
    {
        $profile = $this->loadProfile();
        $this->expectException(PlanException::class);
        (new ProjectPlanner)->build($profile, [], [], 'core');
    }

    public function test_cli_rejects_invalid_features_and_dry_run_does_not_create_target(): void
    {
        $target = $this->temporary.DIRECTORY_SEPARATOR.'plan-only';
        $exit = (new Application)->run(['master-core', 'new', $target, '--features=not-a-feature', '--frontends=', '--dry-run']);
        self::assertSame(1, $exit);
        self::assertDirectoryDoesNotExist($target);
    }

    public function test_cli_dry_run_creates_no_files_and_noninteractive_selection_is_mandatory(): void
    {
        $target = $this->temporary.DIRECTORY_SEPARATOR.'dry-run';
        self::assertSame(0, (new Application)->run(['master-core', 'new', $target, '--features=', '--frontends=', '--dry-run', '--json']));
        self::assertDirectoryDoesNotExist($target);

        $second = $this->temporary.DIRECTORY_SEPARATOR.'missing-selection';
        self::assertSame(1, (new Application)->run(['master-core', 'new', $second]));
        self::assertDirectoryDoesNotExist($second);
    }

    public function test_plan_command_reads_selection_and_emits_no_project_files(): void
    {
        $selection = $this->temporary.DIRECTORY_SEPARATOR.'selection.json';
        file_put_contents($selection, json_encode([
            'backend_features' => ['settings'],
            'frontend_features' => ['settings'],
            'auth_shell' => 'host',
        ], JSON_THROW_ON_ERROR));
        self::assertSame(0, (new Application)->run(['master-core', 'plan', $selection, '--json']));
        self::assertSame([$selection], array_map(static fn (string $path): string => basename($path) === 'selection.json' ? $path : '', glob($this->temporary.DIRECTORY_SEPARATOR.'*') ?: []));
    }

    public function test_native_process_executor_uses_windows_safe_argument_arrays(): void
    {
        $executor = new NativeProcessExecutor;
        $executor->run([PHP_BINARY, '-r', 'exit(0);'], $this->temporary);
        self::assertTrue(true);
    }

    public function test_existing_target_is_rejected_without_modification(): void
    {
        $existing = $this->temporary.DIRECTORY_SEPARATOR.'existing';
        mkdir($existing);
        file_put_contents($existing.DIRECTORY_SEPARATOR.'marker.txt', 'keep');
        try {
            TargetPath::validate($existing);
            self::fail('Existing path should not be accepted.');
        } catch (InstallationException) {
            self::assertSame('keep', file_get_contents($existing.DIRECTORY_SEPARATOR.'marker.txt'));
        }
    }

    public function test_current_placeholder_namespaces_fail_before_staging_or_processes(): void
    {
        $profile = $this->loadProfile();
        $plan = (new ProjectPlanner)->build($profile, [], []);
        $trusted = new TrustedSources(['https://packages.example.test'], ['example', 'laravel'], 'https://registry.example.test', ['@verified']);
        $processes = new RecordingExecutor;
        $target = $this->temporary.DIRECTORY_SEPARATOR.'new-app';
        try {
            (new ProjectInstaller($processes))->install($target, $plan, $profile, $trusted);
            self::fail('Placeholder package vendor must be rejected.');
        } catch (InstallationException $exception) {
            self::assertStringContainsString('not explicitly approved', $exception->getMessage());
            self::assertSame([], $processes->calls);
            self::assertDirectoryDoesNotExist($target);
            self::assertCount(0, glob($this->temporary.DIRECTORY_SEPARATOR.'.new-app.master-core-staging-*') ?: []);
        }
    }

    public function test_failed_staged_install_preserves_recovery_output_and_never_calls_migrations(): void
    {
        $profile = $this->trustedProfile();
        $plan = (new ProjectPlanner)->build($profile, [], []);
        $processes = new RecordingExecutor(failAt: 1);
        $target = $this->temporary.DIRECTORY_SEPARATOR.'generated-app';
        try {
            (new ProjectInstaller($processes))->install($target, $plan, $profile, $this->trustedSources());
            self::fail('Expected the scaffold process to fail.');
        } catch (InstallationException $exception) {
            self::assertStringContainsString('staging output was preserved', $exception->getMessage());
            $staging = glob($this->temporary.DIRECTORY_SEPARATOR.'.generated-app.master-core-staging-*') ?: [];
            self::assertCount(1, $staging);
            self::assertFileExists($staging[0].DIRECTORY_SEPARATOR.'master-core-generator-recovery.json');
            self::assertContains('--repository={"packagist.org":false}', $processes->calls[0]);
            self::assertSame([], array_filter($processes->calls, static fn (array $call): bool => str_contains(implode(' ', $call), 'migrate') || str_contains(implode(' ', $call), 'db:seed')));
            self::assertDirectoryDoesNotExist($target);
        }
    }

    public function test_successful_fake_install_creates_manifest_and_read_only_doctor_passes(): void
    {
        $profile = $this->trustedProfile();
        $plan = (new ProjectPlanner)->build($profile, [], []);
        $processes = new RecordingExecutor;
        $target = $this->temporary.DIRECTORY_SEPARATOR.'generated-app';
        (new ProjectInstaller($processes))->install($target, $plan, $profile, $this->trustedSources());
        self::assertFileExists($target.DIRECTORY_SEPARATOR.'master-core.project.json');
        self::assertFileExists($target.DIRECTORY_SEPARATOR.'docs/master-core-installation.md');
        self::assertSame(3, count($processes->calls));
        self::assertStringContainsString('package:discover', implode(' ', $processes->calls[2]));
        self::assertStringNotContainsString('migrate', implode(' ', array_merge(...$processes->calls)));
        $before = hash_file('sha256', $target.DIRECTORY_SEPARATOR.'master-core.project.json');
        self::assertTrue((new Doctor)->inspect($target)['ok']);
        self::assertSame($before, hash_file('sha256', $target.DIRECTORY_SEPARATOR.'master-core.project.json'));
        self::assertSame(0, (new Application)->run(['master-core', 'doctor', $target, '--json']));
    }

    public function test_doctor_reports_incomplete_generated_configuration(): void
    {
        $project = $this->temporary.DIRECTORY_SEPARATOR.'incomplete-app';
        mkdir($project);
        file_put_contents($project.'/master-core.project.json', json_encode([
            'schema_version' => 1,
            'automatic_migrations' => false,
            'automatic_permission_grants' => false,
            'automatic_super_admin_provisioning' => false,
            'composer_packages' => ['example/laravel-master-core' => '^0.1.0'],
            'frontend_features' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($project.'/composer.json', json_encode(['require' => []], JSON_THROW_ON_ERROR));

        $result = (new Doctor)->inspect($project);

        self::assertFalse($result['ok']);
        $checks = array_column($result['checks'], null, 'name');
        self::assertFalse($checks['composer-selection']['ok']);
    }

    public function test_frontend_selection_generates_explicit_routes_and_styles(): void
    {
        $profile = $this->trustedProfile();
        $plan = (new ProjectPlanner)->build($profile, ['roles-permissions'], ['roles-permissions']);
        $target = $this->temporary.DIRECTORY_SEPARATOR.'frontend-app';
        (new ProjectInstaller(new RecordingExecutor))->install($target, $plan, $profile, $this->trustedSources());
        $routes = (string) file_get_contents($target.'/resources/js/master-core/routes.ts');
        $styles = (string) file_get_contents($target.'/resources/css/master-core.css');
        self::assertStringContainsString("from '@acme/roles-permissions'", $routes);
        self::assertStringContainsString('createRolesPermissionsRoutes(apiBase)', $routes);
        self::assertStringContainsString("@import '@acme/ui/styles.css';", $styles);
        self::assertStringContainsString("@import '@acme/roles-permissions/style.css';", $styles);
        $composer = json_decode((string) file_get_contents($target.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $npm = json_decode((string) file_get_contents($target.'/package.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('acme/laravel-master-core', $composer['require']);
        self::assertArrayHasKey('acme/laravel-master-core-roles-permissions', $composer['require']);
        self::assertContains(['packagist.org' => false], $composer['repositories']);
        self::assertArrayHasKey('@acme/roles-permissions', $npm['dependencies']);
        self::assertSame("registry=https://registry.acme.test\n", file_get_contents($target.'/.npmrc'));
    }

    private function loadProfile(): CoreProfile
    {
        return (new ProfileLoader)->load(dirname(__DIR__).'/resources/profiles/master-core-v1.json');
    }

    private function trustedProfile(): CoreProfile
    {
        $data = $this->loadProfile()->data;
        $rewrite = static fn (string $name): string => str_replace(['example/laravel-master-core', '@itassistance-bd'], ['acme/laravel-master-core', '@acme'], $name);
        $data['metapackage']['name'] = $rewrite($data['metapackage']['name']);
        foreach ($data['metapackage']['require'] as $name => $constraint) {
            unset($data['metapackage']['require'][$name]);
            $data['metapackage']['require'][$rewrite($name)] = $constraint;
        }
        foreach ($data['metapackage']['extra']['baseline'] as &$name) {
            $name = $rewrite($name);
        }
        unset($name);
        foreach ($data['metapackage']['extra']['optional_features'] as &$feature) {
            $feature['composer'] = $rewrite($feature['composer']);
            $feature['npm'] = $rewrite($feature['npm']);
            $feature['frontend_styles'] = $rewrite($feature['frontend_styles']);
            $feature['composer_dependencies'] = array_map($rewrite, $feature['composer_dependencies']);
        }
        unset($feature);
        foreach ($data['npm_packages'] as &$package) {
            $package['name'] = $rewrite($package['name']);
            foreach (['dependencies', 'peerDependencies'] as $kind) {
                foreach ($package[$kind] as $name => $version) {
                    unset($package[$kind][$name]);
                    $package[$kind][$rewrite($name)] = $version;
                }
            }
            $package['exports']['./style.css']['import'] = $rewrite($package['exports']['./style.css']['import']);
        }
        unset($package);
        $data['shared_npm']['name'] = $rewrite($data['shared_npm']['name']);

        return new CoreProfile($data);
    }

    private function trustedSources(): TrustedSources
    {
        return new TrustedSources(['https://packages.acme.test'], ['acme', 'laravel'], 'https://registry.acme.test', ['@acme']);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}

final class RecordingExecutor implements ProcessExecutor
{
    /** @var list<list<string>> */
    public array $calls = [];

    public function __construct(private readonly ?int $failAt = null) {}

    public function run(array $arguments, string $workingDirectory, ?array $environment = null): void
    {
        $this->calls[] = $arguments;
        if ($this->failAt === count($this->calls)) {
            throw new RuntimeException('Simulated tool failure.');
        }
        if (($arguments[1] ?? null) === 'create-project') {
            $target = $arguments[3];
            file_put_contents($target.'/composer.json', json_encode(['require' => ['php' => '^8.2']], JSON_THROW_ON_ERROR));
            mkdir($target.'/resources/js', 0700, true);
        }
    }
}
