# Development Standards

These standards guide changes across the Laravel Master Core repository and its future modules.

## Coding practices

- Follow the established conventions of the language and framework already used by the target module.
- Keep responsibilities focused and prefer small, cohesive classes and components.
- Reuse existing package services, components, and conventions where they fit. Add abstractions only for a demonstrated need.
- Keep API, persistence, and presentation responsibilities separate.
- Validate all externally supplied data on the server. Enforce authorization on the server for every protected operation.
- Use explicit types and predictable response shapes where supported by the language and framework.
- Keep comments meaningful: explain intent, constraints, edge cases, or non-obvious choices in PHP, CSS, HTML, JavaScript, and TypeScript. Update or remove comments when behavior changes.

## Naming and structure

- Use names that describe the domain responsibility and remain consistent across related files.
- Follow Laravel naming conventions for migrations, models, relationships, factories, and tests.
- Keep shared Foundation code in its package namespace and package directories. Keep host-application or module-specific code with its owning application or module.
- Name database tables, columns, relationships, routes, and tests consistently; document intentional exceptions.
- Avoid ambiguous abbreviations except for established terms such as `id` and the DataTable serial-number label `SL`.

## Testing expectations

- Add deterministic tests for new behavior, including success, validation, authorization, boundary, and failure cases relevant to the change.
- Test database migrations, constraints, and model relationships using an isolated test database. Tests must not depend on production data or external services.
- Verify both the intended behavior and important regressions in neighboring behavior.
- Do not report a check as passing unless it was run and its result was observed. Distinguish automated test results from manual review.

## Safe implementation rules

- Inspect a file before changing it. Preserve unrelated user work and existing Phase 1 behavior.
- Do not delete or overwrite existing files as a shortcut. If a required target already exists, inspect it and make a deliberate, minimal edit.
- Keep database changes additive and reversible where practical. Never reset, drop, or migrate a live or existing database without explicit authorization and a verified backup.
- Use isolated databases for migration and persistence tests. Stop if a required backup or database verification step fails.
- Avoid broad cleanup, destructive Git operations, or changes outside the project directory.
- Keep phase boundaries: do not start excluded authentication, user-management, authorization, or frontend work without explicit inclusion in the approved scope.
