# Phase 17 P-07 Isolated CI Evidence Preparation

**Status:** Preparation only. The remote workflow has not been run. P-07 remains **PENDING** until the owner reviews and accepts collected evidence.

## Scope and files

This preparation adds:

- `.github/workflows/p07-mysql-qualification.yml` — manually dispatched GitHub Actions workflow, with an ephemeral Oracle MySQL service and the approved PHP matrix.
- `scripts/p07/Identity.php` — CI/job/schema/user/server/PDO preflight, schema-scoped grant check, and pure fail-closed policy checks.
- `scripts/p07/ProbeSuite.php` — standalone, uniquely named temporary table probes for engine behavior; it creates no Plugin Runtime tables.
- `scripts/p07/run.php` — report orchestration, preflight gate, probe execution, and JSON/Markdown evidence output.
- `scripts/p07/self-test.php` — database-free checks that invalid CI/database identities are rejected before a connection is attempted.

The harness does not bootstrap Laravel or execute Composer scripts. It reads the checked-in `composer.lock` to report the locked Laravel version.

## Qualification target

| Component | Configured target |
|---|---|
| Database | Oracle MySQL Community Server 8.4.13 LTS (`mysql:8.4.13`) |
| Framework | Laravel 12.x, version reported from `composer.lock` |
| PHP | 8.3.37, 8.4.28, and 8.5.12 |
| Driver | PDO_MYSQL; client/mysqlnd details are captured from PDO at runtime |

MySQL 8.0 is not a qualification target. PHP 8.2 is excluded from the forward matrix. Local MariaDB 10.11.16 is development evidence only and is not Oracle MySQL qualification evidence. A passing workflow run is evidence for review, not a production-support claim.

## Isolation and preflight

The workflow is manual-dispatch only and grants `contents: read`. Each PHP matrix job gets an ephemeral MySQL service and a distinct schema/user derived from the GitHub run ID, attempt, and short PHP-matrix suffix; the service is destroyed with the job. The user and password are scoped to that unique schema and the password is never logged. The service's dynamically mapped host endpoint port is used by PDO as the connection port and must match the Actions service mapping. This value is distinct from MySQL's internal `@@port`; the connected server must report the expected internal port `3306`. Both values are checked independently. The harness checks GitHub Actions, Linux, Ubuntu image metadata, and the actual `RUNNER_ENVIRONMENT` supplied by GitHub Actions, requiring `github-hosted`; missing, self-hosted, or unexpected values are rejected without a local fallback. It rejects malformed or mismatched job/schema/user identity, an unapproved PHP/server target, a wrong PDO driver, a non-Oracle-MySQL product/version, missing InnoDB, global or unrelated-schema grants, and `GRANT OPTION`. The harness logs no password or raw exception message.

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

Each run writes uniquely named JSON and Markdown reports under `artifacts/p07/`, including environment identity, UTC timestamps, CI run identifiers, expected/observed values, per-probe `PASS`/`FAIL`/`NOT RUN`, sanitized error classifications, and an overall `PASS`/`FAIL`/`BLOCKED` result. The workflow uploads the evidence even when a probe fails. Report status never changes P-07's owner-decision status.

## Running later

After reviewing this preparation, an authorized operator may select **P-07 isolated MySQL evidence** in the repository's GitHub Actions workflow-dispatch UI. It runs only on the GitHub-hosted CI runner, never uses local/shared/production databases, and has not been run as part of this preparation task.

## Validation and remaining evidence

Correction validation: PHP syntax checks passed for all four PHP files, and 29 database-free policy checks passed with zero failures; the self-test reported that no database connection was attempted. No YAML parser is installed or resolvable in the local environment, so the workflow has not received parser-based syntax validation. The workflow was inspected but not run. Remote MySQL engine observations, exact service image availability, matrix execution, artifact upload, and every database probe remain unverified until the separately authorized workflow is run. No MySQL engine behavior has been qualified yet, including CHECK enforcement, collations, locking, snapshot behavior, or concurrency.

**P-07 remains PENDING until the owner reviews and accepts the resulting evidence.**

**This work does not establish production support, authorize Plugin Runtime implementation, authorize migration creation/execution, or authorize activation lifecycle implementation.**
