<?php

declare(strict_types=1);

namespace P07;

use PDO;
use RuntimeException;

final class Identity
{
    /** @return array<string, string|int> */
    public static function config(array $env): array
    {
        $required = [
            'GITHUB_ACTIONS', 'CI', 'RUNNER_OS', 'RUNNER_ENVIRONMENT', 'RUNNER_NAME', 'ImageOS', 'ImageVersion', 'GITHUB_RUN_ID',
            'GITHUB_RUN_ATTEMPT', 'GITHUB_WORKFLOW', 'P07_DB_HOST', 'P07_DB_PORT',
            'P07_EXPECTED_HOST', 'P07_EXPECTED_PORT', 'P07_EXPECTED_SERVER_PORT', 'P07_DB_SCHEMA',
            'P07_EXPECTED_SCHEMA', 'P07_JOB_SUFFIX', 'P07_DB_USER', 'P07_EXPECTED_USER', 'P07_DB_PASSWORD',
            'P07_EXPECTED_SERVER_VERSION', 'P07_EXPECTED_PHP_VERSION', 'P07_OUTPUT_DIR',
        ];

        foreach ($required as $key) {
            if (!isset($env[$key]) || trim((string) $env[$key]) === '') {
                throw new RuntimeException("Required CI identity value missing: {$key}");
            }
        }

        if ($env['GITHUB_ACTIONS'] !== 'true' || $env['CI'] !== 'true' || $env['RUNNER_OS'] !== 'Linux' || $env['RUNNER_ENVIRONMENT'] !== 'github-hosted' || !str_starts_with(strtolower((string) $env['ImageOS']), 'ubuntu')) {
            throw new RuntimeException('Execution is not on the authorized GitHub-hosted Linux CI runner.');
        }

        if (!preg_match('/^\d+$/', (string) $env['GITHUB_RUN_ID']) || !preg_match('/^\d+$/', (string) $env['GITHUB_RUN_ATTEMPT'])) {
            throw new RuntimeException('CI run identity is malformed.');
        }

        $phpSuffixes = ['8.3.37' => 'p83', '8.4.28' => 'p84', '8.5.12' => 'p85'];
        $expectedPhp = (string) $env['P07_EXPECTED_PHP_VERSION'];
        if (!isset($phpSuffixes[$expectedPhp]) || $env['P07_JOB_SUFFIX'] !== $phpSuffixes[$expectedPhp]) {
            throw new RuntimeException('Job suffix does not match the approved PHP matrix entry.');
        }

        $expectedSchema = 'p07_' . $env['GITHUB_RUN_ID'] . '_' . $env['GITHUB_RUN_ATTEMPT'] . '_' . $env['P07_JOB_SUFFIX'];
        $expectedUser = $expectedSchema;
        if (!preg_match('/^p07_\d+_\d+_p(?:83|84|85)$/', (string) $env['P07_DB_SCHEMA']) || $env['P07_DB_SCHEMA'] !== $expectedSchema || $env['P07_EXPECTED_SCHEMA'] !== $expectedSchema) {
            throw new RuntimeException('Disposable schema does not match this CI job identity.');
        }

        if ($env['P07_DB_USER'] !== $expectedUser || $env['P07_EXPECTED_USER'] !== $expectedUser) {
            throw new RuntimeException('Database user does not match this CI job identity.');
        }

        if ($env['P07_DB_HOST'] !== '127.0.0.1' || $env['P07_EXPECTED_HOST'] !== '127.0.0.1') {
            throw new RuntimeException('Database endpoint is not the expected CI service port mapping.');
        }

        $port = filter_var($env['P07_DB_PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $expectedPort = filter_var($env['P07_EXPECTED_PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $expectedServerPort = filter_var($env['P07_EXPECTED_SERVER_PORT'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false || $expectedPort === false || (int) $port !== (int) $expectedPort || $expectedServerPort !== 3306 || strlen($expectedSchema) > 64 || strlen($expectedUser) > 32) {
            throw new RuntimeException('Database port does not match the CI service port mapping.');
        }

        if ($env['P07_EXPECTED_SERVER_VERSION'] !== '8.4.13') {
            throw new RuntimeException('Configured server target is not the approved MySQL 8.4.13 target.');
        }

        if (!in_array($expectedPhp, ['8.3.37', '8.4.28', '8.5.12'], true)) {
            throw new RuntimeException('Configured PHP version is outside the approved P-07 matrix.');
        }

        if ($env['P07_OUTPUT_DIR'] !== 'artifacts/p07') {
            throw new RuntimeException('Evidence output directory is not the fixed artifacts/p07 path.');
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
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('PDO_MYSQL is unavailable.');
        }

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
            throw new RuntimeException('Database identity query returned no result.');
        }

        $grants = array_map('strval', $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
        $engines = $pdo->query("SHOW ENGINES")->fetchAll(PDO::FETCH_ASSOC);
        $engineSupport = [];
        foreach ($engines as $engine) {
            if (isset($engine['Engine'], $engine['Support'])) {
                $engineSupport[(string) $engine['Engine']] = (string) $engine['Support'];
            }
        }
        if (!in_array(strtoupper($engineSupport['InnoDB'] ?? ''), ['YES', 'DEFAULT'], true)) {
            throw new RuntimeException('InnoDB storage engine is unavailable.');
        }
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
            throw new RuntimeException('Connected PDO driver is not PDO_MYSQL.');
        }

        foreach (['version', 'version_comment', 'server_port', 'schema_name', 'authenticated_user'] as $field) {
            if (!array_key_exists($field, $row) || !is_scalar($row[$field]) || trim((string) $row[$field]) === '') {
                throw new RuntimeException('Observed database identity is incomplete or malformed.');
            }
        }

        $version = (string) $row['version'];
        $comment = (string) $row['version_comment'];
        if ($version !== $config['expected_version'] || !str_contains($comment, 'MySQL Community Server') || preg_match('/mariadb|percona/i', $comment)) {
            throw new RuntimeException('Connected database is not Oracle MySQL Community Server 8.4.13.');
        }

        if ((string) $row['schema_name'] !== $config['schema'] || (int) $row['server_port'] !== $config['expected_server_port']) {
            throw new RuntimeException('Connected schema or internal MySQL server port does not match the CI job identity.');
        }

        if (!str_starts_with((string) $row['authenticated_user'], $config['user'] . '@')) {
            throw new RuntimeException('Authenticated database principal does not match the job-scoped user.');
        }

        self::assertSchemaScopedGrants($grants, $config['schema']);
    }

    /** @param list<string> $grants */
    public static function assertSchemaScopedGrants(array $grants, string $schema): void
    {
        if ($grants === []) {
            throw new RuntimeException('No grants were returned for the CI database user.');
        }

        $schemaGrant = false;
        foreach ($grants as $grant) {
            $normalized = strtoupper($grant);
            if (preg_match('/^GRANT USAGE ON \*\.\* TO /', $normalized)) {
                continue;
            }

            if (preg_match('/^GRANT ALL PRIVILEGES ON `' . preg_quote(strtoupper($schema), '/') . '`\.\* TO /', $normalized) && !str_contains($normalized, 'GRANT OPTION')) {
                $schemaGrant = true;
                continue;
            }

            throw new RuntimeException('Database principal has a grant outside its unique job schema.');
        }

        if (!$schemaGrant) {
            throw new RuntimeException('Database principal lacks the expected job-schema grant.');
        }
    }

    /** @return list<array<string, mixed>> */
    public static function selfChecks(): array
    {
        $base = [
            'GITHUB_ACTIONS' => 'true', 'CI' => 'true', 'RUNNER_OS' => 'Linux', 'RUNNER_ENVIRONMENT' => 'github-hosted', 'RUNNER_NAME' => 'GitHub Actions 1', 'ImageOS' => 'ubuntu24', 'ImageVersion' => '20261123.1.0',
            'GITHUB_RUN_ID' => '123456', 'GITHUB_RUN_ATTEMPT' => '2', 'GITHUB_WORKFLOW' => 'P-07 evidence',
            'P07_DB_HOST' => '127.0.0.1', 'P07_DB_PORT' => '49152', 'P07_EXPECTED_HOST' => '127.0.0.1',
            'P07_EXPECTED_PORT' => '49152', 'P07_EXPECTED_SERVER_PORT' => '3306', 'P07_DB_SCHEMA' => 'p07_123456_2_p84', 'P07_EXPECTED_SCHEMA' => 'p07_123456_2_p84',
            'P07_JOB_SUFFIX' => 'p84', 'P07_DB_USER' => 'p07_123456_2_p84', 'P07_EXPECTED_USER' => 'p07_123456_2_p84', 'P07_DB_PASSWORD' => 'test-only',
            'P07_EXPECTED_SERVER_VERSION' => '8.4.13', 'P07_EXPECTED_PHP_VERSION' => '8.4.28', 'P07_OUTPUT_DIR' => 'artifacts/p07',
        ];

        $checks = [];
        $cases = [
            'valid GitHub-hosted job identity accepted' => [$base, true],
            'self-hosted runner rejected' => [array_replace($base, ['RUNNER_ENVIRONMENT' => 'self-hosted']), false],
            'missing runner environment rejected' => [array_diff_key($base, ['RUNNER_ENVIRONMENT' => true]), false],
            'unexpected runner environment rejected' => [array_replace($base, ['RUNNER_ENVIRONMENT' => 'custom']), false],
            'missing identity value rejected' => [array_diff_key($base, ['P07_EXPECTED_SCHEMA' => true]), false],
            'wrong host rejected' => [array_replace($base, ['P07_DB_HOST' => 'localhost']), false],
            'wrong schema rejected' => [array_replace($base, ['P07_DB_SCHEMA' => 'shared_db']), false],
            'incorrect job suffix rejected' => [array_replace($base, ['P07_JOB_SUFFIX' => 'p83']), false],
            'correct mapped host port plus internal port accepted' => [$base, true],
            'incorrect mapped host port rejected' => [array_replace($base, ['P07_DB_PORT' => '3306']), false],
            'incorrect expected internal server port rejected' => [array_replace($base, ['P07_EXPECTED_SERVER_PORT' => '49152']), false],
            'wrong run user rejected' => [array_replace($base, ['P07_DB_USER' => 'root']), false],
            'wrong server target rejected' => [array_replace($base, ['P07_EXPECTED_SERVER_VERSION' => '8.0.36']), false],
            'unapproved PHP target rejected' => [array_replace($base, ['P07_EXPECTED_PHP_VERSION' => '8.2.30']), false],
            'invalid output directory rejected' => [array_replace($base, ['P07_OUTPUT_DIR' => '../']), false],
            'non-CI execution rejected' => [array_replace($base, ['GITHUB_ACTIONS' => 'false']), false],
        ];

        foreach ($cases as $name => [$env, $shouldPass]) {
            try {
                self::config($env);
                $passed = true;
            } catch (RuntimeException) {
                $passed = false;
            }

            $checks[] = [
                'name' => $name,
                'status' => $passed === $shouldPass ? 'PASS' : 'FAIL',
                'expected' => $shouldPass ? 'accept configuration' : 'reject configuration before connection',
                'actual' => $passed ? 'accepted' : 'rejected before connection',
            ];
        }

        $grantCases = [
            'schema-only grants accepted' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `P07_123456_2_P84`.* TO `p07_123456_2_p84`@`%`'], true],
            'global grants rejected' => [['GRANT ALL PRIVILEGES ON *.* TO `p07_123456_2_p84`@`%`'], false],
            'grants outside job schema rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `other_schema`.* TO `p07_123456_2_p84`@`%`'], false],
            'grant option rejected' => [['GRANT USAGE ON *.* TO `p07_123456_2_p84`@`%`', 'GRANT ALL PRIVILEGES ON `P07_123456_2_P84`.* TO `p07_123456_2_p84`@`%` WITH GRANT OPTION'], false],
        ];
        foreach ($grantCases as $name => [$grants, $shouldPass]) {
            try {
                self::assertSchemaScopedGrants($grants, 'p07_123456_2_p84');
                $passed = true;
            } catch (RuntimeException) {
                $passed = false;
            }
            $checks[] = [
                'name' => $name,
                'status' => $passed === $shouldPass ? 'PASS' : 'FAIL',
                'expected' => $shouldPass ? 'accept job-schema-only privileges' : 'reject global privileges',
                'actual' => $passed ? 'accepted' : 'rejected',
            ];
        }

        $config = self::config($base);
        $observed = [
            'version' => '8.4.13', 'version_comment' => 'MySQL Community Server - GPL',
            'server_port' => 3306, 'schema_name' => 'p07_123456_2_p84', 'authenticated_user' => 'p07_123456_2_p84@%',
        ];
        $observedCases = [
            'Oracle MySQL job identity accepted' => [$observed, 'mysql', true],
            'wrong database product rejected' => [array_replace($observed, ['version_comment' => 'MariaDB Server']), 'mysql', false],
            'wrong actual server version rejected' => [array_replace($observed, ['version' => '8.4.12']), 'mysql', false],
            'wrong actual schema rejected' => [array_replace($observed, ['schema_name' => 'shared']), 'mysql', false],
            'wrong internal server port rejected before probes' => [array_replace($observed, ['server_port' => 49152]), 'mysql', false],
            'wrong authenticated user rejected' => [array_replace($observed, ['authenticated_user' => 'root@localhost']), 'mysql', false],
            'wrong actual PDO driver rejected' => [$observed, 'sqlite', false],
            'missing observed identity rejected' => [array_diff_key($observed, ['schema_name' => true]), 'mysql', false],
            'malformed observed identity rejected' => [array_replace($observed, ['server_port' => 'not-a-port']), 'mysql', false],
        ];
        foreach ($observedCases as $name => [$row, $driver, $shouldPass]) {
            try {
                self::assertObservedIdentity($row, $driver, $config, $grantCases['schema-only grants accepted'][0]);
                $passed = true;
            } catch (RuntimeException) {
                $passed = false;
            }
            $checks[] = [
                'name' => $name,
                'status' => $passed === $shouldPass ? 'PASS' : 'FAIL',
                'expected' => $shouldPass ? 'accept exact Oracle MySQL job identity' : 'reject identity before any probe write',
                'actual' => $passed ? 'accepted' : 'rejected',
            ];
        }

        return $checks;
    }
}
