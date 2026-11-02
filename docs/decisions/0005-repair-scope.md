# ADR-0005: Scope and safeguards of `--repair`

- Status: accepted
- Date: 2026-10-31

## Context

`--repair` marks stuck indexers invalid and reindexes invalid ones (SPEC 4.3). Releasing an indexer that is still
running starts a second reindex of the same index in parallel. With the application lock, the core reports any
`working` indexer without lock as invalid, so a naive "reindex all invalid" would also rebuild indexers the repair
decided to leave alone.

## Decision

- `Repairer` refuses any `PHP_SAPI` other than `cli`.
- Stuck indexers are released one by one: interactive runs ask `[y/N]` for stuck and doubtful ones; with
  `--no-interaction` only stuck (`critical`) ones are released and doubtful ones are skipped. Running, active and
  unverifiable ones are never touched. The detector runs again right before each release.
- Invalid indexers are reindexed in indexer config order (dependencies first), with the core rules for shared
  indexes (skip while a member is suspended, make the shared index valid once), timing each one. Indexers whose raw
  status is still `working` are skipped. A failure is reported and the next indexers still run.
- Progress goes to stderr. Afterwards `INDEXER_STATE` and `INDEXER_STUCK` run again and replace their findings; the
  exit code is `1` if a critical finding remains or a reindex failed.
- Nothing the repair depends on can write configuration: `RepairWritesNoConfigTest` walks the constructor graph of
  `Repairer` and `DoctorCommand` and fails on config writers (with a control case).

## Consequences

- All invalid indexers are rebuilt, not only the released ones: on a large catalog `--repair` can take hours.
- An indexer the operator declines stays `working`; the core cron will treat it as invalid if the application lock
  is enabled.
