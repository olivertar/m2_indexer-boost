# Changelog

All notable changes to `orangecat/module-indexer-boost` are documented here.

## [Unreleased]

## [0.4.0] - 2026-11-02

### Changed (packaging)
- MIT licence; published as `orangecat/module-indexer-boost` on GitHub (`olivertar/m2_indexer-boost`). Requires
  `magento/module-store`, which the category rewrite already used. Project documentation moved to `docs/`.

### Added
- `REINDEX_CHURN` check (light, read-only, last 24 h): indexers with 3 or more full reindexes in the passive run
  log, full reindexes that ran at the same time, and index cron jobs (`indexer_update_all_views`,
  `indexer_reindex_all_invalid`) that were missed, failed or ran longer than their 60 s interval. Covers the
  indexing that piles up after CSV imports and API bursts.

### Changed
- `INDEXER_MODE` says what a core import does to each realtime indexer (new `import` metric): reindexed
  synchronously per batch, invalidated so cron rebuilds it in full, or not refreshed at all (e.g.
  `catalogsearch_fulltext`). Lists in `di.xml` (`importReindexed`, `importInvalidated`), verified on 2.4.9.
- `ToggleCommand` uses the new `Model/Indexer/Toggle` service, shared with the Mago add-on.
- `MARIADB_OPTIMIZER` recommendation names the effect of each variable.

## [0.3.0] - 2026-11-02

Phase C: rewritten full reindexes of `catalog_category_product`, `catalog_product_attribute` and
`catalog_product_price`, each behind its own flag (off by default). Reports in `docs/benchmarks/*-2026-11-02.md`.

### Added
- First rewrite: full `catalog_category_product` reindex (also used by `catalog_product_category`) empties its tmp
  table with `TRUNCATE` instead of `DELETE` before every batch (`Action\Full`, `BatchWriter`,
  `CategoryProductFullPlugin`). Off by default: `bin/magento indexer-boost:toggle catalog_category_product on`.
  Same output as the core (snapshot MATCH). 502 040 products, 5 stores: 1 852 s → 876 s; 42 040 products:
  33.8 s → 28.4 s. ADR-0009.
- The same rewrite reads store view status, visibility, `is_active` and `is_anchor` from per-store TEMPORARY
  snapshots built once per reindex (`StoreSnapshot`) instead of resolving them in every batch: 502 040 products
  1 852 s → 461 s, 42 040 products 33.8 s → 18.7 s, same output. ADR-0010.
- Second rewrite: full `catalog_product_attribute` reindex batches product IDs by key instead of `LIMIT … OFFSET`
  (the core iterator stays with staging) and truncates its TEMPORARY work table before every batch
  (`ProductEav\Action\Full`, `ProductEavFullPlugin`, `TemporaryTable`). Off by default:
  `bin/magento indexer-boost:toggle catalog_product_attribute on`. 502 040 products 664 s → 268 s, 42 040 products
  13.6 s → 12.1 s, same output. ADR-0011.
- Third rewrite: full `catalog_product_price` reindex reads customer groups after the product, website and EAV
  values of the base price select (`PriceFullPlugin`, `PriceQueryPlugin`, `FullReindexScope`, `JoinOrder`): MariaDB
  read them first and looked every value up once per group. Off by default:
  `bin/magento indexer-boost:toggle catalog_product_price on`. 502 040 products 1 039 s → 790 s, same output.
  ADR-0012.
- Integration tests `ProductEavFullIdentityTest`, `PriceFullIdentityTest` and `JoinOrderTest`.
- Doctor check `BOOST_STATUS` (`info`): which rewritten indexers run the Indexer Boost implementation and which the
  core one.
- Doctor check `BOOST_COMPATIBILITY` (CLI only): `warn` when a plugin of another vendor intercepts, or a preference
  replaces, one of the core classes the rewrites hook into, in the `global`, `adminhtml` and `crontab` areas.

### Fixed
- `fallback_on_error` never applied: nothing threw `PreparationException`. A failure while building the
  `catalog_category_product` snapshots now does, so the `Switcher` logs it and runs the core indexer (integration test
  `PreparationFallbackTest`).
- The `catalog_category_product` product snapshot called `Select::useStraightJoin()`, which Magento never renders;
  removed. Starting from `catalog_product_website` is what keeps the plan (ADR-0010, corrected).
- Integration test `CategoryProductFullIdentityTest`: core and Indexer Boost full reindex write the same rows on a
  catalog with store view overrides, composite children and an inactive branch.

## [0.2.0] - 2026-11-01

### Added
- Phase C groundwork: per-indexer flags in `Config` (`isIndexerEnabled`, `isFallbackOnError`, `getBatchSize`),
  `indexer-boost:toggle <indexer_id> on|off`, `RewrittenIndexers` (declared in `di.xml`, empty for now), `Switcher`
  and `PreparationException` (fall back to the core only while preparing).
- `BATCH_SIZE`: `warn` on MariaDB when `catalog_category_product` batches exceed 1 000 product IDs, with the
  measured gain (1 258 s → 34 s, same output). ADR-0008.

