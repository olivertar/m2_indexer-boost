# ADR-0001: Module location and test scope for phase A

- Status: accepted; test scope superseded by ADR-0007
- Date: 2026-10-30

## Context

The specification describes a standalone repository (`src/IndexerBoost`, `src/IndexerBench`).
The host project is a `magento/project-community-edition` installation whose custom code lives in `app/code/Orangecat/`.
The local environment (reward, MariaDB 10.11, PHP 8.5) has no integration-test database.

## Decision

- The module lives in `app/code/Orangecat/IndexerBoost` with its own `composer.json`, so it can be extracted to a package later without code changes.
- Phase A ships **unit tests only**. Database behaviour is tested against mocked/fake adapters.
  The "recorded exactly once with the dependency decorator" criterion is covered by a unit test that wires the real
  `Magento\Indexer\Model\Indexer\DependencyDecorator` around an indexer wrapped by the plugin, plus a manual check
  (`indexer:reindex` + row count in the run log).
- Code targets PHP 8.2 syntax (`phpVersion: 80200` in PHPStan) even though it runs on 8.5 locally.

## Consequences

- No coverage of real DI wiring or real SQL in automated tests; manual verification steps are listed in the README.
- Integration tests can be added later when a test database is available.
