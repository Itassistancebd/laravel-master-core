# Phase 17 P-07 Isolated CI Evidence Preparation

**Status:** Local diagnostic and exact PHP source-build corrections are prepared but have not been dispatched. Run #2 (`45454215333`) was blocked during preflight in all three matrix jobs; no database probes ran. P-07 remains **PENDING** until the owner reviews and accepts qualification evidence.

## Scope and files

This preparation adds:

- `.github/workflows/p07-mysql-qualification.yml` — manually dispatched GitHub Actions workflow, with an ephemeral Oracle MySQL service and the approved PHP matrix.
- `scripts/p07/Identity.php` — CI/job/schema/user/server/PDO preflight, schema-scoped grant check, and pure fail-closed policy checks.
- `scripts/p07/ProbeSuite.php` — standalone, uniquely named temporary table probes for engine behavior; it creates no Plugin Runtime tables.
- `scripts/p07/run.php` — report orchestration, preflight gate, probe execution, and JSON/Markdown evidence output.
- `scripts/p07/self-test.php` — database-free checks that invalid CI/database identities are rejected before a connection is attempted.
- `scripts/p07/build-php.sh` — verified official source archive build into an isolated runner temporary prefix, followed by exact-version and extension checks; it makes no database connection.

The harness does not bootstrap Laravel or execute Composer scripts. It reads the checked-in `composer.lock` to report the locked Laravel version. JSON and Markdown are produced from the same sanitized report object with explicit field allow-lists. Reports include only the GitHub host, fixed workflow identity, numeric run/attempt IDs, runner class, PHP/Laravel versions, selected MySQL engine/version settings, schema-scope verification, and probe results. They omit repository/account names, runner names, image build IDs, hostnames, database usernames/schema names, DSNs, passwords, raw exception messages, stack traces, and internal paths. PDO diagnostics retain only stable codes, gate/class, SQLSTATE, and numeric driver code.

## Qualification target

| Component | Configured target |
|---|---|
| Database | Oracle MySQL Community Server 8.4.13 LTS (`mysql:8.4.13`) |
| Framework | Laravel 12.x, version reported from `composer.lock` |
| PHP | 8.3.37, 8.4.28, and 8.5.12 |
| Driver | PDO_MYSQL; client/mysqlnd details are captured from PDO at runtime |

MySQL 8.0 is not a qualification target. PHP 8.2 is excluded from the forward matrix. Local MariaDB 10.11.16 is development evidence only and is not Oracle MySQL qualification evidence. A passing workflow run is evidence for review, not a production-support claim.

### Exact PHP source builds

The workflow builds PHP CLI from each official PHP source archive in an isolated prefix under the GitHub runner's temporary directory. It verifies the archive SHA-256 before extraction and compilation. The approved matrix targets and checks remain exact: 8.3.37 (`cdd2ed867cb104ececff7c0292142c807c68a6b6da1b114ead4d586fba628a4f`), 8.4.28 (`deffc88c60fcfb99f683a9f6689e9c91cf41b7cba50bd5f01a232a98c27f2cd4`), and 8.5.12 (`81a0f450349e84b08eb43faa85f8a4c835684f3cc1c50471775d1317a046c525`). The build enables the Laravel 12 CLI extensions plus PDO_MYSQL/mysqlnd, OpenSSL, cURL, mbstring, XML/DOM, fileinfo, and tokenizer; it checks the exact `PHP_VERSION`, required modules, the MySQL PDO driver, and mysqlnd before adding the isolated binary to `PATH`. It does not overwrite system PHP.

Run #2's 8.5.12 matrix job recorded actual `PHP_VERSION` 8.5.13 and was correctly blocked by the exact equality check. The old evidence does not establish why the PHP 8.3 and PHP 8.4 jobs failed. The current diagnostic codes and gate fields remain enabled so another CI run can identify those gates without guessing. Source compilation and the GitHub-hosted runner integration have not yet been executed; P-07 remains pending and no MySQL qualification is claimed.

## Isolation and preflight

