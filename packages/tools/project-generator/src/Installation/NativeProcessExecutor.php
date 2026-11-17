<?php

namespace MasterCore\ProjectGenerator\Installation;

use RuntimeException;

final class NativeProcessExecutor implements ProcessExecutor
{
    public function run(array $arguments, string $workingDirectory, ?array $environment = null): void
    {
        if ($arguments === [] || array_filter($arguments, static fn ($value): bool => ! is_string($value)) !== []) {
            throw new RuntimeException('A process command must be a non-empty string argument list.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $nullDevice = 'NUL';
        } else {
            $nullDevice = '/dev/null';
        }

        // Discard child output so credentials or private package URLs cannot leak into logs.
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $nullDevice, 'a'],
            2 => ['file', $nullDevice, 'a'],
        ];
        $inherited = getenv();
        $processEnvironment = is_array($inherited) ? $inherited : [];
        if ($environment !== null) {
            $processEnvironment = array_merge($processEnvironment, $environment);
        }
        $process = proc_open($arguments, $descriptors, $pipes, $workingDirectory, $processEnvironment, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start a package-manager process.');
        }

        $status = proc_close($process);
        if ($status !== 0) {
            throw new ProcessFailureException("Package-manager process failed with exit code {$status}.", $status);
        }
    }
}
