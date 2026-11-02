# ADR-0006: Admin page shows light checks live and heavy checks from the last CLI report

- Status: accepted
- Date: 2026-10-31

## Context

The admin page (SPEC 4.5) must show the doctor findings. Some checks scan EAV tables by ranges or search the
installed code; they take too long for a web request.

## Decision

- `CheckInterface::isHeavy()` splits the pool. The page runs the light checks on every load.
- Every full CLI run (without `--only`) saves its findings to `var/indexer-boost/doctor-last.json`
  (`LastReportStorage`, versioned format, UTC date). After `--repair` the re-checked findings are saved. Partial runs
  keep the previous file. Failing to save is a warning that does not change the exit code.
- The page shows the saved findings of the checks that did not run live, with the report date, or asks for a CLI
  run when the file is missing or unreadable.
- The controller implements only `HttpGetActionInterface`, behind its own ACL resource
  `Orangecat_IndexerBoost::doctor` under `System > Tools`.

## Consequences

- Writing a file in `var/` is the doctor's only write, outside data and configuration (ADR-0002).
- Heavy findings on the page can be old; the date tells how old.
