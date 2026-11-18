<?php

namespace MasterCore\ProjectGenerator\Installation;

use MasterCore\ProjectGenerator\Planning\ProjectPlan;
use MasterCore\ProjectGenerator\Profile\CoreProfile;
use RuntimeException;

final class ProjectInstaller
{
    public function __construct(private readonly ProcessExecutor $processes) {}

    /**
     * Generate from the Laravel application already stored in the Core monorepo.
     * Internal Composer packages resolve from the copied relative path repositories;
     * the normal Composer/npm registries are used only for third-party dependencies.
     * No Laravel skeleton is downloaded and no database migrations are run.
     *
     * @param list<string> $composerCommand Executable prefix, such as [php.exe, composer.phar]
     * @param list<string> $npmCommand Executable prefix, such as [node.exe, npm-cli.js]
     * @return array{target: string, stage: string, source: string}
     */
    public function installMonorepoTemplate(
        string $target,
        string $sourceRoot,
        array $composerCommand,
        array $npmCommand,
        string $phpBinary = PHP_BINARY,
        string $sourceRef = 'main',
        string $sourceCommit = '',
    ): array {
        $source = realpath($sourceRoot);
        if ($source === false || ! is_dir($source)) {
            throw new InstallationException('The downloaded Core source directory is unavailable.');
        }
        foreach ([
            'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', '.env.example',
            'packages/core/master-core/composer.json', 'packages/core/foundation/composer.json',
        ] as $required) {
            if (! is_file($source.DIRECTORY_SEPARATOR.$required)) {
                throw new InstallationException("The Core template archive is incomplete; '{$required}' is missing.");
            }
        }
        if ($composerCommand === [] || $npmCommand === []) {
            throw new InstallationException('Composer and Node/npm executable paths are required.');
        }

        $final = TargetPath::validate($target);
        $parent = dirname($final);
        $stage = $parent.DIRECTORY_SEPARATOR.'.'.basename($final).'.master-core-staging-'.bin2hex(random_bytes(6));
        if (file_exists($stage) || is_link($stage) || ! mkdir($stage, 0700)) {
            throw new InstallationException('Could not safely create an isolated project staging directory.');
        }

        $step = 'template-copy';
        $unzipShim = $stage.DIRECTORY_SEPARATOR.'.master-core-unzip';
        try {
            $this->copyMonorepoTemplate($source, $stage);
            $this->configureTemplateIdentity($stage, basename($final));
            $this->prepareTemplateDirectories($stage);
            $this->assertMonorepoRepositories($stage);

            $this->createUnzipShim($unzipShim);
            $composerEnvironment = [
                'COMPOSER_NO_INTERACTION' => '1',
                'COMPOSER_PROCESS_TIMEOUT' => '300',
                'PATH' => $unzipShim.PATH_SEPARATOR.(string) (getenv('PATH') ?: ''),
            ];

            $step = 'composer-lock-refresh';
            $this->processes->run(array_merge($composerCommand, ['update', '--lock', '--no-install', '--no-scripts', '--no-plugins', '--no-interaction']), $stage, $composerEnvironment);

            $step = 'composer-install';
            // Use Composer dist archives with an isolated .NET unzip shim if PHP zip/7-Zip are absent.
            $this->processes->run(array_merge($composerCommand, ['install', '--no-interaction', '--prefer-dist', '--no-progress']), $stage, $composerEnvironment);
            $this->processes->run(array_merge($composerCommand, ['validate', '--no-check-publish']), $stage, $composerEnvironment);
            $this->processes->run([$phpBinary, 'artisan', 'key:generate', '--force'], $stage, null);
            $this->processes->run([$phpBinary, 'artisan', 'package:discover', '--ansi'], $stage, null);
            $this->processes->run([$phpBinary, 'artisan', '--version'], $stage, null);

            $step = 'npm-install';
            $this->processes->run(array_merge($npmCommand, ['ci', '--no-audit', '--no-fund']), $stage, null);
            $this->processes->run(array_merge($npmCommand, ['run', 'build']), $stage, null);

            $this->removeGeneratedDirectory($unzipShim);
            $this->writeJson($stage.'/master-core-source.json', [
                'repository' => 'https://github.com/Itassistancebd/laravel-master-core',
                'ref' => $sourceRef,
                'commit' => $sourceCommit,
                'source' => 'existing Laravel Master Core application in the monorepo',
                'internal_composer' => 'relative monorepo path repositories',
                'internal_npm' => 'local npm workspaces',
                'database_migrations_run' => false,
            ]);
            $step = 'finalize';
            if (file_exists($final) || is_link($final)) {
                throw new InstallationException('Target appeared during generation; staging output has been preserved.');
            }
            if (! rename($stage, $final)) {
                throw new InstallationException('Could not move the verified project to the target; staging output has been preserved.');
            }

            return ['target' => $final, 'stage' => $stage, 'source' => $source];
        } catch (\Throwable $exception) {
            $this->writeRecovery($stage, $step, $exception);
            throw new InstallationException(
                "Monorepo-template generation failed during {$step}: {$exception->getMessage()} Staging files were preserved at {$stage}; see its recovery file.",
                previous: $exception,
            );
        }
    }

