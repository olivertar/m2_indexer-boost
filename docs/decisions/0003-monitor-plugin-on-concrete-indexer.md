# ADR-0003: Passive monitor plugins on the concrete indexer class

- Status: accepted
- Date: 2026-10-31

## Context

`Magento\Framework\Indexer\IndexerInterface` is preferred to `Magento\Indexer\Model\Indexer\DependencyDecorator`,
which delegates `reindexAll()` to a concrete `Magento\Indexer\Model\Indexer`. A plugin on the interface intercepts
both objects, so every full reindex would be recorded twice. `Indexer::reindexAll()` also returns without doing
anything when the indexer is already `working`.

Changelog runs go through `Magento\Framework\Mview\ViewInterface::update()`, where the core already has the around
plugin `skip_suspended_indexer_mview_update` (sortOrder 10). Views have a `view_id`, not an indexer id.

## Decision

- `RecordFullReindexPlugin` is declared on `Magento\Indexer\Model\Indexer`. It records nothing when the indexer is
  already working (same `WorkingStateProvider` test as the core) and rethrows the original exception after recording
  a failure.
- `RecordViewUpdatePlugin` is declared on `ViewInterface` with sortOrder 100, so it runs inside the core plugin and
  times only real updates. `ViewIdResolver` maps view ids to indexer ids from the indexer config.
- `RunRecorder` never throws: storage errors are logged and dropped. Changelog runs under 1 s are not stored;
  failures always are.
- `DecoratedReindexRecordedOnceTest` reads the plugin type from `etc/di.xml`, runs the real plugin behind the real
  decorator with generated-style interceptors, and checks there is one record; a control plugin on the interface is
  called twice.

## Consequences

- Moving the plugin to the interface fails the build.
- Partial reindexes (`reindexRow`/`reindexList`, update on save) are not monitored; the SPEC does not ask for them.
