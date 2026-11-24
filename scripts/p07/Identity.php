<?php

declare(strict_types=1);

namespace P07;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class PreflightDiagnosticException extends RuntimeException
{
    private const CODES = [
        'runner_identity_invalid',
        'php_runtime_mismatch',
        'laravel_lock_invalid',
        'pdo_mysql_unavailable',
        'database_connection_failed',
        'database_identity_mismatch',
        'grant_scope_invalid',
        'innodb_unavailable',
        'artifact_output_invalid',
        'preflight_unexpected_error',
        'harness_policy_checks_failed',
    ];

    public readonly string $diagnosticCode;
    /** @var array<string, string> */
    public readonly array $safeDetails;

    public function __construct(string $diagnosticCode, array $safeDetails = [])
    {
        $this->diagnosticCode = in_array($diagnosticCode, self::CODES, true)
            ? $diagnosticCode
            : 'preflight_unexpected_error';

        parent::__construct('P-07 preflight gate failed.');
        $this->safeDetails = $safeDetails;
    }
}

final class Identity
{
    private const EXPECTED_GITHUB_SERVER_URL = 'https://github.com';
    private const EXPECTED_GITHUB_REPOSITORY = 'Itassistancebd/laravel-master-core';
    private const EXPECTED_GITHUB_WORKFLOW = 'P-07 isolated MySQL evidence';
    private const EXPECTED_DATABASE_ACCOUNT_HOST = '%';

    /** @return array{code: string, gate: string, class: string, sqlstate?: string, driver_code?: int|null} */
    public static function safeError(Throwable $error, string $gate): array
    {
        $allowedGates = [
            'harness_policy_checks', 'runner_identity', 'php_runtime', 'laravel_lock', 'database_connection',
            'database_identity', 'probe_execution', 'artifact_output',
        ];
        if (!in_array($gate, $allowedGates, true)) {
            $gate = 'preflight';
        }

        if ($error instanceof PreflightDiagnosticException) {
            $code = $error->diagnosticCode;
        } elseif ($error instanceof PDOException) {
            $code = match ($gate) {
                'database_connection' => 'database_connection_failed',
                'database_identity' => 'database_identity_mismatch',
                'artifact_output' => 'artifact_output_invalid',
                default => 'preflight_unexpected_error',
            };
        } else {
            $code = 'preflight_unexpected_error';
        }

        $classification = [
            'code' => $code,
            'gate' => $gate,
            'class' => $error instanceof PDOException
                ? 'database_connection_or_identity_error'
                : ($error instanceof PreflightDiagnosticException ? 'preflight_gate_error' : 'preflight_unexpected_error'),
        ];

        if ($error instanceof PDOException) {
            $info = is_array($error->errorInfo ?? null) ? $error->errorInfo : [];
            $sqlstate = isset($info[0]) ? (string) $info[0] : (string) $error->getCode();
            $classification['sqlstate'] = preg_match('/^[A-Z0-9]{5}$/i', $sqlstate) ? strtoupper($sqlstate) : 'unknown';
            $classification['driver_code'] = isset($info[1]) && is_numeric($info[1]) ? (int) $info[1] : null;
        }

        if ($error instanceof PreflightDiagnosticException && $error->diagnosticCode === 'grant_scope_invalid') {
            $classification['grant_diagnostic'] = self::sanitizeGrantDiagnostic($error->safeDetails);
        }

        return $classification;
    }

    /** @param array<string, mixed> $details @return array<string, string> */
    private static function sanitizeGrantDiagnostic(array $details): array
    {
        $allowed = [
            'statement_category' => ['global_usage', 'schema_all_privileges', 'global_privilege', 'other', 'unparseable', 'none'],
            'scope_category' => ['global', 'job_schema', 'other_schema', 'other', 'unknown', 'none'],
            'account_host_category' => ['wildcard', 'other', 'unknown', 'not_applicable'],
            'rejection_reason' => ['empty_grant_list', 'invalid_statement', 'grant_option_present', 'wrong_grantee', 'wrong_host', 'duplicate_global_usage', 'wrong_schema', 'duplicate_schema_grant', 'unexpected_grant_form', 'missing_schema_grant'],
        ];
        $safe = [];
        foreach ($allowed as $field => $values) {
            $value = $details[$field] ?? null;
            $safe[$field] = is_string($value) && in_array($value, $values, true) ? $value : 'unknown';
        }
        return $safe;
    }

