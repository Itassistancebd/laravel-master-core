# Stage 17 P-07 Part 3.3 — Schema Binding Root Cause

**Run #4:** `45503483249`
**Run commit:** `a7e6b489a841792394e4b838b715a11a464a1470`
**Verdict:** **ROOT CAUSE FIXED — READY FOR OWNER REVIEW**

## Root cause

The service and runner schema expressions are aligned. The failure was caused by the grant validator comparing the escaped schema identifier returned as a MySQL `GRANT` statement directly with the unescaped expected schema.

The generated job schema contains underscores. The official MySQL 8.4 image creates the database using `MYSQL_DATABASE`, then escapes underscores in the schema-level grant (`\_`) so those underscores are treated literally rather than as grant-pattern wildcards. MySQL documents both the need to escape literal underscores in database-level `GRANT` statements and that `SHOW GRANTS` returns statements that reproduce assigned privileges. Run #4’s safe classification (`schema_all_privileges / other_schema / wildcard / wrong_schema`) is consistent with the validator capturing this escaped spelling and treating it as a different schema. [Official MySQL 8.4 image entrypoint](https://github.com/docker-library/mysql/blob/master/8.4/docker-entrypoint.sh#L1528-L1548), [MySQL 8.4 GRANT rules](https://dev.mysql.com/doc/refman/8.4/en/grant.html), [MySQL 8.4 SHOW GRANTS behavior](https://dev.mysql.com/doc/refman/8.4/en/show-grants.html).

The Run #4 artifact does not retain a literal grant statement, so the exact emitted string was not independently inspected. However, the service setup source, MySQL’s documented escaping rule, and the observed safe mismatch classification establish this parser incompatibility as the root cause. No service-to-runner expression divergence is present in the checked-in workflow. GitHub’s context-availability table explicitly permits `github` and `matrix` in service environment fields as well as step environment fields. [GitHub Actions contexts reference](https://docs.github.com/en/actions/reference/workflows-and-actions/contexts).

## Binding trace

The workflow is manual-dispatch only. It defines the matrix suffixes for the three approved PHP versions. In each job, `MYSQL_DATABASE`, `P07_DB_SCHEMA`, and `P07_EXPECTED_SCHEMA` are the same expression of `github.run_id`, `github.run_attempt`, and `matrix.job_suffix`. The runner additionally checks the suffix against the exact PHP target and derives the canonical schema from the run ID, attempt, and suffix. PHP self-tests validate those identity relationships.

MySQL image initialization creates the named database and the `MYSQL_USER` account at host `%`, then grants `ALL` on that database. To make the database-name underscores literal in grant-pattern syntax, the official image’s entrypoint escapes underscores in the grant identifier. `SHOW GRANTS` reports the resulting grant statement. The old validator captured the escaped identifier and compared it byte-for-byte (case-insensitively) to the expected identifier, so it classified the matching job schema as `other_schema`.

## Correction

Before: grant-schema text was compared directly with the expected job schema; MySQL’s escaped underscores did not compare equal.

After: the validator decodes only MySQL’s documented `\_` escape to `_`, then performs the same case-insensitive equality check against the canonical expected schema. Any remaining backslash or unsupported escape remains invalid. The grant’s scope is still required to be exactly the job schema.

The least-privilege policy is unchanged: the validator permits only the existing optional global `USAGE` baseline and one required schema-scoped `ALL PRIVILEGES` grant for the expected account and host, without grant option. Global privileges, other schemas, unexpected accounts/hosts, duplicates, malformed forms, and grant delegation continue to fail closed.

## Files changed

- `scripts/p07/Identity.php` — narrowly decodes MySQL’s escaped underscore in grant schema identifiers; adds DB-free acceptance, rejection, and classification assertions.
- `project-management/reports/Stage-17-P07-Part-3-3-Schema-Binding-Root-Cause.md` — this investigation and local validation report.

No workflow, runner, application, Plugin Runtime, migration, or other documentation file was changed.

## Validation

- DB-free P-07 policy checks: **75 passed, 0 failed**; no database connection was attempted.
- PHP syntax: all four P-07 PHP files passed.
- Bash syntax: `scripts/p07/build-php.sh` passed with Git for Windows Bash.
- Workflow YAML parse and structural validation: passed using Symfony YAML. Verified manual-only `workflow_dispatch`, PHP matrix `8.3.37` / `8.4.28` / `8.5.12`, MySQL image `8.4.13`, and identical service/runner schema expressions.
- `git diff --check`: passed. Git printed line-ending conversion warnings for pre-existing unrelated worktree files only.
- No Docker, database, local/standalone MySQL, workflow dispatch, staging, commit, or push was used.

## Remaining verification

The correction is verified locally against MySQL’s documented escape grammar and Run #4’s safe classification. A later owner-authorized hosted run is still needed to confirm that MySQL 8.4.13’s actual `SHOW GRANTS` output now classifies as `job_schema` in all three PHP jobs and that the eight probes execute. No workflow was dispatched for this task, and P-07 is not yet qualified.
