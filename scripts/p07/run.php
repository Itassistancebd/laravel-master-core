<?php

declare(strict_types=1);

use P07\Identity;
use P07\ProbeSuite;
require __DIR__ . '/Identity.php';
require __DIR__ . '/ProbeSuite.php';

const PROBE_NAMES = [
    'check_constraint_enforcement',
    'foreign_key_and_rollback',
    'collation_and_unique_identity',
    'datetime6_precision_and_timezone',
    'signed_bigint_boundaries',
    'row_lock_timeout_and_release',
    'transaction_snapshot_visibility',
    'optimistic_stale_write_conflict_and_atomic_rollback',
];

function lockedLaravelVersion(): ?string
{
    $lockPath = dirname(__DIR__, 2) . '/composer.lock';
    if (!is_file($lockPath)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($lockPath), true);
    if (!is_array($decoded)) {
        return null;
    }
    foreach (array_merge($decoded['packages'] ?? [], $decoded['packages-dev'] ?? []) as $package) {
        if (($package['name'] ?? null) === 'laravel/framework') {
            return isset($package['version']) ? (string) $package['version'] : null;
        }
    }
    return null;
}

function safeError(Throwable $error): array
{
    if ($error instanceof PDOException) {
        $info = is_array($error->errorInfo ?? null) ? $error->errorInfo : [];
        return [
            'class' => 'database_connection_or_identity_error',
            'sqlstate' => isset($info[0]) ? (string) $info[0] : (string) $error->getCode(),
            'driver_code' => isset($info[1]) ? (int) $info[1] : null,
        ];
    }
    return ['class' => 'configuration_or_identity_preflight_error'];
}

function writeReports(array $report, string $outputDirectory): array
{
    if ($outputDirectory !== 'artifacts/p07') {
        throw new RuntimeException('Evidence output directory does not match the fixed artifact path.');
    }
    $root = dirname(__DIR__, 2);
    $directory = $root . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'p07';
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Evidence output directory could not be created.');
    }

    $phpSlug = preg_replace('/[^0-9]/', '', (string) $report['runtime']['php_version']);
    $base = sprintf('p07-%s-%s-php%s', $report['ci']['run_id'], $report['ci']['run_attempt'], $phpSlug);
    $jsonPath = $directory . DIRECTORY_SEPARATOR . $base . '.json';
    $markdownPath = $directory . DIRECTORY_SEPARATOR . $base . '.md';
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($jsonPath, $json, LOCK_EX) === false || file_put_contents($markdownPath, markdownReport($report), LOCK_EX) === false) {
        throw new RuntimeException('Evidence report could not be written.');
    }
    return ['json' => $jsonPath, 'markdown' => $markdownPath];
}

function markdownReport(array $report): string
{
    $lines = [
        '# P-07 isolated CI evidence',
        '',
        '- Overall run result: **' . $report['overall_result'] . '**',
        '- P-07 status: **PENDING** until owner review and acceptance.',
        '- Qualification target: Oracle MySQL Community Server 8.4.13 LTS; Laravel 12.x; PHP ' . $report['runtime']['php_version'] . '; PDO_MYSQL.',
        '- CI run: `' . $report['ci']['run_id'] . '` / attempt `' . $report['ci']['run_attempt'] . '`; runner `' . $report['ci']['runner_name'] . '`.',
        '- Runner environment: `' . $report['ci']['runner_environment'] . '`.',
        '- Started/reported at UTC: `' . $report['generated_at_utc'] . '`.',
        '',
        '## Environment identity',
        '',
        '| Field | Observed value |',
        '|---|---|',
    ];

    foreach ($report['runtime'] as $key => $value) {
        $lines[] = '| ' . $key . ' | ' . str_replace('|', '\\|', is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES)) . ' |';
    }
    if (is_array($report['database_identity'])) {
        foreach ($report['database_identity'] as $key => $value) {
            $lines[] = '| database_' . $key . ' | ' . str_replace('|', '\\|', is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES)) . ' |';
        }
    }

    $lines[] = '';
    $lines[] = '## Preflight and harness safety checks';
    $lines[] = '';
    $lines[] = '- Database preflight: **' . $report['preflight']['status'] . '**';
    $lines[] = '- Harness policy checks: **' . $report['policy_checks_status'] . '**';
    if (isset($report['preflight']['error_classification'])) {
        $lines[] = '- Preflight error: `' . json_encode($report['preflight']['error_classification'], JSON_UNESCAPED_SLASHES) . '`';
    }
    $lines[] = '';
    $lines[] = '## Probe inventory';
    $lines[] = '';
    $lines[] = '| Probe | Status | Expected | Actual | Error classification |';
    $lines[] = '|---|---|---|---|---|';
    foreach ($report['probes'] as $probe) {
        $actual = is_scalar($probe['actual']) ? (string) $probe['actual'] : json_encode($probe['actual'], JSON_UNESCAPED_SLASHES);
        $expected = is_scalar($probe['expected']) ? (string) $probe['expected'] : json_encode($probe['expected'], JSON_UNESCAPED_SLASHES);
        $lines[] = '| ' . $probe['name'] . ' | ' . $probe['status'] . ' | ' . str_replace('|', '\\|', $expected) . ' | ' . str_replace('|', '\\|', $actual) . ' | ' . (isset($probe['error_classification']) ? json_encode($probe['error_classification'], JSON_UNESCAPED_SLASHES) : '') . ' |';
    }

    $lines[] = '';
    $lines[] = '## Boundaries';
    $lines[] = '';
    $lines[] = 'This report records isolated engine evidence only. It does not establish production support or approve Plugin Runtime implementation, migration creation/execution, activation lifecycle, deployment, provider execution, or plugin routes.';
    $lines[] = 'The optimistic stale-write probe uses two independent connections sequentially and does not test simultaneous worker execution. The row-lock probe separately tests bounded lock contention. True simultaneous worker concurrency remains unverified.';
    $lines[] = '';
    return implode(PHP_EOL, $lines);
}

