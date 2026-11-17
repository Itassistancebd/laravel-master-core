<?php

namespace MasterCore\ProjectGenerator\Installation;

use MasterCore\ProjectGenerator\Planning\ProjectPlan;
use MasterCore\ProjectGenerator\Profile\CoreProfile;
use RuntimeException;

final class ProjectInstaller
{
    public function __construct(private readonly ProcessExecutor $processes) {}

    /** @return array{target: string, stage: string, plan: array<string, mixed>} */
    public function install(
        string $target,
        ProjectPlan $plan,
        CoreProfile $profile,
        TrustedSources $trusted,
        string $phpBinary = PHP_BINARY,
        string $composerBinary = 'composer',
        string $npmBinary = 'npm',
    ): array {
        // Registry verification happens before the first filesystem write.
        $trusted->assertTrusted($profile, $plan);
        $final = TargetPath::validate($target);
        $parent = dirname($final);
        $stage = $parent.DIRECTORY_SEPARATOR.'.'.basename($final).'.master-core-staging-'.bin2hex(random_bytes(5));
        if (file_exists($stage) || is_link($stage) || ! mkdir($stage, 0700)) {
            throw new InstallationException('Could not safely create an isolated staging directory.');
        }

        $step = 'laravel-scaffold';
        try {
            $scaffoldArguments = [
                $composerBinary, 'create-project', 'laravel/laravel', $stage, '12.*',
                '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist',
            ];
            foreach ($trusted->composerRepositories as $repository) {
                $scaffoldArguments[] = '--repository='.$repository;
            }
            // Disable implicit Packagist fallback so only the reviewed repositories can serve packages.
            $scaffoldArguments[] = '--repository={"packagist.org":false}';
            $this->processes->run($scaffoldArguments, $parent, null);
            $environment = $this->composerEnvironment($trusted, $stage);

            $step = 'manifest-generation';
            $this->configureComposer($stage, $plan, $trusted);
            $this->writeFrontend($stage, $plan);
            if ($plan->frontendFeatures !== []) {
                $this->writeNpmConfig($stage, $trusted);
            }
            $this->writeProjectInstructions($stage, $plan);

            $step = 'composer-resolution';
            $this->processes->run([
                $composerBinary, 'update', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist',
            ], $stage, $environment);
            $this->processes->run([
                $phpBinary, 'artisan', 'package:discover', '--ansi',
            ], $stage, null);

            if ($plan->frontendFeatures !== []) {
                $step = 'npm-resolution';
                $npmEnvironment = ['npm_config_ignore_scripts' => 'true', 'npm_config_registry' => $trusted->npmRegistry];
                $this->processes->run([$npmBinary, 'install', '--ignore-scripts', '--no-audit', '--no-fund'], $stage, $npmEnvironment);
            }

            $step = 'manifest-finalization';
            $this->writeManifest($stage, $plan, $profile);

            $step = 'finalize';
            if (file_exists($final) || is_link($final)) {
                throw new InstallationException('Target appeared during installation; staging output has been preserved.');
            }
            if (! rename($stage, $final)) {
                throw new InstallationException('Could not move completed staging output to the target; staging output has been preserved.');
            }

            return ['target' => $final, 'stage' => $stage, 'plan' => $plan->toArray()];
        } catch (\Throwable $exception) {
            $this->writeRecovery($stage, $step, $exception);
            throw new InstallationException(
                "Project generation failed during {$step}; staging output was preserved at {$stage}. See its recovery file.",
                previous: $exception,
            );
        }
    }

    /** @return array<string, string> */
    private function composerEnvironment(TrustedSources $trusted, string $stage): array
    {
        // Keep Composer's repository policy inside the preserved staging output.
        $home = $stage.DIRECTORY_SEPARATOR.'.master-core-composer-home';
        if (! is_dir($home) && ! mkdir($home, 0700, true) && ! is_dir($home)) {
            throw new InstallationException('Unable to prepare an isolated Composer home.');
        }
        $configPath = $home.DIRECTORY_SEPARATOR.'composer.json';
        $config = ['repositories' => array_merge($trusted->composerRepositoryDefinitions(), [['packagist.org' => false]])];
        if (! is_file($configPath)) {
            $this->writeJson($configPath, $config);
        }

        return ['COMPOSER_HOME' => $home, 'COMPOSER_NO_INTERACTION' => '1', 'COMPOSER_ALLOW_SUPERUSER' => '0'];
    }