    /** @return never */
    private static function rejectGrant(string $reason, string $statement, string $scope, string $host): never
    {
        throw new PreflightDiagnosticException('grant_scope_invalid', [
            'statement_category' => $statement,
            'scope_category' => $scope,
            'account_host_category' => $host,
            'rejection_reason' => $reason,
        ]);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function classifyGrant(string $grant, string $schema): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($grant));
        if (!is_string($normalized) || $normalized === '') {
            return ['unparseable', 'unknown', 'unknown'];
        }
        if (preg_match('/^GRANT\s+USAGE\s+ON\s+\*\s*\.\s*\*\s+TO\s+`[^`]+`\s*@\s*`([^`]+)`(?:\s+WITH\s+GRANT\s+OPTION)?$/iD', $normalized, $m)) {
            return ['global_usage', 'global', $m[1] === '%' ? 'wildcard' : 'other'];
        }
        if (preg_match('/^GRANT\s+(.+?)\s+ON\s+`([^`]+)`\s*\.\s*\*\s+TO\s+`[^`]+`\s*@\s*`([^`]+)`(?:\s+WITH\s+GRANT\s+OPTION)?$/iD', $normalized, $m)) {
            $statement = strcasecmp(trim($m[1]), 'ALL PRIVILEGES') === 0 ? 'schema_all_privileges' : 'other';
            $grantSchema = self::decodeGrantSchemaIdentifier($m[2]);
            $scope = $grantSchema === null ? 'unknown' : (strcasecmp($grantSchema, $schema) === 0 ? 'job_schema' : 'other_schema');
            return [$statement, $scope, $m[3] === '%' ? 'wildcard' : 'other'];
        }
        if (preg_match('/^GRANT\s+.+?\s+ON\s+\*\s*\.\s*\*\s+TO\s+`[^`]+`\s*@\s*`([^`]+)`(?:\s+WITH\s+GRANT\s+OPTION)?$/iD', $normalized, $m)) {
            return ['global_privilege', 'global', $m[1] === '%' ? 'wildcard' : 'other'];
        }
        return ['unparseable', 'unknown', 'unknown'];
    }

    /** Decode only MySQL's documented escaped underscore in database-level GRANT identifiers. */
    private static function decodeGrantSchemaIdentifier(string $identifier): ?string
    {
        if (!str_contains($identifier, '\\')) {
            return $identifier;
        }

        $decoded = str_replace('\\_', '_', $identifier);
        return str_contains($decoded, '\\') ? null : $decoded;
    }

    /** @return array<string, mixed> */
    public static function sanitizeEvidenceReport(array $report): array
    {
        $ci = is_array($report['ci'] ?? null) ? $report['ci'] : [];
        $runtime = is_array($report['runtime'] ?? null) ? $report['runtime'] : [];
        $preflight = is_array($report['preflight'] ?? null) ? $report['preflight'] : [];
        $databaseIdentity = is_array($report['database_identity'] ?? null) ? $report['database_identity'] : null;

        $safeRunId = preg_match('/^\d+$/D', (string) ($ci['run_id'] ?? '')) ? (string) $ci['run_id'] : 'unavailable';
        $safeRunAttempt = preg_match('/^\d+$/D', (string) ($ci['run_attempt'] ?? '')) ? (string) $ci['run_attempt'] : 'unavailable';
        $safePhpVersion = preg_match('/^\d+\.\d+\.\d+$/D', (string) ($runtime['php_version'] ?? '')) ? (string) $runtime['php_version'] : 'unavailable';
        $safeLaravelVersion = preg_match('/^v12\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/D', (string) ($runtime['laravel_locked_version'] ?? ''))
            ? (string) $runtime['laravel_locked_version']
            : 'unavailable';
        $timestamp = static fn (mixed $value): string => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)
            ? $value
            : 'unavailable';
        $safeStatus = static fn (mixed $value, array $allowed): string => is_string($value) && in_array($value, $allowed, true) ? $value : 'BLOCKED';

        $safeReport = [
            'report_type' => 'isolated_database_engine_evidence',
            'qualification_target' => [
                'database' => 'Oracle MySQL Community Server 8.4.13 LTS',
                'framework' => 'Laravel 12.x',
                'php_matrix' => ['8.3.37', '8.4.28', '8.5.12'],
                'driver' => 'PDO_MYSQL',
                'production_support_claim' => false,
            ],
            'p07_status' => 'PENDING',
            'generated_at_utc' => $timestamp($report['generated_at_utc'] ?? null),
            'ci' => [
                'provider' => 'GitHub Actions',
                'github_host' => 'github.com',
                'workflow' => self::EXPECTED_GITHUB_WORKFLOW,
                'run_id' => $safeRunId,
                'run_attempt' => $safeRunAttempt,
                'runner_environment' => 'github-hosted',
            ],
            'runtime' => [
                'php_version' => $safePhpVersion,
                'php_int_size_bytes' => is_int($runtime['php_int_size_bytes'] ?? null) ? $runtime['php_int_size_bytes'] : null,
                'php_int_bits' => is_int($runtime['php_int_bits'] ?? null) ? $runtime['php_int_bits'] : null,
                'operating_system' => in_array($runtime['operating_system'] ?? null, ['Linux', 'Windows', 'Darwin', 'FreeBSD'], true)
                    ? $runtime['operating_system']
                    : 'unavailable',
                'laravel_locked_version' => $safeLaravelVersion,
            ],
            'preflight' => [
                'status' => $safeStatus($preflight['status'] ?? null, ['PASS', 'FAIL', 'NOT RUN']),
            ],
            'policy_checks' => self::sanitizePolicyChecks($report['policy_checks'] ?? []),
            'policy_checks_status' => $safeStatus($report['policy_checks_status'] ?? null, ['PASS', 'FAIL']),
            'database_identity' => $databaseIdentity === null ? null : self::sanitizeDatabaseIdentity($databaseIdentity),
            'probes' => self::sanitizeProbeResults($report['probes'] ?? []),
            'overall_result' => $safeStatus($report['overall_result'] ?? null, ['PASS', 'FAIL', 'BLOCKED']),
            'overall_reason' => self::sanitizeOverallReason($report['overall_reason'] ?? null),
        ];

        if (isset($report['completed_at_utc'])) {
            $safeReport['completed_at_utc'] = $timestamp($report['completed_at_utc']);
        }

        if (isset($preflight['checks']) && is_array($preflight['checks'])) {
            $allowedChecks = [
                'GitHub Actions GitHub-hosted Linux runner',
                'approved GitHub repository, server host, and workflow identity',
                'mapped host endpoint port matches the Actions service mapping',
                'connected MySQL internal server port matches expected port 3306',
                'unique run/attempt schema and matching job user',
                'exact Oracle MySQL Community Server 8.4.13 identity',
                'schema, internal server port, PDO_MYSQL and authenticated user match expected values',
                'database grants limited to the unique job schema',
                'PHP matrix and locked Laravel 12.x match target',
            ];
            $safeReport['preflight']['checks'] = array_values(array_filter($preflight['checks'], static fn (mixed $check): bool => is_string($check) && in_array($check, $allowedChecks, true)));
        }
        if (isset($preflight['error_classification']) && is_array($preflight['error_classification'])) {
            $safeReport['preflight']['error_classification'] = self::sanitizeErrorClassification($preflight['error_classification']);
        }
        if (isset($preflight['probes_blocked']) && is_bool($preflight['probes_blocked'])) {
            $safeReport['preflight']['probes_blocked'] = $preflight['probes_blocked'];
        }

        return $safeReport;
    }

    /** @return array<string, mixed> */
    private static function sanitizeDatabaseIdentity(array $identity): array
    {
        $safe = [
            'server_product' => 'Oracle MySQL Community Server',
            'server_version' => preg_match('/^8\.4\.13$/D', (string) ($identity['version'] ?? '')) ? (string) $identity['version'] : 'unavailable',
            'internal_server_port' => (int) ($identity['server_port'] ?? 0) === 3306 ? 3306 : null,
            'schema_scope_verified' => true,
        ];

        foreach ([
            'pdo_driver' => ['mysql'],
            'pdo_client_version' => null,
            'pdo_server_version' => null,
            'default_storage_engine' => ['InnoDB'],
            'character_set_server' => null,
            'collation_server' => null,
            'transaction_isolation' => ['REPEATABLE-READ', 'READ-COMMITTED', 'READ-UNCOMMITTED', 'SERIALIZABLE'],
            'session_time_zone' => null,
        ] as $field => $allowedValues) {
            $value = $identity[$field] ?? null;
            if (!is_string($value) || strlen($value) > 120 || preg_match('/[^A-Za-z0-9_.:+ -]/', $value)) {
                continue;
            }
            if ($allowedValues === null || in_array(strtoupper($value), $allowedValues, true)) {
                $safe[$field] = $value;
            }
        }

        $innodbSupport = $identity['storage_engine_support']['InnoDB'] ?? null;
        if (is_string($innodbSupport) && in_array(strtoupper($innodbSupport), ['YES', 'DEFAULT'], true)) {
            $safe['innodb_support'] = strtoupper($innodbSupport);
        }

        return $safe;
    }

    /** @return list<array<string, string>> */
    private static function sanitizePolicyChecks(mixed $checks): array
    {
        if (!is_array($checks)) {
            return [];
        }

        $safe = [];
        foreach ($checks as $check) {
            if (!is_array($check) || !is_string($check['name'] ?? null) || !is_string($check['status'] ?? null)) {
                continue;
            }
            $safe[] = [
                'name' => substr($check['name'], 0, 120),
                'status' => in_array($check['status'], ['PASS', 'FAIL'], true) ? $check['status'] : 'FAIL',
                'expected' => is_string($check['expected'] ?? null) ? substr($check['expected'], 0, 240) : '',
                'actual' => is_string($check['actual'] ?? null) ? substr($check['actual'], 0, 240) : '',
            ];
        }

        return $safe;
    }

    /** @return list<array<string, mixed>> */
    private static function sanitizeProbeResults(mixed $probes): array
    {
        if (!is_array($probes)) {
            return [];
        }

        $safe = [];
        foreach ($probes as $probe) {
            if (!is_array($probe) || !is_string($probe['name'] ?? null) || !preg_match('/^[a-z0-9_]{1,80}$/D', $probe['name'])) {
                continue;
            }
            $result = [
                'name' => $probe['name'],
                'status' => in_array($probe['status'] ?? null, ['PASS', 'FAIL', 'NOT RUN'], true) ? $probe['status'] : 'FAIL',
                'expected' => self::sanitizeProbeValue($probe['expected'] ?? ''),
                'actual' => self::sanitizeProbeValue($probe['actual'] ?? ''),
                'error_classification' => isset($probe['error_classification']) && is_array($probe['error_classification'])
                    ? self::sanitizeErrorClassification($probe['error_classification'])
                    : null,
            ];
            foreach (['started_at_utc', 'completed_at_utc'] as $field) {
                if (isset($probe[$field]) && is_string($probe[$field]) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $probe[$field])) {
                    $result[$field] = $probe[$field];
                }
            }
            if (isset($probe['cleanup_error_classification']) && is_array($probe['cleanup_error_classification'])) {
                $result['cleanup_error_classification'] = self::sanitizeErrorClassification($probe['cleanup_error_classification']);
            }
            $safe[] = $result;
        }

        return $safe;
    }

    private static function sanitizeProbeValue(mixed $value): string|array|int|float|bool|null
    {
        if (is_string($value)) {
            return substr($value, 0, 2000);
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                if (!is_string($key) && !is_int($key)) {
                    continue;
                }
                $safe[(string) $key] = self::sanitizeProbeValue($item);
            }
            return $safe;
        }
        return get_debug_type($value);
    }

    /** @return array<string, scalar|null> */
    private static function sanitizeErrorClassification(array $error): array
    {
        $safe = [];
        foreach (['code', 'gate', 'class'] as $field) {
            if (is_string($error[$field] ?? null) && preg_match('/^[a-z0-9_]{1,80}$/D', $error[$field])) {
                $safe[$field] = $error[$field];
            }
        }
        if (is_string($error['sqlstate'] ?? null) && (preg_match('/^[A-Z0-9]{5}$/D', $error['sqlstate']) || $error['sqlstate'] === 'unknown')) {
            $safe['sqlstate'] = $error['sqlstate'];
        }
        if (is_int($error['driver_code'] ?? null)) {
            $safe['driver_code'] = $error['driver_code'];
        }
        if (is_array($error['grant_diagnostic'] ?? null)) {
            $safe['grant_diagnostic'] = self::sanitizeGrantDiagnostic($error['grant_diagnostic']);
        }
        return $safe;
    }

    private static function sanitizeOverallReason(mixed $reason): ?string
    {
        $allowed = [
            'Every preflight and standalone engine probe passed.',
            'One or more standalone engine probes failed.',
            'Preflight failed; no database probes were run.',
        ];
        return is_string($reason) && in_array($reason, $allowed, true) ? $reason : null;
    }

    public static function assertRuntimePhpVersion(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            throw new PreflightDiagnosticException('php_runtime_mismatch');
        }
    }

    public static function assertLaravelVersion(?string $version): void
    {
        if (!str_starts_with((string) $version, 'v12.')) {
            throw new PreflightDiagnosticException('laravel_lock_invalid');
        }
    }

    public static function assertPdoMysqlAvailable(bool $available): void
    {
        if (!$available) {
            throw new PreflightDiagnosticException('pdo_mysql_unavailable');
        }
    }

    /** @param array<string, string> $engineSupport */
    public static function assertInnoDbAvailable(array $engineSupport): void
    {
        if (!in_array(strtoupper($engineSupport['InnoDB'] ?? ''), ['YES', 'DEFAULT'], true)) {
            throw new PreflightDiagnosticException('innodb_unavailable');
        }
    }

    /** @return array<string, string|int> */
    public static function config(array $env): array
    {
        $required = [
            'GITHUB_ACTIONS', 'CI', 'RUNNER_OS', 'RUNNER_ENVIRONMENT', 'RUNNER_NAME', 'ImageOS', 'ImageVersion', 'GITHUB_RUN_ID',
            'GITHUB_RUN_ATTEMPT', 'GITHUB_SERVER_URL', 'GITHUB_REPOSITORY', 'GITHUB_WORKFLOW', 'P07_DB_HOST', 'P07_DB_PORT',
            'P07_EXPECTED_HOST', 'P07_EXPECTED_PORT', 'P07_EXPECTED_SERVER_PORT', 'P07_DB_SCHEMA',
            'P07_EXPECTED_SCHEMA', 'P07_JOB_SUFFIX', 'P07_DB_USER', 'P07_EXPECTED_USER', 'P07_DB_PASSWORD',
            'P07_EXPECTED_SERVER_VERSION', 'P07_EXPECTED_PHP_VERSION', 'P07_OUTPUT_DIR',
        ];

        foreach ($required as $key) {
            if (!isset($env[$key]) || trim((string) $env[$key]) === '') {
                $code = match (true) {
                    in_array($key, ['GITHUB_ACTIONS', 'CI', 'RUNNER_OS', 'RUNNER_ENVIRONMENT', 'RUNNER_NAME', 'ImageOS', 'ImageVersion', 'GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT', 'GITHUB_SERVER_URL', 'GITHUB_REPOSITORY', 'GITHUB_WORKFLOW'], true) => 'runner_identity_invalid',
                    in_array($key, ['P07_EXPECTED_PHP_VERSION', 'P07_JOB_SUFFIX'], true) => 'php_runtime_mismatch',
                    $key === 'P07_OUTPUT_DIR' => 'artifact_output_invalid',
                    default => 'database_identity_mismatch',
                };
                throw new PreflightDiagnosticException($code);
            }
        }

        if ($env['GITHUB_ACTIONS'] !== 'true' || $env['CI'] !== 'true' || $env['RUNNER_OS'] !== 'Linux' || $env['RUNNER_ENVIRONMENT'] !== 'github-hosted' || !str_starts_with(strtolower((string) $env['ImageOS']), 'ubuntu')) {
            throw new PreflightDiagnosticException('runner_identity_invalid');
        }

        if (!preg_match('/^\d+$/', (string) $env['GITHUB_RUN_ID']) || !preg_match('/^\d+$/', (string) $env['GITHUB_RUN_ATTEMPT'])) {
            throw new PreflightDiagnosticException('runner_identity_invalid');
        }

        $repository = (string) $env['GITHUB_REPOSITORY'];
        if ($env['GITHUB_SERVER_URL'] !== self::EXPECTED_GITHUB_SERVER_URL
            || trim($repository) !== $repository
            || !preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository)
            || strcasecmp($repository, self::EXPECTED_GITHUB_REPOSITORY) !== 0
            || $env['GITHUB_WORKFLOW'] !== self::EXPECTED_GITHUB_WORKFLOW) {
            throw new PreflightDiagnosticException('runner_identity_invalid');
        }

        $phpSuffixes = ['8.3.37' => 'p83', '8.4.28' => 'p84', '8.5.12' => 'p85'];
        $expectedPhp = (string) $env['P07_EXPECTED_PHP_VERSION'];
        if (!isset($phpSuffixes[$expectedPhp]) || $env['P07_JOB_SUFFIX'] !== $phpSuffixes[$expectedPhp]) {
            throw new PreflightDiagnosticException('php_runtime_mismatch');
        }

        $expectedSchema = 'p07_' . $env['GITHUB_RUN_ID'] . '_' . $env['GITHUB_RUN_ATTEMPT'] . '_' . $env['P07_JOB_SUFFIX'];
        $expectedUser = $expectedSchema;
        if (!preg_match('/^p07_\d+_\d+_p(?:83|84|85)$/', (string) $env['P07_DB_SCHEMA']) || $env['P07_DB_SCHEMA'] !== $expectedSchema || $env['P07_EXPECTED_SCHEMA'] !== $expectedSchema) {
            throw new PreflightDiagnosticException('database_identity_mismatch');
        }

        if ($env['P07_DB_USER'] !== $expectedUser || $env['P07_EXPECTED_USER'] !== $expectedUser) {
            throw new PreflightDiagnosticException('database_identity_mismatch');
        }

        if ($env['P07_DB_HOST'] !== '127.0.0.1' || $env['P07_EXPECTED_HOST'] !== '127.0.0.1') {
            throw new PreflightDiagnosticException('database_identity_mismatch');
        }

        $port = filter_var($env['P07_DB_PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $expectedPort = filter_var($env['P07_EXPECTED_PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $expectedServerPort = filter_var($env['P07_EXPECTED_SERVER_PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false || $expectedPort === false || (int) $port !== (int) $expectedPort || $expectedServerPort !== 3306 || strlen($expectedSchema) > 64 || strlen($expectedUser) > 32) {
            throw new PreflightDiagnosticException('database_identity_mismatch');
        }

        if ($env['P07_EXPECTED_SERVER_VERSION'] !== '8.4.13') {
            throw new PreflightDiagnosticException('database_identity_mismatch');
        }

        if (!in_array($expectedPhp, ['8.3.37', '8.4.28', '8.5.12'], true)) {
            throw new PreflightDiagnosticException('php_runtime_mismatch');
        }

        if ($env['P07_OUTPUT_DIR'] !== 'artifacts/p07') {
            throw new PreflightDiagnosticException('artifact_output_invalid');
        }

        return [
            'host' => (string) $env['P07_DB_HOST'],
            'connection_port' => $port,
            'expected_connection_port' => $expectedPort,
            'expected_server_port' => $expectedServerPort,
            'schema' => (string) $env['P07_DB_SCHEMA'],
            'user' => (string) $env['P07_DB_USER'],
            'password' => (string) $env['P07_DB_PASSWORD'],
            'expected_version' => (string) $env['P07_EXPECTED_SERVER_VERSION'],
            'output_dir' => (string) $env['P07_OUTPUT_DIR'],
        ];
    }

    /** @return array<string, mixed> */
    public static function connect(array $config): PDO
    {
        self::assertPdoMysqlAvailable(extension_loaded('pdo_mysql'));

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['connection_port'], $config['schema']);
        return new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /** @return array<string, mixed> */
    public static function inspect(PDO $pdo, array $config): array
    {
        $row = $pdo->query(
            'SELECT VERSION() AS version, @@version_comment AS version_comment, @@hostname AS server_hostname, '
            . '@@port AS server_port, DATABASE() AS schema_name, CURRENT_USER() AS authenticated_user, '
            . '@@sql_mode AS sql_mode, @@default_storage_engine AS default_storage_engine, '
            . '@@character_set_server AS character_set_server, @@collation_server AS collation_server, '
            . '@@transaction_isolation AS transaction_isolation, @@session.time_zone AS session_time_zone'
        )->fetch();

        if (!is_array($row)) {
            throw new PreflightDiagnosticException('database_identity_mismatch');
        }

        $grants = array_map('strval', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
        $engines = $pdo->query("SHOW ENGINES")->fetchAll(PDO::FETCH_ASSOC);
        $engineSupport = [];
        foreach ($engines as $engine) {
            if (isset($engine['Engine'], $engine['Support'])) {
                $engineSupport[(string) $engine['Engine']] = (string) $engine['Support'];
            }
        }
        self::assertInnoDbAvailable($engineSupport);
        self::assertObservedIdentity($row, (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME), $config, $grants);

        $row['pdo_driver'] = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $row['pdo_client_version'] = $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION);
        $row['pdo_server_version'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $row['connection_status'] = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
        $row['storage_engine_support'] = $engineSupport;
        $row['authenticated_user'] = (string) $row['authenticated_user'];
        $row['server_port'] = (int) $row['server_port'];
        return $row;
    }

    /** @param array<string, mixed> $row @param list<string> $grants */
    public static function assertObservedIdentity(array $row, string $driver, array $config, array $grants): void
    {
        if ($driver !== 'mysql') {
            throw new PreflightDiagnosticException('database_identity_mismatch');
        }

        foreach (['version', 'version_comment', 'server_port', 'schema_name', 'authenticated_user'] as $field) {
            if (!array_key_exists($field, $row) || !is_scalar($row[$field]) || trim((string) $row[$field]) === '') {
                throw new PreflightDiagnosticException('database_identity_mismatch');
            }
        }

        $version = (string) $row['version'];
        $comment = (string) $row['version_comment'];
        if ($version !== $config['expected_version'] || !str_contains($comment, 'MySQL Community Server') || preg_match('/mariadb|percona/i', $comment)) {
            throw new PreflightDiagnosticException('database_identity_mismatch');
        }

        if ((string) $row['schema_name'] !== $config['schema'] || (int) $row['server_port'] !== $config['expected_server_port']) {
            throw new PreflightDiagnosticException('database_identity_mismatch');
        }

        $expectedAuthenticatedAccount = $config['user'] . '@' . self::EXPECTED_DATABASE_ACCOUNT_HOST;
        if (trim((string) $row['authenticated_user']) !== (string) $row['authenticated_user']
            || strcasecmp((string) $row['authenticated_user'], $expectedAuthenticatedAccount) !== 0) {
            throw new PreflightDiagnosticException('database_identity_mismatch');
        }

        self::assertSchemaScopedGrants($grants, $config['schema'], $config['user']);
    }

    /** @param list<string> $grants */
    public static function assertSchemaScopedGrants(array $grants, string $schema, string $expectedUser): void
    {
        if ($grants === []) {
            self::rejectGrant('empty_grant_list', 'none', 'none', 'not_applicable');
        }

        $schemaGrant = false;
        $globalUsageGrant = false;
        foreach ($grants as $grant) {
            if (!is_string($grant)) {
                self::rejectGrant('invalid_statement', 'unparseable', 'unknown', 'unknown');
            }

            $normalizedGrant = preg_replace('/\s+/', ' ', trim($grant));
            if (!is_string($normalizedGrant) || $normalizedGrant === '') {
                self::rejectGrant('invalid_statement', 'unparseable', 'unknown', 'unknown');
            }

            [$statementCategory, $scopeCategory, $hostCategory] = self::classifyGrant($normalizedGrant, $schema);
            if (preg_match('/\s+WITH\s+GRANT\s+OPTION$/iD', $normalizedGrant)) {
                self::rejectGrant('grant_option_present', $statementCategory, $scopeCategory, $hostCategory);
            }

            if (preg_match('/^GRANT\s+USAGE\s+ON\s+\*\s*\.\s*\*\s+TO\s+`([^`]+)`\s*@\s*`([^`]+)`$/iD', $normalizedGrant, $matches)) {
                if (strcasecmp($matches[1], $expectedUser) !== 0) {
                    self::rejectGrant('wrong_grantee', $statementCategory, $scopeCategory, $hostCategory);
                }
                if ($matches[2] !== self::EXPECTED_DATABASE_ACCOUNT_HOST) {
                    self::rejectGrant('wrong_host', $statementCategory, $scopeCategory, $hostCategory);
                }
                if ($globalUsageGrant) {
                    self::rejectGrant('duplicate_global_usage', $statementCategory, $scopeCategory, $hostCategory);
                }

                $globalUsageGrant = true;
                continue;
            }

            if (preg_match('/^GRANT\s+ALL\s+PRIVILEGES\s+ON\s+`([^`]+)`\s*\.\s*\*\s+TO\s+`([^`]+)`\s*@\s*`([^`]+)`$/iD', $normalizedGrant, $matches)) {
                $grantSchema = self::decodeGrantSchemaIdentifier($matches[1]);
                if ($grantSchema === null || strcasecmp($grantSchema, $schema) !== 0) {
                    self::rejectGrant('wrong_schema', $statementCategory, $scopeCategory, $hostCategory);
                }
                if (strcasecmp($matches[2], $expectedUser) !== 0) {
                    self::rejectGrant('wrong_grantee', $statementCategory, $scopeCategory, $hostCategory);
                }
                if ($matches[3] !== self::EXPECTED_DATABASE_ACCOUNT_HOST) {
                    self::rejectGrant('wrong_host', $statementCategory, $scopeCategory, $hostCategory);
                }
                if ($schemaGrant) {
                    self::rejectGrant('duplicate_schema_grant', $statementCategory, $scopeCategory, $hostCategory);
                }
                $schemaGrant = true;
                continue;
            }

            self::rejectGrant('unexpected_grant_form', $statementCategory, $scopeCategory, $hostCategory);
        }

        if (!$schemaGrant) {
            self::rejectGrant('missing_schema_grant', 'none', 'none', 'not_applicable');
        }
    }

    /** @return list<array<string, mixed>> */
    public static function selfChecks(): array
    {
        $base = [
            'GITHUB_ACTIONS' => 'true', 'CI' => 'true', 'RUNNER_OS' => 'Linux', 'RUNNER_ENVIRONMENT' => 'github-hosted', 'RUNNER_NAME' => 'GitHub Actions 1', 'ImageOS' => 'ubuntu24', 'ImageVersion' => '20261123.1.0',
            'GITHUB_RUN_ID' => '123456', 'GITHUB_RUN_ATTEMPT' => '2', 'GITHUB_SERVER_URL' => 'https://github.com', 'GITHUB_REPOSITORY' => 'Itassistancebd/laravel-master-core', 'GITHUB_WORKFLOW' => 'P-07 isolated MySQL evidence',
            'P07_DB_HOST' => '127.0.0.1', 'P07_DB_PORT' => '49152', 'P07_EXPECTED_HOST' => '127.0.0.1',
            'P07_EXPECTED_PORT' => '49152', 'P07_EXPECTED_SERVER_PORT' => '3306', 'P07_DB_SCHEMA' => 'p07_123456_2_p84', 'P07_EXPECTED_SCHEMA' => 'p07_123456_2_p84',
            'P07_JOB_SUFFIX' => 'p84', 'P07_DB_USER' => 'p07_123456_2_p84', 'P07_EXPECTED_USER' => 'p07_123456_2_p84', 'P07_DB_PASSWORD' => 'test-only',
            'P07_EXPECTED_SERVER_VERSION' => '8.4.13', 'P07_EXPECTED_PHP_VERSION' => '8.4.28', 'P07_OUTPUT_DIR' => 'artifacts/p07',
        ];

        $checks = [];
        $cases = [
            'valid GitHub-hosted job identity accepted' => [$base, true, null],
            'self-hosted runner reports runner diagnostic' => [array_replace($base, ['RUNNER_ENVIRONMENT' => 'self-hosted']), false, 'runner_identity_invalid'],
            'missing runner environment reports runner diagnostic' => [array_diff_key($base, ['RUNNER_ENVIRONMENT' => true]), false, 'runner_identity_invalid'],
            'unexpected runner environment reports runner diagnostic' => [array_replace($base, ['RUNNER_ENVIRONMENT' => 'custom']), false, 'runner_identity_invalid'],
            'approved GitHub identity accepts repository casing variation' => [array_replace($base, ['GITHUB_REPOSITORY' => 'ITASSISTANCEBD/LARAVEL-MASTER-CORE']), true, null],
            'missing GitHub server URL rejected' => [array_diff_key($base, ['GITHUB_SERVER_URL' => true]), false, 'runner_identity_invalid'],
            'missing GitHub repository rejected' => [array_diff_key($base, ['GITHUB_REPOSITORY' => true]), false, 'runner_identity_invalid'],
            'unexpected GitHub server rejected' => [array_replace($base, ['GITHUB_SERVER_URL' => 'https://notgithub.com']), false, 'runner_identity_invalid'],
            'deceptive GitHub suffix host rejected' => [array_replace($base, ['GITHUB_SERVER_URL' => 'https://github.com.attacker.example']), false, 'runner_identity_invalid'],
            'deceptive GitHub prefix host rejected' => [array_replace($base, ['GITHUB_SERVER_URL' => 'https://github.com.evil']), false, 'runner_identity_invalid'],
            'non-HTTPS GitHub server rejected' => [array_replace($base, ['GITHUB_SERVER_URL' => 'http://github.com']), false, 'runner_identity_invalid'],
            'GitHub server whitespace rejected' => [array_replace($base, ['GITHUB_SERVER_URL' => ' https://github.com']), false, 'runner_identity_invalid'],
            'unexpected GitHub repository rejected' => [array_replace($base, ['GITHUB_REPOSITORY' => 'attacker/laravel-master-core']), false, 'runner_identity_invalid'],
            'GitHub repository suffix rejected' => [array_replace($base, ['GITHUB_REPOSITORY' => 'Itassistancebd/laravel-master-core.attacker']), false, 'runner_identity_invalid'],
            'GitHub repository whitespace rejected' => [array_replace($base, ['GITHUB_REPOSITORY' => 'Itassistancebd/laravel-master-core ']), false, 'runner_identity_invalid'],
            'unexpected workflow identity rejected' => [array_replace($base, ['GITHUB_WORKFLOW' => 'P-07 evidence']), false, 'runner_identity_invalid'],
            'missing database identity value reports database diagnostic' => [array_diff_key($base, ['P07_EXPECTED_SCHEMA' => true]), false, 'database_identity_mismatch'],
            'wrong host reports database diagnostic' => [array_replace($base, ['P07_DB_HOST' => 'localhost']), false, 'database_identity_mismatch'],
            'wrong schema reports database diagnostic' => [array_replace($base, ['P07_DB_SCHEMA' => 'shared_db']), false, 'database_identity_mismatch'],
            'incorrect job suffix reports PHP diagnostic' => [array_replace($base, ['P07_JOB_SUFFIX' => 'p83']), false, 'php_runtime_mismatch'],
            'correct mapped host port plus internal port accepted' => [$base, true, null],
            'incorrect mapped host port reports database diagnostic' => [array_replace($base, ['P07_DB_PORT' => '3306']), false, 'database_identity_mismatch'],
            'incorrect expected internal server port reports database diagnostic' => [array_replace($base, ['P07_EXPECTED_SERVER_PORT' => '49152']), false, 'database_identity_mismatch'],
            'wrong run user reports database diagnostic' => [array_replace($base, ['P07_DB_USER' => 'root']), false, 'database_identity_mismatch'],
            'wrong server target reports database diagnostic' => [array_replace($base, ['P07_EXPECTED_SERVER_VERSION' => '8.0.36']), false, 'database_identity_mismatch'],
            'unapproved PHP target reports PHP diagnostic' => [array_replace($base, ['P07_EXPECTED_PHP_VERSION' => '8.2.30']), false, 'php_runtime_mismatch'],
            'invalid output directory reports artifact diagnostic' => [array_replace($base, ['P07_OUTPUT_DIR' => '../']), false, 'artifact_output_invalid'],
            'non-CI execution reports runner diagnostic' => [array_replace($base, ['GITHUB_ACTIONS' => 'false']), false, 'runner_identity_invalid'],
        ];

        foreach ($cases as $name => [$env, $shouldPass, $expectedCode]) {
            $actualCode = null;
            try {
                self::config($env);
                $passed = true;
            } catch (PreflightDiagnosticException $error) {
                $passed = false;
                $actualCode = $error->diagnosticCode;
            } catch (Throwable) {
                $passed = false;
                $actualCode = 'preflight_unexpected_error';
            }

            $checks[] = [
                'name' => $name,
                'status' => $passed === $shouldPass && $actualCode === $expectedCode ? 'PASS' : 'FAIL',
                'expected' => $shouldPass ? 'accept configuration' : 'reject before connection with ' . $expectedCode,
                'actual' => $passed ? 'accepted' : 'rejected with ' . $actualCode,
            ];
        }

        $grantCases = [
            'lowercase job-schema grant accepted' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `p07_123456_2_p84`.* TO `p07_123456_2_p84`@`%`'], true, null],
            'uppercase job-schema grant accepted' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `P07_123456_2_P84`.* TO `p07_123456_2_p84`@`%`'], true, null],
            'mixed-case job-schema grant accepted' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `P07_123456_2_p84`.* TO `p07_123456_2_p84`@`%`'], true, null],
            'whitespace-normalized schema grant accepted' => [[' GRANT   USAGE ON *.* TO `p07_123456_2_p84`@`%` ', "GRANT\n ALL PRIVILEGES ON `p07_123456_2_p84` . * TO `p07_123456_2_p84` @ `%`"], true, null],
            'global USAGE without grant option accepted' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `p07_123456_2_p84`.* TO `p07_123456_2_p84`@`%`'], true, null],
            'global USAGE with grant option rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%` WITH GRANT OPTION', 'GRANT ALL PRIVILEGES ON `p07_123456_2_p84`.* TO `p07_123456_2_p84`@`%`'], false, 'grant_scope_invalid'],
            'schema-scoped grant with grant option rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `p07_123456_2_p84`.* TO `p07_123456_2_p84`@`%` WITH GRANT OPTION'], false, 'grant_scope_invalid'],
            'global privilege grant rejected' => [['GRANT ALL PRIVILEGES ON *.* TO `p07_123456_2_p84`@`%`'], false, 'grant_scope_invalid'],
            'different schema grant rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `other_schema`.* TO `p07_123456_2_p84`@`%`'], false, 'grant_scope_invalid'],
            'missing job-schema grant rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`'], false, 'grant_scope_invalid'],
            'malformed grant output rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON p07_123456_2_p84.* TO `p07_123456_2_p84`@`%`'], false, 'grant_scope_invalid'],
            'unexpected privilege form rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT SELECT ON `p07_123456_2_p84`.* TO `p07_123456_2_p84`@`%`'], false, 'grant_scope_invalid'],
            'wrong grantee rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `p07_123456_2_p84`.* TO `another_user`@`%`'], false, 'grant_scope_invalid'],
            'duplicate global USAGE grant rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `p07_123456_2_p84`.* TO `p07_123456_2_p84`@`%`'], false, 'grant_scope_invalid'],
            'global USAGE for unexpected account host rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`localhost`', 'GRANT ALL PRIVILEGES ON `p07_123456_2_p84`.* TO `p07_123456_2_p84`@`%`'], false, 'grant_scope_invalid'],
            'schema grant for deceptive account host rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `p07_123456_2_p84`.* TO `p07_123456_2_p84`@`localhost.github.com`'], false, 'grant_scope_invalid'],
            'MySQL escaped underscores in job schema accepted' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `p07\\_123456\\_2\\_p84`.* TO `p07_123456_2_p84`@`%`'], true, null],
            'unrecognized schema escape rejected' => [['GRANT ALL PRIVILEGES ON `p07\\q123456_2_p84`.* TO `p07_123456_2_p84`@`%`'], false, 'grant_scope_invalid'],
        ];
        foreach ($grantCases as $name => [$grants, $shouldPass, $expectedCode]) {
            $actualCode = null;
            try {
                self::assertSchemaScopedGrants($grants, 'p07_123456_2_p84', 'p07_123456_2_p84');
                $passed = true;
            } catch (PreflightDiagnosticException $error) {
                $passed = false;
                $actualCode = $error->diagnosticCode;
            } catch (Throwable) {
                $passed = false;
                $actualCode = 'preflight_unexpected_error';
            }
            $checks[] = [
                'name' => $name,
                'status' => $passed === $shouldPass && $actualCode === $expectedCode ? 'PASS' : 'FAIL',
                'expected' => $shouldPass ? 'accept the exact job-schema grant' : 'reject the noncompliant grant set',
                'actual' => $passed ? 'accepted' : 'rejected with ' . $actualCode,
            ];
        }

        $diagnosticCases = [
            'wrong schema grant has allow-listed diagnostic' => [
                ['GRANT ALL PRIVILEGES ON `private_schema_name`.* TO `private_user_name`@`%`'],
                ['statement_category' => 'schema_all_privileges', 'scope_category' => 'other_schema', 'account_host_category' => 'wildcard', 'rejection_reason' => 'wrong_schema'],
            ],
            'grant option diagnostic omits grant text and identifiers' => [
                ['GRANT ALL PRIVILEGES ON `private_schema_name`.* TO `private_user_name`@`localhost` WITH GRANT OPTION'],
                ['statement_category' => 'schema_all_privileges', 'scope_category' => 'other_schema', 'account_host_category' => 'other', 'rejection_reason' => 'grant_option_present'],
            ],
        ];
        foreach ($diagnosticCases as $name => [$diagnosticGrants, $expectedDetails]) {
            try {
                self::assertSchemaScopedGrants($diagnosticGrants, 'p07_123456_2_p84', 'p07_123456_2_p84');
                $diagnostic = [];
            } catch (PreflightDiagnosticException $error) {
                $diagnostic = self::safeError($error, 'database_identity');
            }
            $actualDetails = $diagnostic['grant_diagnostic'] ?? [];
            $serializedDiagnostic = json_encode($diagnostic, JSON_UNESCAPED_SLASHES) ?: '';
            $checks[] = [
                'name' => $name,
                'status' => ($actualDetails === $expectedDetails
                    && !str_contains($serializedDiagnostic, 'private_schema_name')
                    && !str_contains($serializedDiagnostic, 'private_user_name')) ? 'PASS' : 'FAIL',
                'expected' => 'fixed grant categories and reason only; no identifiers or statement text',
                'actual' => $actualDetails === $expectedDetails ? 'safe classification returned' : 'classification mismatch',
            ];
        }

        $escapedGrantClassification = self::classifyGrant(
            'GRANT ALL PRIVILEGES ON `p07\\_123456\\_2\\_p84`.* TO `p07_123456_2_p84`@`%`',
            'p07_123456_2_p84'
        );
        $checks[] = [
            'name' => 'escaped job-schema grant is classified as the job schema',
            'status' => $escapedGrantClassification === ['schema_all_privileges', 'job_schema', 'wildcard'] ? 'PASS' : 'FAIL',
            'expected' => 'schema_all_privileges / job_schema / wildcard',
            'actual' => implode(' / ', $escapedGrantClassification),
        ];

        $casingFixtures = [];
        foreach (['lowercase', 'uppercase', 'mixed-case'] as $case) {
            $grant = $grantCases[$case . ' job-schema grant accepted'][0][1] ?? '';
            if (preg_match('/^GRANT ALL PRIVILEGES ON `([^`]+)`\.\*/', $grant, $matches)) {
                $casingFixtures[$case] = $matches[1];
            }
        }
        $expectedCasingFixtures = [
            'lowercase' => 'p07_123456_2_p84',
            'uppercase' => 'P07_123456_2_P84',
            'mixed-case' => 'P07_123456_2_p84',
        ];
        $checks[] = [
            'name' => 'lowercase uppercase and mixed-case grant fixtures are exact and distinct',
            'status' => $casingFixtures === $expectedCasingFixtures && count(array_unique($casingFixtures)) === 3 ? 'PASS' : 'FAIL',
            'expected' => 'three distinct casing forms against the canonical lowercase schema',
            'actual' => $casingFixtures === $expectedCasingFixtures ? 'all three fixture forms are distinct' : 'fixture forms differ or are duplicated',
        ];

        $config = self::config($base);
        $observed = [
            'version' => '8.4.13', 'version_comment' => 'MySQL Community Server - GPL',
            'server_port' => 3306, 'schema_name' => 'p07_123456_2_p84', 'authenticated_user' => 'p07_123456_2_p84@%',
        ];
        $observedCases = [
            'Oracle MySQL job identity accepted' => [$observed, 'mysql', true, null],
            'wrong database product reports identity diagnostic' => [array_replace($observed, ['version_comment' => 'MariaDB Server']), 'mysql', false, 'database_identity_mismatch'],
            'wrong actual server version reports identity diagnostic' => [array_replace($observed, ['version' => '8.4.12']), 'mysql', false, 'database_identity_mismatch'],
            'wrong actual schema reports identity diagnostic' => [array_replace($observed, ['schema_name' => 'shared']), 'mysql', false, 'database_identity_mismatch'],
            'wrong internal server port reports identity diagnostic' => [array_replace($observed, ['server_port' => 49152]), 'mysql', false, 'database_identity_mismatch'],
            'wrong authenticated user reports identity diagnostic' => [array_replace($observed, ['authenticated_user' => 'root@localhost']), 'mysql', false, 'database_identity_mismatch'],
            'authenticated username casing accepted for approved wildcard host' => [array_replace($observed, ['authenticated_user' => 'P07_123456_2_P84@%']), 'mysql', true, null],
            'wrong authenticated account host rejected' => [array_replace($observed, ['authenticated_user' => 'p07_123456_2_p84@localhost']), 'mysql', false, 'database_identity_mismatch'],
            'deceptive authenticated account host rejected' => [array_replace($observed, ['authenticated_user' => 'p07_123456_2_p84@localhost.github.com']), 'mysql', false, 'database_identity_mismatch'],
            'authenticated account whitespace rejected' => [array_replace($observed, ['authenticated_user' => 'p07_123456_2_p84@% ']), 'mysql', false, 'database_identity_mismatch'],
            'wrong actual PDO driver reports identity diagnostic' => [$observed, 'sqlite', false, 'database_identity_mismatch'],
            'missing observed identity reports identity diagnostic' => [array_diff_key($observed, ['schema_name' => true]), 'mysql', false, 'database_identity_mismatch'],
            'malformed observed identity reports identity diagnostic' => [array_replace($observed, ['server_port' => 'not-a-port']), 'mysql', false, 'database_identity_mismatch'],
        ];
        foreach ($observedCases as $name => [$row, $driver, $shouldPass, $expectedCode]) {
            $actualCode = null;
            try {
                self::assertObservedIdentity($row, $driver, $config, $grantCases['lowercase job-schema grant accepted'][0]);
                $passed = true;
            } catch (PreflightDiagnosticException $error) {
                $passed = false;
                $actualCode = $error->diagnosticCode;
            } catch (Throwable) {
                $passed = false;
                $actualCode = 'preflight_unexpected_error';
            }
            $checks[] = [
                'name' => $name,
                'status' => $passed === $shouldPass && $actualCode === $expectedCode ? 'PASS' : 'FAIL',
                'expected' => $shouldPass ? 'accept exact Oracle MySQL job identity' : 'reject identity before any probe write',
                'actual' => $passed ? 'accepted' : 'rejected with ' . $actualCode,
            ];
        }

        $diagnosticChecks = [
            'exact PHP runtime accepted' => [static fn () => self::assertRuntimePhpVersion('8.4.28', '8.4.28'), null],
            'PHP runtime mismatch reports PHP diagnostic' => [static fn () => self::assertRuntimePhpVersion('8.5.13', '8.5.12'), 'php_runtime_mismatch'],
            'Laravel 12 lock accepted' => [static fn () => self::assertLaravelVersion('v12.71.0'), null],
            'invalid Laravel lock reports Laravel diagnostic' => [static fn () => self::assertLaravelVersion(null), 'laravel_lock_invalid'],
            'PDO_MYSQL present accepted' => [static fn () => self::assertPdoMysqlAvailable(true), null],
            'missing PDO_MYSQL reports extension diagnostic' => [static fn () => self::assertPdoMysqlAvailable(false), 'pdo_mysql_unavailable'],
            'InnoDB available accepted' => [static fn () => self::assertInnoDbAvailable(['InnoDB' => 'DEFAULT']), null],
            'missing InnoDB reports engine diagnostic' => [static fn () => self::assertInnoDbAvailable(['InnoDB' => 'NO']), 'innodb_unavailable'],
        ];
        foreach ($diagnosticChecks as $name => [$callback, $expectedCode]) {
            $actualCode = null;
            try {
                $callback();
            } catch (PreflightDiagnosticException $error) {
                $actualCode = $error->diagnosticCode;
            } catch (Throwable) {
                $actualCode = 'preflight_unexpected_error';
            }
            $checks[] = [
                'name' => $name,
                'status' => $actualCode === $expectedCode ? 'PASS' : 'FAIL',
                'expected' => $expectedCode ?? 'no diagnostic',
                'actual' => $actualCode ?? 'no diagnostic',
            ];
        }

        $checks[] = self::safeErrorSelfCheck();
        array_push($checks, ...self::reportSerializationSelfChecks());

        return $checks;
    }

    /** @return list<array{name: string, status: string, expected: string, actual: string}> */
    private static function reportSerializationSelfChecks(): array
    {
        $successPayloads = \serializeReportPayloads(self::reportFixture(false));
        $successJson = json_decode($successPayloads['json'], true);
        $successConsistent = is_array($successJson)
            && $successJson['overall_result'] === 'PASS'
            && ($successJson['database_identity']['pdo_client_version'] ?? null) === 'mysqlnd 8.4.28'
            && ($successJson['database_identity']['schema_scope_verified'] ?? null) === true
            && str_contains($successPayloads['markdown'], 'Overall run result: **PASS**')
            && self::reportProbeSummariesMatch($successJson, $successPayloads['markdown'], 'PASS');
        $successPrivateFieldsRemoved = self::reportContainsNoPrivateMarkers($successPayloads);

        $failedPayloads = \serializeReportPayloads(self::reportFixture(true));
        $failedJson = json_decode($failedPayloads['json'], true);
        $failedProbeStates = is_array($failedJson)
            && count($failedJson['probes'] ?? []) === 8
            && count(array_filter($failedJson['probes'], static fn (array $probe): bool => ($probe['status'] ?? null) === 'NOT RUN')) === 8
            && str_contains($failedPayloads['markdown'], 'Preflight diagnostic: `database_connection_failed`');
        $failedConsistent = is_array($failedJson)
            && $failedJson['overall_result'] === 'BLOCKED'
            && str_contains($failedPayloads['markdown'], 'Overall run result: **BLOCKED**')
            && self::reportProbeSummariesMatch($failedJson, $failedPayloads['markdown'], 'NOT RUN');
        $failedPrivateFieldsRemoved = self::reportContainsNoPrivateMarkers($failedPayloads);

        $writeFailureIsClassified = false;
        try {
            \writeReports(self::reportFixture(true), '../private-output');
        } catch (PreflightDiagnosticException $error) {
            $writeFailureIsClassified = $error->diagnosticCode === 'artifact_output_invalid';
        } catch (Throwable) {
            $writeFailureIsClassified = false;
        }

        return [
            [
                'name' => 'successful JSON and Markdown evidence are consistent and sanitized',
                'status' => $successConsistent && $successPrivateFieldsRemoved ? 'PASS' : 'FAIL',
                'expected' => 'matching PASS result and no private metadata in either format',
                'actual' => $successConsistent && $successPrivateFieldsRemoved ? 'PASS evidence matches and private fields are omitted' : 'serialization mismatch or private field retained',
            ],
            [
                'name' => 'failed preflight evidence remains BLOCKED with eight NOT RUN probes',
                'status' => $failedProbeStates && $failedConsistent && $failedPrivateFieldsRemoved ? 'PASS' : 'FAIL',
                'expected' => 'diagnostic retained, all probes NOT RUN, formats consistent, private fields omitted',
                'actual' => $failedProbeStates && $failedConsistent && $failedPrivateFieldsRemoved ? 'BLOCKED evidence is consistent and sanitized' : 'blocked evidence mismatch or private field retained',
            ],
            [
                'name' => 'report output policy failure returns safe artifact diagnostic',
                'status' => $writeFailureIsClassified ? 'PASS' : 'FAIL',
                'expected' => 'artifact_output_invalid without writing a file',
                'actual' => $writeFailureIsClassified ? 'safe artifact diagnostic returned' : 'unexpected report output failure behavior',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function reportFixture(bool $failed): array
    {
        $probes = [];
        foreach (['check_constraint_enforcement', 'foreign_key_and_rollback', 'collation_and_unique_identity', 'datetime6_precision_and_timezone', 'signed_bigint_boundaries', 'row_lock_timeout_and_release', 'transaction_snapshot_visibility', 'optimistic_stale_write_conflict_and_atomic_rollback'] as $name) {
            $probes[] = [
                'name' => $name,
                'status' => $failed ? 'NOT RUN' : 'PASS',
                'expected' => 'sanitized expected behavior',
                'actual' => $failed ? 'NOT RUN because preflight failed.' : ['observation' => 'valid'],
                'error_classification' => null,
            ];
        }

        return [
            'report_type' => 'isolated_database_engine_evidence',
            'qualification_target' => ['database' => 'private_marker_target'],
            'p07_status' => 'PENDING',
            'generated_at_utc' => '2026-11-25T12:00:00Z',
            'ci' => [
                'run_id' => '123456', 'run_attempt' => '1', 'runner_name' => 'private_marker_runner',
                'server_url' => 'https://github.com.attacker.example', 'repository' => 'private_marker_repository',
                'workflow' => 'private_marker_workflow', 'password' => 'private_marker_password',
            ],
            'runtime' => [
                'php_version' => '8.4.28', 'php_int_size_bytes' => 8, 'php_int_bits' => 64,
                'operating_system' => 'private_marker_operating_system path C:\\internal\\host',
                'laravel_locked_version' => 'v12.71.0', 'dsn' => 'private_marker_dsn',
            ],
            'preflight' => $failed
                ? ['status' => 'FAIL', 'checks' => ['private_marker_raw_env_check'], 'error_classification' => ['code' => 'database_connection_failed', 'gate' => 'database_connection', 'class' => 'database_connection_or_identity_error', 'sqlstate' => 'HY000', 'driver_code' => 1045, 'message' => 'private_marker_exception']]
                : ['status' => 'PASS', 'checks' => ['GitHub Actions GitHub-hosted Linux runner']],
            'policy_checks' => [],
            'policy_checks_status' => 'PASS',
            'database_identity' => [
                'version' => '8.4.13', 'server_port' => 3306, 'pdo_driver' => 'mysql', 'pdo_client_version' => 'mysqlnd 8.4.28',
                'pdo_server_version' => 'mysqlnd 8.4.28', 'default_storage_engine' => 'InnoDB', 'character_set_server' => 'utf8mb4',
                'collation_server' => 'utf8mb4_0900_ai_ci', 'transaction_isolation' => 'REPEATABLE-READ', 'session_time_zone' => '+00:00',
                'storage_engine_support' => ['InnoDB' => 'DEFAULT'], 'server_hostname' => 'private_marker_hostname',
                'schema_name' => 'private_marker_schema', 'authenticated_user' => 'private_marker_account',
                'connection_status' => 'private_marker_connection_status', 'sql_mode' => 'private_marker_sql_mode',
            ],
            'probes' => $probes,
            'overall_result' => $failed ? 'BLOCKED' : 'PASS',
            'overall_reason' => $failed ? 'Preflight failed; no database probes were run.' : 'Every preflight and standalone engine probe passed.',
            'completed_at_utc' => '2026-11-25T12:01:00Z',
            'private_marker_extra_field' => 'private_marker_extra',
        ];
    }

    private static function reportContainsNoPrivateMarkers(array $payloads): bool
    {
        $serialized = $payloads['json'] . $payloads['markdown'];
        return !str_contains($serialized, 'private_marker_')
            && !str_contains($serialized, 'attacker.example')
            && !str_contains($serialized, 'C:\\internal');
    }

    private static function reportProbeSummariesMatch(array $report, string $markdown, string $expectedStatus): bool
    {
        foreach ($report['probes'] ?? [] as $probe) {
            if (($probe['status'] ?? null) !== $expectedStatus || !str_contains($markdown, '| ' . $probe['name'] . ' | ' . $expectedStatus . ' |')) {
                return false;
            }
        }
        return count($report['probes'] ?? []) === 8;
    }

    /** @return array{name: string, status: string, expected: string, actual: string} */
    private static function safeErrorSelfCheck(): array
    {
        $pdoError = new PDOException('sensitive message must not be reported');
        $pdoError->errorInfo = ['HY000', 1045, 'sensitive driver message'];
        $classification = self::safeError($pdoError, 'database_connection');
        $safe = $classification === [
            'code' => 'database_connection_failed',
            'gate' => 'database_connection',
            'class' => 'database_connection_or_identity_error',
            'sqlstate' => 'HY000',
            'driver_code' => 1045,
        ];

        return [
            'name' => 'PDO diagnostic preserves only safe code and database codes',
            'status' => $safe ? 'PASS' : 'FAIL',
            'expected' => 'stable code, gate, class, SQLSTATE, driver code only',
            'actual' => $safe ? 'sanitized fields only' : 'unexpected diagnostic fields',
        ];
    }
}
