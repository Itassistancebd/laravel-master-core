<?php

namespace MasterCore\ProjectGenerator\Installation;

use MasterCore\ProjectGenerator\Planning\ProjectPlan;
use MasterCore\ProjectGenerator\Profile\CoreProfile;

final class Preflight
{
    public function assertSupported(CoreProfile $profile, ProjectPlan $plan, string $composerBinary, string $npmBinary): void
    {
        if (version_compare(PHP_VERSION, '8.2.0', '<') || version_compare(PHP_VERSION, '9.0.0', '>=')) {
            throw new InstallationException('Generator runtime is incompatible; supported PHP is ^8.2.');
        }

        $phpConstraint = $profile->data['metapackage']['require']['php'] ?? '';
        $laravelConstraint = $profile->data['metapackage']['require']['laravel/framework'] ?? '';
        if ($phpConstraint !== '^8.2' || $laravelConstraint !== '^12.0') {
            throw new InstallationException('Profile compatibility is unsupported by this generator release.');
        }

        $this->assertCommandAvailable($composerBinary, 'Composer');
        if ($plan->frontendFeatures !== []) {
            $this->assertCommandAvailable($npmBinary, 'npm');
            $this->assertCommandAvailable('node', 'Node.js');
        }
    }

    private function assertCommandAvailable(string $command, string $label): void
    {
        if ($command === '') {
            throw new InstallationException("{$label} executable is not configured.");
        }

        $extensions = PHP_OS_FAMILY === 'Windows'
            ? array_filter(explode(';', (string) getenv('PATHEXT'))) ?: ['.EXE', '.BAT', '.CMD']
            : [''];
        if (str_contains($command, DIRECTORY_SEPARATOR) || str_contains($command, '/') || str_contains($command, '\\')) {
            foreach ($extensions as $extension) {
                if (is_file($command) || is_file($command.$extension)) {
                    return;
                }
            }
            throw new InstallationException("{$label} executable was not found at the configured path.");
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            foreach ($extensions as $extension) {
                if (is_file(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$command)
                    || is_file(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$command.$extension)) {
                    return;
                }
            }
        }
        throw new InstallationException("{$label} executable is not available on PATH.");
    }
}