The workflow is manual-dispatch only and grants `contents: read`. Each PHP matrix job gets an ephemeral MySQL service and a distinct schema/user derived from the GitHub run ID, attempt, and short PHP-matrix suffix; the service is destroyed with the job. The service's `MYSQL_USER` account is created for host `%` by the [official MySQL image entrypoint](https://github.com/docker-library/mysql/blob/master/8.4/docker-entrypoint.sh); preflight now requires the exact `user@%` identity in `CURRENT_USER()` and in every accepted grant line. The user and password are scoped to that unique schema and the password is never logged. The service's dynamically mapped host endpoint port is used by PDO as the connection port and must match the Actions service mapping. This value is distinct from MySQL's internal `@@port`; the connected server must report the expected internal port `3306`. Both values are checked independently. The harness checks GitHub Actions, Linux, Ubuntu image metadata, `RUNNER_ENVIRONMENT=github-hosted`, and the exact `https://github.com` server, `Itassistancebd/laravel-master-core` repository, and `P-07 isolated MySQL evidence` workflow identity; missing, self-hosted, unexpected, whitespace-padded, or deceptive values are rejected. It rejects malformed or mismatched job/schema/user/account-host identity, an unapproved PHP/server target, a wrong PDO driver, a non-Oracle-MySQL product/version, missing InnoDB, global or unrelated-schema grants, and `GRANT OPTION`. The harness logs no password or raw exception message.

Probe table names use a run-specific hash prefix and fixed internal probe suffixes; they are not supplied through environment variables. Cleanup drops only explicitly registered, internally generated probe tables within the connection's validated disposable schema. Cleanup errors are reported as probe failures and are not silently treated as success. Every connection uses a bounded connect timeout; row-lock contention uses a two-second InnoDB lock timeout. All runner/configuration and actual database identity checks complete before the first probe can create a table. A failed preflight blocks every database probe, marks all probes `NOT RUN`, and produces an overall `BLOCKED` result.

## Probe inventory

- CHECK constraint acceptance and rejection.
- Foreign-key valid/invalid references, `RESTRICT` update/delete behavior, and rollback after a constraint failure.
- Case-sensitive, case-insensitive, and binary collation observations, trailing-space behavior, and unique-index behavior. These probes collect evidence and do not select a Plugin Runtime identity collation.
- `DATETIME(6)` microsecond round-trip, ordering, and UTC session behavior.
- Signed `BIGINT` minimum/maximum and strict-mode overflow rejection; PHP integer width is recorded.
- Independent-connection row locking, bounded timeout, release, and subsequent progress.
- `REPEATABLE READ` snapshot visibility across an explicitly ordered independent-connection commit.
- `optimistic_stale_write_conflict_and_atomic_rollback`: two independent connections read the same initial version; connection A commits an update; connection B's stale conditional update affects zero rows; rollback preserves the committed state. This is a sequential optimistic stale-write conflict test, not simultaneous worker execution.
- Database-free policy self-checks for GitHub-hosted/self-hosted/missing/unexpected runner environment, mapped endpoint and internal server ports, host, schema, user, server/PHP target, PDO driver, observed database identity, and grant scope. They run before any connection is attempted and do not establish MySQL engine behavior.

The row-lock probe separately tests bounded lock contention. The stale-write probe does not test simultaneous worker execution; true simultaneous worker concurrency and complete concurrency qualification remain unverified.

Each run writes uniquely named JSON and Markdown reports under `artifacts/p07/`, including sanitized runtime identity, UTC timestamps, numeric CI run/attempt identifiers, expected/observed probe values, per-probe `PASS`/`FAIL`/`NOT RUN`, sanitized error classifications, and an overall `PASS`/`FAIL`/`BLOCKED` result. Artifact names and the report-written log line contain only the run ID, attempt, and PHP version; they do not include account identifiers, schema names, or credentials. The workflow uploads evidence with `if: always()` even when a probe fails. Report status never changes P-07's owner-decision status.

## Running later

After reviewing the blocked Run #2 evidence and the local diagnostic and source-build changes, an authorized operator may separately decide whether to authorize another **P-07 isolated MySQL evidence** workflow dispatch. The workflow runs only on GitHub-hosted CI and is configured for ephemeral job-scoped MySQL services; it does not use local/shared/production databases. These changes have not been dispatched or remotely validated.

## Validation and remaining evidence

Historical test counts are time-specific: the Part 1 technical review recorded 41 checks before the grant-validation corrections, and the Part 1.1/Part 2 reviews recorded the 48-check baseline. The current Part 2.1 suite runs **70 database-free checks, all passing**, including explicit distinct-casing, repository/workflow identity, MySQL account-host, and safe report serialization checks. Do not treat the historical 41- or 48-check counts as the current suite count. PHP syntax and Bash syntax checks pass for the current P-07 files. The workflow has not been dispatched. Source compilation on GitHub-hosted Linux, PDO_MYSQL/mysqlnd after compilation, remote MySQL identity, matrix execution, artifact upload, and every database probe remain unverified until separately authorized evidence collection. No MySQL engine behavior has been qualified, including CHECK enforcement, collations, locking, snapshot behavior, or concurrency.

**P-07 remains PENDING until the owner reviews and accepts the resulting evidence.**

**This work does not establish production support, authorize Plugin Runtime implementation, authorize migration creation/execution, or authorize activation lifecycle implementation.**