$repositoryRoot = dirname(__DIR__, 2);
    $initialEnv = getenv();
$outputDirectory = 'artifacts/p07';
$policyChecks = Identity::selfChecks();
$policyFailed = count(array_filter($policyChecks, static fn (array $check): bool => $check['status'] !== 'PASS')) > 0;
$report = [
    'report_type' => 'isolated_database_engine_evidence',
    'qualification_target' => [
        'database' => 'Oracle MySQL Community Server 8.4.13 LTS',
        'framework' => 'Laravel 12.x',
        'php_matrix' => ['8.3.37', '8.4.28', '8.5.12'],
        'driver' => 'PDO_MYSQL',
        'production_support_claim' => false,
    ],
    'p07_status' => 'PENDING',
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'ci' => [
        'provider' => 'GitHub Actions',
        'run_id' => preg_match('/^\d+$/', (string) ($initialEnv['GITHUB_RUN_ID'] ?? '')) ? (string) $initialEnv['GITHUB_RUN_ID'] : 'unavailable',
        'run_attempt' => preg_match('/^\d+$/', (string) ($initialEnv['GITHUB_RUN_ATTEMPT'] ?? '')) ? (string) $initialEnv['GITHUB_RUN_ATTEMPT'] : 'unavailable',
        'workflow' => (string) ($initialEnv['GITHUB_WORKFLOW'] ?? 'unavailable'),
        'runner_name' => (string) ($initialEnv['RUNNER_NAME'] ?? 'unavailable'),
        'runner_environment' => (string) ($initialEnv['RUNNER_ENVIRONMENT'] ?? 'unavailable'),
        'runner_os' => (string) PHP_OS_FAMILY,
        'runner_image_os' => (string) ($initialEnv['ImageOS'] ?? 'unavailable'),
        'runner_image_version' => (string) ($initialEnv['ImageVersion'] ?? 'unavailable'),
    ],
    'runtime' => [
        'php_version' => PHP_VERSION,
        'php_int_size_bytes' => PHP_INT_SIZE,
        'php_int_bits' => PHP_INT_SIZE * 8,
        'operating_system' => php_uname(),
        'laravel_locked_version' => lockedLaravelVersion(),
    ],
    'preflight' => ['status' => 'NOT RUN'],
    'policy_checks' => $policyChecks,
    'policy_checks_status' => $policyFailed ? 'FAIL' : 'PASS',
    'database_identity' => null,
    'probes' => [],
    'overall_result' => 'BLOCKED',
    'overall_reason' => null,
];

foreach (PROBE_NAMES as $name) {
    $report['probes'][] = [
        'name' => $name,
        'status' => 'NOT RUN',
        'expected' => 'Run only after the complete isolated CI database preflight passes.',
        'actual' => 'Preflight not yet completed.',
        'error_classification' => null,
    ];
}

$pdo = null;
try {
    if ($policyFailed) {
        throw new RuntimeException('Harness identity policy self-check failed.');
    }
    $config = Identity::config($initialEnv);
    if (PHP_VERSION !== (string) ($initialEnv['P07_EXPECTED_PHP_VERSION'] ?? '')) {
        throw new RuntimeException('PHP runtime version does not match this exact CI matrix entry.');
    }
    if (!str_starts_with((string) lockedLaravelVersion(), 'v12.')) {
        throw new RuntimeException('Locked Laravel framework version is unavailable or outside Laravel 12.x.');
    }

    $pdo = Identity::connect($config);
    $databaseIdentity = Identity::inspect($pdo, $config);
    $report['database_identity'] = $databaseIdentity;
    $report['preflight'] = ['status' => 'PASS', 'checks' => [
        'GitHub Actions GitHub-hosted Linux runner', 'mapped host endpoint port matches the Actions service mapping',
        'connected MySQL internal server port matches expected port 3306',
        'unique run/attempt schema and matching job user', 'exact Oracle MySQL Community Server 8.4.13 identity',
        'schema, internal server port, PDO_MYSQL and authenticated user match expected values',
        'database grants limited to the unique job schema', 'PHP matrix and locked Laravel 12.x match target',
    ]];

    $factory = static fn (): PDO => Identity::connect($config);
    $suite = new ProbeSuite($config, $factory);
    $probeResults = $suite->run();
    $report['probes'] = $probeResults;
    $failedProbes = array_filter($probeResults, static fn (array $probe): bool => $probe['status'] !== 'PASS');
    $report['overall_result'] = $failedProbes === [] ? 'PASS' : 'FAIL';
    $report['overall_reason'] = $failedProbes === [] ? 'Every preflight and standalone engine probe passed.' : 'One or more standalone engine probes failed.';
} catch (Throwable $error) {
    $report['preflight'] = [
        'status' => 'FAIL',
        'error_classification' => safeError($error),
        'probes_blocked' => true,
    ];
    $report['overall_result'] = 'BLOCKED';
    $report['overall_reason'] = 'Preflight failed; no database probes were run.';
    foreach ($report['probes'] as &$probe) {
        $probe['actual'] = 'NOT RUN because preflight failed.';
    }
    unset($probe);
}

try {
    $report['completed_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    $paths = writeReports($report, $outputDirectory);
    fwrite(STDOUT, 'Evidence reports written: ' . basename($paths['json']) . ', ' . basename($paths['markdown']) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Evidence report write failed; classified as ' . safeError($error)['class'] . PHP_EOL);
    exit(3);
}

exit($report['overall_result'] === 'PASS' ? 0 : 1);