    private function configureComposer(string $stage, ProjectPlan $plan, TrustedSources $trusted): void
    {
        $path = $stage.'/composer.json';
        if (! is_file($path)) {
            throw new RuntimeException('Laravel scaffold did not create composer.json.');
        }
        $composer = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ($plan->composerRequirements as $package => $constraint) {
            $composer['require'][$package] = $constraint;
        }
        ksort($composer['require']);
        $composer['repositories'] = array_merge($trusted->composerRepositoryDefinitions(), [['packagist.org' => false]]);
        $composer['scripts'] = [];
        $composer['extra']['laravel']['dont-discover'] = [];
        $this->writeJson($path, $composer, allowFreshScaffoldFile: true);
    }

    private function writeFrontend(string $stage, ProjectPlan $plan): void
    {
        if ($plan->frontendFeatures === []) {
            return;
        }
        $packagePath = $stage.'/package.json';
        $package = is_file($packagePath)
            ? json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR)
            : ['private' => true, 'type' => 'module', 'scripts' => []];
        $package['private'] = true;
        $package['type'] = 'module';
        $package['dependencies'] = array_merge($package['dependencies'] ?? [], $plan->npmDependencies);
        $package['devDependencies'] = array_merge($package['devDependencies'] ?? [], $plan->npmDevDependencies);
        ksort($package['dependencies']);
        ksort($package['devDependencies']);
        $this->writeJson($packagePath, $package, allowFreshScaffoldFile: true);

        $routeLines = ['// Explicit host route composition generated from the selected frontend features.', "import type { RouteRecordRaw } from 'vue-router';"];
        foreach ($plan->routeExports as $feature => $export) {
            $name = $plan->frontendPackageNames[$feature];
            $routeLines[] = "import { {$export} } from '{$name}';";
        }
        $routeLines[] = '';
        $routeLines[] = 'export function createMasterCoreRoutes(apiBase: string): RouteRecordRaw[] {';
        $routeLines[] = '    return [';
        foreach ($plan->routeExports as $export) {
            $routeLines[] = "        ...{$export}(apiBase),";
        }
        $routeLines[] = '    ];';
        $routeLines[] = '}';
        $routeLines[] = '';
        $routeLines[] = '// Add masterCoreRoutes to the host router after reviewing authentication and authorization guards.';
        if ($plan->authShell === 'core') {
            $routeLines[] = '';
            $routeLines[] = "import { createAuthRouter } from '{$plan->frontendPackageNames['auth']}';";
            $routeLines[] = 'export function createMasterCoreRouter(apiBase: string, canAccessPermission?: (permission: string) => Promise<boolean>) {';
            $routeLines[] = '    return createAuthRouter(apiBase, createMasterCoreRoutes(apiBase), canAccessPermission);';
            $routeLines[] = '}';
        }
        $this->writeText($stage.'/resources/js/master-core/routes.ts', implode("\n", $routeLines)."\n");

        if (in_array('settings', $plan->frontendFeatures, true)) {
            $this->writeText($stage.'/resources/js/master-core/settings.ts', implode("\n", [
                '// Mount the Settings UI only inside a host route protected by backend authorization.',
                "import { createApp } from 'vue';",
                "import { SettingsApp } from '{$plan->frontendPackageNames['settings']}';",
                'export function mountMasterCoreSettings(element: Element, apiBase = \'/api/v1/settings\') {',
                '    return createApp(SettingsApp, { apiBase }).mount(element);',
                '}',
                '',
            ]));
        }

