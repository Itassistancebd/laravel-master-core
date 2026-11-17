<?php

namespace MasterCore\ProjectGenerator\Doctor;

use JsonException;

final class Doctor
{
    /** @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>} */
    public function inspect(string $project): array
    {
        $root = realpath($project);
        if ($root === false || ! is_dir($root)) {
            return ['ok' => false, 'checks' => [['name' => 'project-directory', 'ok' => false, 'detail' => 'Project directory does not exist.']]];
        }

        $checks = [];
        $manifestPath = $root.'/master-core.project.json';
        $checks[] = $this->fileCheck('generator-manifest', $manifestPath);
        $manifest = null;
        if (is_file($manifestPath)) {
            try {
                $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                $valid = is_array($manifest) && ($manifest['schema_version'] ?? null) === 1;
                $checks[] = ['name' => 'manifest-schema', 'ok' => $valid, 'detail' => $valid ? 'Generator manifest schema 1 is readable.' : 'Generator manifest schema is unsupported.'];
                $safe = $valid
                    && ($manifest['automatic_migrations'] ?? true) === false
                    && ($manifest['automatic_permission_grants'] ?? true) === false
                    && ($manifest['automatic_super_admin_provisioning'] ?? true) === false;
                $checks[] = ['name' => 'safe-installation-flags', 'ok' => $safe, 'detail' => $safe ? 'Migrations, grants, and Super Admin provisioning are not automatic.' : 'Manifest does not preserve the approved safety flags.'];
            } catch (JsonException) {
                $checks[] = ['name' => 'manifest-schema', 'ok' => false, 'detail' => 'Generator manifest is invalid JSON.'];
            }
        }

        $checks[] = $this->fileCheck('composer-manifest', $root.'/composer.json');
        $checks[] = $this->fileCheck('setup-guide', $root.'/docs/master-core-installation.md');
        if (is_array($manifest) && is_file($root.'/composer.json')) {
            try {
                $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
                $requirements = $manifest['composer_packages'] ?? [];
                $matches = is_array($requirements) && is_array($composer['require'] ?? null);
                foreach (is_array($requirements) ? $requirements : [] as $name => $constraint) {
                    if (($composer['require'][$name] ?? null) !== $constraint) {
                        $matches = false;
                    }
                }
                $checks[] = ['name' => 'composer-selection', 'ok' => $matches, 'detail' => $matches ? 'Selected Composer requirements match the generated manifest.' : 'Composer requirements differ from the generated feature manifest.'];
            } catch (JsonException) {
                $checks[] = ['name' => 'composer-selection', 'ok' => false, 'detail' => 'Composer manifest is invalid JSON.'];
            }
        }
        if (is_array($manifest) && ($manifest['frontend_features'] ?? []) !== []) {
            $checks[] = $this->fileCheck('generated-route-composition', $root.'/resources/js/master-core/routes.ts');
            $checks[] = $this->fileCheck('generated-style-composition', $root.'/resources/css/master-core.css');
            $checks[] = $this->fileCheck('npm-manifest', $root.'/package.json');
            if (is_file($root.'/package.json')) {
                try {
                    $npm = json_decode((string) file_get_contents($root.'/package.json'), true, 512, JSON_THROW_ON_ERROR);
                    $matches = is_array($npm['dependencies'] ?? null) && is_array($npm['devDependencies'] ?? null);
                    foreach (($manifest['npm_dependencies'] ?? []) as $name => $version) {
                        if (($npm['dependencies'][$name] ?? $npm['devDependencies'][$name] ?? null) !== $version) {
                            $matches = false;
                        }
                    }
                    foreach (($manifest['npm_dev_dependencies'] ?? []) as $name => $version) {
                        if (($npm['devDependencies'][$name] ?? null) !== $version) {
                            $matches = false;
                        }
                    }
                    $checks[] = ['name' => 'npm-selection', 'ok' => $matches, 'detail' => $matches ? 'Selected npm requirements match the generated manifest.' : 'npm requirements differ from the generated feature manifest.'];
                } catch (JsonException) {
                    $checks[] = ['name' => 'npm-selection', 'ok' => false, 'detail' => 'npm manifest is invalid JSON.'];
                }
            }
        }

        return ['ok' => array_reduce($checks, static fn (bool $ok, array $check): bool => $ok && $check['ok'], true), 'checks' => $checks];
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function fileCheck(string $name, string $path): array
    {
        $ok = is_file($path) && is_readable($path);

        return ['name' => $name, 'ok' => $ok, 'detail' => $ok ? 'Present and readable.' : 'Missing or unreadable.'];
    }
}