    private function createUnzipShim(string $directory): void
    {
        if (! mkdir($directory, 0700)) {
            throw new InstallationException('Unable to prepare the isolated Composer archive extractor.');
        }
        $batch = "@echo off\r\nsetlocal\r\npowershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File \"%~dp0unzip.ps1\" %*\r\nexit /b %ERRORLEVEL%\r\n";
        $script = <<<'POWERSHELL'
$Arguments = $args
$ErrorActionPreference = 'Stop'
$archive = $Arguments | Where-Object { $_ -match '(?i)\.zip$' } | Select-Object -First 1
$destinationIndex = [Array]::IndexOf($Arguments, '-d')
if (-not $archive -or $destinationIndex -lt 0 -or $destinationIndex + 1 -ge $Arguments.Count) { exit 2 }
$destinationRoot = [IO.Path]::GetFullPath($Arguments[$destinationIndex + 1])
New-Item -ItemType Directory -Force -Path $destinationRoot | Out-Null
if ((Get-Item -LiteralPath $archive).Length -gt 500MB) { exit 3 }
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [IO.Compression.ZipFile]::OpenRead($archive)
$expanded = [long] 0
$count = 0
try {
    foreach ($entry in $zip.Entries) {
        $count++
        $expanded += $entry.Length
        if ($count -gt 50000 -or $expanded -gt 1GB) { exit 4 }
        $unixType = ($entry.ExternalAttributes -shr 16) -band 0xF000
        if ($unixType -eq 0xA000) { exit 5 }
        $relative = $entry.FullName.Replace('/', [IO.Path]::DirectorySeparatorChar)
        $fullPath = [IO.Path]::GetFullPath((Join-Path $destinationRoot $relative))
        $prefix = $destinationRoot.TrimEnd('\') + [IO.Path]::DirectorySeparatorChar
        if (-not $fullPath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { exit 6 }
        if ($entry.Name -eq '' -or $entry.FullName.EndsWith('/')) {
            New-Item -ItemType Directory -Force -Path $fullPath | Out-Null
        } else {
            New-Item -ItemType Directory -Force -Path (Split-Path -Parent $fullPath) | Out-Null
            [IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $fullPath, $true)
        }
    }
} finally { $zip.Dispose() }
exit 0
POWERSHELL;
        if (file_put_contents($directory.'/unzip.cmd', $batch, LOCK_EX) === false
            || file_put_contents($directory.'/unzip.ps1', $script, LOCK_EX) === false) {
            throw new InstallationException('Unable to write the isolated Composer archive extractor.');
        }
    }

    private function removeGeneratedDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) { return; }
        foreach (new \DirectoryIterator($directory) as $item) {
            if ($item->isDot()) { continue; }
            $path = $item->getPathname();
            if ($item->isLink()) { unlink($path); }
            elseif ($item->isDir()) { $this->removeGeneratedDirectory($path); }
            else { unlink($path); }
        }
        rmdir($directory);
    }

    private function copyMonorepoTemplate(string $source, string $stage): void
    {
        $excludedFiles = [
            'database/migrations/2026_11_17_000000_create_categories_table.php',
        ];
        $excludedDirectories = [
            '.git', '.idea', '.vscode', 'vendor', 'node_modules', 'dist', 'build', 'artifacts', 'backups', 'backup',
            'test-results', 'playwright-report', '.phpunit.cache', '.cache',
        ];
        $copyDirectory = function (string $directory, string $relativeDirectory = '') use (&$copyDirectory, $stage, $excludedDirectories, $excludedFiles): void {
            foreach (new \DirectoryIterator($directory) as $item) {
                if ($item->isDot()) { continue; }
                $relative = ltrim($relativeDirectory.'/'.$item->getFilename(), '/');
                $relative = str_replace('\\', '/', $relative);
                $segments = explode('/', $relative);
                $base = basename($relative);
                if (in_array(strtolower($relative), $excludedFiles, true)) { continue; }
                if (array_intersect(array_map('strtolower', $segments), $excludedDirectories) !== []) { continue; }
                if (preg_match('~(^|/)\.env(?!\.example$)[^/]*$~i', $relative) === 1
                    || in_array(strtolower($base), ['.php-runtime.local.json', '.npmrc', 'auth.json', 'composer-auth.json'], true)
                    || preg_match('~(^|/)storage/(?:logs|framework/(?:cache|sessions|views))(/|$)~i', $relative) === 1
                    || preg_match('~(^|/)database/[^/]+\.(?:sqlite|sqlite3|db)$~i', $relative) === 1
                    || preg_match('~\.(?:log|tmp|temp|bak|orig)$~i', $relative) === 1
                    || preg_match('~(^|/)[^/]*(?:backup|\.old)(?:[/._-]|$)~i', $relative) === 1) { continue; }
                if ($item->isLink()) {
                    throw new InstallationException("The Core source contains a symbolic link that cannot be copied safely: {$relative}");
                }
                $destination = $stage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if ($item->isDir()) {
                    if (! is_dir($destination) && ! mkdir($destination, 0700, true) && ! is_dir($destination)) {
                        throw new InstallationException("Unable to create project directory '{$relative}'.");
                    }
                    $copyDirectory($item->getPathname(), $relative);
                    continue;
                }
                $destinationDirectory = dirname($destination);
                if (! is_dir($destinationDirectory) && ! mkdir($destinationDirectory, 0700, true) && ! is_dir($destinationDirectory)) {
                    throw new InstallationException("Unable to create project directory for '{$relative}'.");
                }
                if (! copy($item->getPathname(), $destination)) {
                    throw new InstallationException("Unable to copy Core template file '{$relative}'.");
                }
            }
        };
        $copyDirectory($source);
    }