        $styleLines = ['/* Explicit package style composition; review order with host theme tokens. */'];
        foreach ($plan->styleImports as $style) {
            $styleLines[] = "@import '{$style}';";
        }
        $this->writeText($stage.'/resources/css/master-core.css', implode("\n", $styleLines)."\n");
    }

    private function writeNpmConfig(string $stage, TrustedSources $trusted): void
    {
        $path = $stage.'/.npmrc';
        if (file_exists($path)) {
            throw new RuntimeException('Unexpected .npmrc in the fresh scaffold; refusing to overwrite it.');
        }
        $this->writeText($path, 'registry='.$trusted->npmRegistry."\n");
    }

    private function writeProjectInstructions(string $stage, ProjectPlan $plan): void
    {
        $lines = [
            '# Master Core setup', '',
            'This project was generated with an explicit feature profile. Review each step before running it.', '',
            '## Host configuration',
            '- Review package configuration and Laravel package discovery. Providers may register their own routes and migration paths.',
            '- Compose the generated Vue route helper and stylesheet explicitly in the host entrypoints; add host guards and CSRF/session setup as required.',
            '- Configure mail, cookies, Sanctum stateful domains, trusted proxies, and production environment values yourself.',
            '- No credentials, permission grants, Super Admin account, or seed data were generated.', '',
            '## Database and migration review',
            '- Inspect every package-owned migration and host-table prerequisite before applying schema changes.',
            '- Back up the intended database and verify DB_CONNECTION/DB_DATABASE before any manual migration.',
            '- Migrations and seeders were not run by the generator. Execute only after your review and approval.',
            '- Provision Super Admin only through the documented interactive command after verifying an active host user.', '',
            '## Selected packages',
        ];
        foreach ($plan->backendFeatures as $feature) {
            $lines[] = '- Backend: `'.$feature.'`';
        }
        foreach ($plan->frontendFeatures as $feature) {
            $lines[] = '- Frontend: `'.$feature.'`';
        }
        $lines[] = '';
        $lines[] = '## Package migration owners';
        if ($plan->migrationFeatures === []) {
            $lines[] = '- No selected package currently declares package-owned migrations.';
        } else {
            foreach ($plan->migrationFeatures as $feature) {
                $lines[] = '- `'.$feature.'` owns migrations; review its package guide before applying them.';
            }
        }
        $lines[] = '- The generator does not execute `artisan migrate` or seeders.';
        $this->writeText($stage.'/docs/master-core-installation.md', implode("\n", $lines)."\n");
    }

    private function writeManifest(string $stage, ProjectPlan $plan, CoreProfile $profile): void
    {
        $manifest = $plan->toArray() + [
            'schema_version' => 1,
            'generated_at' => gmdate(DATE_ATOM),
            'metapackage' => $profile->packageName(),
            'profile' => $profile->profileName(),
            'php_requirement' => $profile->data['metapackage']['require']['php'],
            'laravel_requirement' => $profile->data['metapackage']['require']['laravel/framework'],
            'composer_packages' => $plan->composerRequirements,
            'generator' => 'master-core-project-generator',
            'resolved_composer_versions' => $this->lockedComposerVersions($stage, $plan),
            'resolved_npm_versions' => $this->lockedNpmVersions($stage, $plan),
        ];
        $this->writeJson($stage.'/master-core.project.json', $manifest);
    }

    private function lockedComposerVersions(string $stage, ProjectPlan $plan): array
    {
        $path = $stage.'/composer.lock';
        if (! is_file($path)) {
            return [];
        }
        $lock = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $wanted = array_fill_keys(array_keys($plan->composerRequirements), true);
        $resolved = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (isset($wanted[$package['name'] ?? ''])) {
                $resolved[$package['name']] = $package['version'];
            }
        }
        ksort($resolved);

        return $resolved;
    }

    private function lockedNpmVersions(string $stage, ProjectPlan $plan): array
    {
        $path = $stage.'/package-lock.json';
        if (! is_file($path)) {
            return [];
        }
        $lock = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $resolved = [];
        foreach (array_keys($plan->npmDependencies + $plan->npmDevDependencies) as $name) {
            $package = $lock['packages']['node_modules/'.$name] ?? null;
            if (is_array($package) && isset($package['version'])) {
                $resolved[$name] = $package['version'];
            }
        }
        ksort($resolved);

        return $resolved;
    }

    private function writeRecovery(string $stage, string $step, \Throwable $exception): void
    {
        if (! is_dir($stage)) {
            return;
        }
        try {
            $this->writeJson($stage.'/master-core-generator-recovery.json', [
                'schema_version' => 1,
                'failed_step' => $step,
                'error_type' => $exception::class,
                'exit_code' => $exception instanceof ProcessFailureException ? $exception->exitCode : null,
                'recovery' => 'Inspect the preserved staging directory, correct the reported prerequisite, and copy only reviewed output into a new target.',
            ]);
        } catch (\Throwable) {
            // Preserve staging data even if the recovery marker cannot be written.
        }
    }

    private function writeJson(string $path, array $data, bool $allowFreshScaffoldFile = false): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create generated directory.');
        }
        if (file_exists($path) && ! $allowFreshScaffoldFile) {
            throw new RuntimeException('Refusing to overwrite generated file: '.basename($path));
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write generated JSON file.');
        }
    }

    private function writeText(string $path, string $content): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create generated directory.');
        }
        if (file_exists($path)) {
            throw new RuntimeException('Refusing to overwrite generated file: '.basename($path));
        }
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write generated file.');
        }
    }
}