### Changed
- `BATCH_SIZE` example no longer suggests 100 000 for `catalog_category_product`.
- `MARIADB_OPTIMIZER` recommendation includes the measurement made here (400 s → 1.1 s with
  `optimizer_use_condition_selectivity=1`; the IN() conversion threshold alone did not help).

## [0.1.1] - 2026-11-01

### Added
- Integration tests (`Test/Integration`, 18 tests): full reindex recorded once through the real dependency
  decorator, real changelog run recorded under the indexer id, run log stats and cleanup on MariaDB, every check
  evaluated on the real database, stuck detection with the real lock backend and privileges, `--repair` release and
  rebuild, admin page ACL/GET/POST, report storage in `var/`. ADR-0007.
- `composer.json`: `magento/module-config` dependency (used by `system.xml`).

### Changed
- `MysqlVarsCheck::VARIABLES` is public, so the benchmark fingerprint records the same server variables.

## [0.1.0] - 2026-10-31

Phase A: doctor, `--repair`, passive reindex monitor and read-only admin page.

### Added
- Module skeleton (`Orangecat_IndexerBoost`).
- Doctor finding model (`Severity`, `CheckResult`).
- `ReadOnlyConnection`: read-only DB access for doctor checks, rejects writes, locking reads and hidden SQL.
- `Doctor` runner with `CheckInterface` and per-run `DoctorContext`: `--only` filtering (rejects unknown codes),
  light-only mode for the admin page, failing checks reported as `info` findings, results sorted worst first.
- Report formatters: console table, JSON (summary + findings) and Markdown (escaped table).
- `bin/magento indexer-boost:doctor` with `--format`, `--only` and `--output`; exit code 1 on critical findings,
  2 on usage errors.
- Checks: `INDEXER_MODE`, `INDEXER_STATE`, `APPLICATION_LOCK`, `CATALOG_SIZE`, `PARALLEL_THREADS`, `BATCH_SIZE`,
  `PRICE_DIMENSIONS`, `DATA_EXPORTERS`, `CATALOG_RULE_CRON`, `MVIEW_BACKLOG`, `CRON_INDEXER`, `MYSQL_VARS`,
  `DB_SERVER_VERSION`, `MARIADB_OPTIMIZER`, `TABLE_STATS`, `CUSTOMER_GROUP_WEBSITES`, `CATALOG_RULES`,
  `ATTRIBUTE_FLAGS`, `EAV_INDEXER`, `EAV_REDUNDANT_STORE_VALUES`, `EAV_ORPHANS`, `REINDEX_REGRESSION`, `INDEXER_STUCK`.
- `RangeIterator`: consecutive key ranges (`MIN`/`MAX` + step) so heavy checks never scan a table in one query.
- `VendorReferenceScanner`: plain-text search of installed code with pruned exclusions.
- Passive run log: `orangecat_indexer_boost_run_log` table, `RunLog` (writes), `RunRecorder` (monitor flag,
  1 s threshold for changelog runs, never throws), `RunStatsReader` (read-only stats with the median of previous
  full reindexes) and `Config` with admin settings `indexer_boost/monitor/{enabled,retention_days}`.
- `RecordFullReindexPlugin` on `Magento\Indexer\Model\Indexer::reindexAll()`: records duration and failures of
  every full reindex, skips calls on indexers already `working`, rethrows the original exception.
- `RecordViewUpdatePlugin` on `Magento\Framework\Mview\ViewInterface::update()` and `ViewIdResolver` (view id to
  indexer id): records changelog runs of 1 s or more and every failed one.
- Cron job `orangecat_indexer_boost_run_log_cleanup` (daily): deletes run log rows older than `retention_days`.
- `StuckIndexerDetector` (raw status, `INDEXER<id>` lock, running writes on the indexer tables via `ProcessListReader`
  with `PROCESS` privilege detection), shared by `INDEXER_STUCK` and `--repair`.
- `indexer-boost:doctor --repair` (`Repairer`, CLI only): marks stuck indexers invalid after re-checking them
  (per-indexer confirmation; with `-n` only `critical` ones), then reindexes every invalid indexer in config order
  with timings, shared index handling as the core, and indexers still raw `working` skipped. Progress on stderr;
  `INDEXER_STATE`/`INDEXER_STUCK` re-run for the exit code. Never writes configuration (`RepairWritesNoConfigTest`).
- `LastReportStorage`: full doctor runs save their findings (after `--repair`, the re-checked ones) to
  `var/indexer-boost/doctor-last.json` for the admin page; partial runs keep it; unreadable files load as no report.
- Admin page `System > Tools > Indexer Boost` (ACL `Orangecat_IndexerBoost::doctor`, GET only): light checks run
  live, heavy-check findings from the last CLI report with its date, and passive run log stats per indexer
  (`DoctorPage` view model).
- Pool-wide write-guard test: every `di.xml` check runs against a write-recording DB and filesystem; direct DB
  dependencies, missing SPEC codes and undocumented checks fail the build.
- ADRs 0001–0006 in `docs/decisions`.
