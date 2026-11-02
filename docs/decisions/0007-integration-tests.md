# ADR-0007: Integration tests on a dedicated database

- Status: accepted (supersedes the test scope of ADR-0001)
- Date: 2026-10-31

## Context

Phase A shipped with unit tests only (ADR-0001): real DI wiring, SQL on MariaDB, the lock backend and the admin
controller were checked by hand. A dedicated database is now available on the local MariaDB server.

## Decision

- Integration tests live in `Test/Integration` and run with the Magento integration framework against
  `magento_integration_tests` (own database, the `magento` user has privileges on it only) and the existing
  OpenSearch with the index prefix `magento2_integration`. The local config (`dev/tests/integration/etc/
  install-config-mysql.php`, `dev/tests/integration/phpunit.xml` with `TESTS_CLEANUP=disabled` and a suite limited to
  this module) is not versioned.
- They cover what unit tests cannot: a full reindex through the real `DependencyDecorator` recorded once, a real
  changelog run recorded under the indexer id, run log statistics (window function) and cleanup on MariaDB, every
  check of the pool evaluated on the real database, stuck detection with the real lock backend and privileges,
  `--repair` releasing and rebuilding an indexer, the admin page (ACL, GET, POST rejected) and the report in `var/`.
- The integration framework replaces `Magento\Framework\Lock\Backend\Database` with a `DummyLocker` whose
  `isLocked()` is always false. Tests that need real locks use `Fixture\DatabaseLock`, a plain subclass the
  preference does not reach, and `Fixture\ApplicationLockDeploymentConfig` to switch the application lock on.

## Consequences

- In one process `Indexer::reindexAll()` takes the `INDEXER` lock twice (`GET_LOCK` nests) and releases it once, so
  the lock outlives the reindex until the connection closes. Tests that reindex release all locks of their
  connection; in production the CLI process ends right after.
- With `TESTS_CLEANUP=disabled` the sandbox keeps its DI cache: after changing `etc/di.xml`, clear
  `dev/tests/integration/tmp/sandbox-*/var/cache` and `generated`, or the tests run the old configuration.
- Unit tests stay the main suite (fast, no services); integration tests run before releases and after changes to
  SQL, DI or the admin.
