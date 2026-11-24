<?php

declare(strict_types=1);

namespace P07;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class ProbeSuite
{
    private string $prefix;
    private string $currentProbe = '';

    /** @var list<string> */
    private array $tables = [];

    /** @var list<array<string, mixed>> */
    private array $results = [];

    /** @param callable(): PDO $connectionFactory */
    public function __construct(private readonly array $config, private readonly mixed $connectionFactory)
    {
        $this->prefix = 'p07_' . substr(hash('sha256', (string) $config['schema']), 0, 12);
    }

    /** @return list<array<string, mixed>> */
    public function run(): array
    {
        $this->probe('check_constraint_enforcement', fn () => $this->checkConstraint());
        $this->probe('foreign_key_and_rollback', fn () => $this->foreignKeys());
        $this->probe('collation_and_unique_identity', fn () => $this->collations());
        $this->probe('datetime6_precision_and_timezone', fn () => $this->dateTime6());
        $this->probe('signed_bigint_boundaries', fn () => $this->bigInt());
        $this->probe('row_lock_timeout_and_release', fn () => $this->rowLocks());
        $this->probe('transaction_snapshot_visibility', fn () => $this->snapshot());
        $this->probe('optimistic_stale_write_conflict_and_atomic_rollback', fn () => $this->concurrency());

        return $this->results;
    }

    private function probe(string $name, callable $callback): void
    {
        $this->currentProbe = $name;
        $this->tables = [];
        $started = gmdate('Y-m-d\TH:i:s\Z');
        try {
            $actual = $callback();
            $this->results[] = [
                'name' => $name,
                'status' => 'PASS',
                'expected' => 'Probe observations match the engine behavior contract documented for this test.',
                'actual' => $actual,
                'error_classification' => null,
                'started_at_utc' => $started,
                'completed_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        } catch (Throwable $error) {
            $this->results[] = [
                'name' => $name,
                'status' => 'FAIL',
                'expected' => 'Probe completes with bounded, recorded database-engine observations.',
                'actual' => 'Probe failed; see sanitized error classification.',
                'error_classification' => self::classify($error),
                'started_at_utc' => $started,
                'completed_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        } finally {
            $cleanupFailure = $this->cleanup();
            if ($cleanupFailure !== null && $this->results !== []) {
                $index = array_key_last($this->results);
                $this->results[$index]['status'] = 'FAIL';
                $this->results[$index]['actual'] = 'Probe cleanup failed; evidence is not a successful clean run.';
                $this->results[$index]['cleanup_error_classification'] = $cleanupFailure;
            }
            $this->currentProbe = '';
        }
    }

    private static function classify(Throwable $error): array
    {
        if ($error instanceof PDOException) {
            $info = is_array($error->errorInfo ?? null) ? $error->errorInfo : [];
            return [
                'class' => 'database_error',
                'sqlstate' => isset($info[0]) ? (string) $info[0] : (string) $error->getCode(),
                'driver_code' => isset($info[1]) ? (int) $info[1] : null,
            ];
        }

        return ['class' => 'probe_assertion_or_runtime_error'];
    }

    private function table(string $suffix): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,19}$/', $suffix)) {
            throw new RuntimeException('Unsafe internal probe table suffix.');
        }

        $table = $this->prefix . '_' . substr($this->currentProbe, 0, 5) . '_' . $suffix;
        if (strlen($table) > 64 || !preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new RuntimeException('Probe table identifier exceeded the safe identifier policy.');
        }

