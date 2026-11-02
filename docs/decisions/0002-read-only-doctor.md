# ADR-0002: The doctor is read-only, enforced at runtime and in tests

- Status: accepted
- Date: 2026-10-31

## Context

The SPEC requires that no check writes. The doctor runs in production, from the CLI and from the admin page, and a
check is free to run any SQL it builds. A code review alone does not keep that promise as checks are added.

## Decision

- Checks never receive `ResourceConnection` or an adapter. They get `ReadOnlyConnection`, which accepts only
  `SELECT`/`SHOW`/`EXPLAIN`/`DESCRIBE`/`WITH` and rejects, outside string literals, locking reads (`FOR UPDATE`,
  `FOR SHARE`, `LOCK IN SHARE MODE`), any `INTO`, executable comments (`/*!`), stacked statements and user lock
  functions (`GET_LOCK`...), throwing `WriteNotAllowedException`.
- `CheckPoolWriteGuardTest` builds every check of the `di.xml` pool by reflection, runs it against a database and a
  filesystem that record writes (including writes a check catches and hides), and fails when a check depends on a
  connection directly, when the pool drifts from the SPEC codes, or when the README misses a check.
- Heavy checks walk tables by key ranges (`RangeIterator`), never in one query.

## Consequences

- A new check that needs a write cannot be added by accident; it needs an explicit change to this ADR.
- The only file the doctor writes is the admin report in `var/` (ADR-0006). `--repair` is a separate, explicit
  path (ADR-0005).
