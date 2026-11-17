<?php

namespace MasterCore\ProjectGenerator\Console;

use MasterCore\ProjectGenerator\Doctor\Doctor;
use MasterCore\ProjectGenerator\Installation\NativeProcessExecutor;
use MasterCore\ProjectGenerator\Installation\Preflight;
use MasterCore\ProjectGenerator\Installation\ProjectInstaller;
use MasterCore\ProjectGenerator\Installation\TargetPath;
use MasterCore\ProjectGenerator\Installation\TrustedSources;
use MasterCore\ProjectGenerator\Planning\ProjectPlan;
use MasterCore\ProjectGenerator\Planning\ProjectPlanner;
use MasterCore\ProjectGenerator\Profile\ProfileLoader;
use RuntimeException;

final class Application
{
    public function run(array $argv): int
    {
        try {
            $command = $argv[1] ?? 'help';
            $options = $this->parseOptions(array_slice($argv, 2));

            return match ($command) {
                'new' => $this->newProject($options),
                'plan' => $this->planFile($options),
                'doctor' => $this->doctor($options),
                'help', '--help', '-h' => $this->help(),
                default => throw new RuntimeException("Unknown command '{$command}'. Run master-core help."),
            };
        } catch (\Throwable $exception) {
            fwrite(STDERR, 'Error: '.$exception->getMessage().PHP_EOL);

            return 1;
        }
    }

    private function newProject(array $options): int
    {
        $target = $options['_'][0] ?? null;
        if (! is_string($target) || $target === '') {
            throw new RuntimeException('Usage: master-core new <target> --features=... --frontends=... [--dry-run].');
        }

        $sourceRoot = $options['source-root'] ?? $this->findSourceRoot();
        $profilePath = $options['profile'] ?? dirname(__DIR__, 2).'/resources/profiles/master-core-v1.json';
        $profile = (new ProfileLoader)->load($profilePath, $sourceRoot);
        [$features, $frontends, $authShell] = $this->selections($options, $profile->features());
        $plan = (new ProjectPlanner)->build($profile, $features, $frontends, $authShell, $options['core-version'] ?? null);
        $composerBinary = 'composer';
        $npmBinary = 'npm';
        (new Preflight)->assertSupported($profile, $plan, $composerBinary, $npmBinary);

        if (isset($options['dry-run'])) {
            TargetPath::validate($target);
            $output = ['target' => $target, 'dry_run' => true, 'plan' => $plan->toArray(), 'registry_installation' => 'requires explicit verified registry configuration'];
            $this->print($output, isset($options['json']));

            return 0;
        }

        if (! $this->confirmInstall($plan, $options)) {
            throw new RuntimeException('Installation cancelled before creating staging files.');
        }

        $trusted = TrustedSources::fromUserConfig($options['trusted-config'] ?? null);
        $result = (new ProjectInstaller(new NativeProcessExecutor))->install(
            $target,
            $plan,
            $profile,
            $trusted,
            PHP_BINARY,
            $composerBinary,
            $npmBinary,
        );
        $this->print(['installed' => true, 'result' => $result], isset($options['json']));

        return 0;
    }

    private function planFile(array $options): int
    {
        $file = $options['_'][0] ?? null;
        if (! is_string($file) || ! is_file($file)) {
            throw new RuntimeException('Usage: master-core plan <selection.json>.');
        }
        $selection = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($selection)) {
            throw new RuntimeException('Selection file must be a JSON object.');
        }
        $profilePath = $options['profile'] ?? dirname(__DIR__, 2).'/resources/profiles/master-core-v1.json';
        $profile = (new ProfileLoader)->load($profilePath, $options['source-root'] ?? $this->findSourceRoot());
        $plan = (new ProjectPlanner)->build(
            $profile,
            $selection['backend_features'] ?? [],
            $selection['frontend_features'] ?? [],
            $selection['auth_shell'] ?? 'host',
            $selection['core_version'] ?? null,
        );
        $this->print($plan->toArray(), true);

        return 0;
    }

    private function doctor(array $options): int
    {
        $project = $options['_'][0] ?? getcwd();
        $result = (new Doctor)->inspect($project);
        $this->print($result, isset($options['json']));

        return $result['ok'] ? 0 : 2;
    }

    private function selections(array $options, array $available): array
    {
        $interactive = function_exists('stream_isatty') && stream_isatty(STDIN) && ! isset($options['no-interaction']);
        $features = isset($options['features']) ? $this->csv($options['features']) : null;
        $frontends = isset($options['frontends']) ? $this->csv($options['frontends']) : null;
        $authShell = $options['auth-shell'] ?? null;
        if (($features === null || $frontends === null || $authShell === null) && $interactive) {
            $keys = implode(', ', array_keys($available));
            if ($features === null) {
                fwrite(STDOUT, "Backend features (comma separated: {$keys}; blank selects none): ");
                $features = $this->csv(trim((string) fgets(STDIN)));
            }
            if ($frontends === null) {
                fwrite(STDOUT, "Frontend features (comma separated: {$keys}; blank selects none): ");
                $frontends = $this->csv(trim((string) fgets(STDIN)));
            }
            if ($authShell === null) {
                fwrite(STDOUT, 'Auth shell [host/core/none] (default host): ');
                $authShell = trim((string) fgets(STDIN)) ?: 'host';
            }
        }
        if ($features === null || $frontends === null) {
            throw new RuntimeException('Non-interactive mode requires both --features and --frontends. Use an empty value for none.');
        }

        return [$features, $frontends, $authShell ?? 'host'];
    }

    private function confirmInstall(ProjectPlan $plan, array $options): bool
    {
        if (isset($options['yes'])) {
            return true;
        }
        $interactive = function_exists('stream_isatty') && stream_isatty(STDIN) && ! isset($options['no-interaction']);
        if (! $interactive) {
            throw new RuntimeException('Non-interactive installation requires --yes after reviewing the dry-run plan.');
        }

        fwrite(STDOUT, "Install the planned packages and create this new project?\n");
        fwrite(STDOUT, json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\nType yes to continue: ");

        return strtolower(trim((string) fgets(STDIN))) === 'yes';
    }

    private function parseOptions(array $args): array
    {
        $options = ['_' => []];
        foreach ($args as $argument) {
            if (! str_starts_with($argument, '--')) {
                $options['_'][] = $argument;

                continue;
            }
            $value = substr($argument, 2);
            if (str_contains($value, '=')) {
                [$key, $value] = explode('=', $value, 2);
                $options[$key] = $value;
            } else {
                $options[$value] = true;
            }
        }

        return $options;
    }

    private function csv(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '')));
    }

    private function findSourceRoot(): ?string
    {
        $candidate = dirname(__DIR__, 5);

        return is_file($candidate.'/packages/core/master-core/composer.json') ? $candidate : null;
    }

    private function print(array $data, bool $json): void
    {
        fwrite(STDOUT, $json
            ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL
            : print_r($data, true));
    }

    private function help(): int
    {
        fwrite(STDOUT, "Master Core Project Generator\n\nCommands:\n  new <target> --features=a,b --frontends=x,y [--auth-shell=host|core|none] [--dry-run]\n  plan <selection.json> [--json]\n  doctor [project] [--json]\n\nNon-interactive selections must be explicit. Registry installs require locally configured verified sources.\n");

        return 0;
    }
}