        $this->tables[] = $table;
        return '`' . $table . '`';
    }

    private function constraint(string $suffix): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,12}$/', $suffix)) {
            throw new RuntimeException('Unsafe internal constraint suffix.');
        }
        return '`' . $this->prefix . '_' . substr($this->currentProbe, 0, 5) . '_' . $suffix . '`';
    }

    private function cleanup(): ?array
    {
        if ($this->tables === []) {
            return null;
        }

        try {
            $pdo = ($this->connectionFactory)();
            foreach (array_reverse(array_unique($this->tables)) as $table) {
                if (str_starts_with($table, $this->prefix . '_') && preg_match('/^[a-z0-9_]+$/', $table)) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
                }
            }
        } catch (Throwable $error) {
            $this->tables = [];
            if ($error instanceof PDOException) {
                $info = is_array($error->errorInfo ?? null) ? $error->errorInfo : [];
                return ['class' => 'cleanup_database_error', 'sqlstate' => isset($info[0]) ? (string) $info[0] : (string) $error->getCode(), 'driver_code' => isset($info[1]) ? (int) $info[1] : null];
            }
            return ['class' => 'cleanup_runtime_error'];
        }
        $this->tables = [];
        return null;
    }

    private function expectDriverError(callable $operation, array $allowedCodes): array
    {
        try {
            $operation();
        } catch (PDOException $error) {
            $info = is_array($error->errorInfo ?? null) ? $error->errorInfo : [];
            $code = isset($info[1]) ? (int) $info[1] : 0;
            if (!in_array($code, $allowedCodes, true)) {
                throw new RuntimeException('Database rejected the operation for an unexpected driver reason.');
            }
            return ['rejected' => true, 'sqlstate' => isset($info[0]) ? (string) $info[0] : null, 'driver_code' => $code];
        }

        throw new RuntimeException('An operation expected to be rejected was accepted.');
    }

    private function checkConstraint(): array
    {
        $pdo = ($this->connectionFactory)();
        $table = $this->table('check');
        $pdo->exec('CREATE TABLE ' . $table . ' (value INT NOT NULL, CONSTRAINT ' . $this->constraint('check_value') . ' CHECK (value > 0)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO {$table} (value) VALUES (1)");
        $rejection = $this->expectDriverError(fn () => $pdo->exec("INSERT INTO {$table} (value) VALUES (0)"), [3819]);

        return ['valid_row_accepted' => true, 'invalid_check_rejected' => $rejection];
    }

    private function foreignKeys(): array
    {
        $pdo = ($this->connectionFactory)();
        $parent = $this->table('parent');
        $child = $this->table('child');
        $pdo->exec("CREATE TABLE {$parent} (id INT PRIMARY KEY) ENGINE=InnoDB");
        $pdo->exec('CREATE TABLE ' . $child . ' (id INT PRIMARY KEY, parent_id INT NOT NULL, CONSTRAINT ' . $this->constraint('parent_fk') . ' FOREIGN KEY (parent_id) REFERENCES ' . $parent . ' (id) ON UPDATE RESTRICT ON DELETE RESTRICT) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO {$parent} (id) VALUES (1)");
        $pdo->exec("INSERT INTO {$child} (id, parent_id) VALUES (1, 1)");
        $badReference = $this->expectDriverError(fn () => $pdo->exec("INSERT INTO {$child} (id, parent_id) VALUES (2, 99)"), [1452]);
        $updateRestricted = $this->expectDriverError(fn () => $pdo->exec("UPDATE {$parent} SET id = 2 WHERE id = 1"), [1451]);
        $deleteRestricted = $this->expectDriverError(fn () => $pdo->exec("DELETE FROM {$parent} WHERE id = 1"), [1451]);

        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO {$parent} (id) VALUES (3)");
        $this->expectDriverError(fn () => $pdo->exec("INSERT INTO {$child} (id, parent_id) VALUES (3, 99)"), [1452]);
        $pdo->rollBack();
        $parentThree = (int) $pdo->query("SELECT COUNT(*) FROM {$parent} WHERE id = 3")->fetchColumn();
        if ($parentThree !== 0) {
            throw new RuntimeException('Foreign-key failure transaction did not roll back.');
        }

        return [
            'valid_parent_child_accepted' => true,
            'invalid_reference_rejected' => $badReference,
            'on_update_restrict' => $updateRestricted,
            'on_delete_restrict' => $deleteRestricted,
            'rollback_after_constraint_failure_removed_parent' => $parentThree === 0,
        ];
    }

    private function collations(): array
    {
        $pdo = ($this->connectionFactory)();
        $collations = ['utf8mb4_0900_as_cs', 'utf8mb4_0900_as_ci', 'utf8mb4_0900_bin'];
        $available = $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME IN ('utf8mb4_0900_as_cs','utf8mb4_0900_as_ci','utf8mb4_0900_bin')")->fetchAll(PDO::FETCH_COLUMN);
        if (count($available) !== count($collations)) {
            throw new RuntimeException('One or more explicitly selected MySQL test collations are unavailable.');
        }

        $observations = [];
        foreach ($collations as $collation) {
            $case = (bool) $pdo->query("SELECT (_utf8mb4'A' COLLATE {$collation}) = (_utf8mb4'a' COLLATE {$collation})")->fetchColumn();
            $trailing = (bool) $pdo->query("SELECT (_utf8mb4'x' COLLATE {$collation}) = (_utf8mb4'x ' COLLATE {$collation})")->fetchColumn();
            $pad = $pdo->prepare('SELECT PAD_ATTRIBUTE FROM information_schema.COLLATIONS WHERE COLLATION_NAME = ?');
            $pad->execute([$collation]);
            $observations[$collation] = ['case_equal' => $case, 'trailing_space_equal' => $trailing, 'pad_attribute' => $pad->fetchColumn()];
        }

        if ($observations['utf8mb4_0900_as_cs']['case_equal'] || !$observations['utf8mb4_0900_as_ci']['case_equal']) {
            throw new RuntimeException('Case comparison observations did not match the explicitly named collation behavior.');
        }

        $csTable = $this->table('uniqcs');
        $ciTable = $this->table('uniqci');
        $pdo->exec("CREATE TABLE {$csTable} (value VARCHAR(20) COLLATE utf8mb4_0900_as_cs UNIQUE) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE {$ciTable} (value VARCHAR(20) COLLATE utf8mb4_0900_as_ci UNIQUE) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO {$csTable} VALUES ('A'), ('a')");
        $pdo->exec("INSERT INTO {$ciTable} VALUES ('A')");
        $uniqueDuplicate = $this->expectDriverError(fn () => $pdo->exec("INSERT INTO {$ciTable} VALUES ('a')"), [1062]);
        $observations['unique_index'] = ['as_cs_case_variants_both_accepted' => true, 'as_ci_case_variant_rejected' => $uniqueDuplicate];

        return ['collations_tested' => $collations, 'observations' => $observations, 'selection_note' => 'Evidence only; no Plugin Runtime identity collation is approved by these probes.'];
    }

    private function dateTime6(): array
    {
        $pdo = ($this->connectionFactory)();
        $table = $this->table('dt6');
        $oldZone = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec("CREATE TABLE {$table} (id INT PRIMARY KEY, value DATETIME(6) NOT NULL) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO {$table} (id, value) VALUES (1, '2026-11-24 12:00:00.123456'), (2, '2026-11-24 12:00:00.123457')");
        $rows = $pdo->query("SELECT DATE_FORMAT(value, '%Y-%m-%d %H:%i:%s.%f') AS value FROM {$table} ORDER BY value")->fetchAll(PDO::FETCH_COLUMN);
        $sessionZone = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
        if ($rows !== ['2026-11-24 12:00:00.123456', '2026-11-24 12:00:00.123457'] || $sessionZone !== '+00:00') {
            throw new RuntimeException('DATETIME(6) precision, ordering, or session timezone observation failed.');
        }

        return ['original_session_time_zone' => $oldZone, 'test_session_time_zone' => $sessionZone, 'retrieved_values_in_order' => $rows];
    }

    private function bigInt(): array
    {
        $pdo = ($this->connectionFactory)();
        $table = $this->table('bigint');
        $pdo->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
        $pdo->exec("CREATE TABLE {$table} (value BIGINT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
        $insert = $pdo->prepare("INSERT INTO {$table} (value) VALUES (?)");
        $insert->execute(['-9223372036854775808']);
        $insert->execute(['9223372036854775807']);
        $values = $pdo->query("SELECT CAST(value AS CHAR) AS value FROM {$table} ORDER BY value")->fetchAll(PDO::FETCH_COLUMN);
        $overflow = $this->expectDriverError(fn () => $insert->execute(['9223372036854775808']), [1264, 1265]);
        if ($values !== ['-9223372036854775808', '9223372036854775807']) {
            throw new RuntimeException('Signed BIGINT boundary values were not stored losslessly.');
        }

        return ['minimum' => $values[0], 'maximum' => $values[1], 'overflow_rejected' => $overflow, 'php_int_size_bytes' => PHP_INT_SIZE, 'php_int_bits' => PHP_INT_SIZE * 8];
    }

    private function rowLocks(): array
    {
        $a = ($this->connectionFactory)();
        $b = ($this->connectionFactory)();
        $table = $this->table('lock');
        $a->exec("CREATE TABLE {$table} (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB");
        $a->exec("INSERT INTO {$table} VALUES (1, 0)");
        $a->beginTransaction();
        $a->query("SELECT value FROM {$table} WHERE id = 1 FOR UPDATE")->fetchColumn();
        $b->exec('SET SESSION innodb_lock_wait_timeout = 2');
        $b->beginTransaction();
        $timeout = $this->expectDriverError(fn () => $b->exec("UPDATE {$table} SET value = value + 1 WHERE id = 1"), [1205]);
        $b->rollBack();
        $a->commit();
        $b->beginTransaction();
        $b->exec("UPDATE {$table} SET value = value + 1 WHERE id = 1");
        $b->commit();
        $value = (int) $a->query("SELECT value FROM {$table} WHERE id = 1")->fetchColumn();
        if ($value !== 1) {
            throw new RuntimeException('Second lock holder did not proceed after first transaction released its lock.');
        }

        return ['connection_a_locked_row' => true, 'connection_b_bounded_wait' => $timeout, 'connection_b_proceeded_after_release' => true, 'final_value' => $value, 'lock_wait_timeout_seconds' => 2];
    }

    private function snapshot(): array
    {
        $a = ($this->connectionFactory)();
        $b = ($this->connectionFactory)();
        $table = $this->table('snap');
        $a->exec("CREATE TABLE {$table} (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB");
        $a->exec("INSERT INTO {$table} VALUES (1, 0)");
        $a->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $isolation = (string) $a->query('SELECT @@transaction_isolation')->fetchColumn();
        $a->beginTransaction();
        $first = (int) $a->query("SELECT value FROM {$table} WHERE id = 1")->fetchColumn();
        $b->beginTransaction();
        $b->exec("UPDATE {$table} SET value = 1 WHERE id = 1");
        $b->commit();
        $withinSnapshot = (int) $a->query("SELECT value FROM {$table} WHERE id = 1")->fetchColumn();
        $a->commit();
        $a->beginTransaction();
        $newTransaction = (int) $a->query("SELECT value FROM {$table} WHERE id = 1")->fetchColumn();
        $a->commit();
        if ($first !== 0 || $withinSnapshot !== 0 || $newTransaction !== 1) {
            throw new RuntimeException('Snapshot visibility observations did not match REPEATABLE READ expectations.');
        }

        return ['isolation_level' => $isolation, 'initial_committed_value' => $first, 'value_after_other_commit_within_snapshot' => $withinSnapshot, 'value_in_new_transaction' => $newTransaction, 'barrier' => 'Connection A read snapshot before connection B committed its update.'];
    }

    private function concurrency(): array
    {
        $a = ($this->connectionFactory)();
        $b = ($this->connectionFactory)();
        $table = $this->table('atomic');
        $a->exec("CREATE TABLE {$table} (id INT PRIMARY KEY, value INT NOT NULL, version INT NOT NULL) ENGINE=InnoDB");
        $a->exec("INSERT INTO {$table} VALUES (1, 0, 0)");
        $a->beginTransaction();
        $aSeen = $a->query("SELECT value, version FROM {$table} WHERE id = 1")->fetch();
        $b->beginTransaction();
        $bSeen = $b->query("SELECT value, version FROM {$table} WHERE id = 1")->fetch();
        $aWrite = $a->prepare("UPDATE {$table} SET value = value + 1, version = version + 1 WHERE id = 1 AND version = ?");
        $aWrite->execute([(int) $aSeen['version']]);
        $aAffected = $aWrite->rowCount();
        $a->commit();
        $bWrite = $b->prepare("UPDATE {$table} SET value = value + 1, version = version + 1 WHERE id = 1 AND version = ?");
        $bWrite->execute([(int) $bSeen['version']]);
        $bAffected = $bWrite->rowCount();
        $b->rollBack();

        $a->beginTransaction();
        $a->exec("UPDATE {$table} SET value = 99 WHERE id = 1");
        $a->exec("INSERT INTO {$table} VALUES (2, 99, 0)");
        $a->rollBack();
        $final = $a->query("SELECT id, value, version FROM {$table} WHERE id = 1")->fetch();
        $rolledBackRow = (int) $a->query("SELECT COUNT(*) FROM {$table} WHERE id = 2")->fetchColumn();
        if ($aAffected !== 1 || $bAffected !== 0 || (int) $final['value'] !== 1 || (int) $final['version'] !== 1 || $rolledBackRow !== 0) {
            throw new RuntimeException('Controlled stale-write or atomic rollback observations failed.');
        }

        return ['execution_model' => 'sequential optimistic stale-write conflict; not simultaneous worker execution', 'connection_a_and_b_read_same_initial_version' => true, 'connection_a_committed_update_rows' => $aAffected, 'connection_b_stale_conditional_update_rows' => $bAffected, 'stale_update_affected_zero_rows' => $bAffected === 0, 'rollback_preserved_committed_state' => true, 'rollback_removed_inserted_row' => $rolledBackRow === 0, 'simultaneous_worker_concurrency_tested' => false];
    }
}