    private function configureTemplateIdentity(string $stage, string $projectName): void
    {
        $composerPath = $stage.'/composer.json';
        $composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
        $slug = strtolower((string) preg_replace('/[^a-z0-9_.-]+/i', '-', $projectName));
        $composer['name'] = 'local/'.$slug;
        $composer['description'] = 'Generated Laravel Master Core application: '.$projectName;
        $this->writeJson($composerPath, $composer, allowFreshScaffoldFile: true);

        $example = $stage.'/.env.example';
        $environment = $stage.'/.env';
        $contents = (string) file_get_contents($example);
        $contents = preg_replace('/^APP_NAME=.*$/m', 'APP_NAME='.$projectName, $contents, 1) ?? $contents;
        if (file_put_contents($environment, $contents) === false) {
            throw new InstallationException('Unable to prepare the project .env file from .env.example.');
        }
    }

    private function prepareTemplateDirectories(string $stage): void
    {
        foreach ([
            'bootstrap/cache', 'database', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data',
            'storage/framework/sessions', 'storage/framework/views', 'storage/logs',
        ] as $directory) {
            $path = $stage.'/'.$directory;
            if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
                throw new InstallationException("Unable to prepare Laravel directory '{$directory}'.");
            }
        }
        $database = $stage.'/database/database.sqlite';
        if (! is_file($database) && file_put_contents($database, '') === false) {
            throw new InstallationException('Unable to prepare the new project SQLite database file.');
        }
    }

    private function assertMonorepoRepositories(string $stage): void
    {
        $composer = json_decode((string) file_get_contents($stage.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $packages = [];
        foreach ($composer['repositories'] ?? [] as $repository) {
            if (($repository['type'] ?? null) !== 'path' || ! is_string($repository['url'] ?? null)) { continue; }
            $path = $stage.'/'.$repository['url'];
            $matches = glob($path, GLOB_ONLYDIR) ?: (is_dir($path) ? [$path] : []);
            if ($matches === []) { throw new InstallationException('A relative Composer path repository is missing from the generated monorepo.'); }
            foreach ($matches as $match) {
                $manifestPath = $match.'/composer.json';
                if (! is_file($manifestPath)) { throw new InstallationException('A monorepo Composer package is missing composer.json.'); }
                $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                if (is_string($manifest['name'] ?? null)) { $packages[$manifest['name']] = true; }
            }
        }
        foreach (array_merge(array_keys($composer['require'] ?? []), array_keys($composer['require-dev'] ?? [])) as $dependency) {
            if (str_starts_with($dependency, 'example/') && ! isset($packages[$dependency])) {
                throw new InstallationException("Internal Core dependency '{$dependency}' does not resolve to a package in this monorepo archive.");
            }
        }

        $npm = json_decode((string) file_get_contents($stage.'/package.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($npm['workspaces'] ?? [] as $workspace) {
            if (! is_string($workspace)) { throw new InstallationException('The npm workspace list contains an invalid entry.'); }
            $matches = glob($stage.'/'.$workspace, GLOB_ONLYDIR) ?: (is_dir($stage.'/'.$workspace) ? [$stage.'/'.$workspace] : []);
            if ($matches === []) { throw new InstallationException('An npm workspace is missing from the generated monorepo archive.'); }
            foreach ($matches as $match) {
                $manifestPath = $match.'/package.json';
                if (! is_file($manifestPath)) { throw new InstallationException('An npm workspace is missing package.json.'); }
            }
        }
    }

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
            $this->writeFrontend($stage, $plan, $profile);
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
            $guidance = match ($step) {
                'laravel-scaffold' => ' Check Laravel repository reachability and the configured Composer repository list.',
                'composer-resolution' => ' Confirm the configured vendor and repository contain every selected package and compatible release version, and that the repository is reachable.',
                'npm-resolution' => ' Confirm the configured scope contains every selected package and compatible release version, and that the registry is reachable.',
                default => '',
            };
            throw new InstallationException(
                "Project generation failed during {$step}.{$guidance} Failed staging output was preserved at {$stage}. See its recovery file.",
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

    private function writeFrontend(string $stage, ProjectPlan $plan, CoreProfile $profile): void
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
        $package['engines'] = array_merge($package['engines'] ?? [], [
            'node' => $profile->data['toolchain']['node'],
            'npm' => $profile->data['toolchain']['npm'],
        ]);
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
