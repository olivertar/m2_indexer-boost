# ADR-0004: Stuck indexer detection with the lock, PROCESSLIST and a table map

- Status: accepted
- Date: 2026-10-31

## Context

The SPEC defines a stuck indexer as `working` for more than 2 h without its `INDEXER<id>` lock. In 2.4.9:

- With the application lock enabled, `State::getStatus()` already reports "working without lock" as `invalid`, so the
  raw `indexer_state.status` must be read with SQL.
- Multi-process reindexes lose the lock while the workers run and take it back at the end (`Indexer::reindexAll()`),
  so "working, lock free" also describes a live reindex.
- The indexer config does not expose the tables an indexer writes.
- Without the `PROCESS` privilege, `information_schema.PROCESSLIST` silently lists only the current user's
  connections. Managed hosting usually withholds `PROCESS`.

## Decision

`StuckIndexerDetector` takes raw `working` rows older than `stuckAfterMinutes` (120) and gives each a verdict:

| Condition | Verdict | `INDEXER_STUCK` |
|---|---|---|
| application lock disabled | unverifiable | `info` (`INDEXER_STATE` already warns) |
| `INDEXER<id>` lock held (`LockManagerInterface::isLocked`) | running | `info` |
| a running **write** statement names one of its tables | active | `info` |
| none of the above, `PROCESS` granted | stuck | `critical` |
| none of the above, no `PROCESS` | doubtful | `warn` |

- Only writes count: the storefront reads index tables all the time.
- Tables come from the `indexTables` argument in `di.xml` (indexer id → table names or prefixes, core indexers
  preset); an indexer without an entry uses its own id. Matching is a case-insensitive substring of the prefixed name.
- The privilege is read from `information_schema.USER_PRIVILEGES` for `CURRENT_USER()`.

## Consequences

- Best effort: a reindex between two statements, or one that writes only to the search engine
  (`catalogsearch_fulltext`), looks idle. A `PROCESS` privilege granted through a role is not seen and yields
  "doubtful", the safe side.
- Third-party indexers need an `indexTables` entry when their tables do not contain their id.
