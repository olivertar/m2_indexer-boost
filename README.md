# Orangecat_IndexerBoost

Indexing diagnostics, passive reindex monitoring and faster full reindexes for Magento 2.4.6 – 2.4.9.

- Phase A of the Indexer Boost SPEC: the doctor (`bin/magento indexer-boost:doctor`, optional `--repair`), the
  passive reindex monitor and a read-only admin page.
- Phase C: rewritten full reindexes of `catalog_category_product`, `catalog_product_attribute` and
  `catalog_product_price`, each switched on with `bin/magento indexer-boost:toggle` (off by default). See
  [Switching implementations](#switching-implementations-phase-c).
- The benchmark (phase B) is the separate development module `Orangecat_IndexerBench`.

Documentation: [overview](docs/overview.md), [user manual](docs/user-manual.md),
[architecture](docs/architecture.md), [technical reference](docs/technical-reference.md),
[benchmark reports](docs/benchmarks/).

## Installation

Requires Magento 2.4.6 – 2.4.9 and PHP 8.2 – 8.5. The package is installed from this GitHub repository:

```bash
composer config repositories.m2_indexer-boost vcs https://github.com/olivertar/m2_indexer-boost.git
composer require orangecat/module-indexer-boost:^0.4
bin/magento module:enable Orangecat_IndexerBoost
bin/magento setup:upgrade
```

## Doctor

```bash
bin/magento indexer-boost:doctor [--format=table|json|markdown] [--only=<CODE,...>] [--output=<file>] [--repair]
```

Read-only diagnosis: it never modifies data or configuration. This is enforced twice:

- At runtime, checks only get `ReadOnlyConnection`, which rejects anything but `SELECT`/`SHOW`/`EXPLAIN`/
  `DESCRIBE`/`WITH` and locking or hidden-SQL constructs.
- In tests, `CheckPoolWriteGuardTest` builds every check of the `di.xml` pool and runs it against a database and
  filesystem that record any write, including writes a check catches and hides. It also fails when a check depends
  on `ResourceConnection` or a DB adapter directly, and when the pool or this README drift from the SPEC codes.

| Option | Description |
|---|---|
| `--format` | `table` (default), `json` (for CI and tooling) or `markdown` |
| `--only` | Comma-separated check codes to run (case-insensitive). Unknown codes are rejected |
| `--output` | Write the report to a file instead of the console |
| `--repair` | After the report, release stuck indexers and reindex invalid ones (see below) |

A full run (without `--only`) also saves its findings to `var/indexer-boost/doctor-last.json` for the admin page,
which cannot run the heavy checks itself. This file in `var/` is the only thing the doctor writes; a partial run
keeps the previous file, and failing to save it is a warning on stderr that does not change the exit code.

Findings are sorted from worst to best (`critical`, `warn`, `info`, `ok`). A check that cannot be evaluated
(e.g. missing database privileges) is reported as `info` and the remaining checks still run.

Exit codes:

| Code | Meaning |
|---|---|
| `0` | No `critical` finding |
| `1` | At least one `critical` finding |
| `2` | Usage error (unknown format or check code) |

### `--repair`

Explicit and CLI only (`Repairer` refuses any other `PHP_SAPI`). After printing the read-only report it does two
things and nothing else:

1. **Release stuck indexers.** For each indexer `INDEXER_STUCK` lists, by verdict:

   | Verdict | Interactive | `-n` / `--no-interaction` |
   |---|---|---|
   | stuck (`critical`) | asks `[y/N]` | marked invalid |
   | doubtful (`warn`, no `PROCESS` privilege) | asks `[y/N]` | skipped |
   | lock held, still writing, no application lock (`info`) | never touched | never touched |

   Right before marking an indexer invalid the detector runs again: if its lock or a running write showed up while
   the operator was answering, the indexer is left untouched.
2. **Reindex every invalid indexer** in the order of the indexer config (dependencies first), timing each one. Like
   `indexer_reindex_all_invalid`, it skips indexers whose shared index has a suspended member and builds a shared
   index once. Unlike the core it also skips indexers whose raw status is still `working`: with the application lock
   the core reports those as invalid, but the repair has just decided to leave them alone. A failed reindex is
   reported and the next ones still run.

It never changes configuration (`env.php`, `my.cnf`, `core_config_data`): `RepairWritesNoConfigTest` fails if
anything the repair depends on can write it. All invalid indexers are rebuilt, not only the released ones: on a
large catalog this can take hours, so run it in a maintenance window.

Progress goes to stderr, so a JSON or Markdown report on stdout stays parseable. Afterwards `INDEXER_STATE` and
`INDEXER_STUCK` run again (within `--only`, if given) and replace their earlier findings; the exit code is `1` if a
`critical` finding remains or a reindex failed.

## Passive reindex monitor

Records real reindex runs in production in `orangecat_indexer_boost_run_log` (indexer id, `full` or `changes`,
UTC start, seconds, failed flag and error). It feeds the `REINDEX_REGRESSION` and `REINDEX_CHURN` checks and the admin page.

- Full reindexes (`full`) are recorded by a plugin on the concrete `Magento\Indexer\Model\Indexer::reindexAll()`, so
  each run is stored once even though `IndexerInterface` resolves to `DependencyDecorator`
  (`DecoratedReindexRecordedOnceTest` checks it with the core interception chain). Indexers the core reindexes as
  dependents get their own row. A call on an indexer that is already `working` is not recorded (the core
  does nothing); a failed reindex is recorded with its error and the original exception is rethrown.
- Changelog (`changes`) runs are recorded by a plugin on `Magento\Framework\Mview\ViewInterface::update()`
  (sortOrder 100, inside the core plugin that skips suspended indexers, so skipped views are not measured). They are
  stored under the indexer id (`customer_dummy` -> `customer_grid`), or under the view id when no indexer owns the
  view, and only when they last 1 s or more (`minChangesSeconds` in `di.xml`): calls with nothing to do return in
  milliseconds. Failures are always stored.
- Recording never interferes with the reindex: any error while saving is logged as a warning and dropped.
- Cleanup: cron job `orangecat_indexer_boost_run_log_cleanup` (group `default`, daily at 03:30) deletes runs older
  than the retention (UTC start time, `started_at` index). It runs even when the monitor is disabled; the number of
  deleted rows is logged and a failure is logged as an error and retried the next day.
- Configuration: *Stores > Configuration > Advanced > Indexer Boost* (ACL `Orangecat_IndexerBoost::config`):
  `indexer_boost/monitor/enabled` (default `1`) and `indexer_boost/monitor/retention_days` (default `90`; invalid
  values fall back to 90).
- Statistics per indexer: last successful full reindex and median of the 5 successful full reindexes before it
  (`previousRuns` in `di.xml`), plus run and failure counts per kind.

## Admin page

`System > Tools > Indexer Boost` (ACL resource `Orangecat_IndexerBoost::doctor`, under `System > Tools`). Read-only:
the controller only answers GET and the page has no actions; fixes are launched from the CLI.

- **Light checks (run now):** the checks with `isHeavy() = false` run on every page load.
- **Other checks (last full CLI run):** the heavy checks (EAV scans, code search...) never run from a web request.
  Their findings come from `var/indexer-boost/doctor-last.json`, with its date. Without that file the page asks to
  run `bin/magento indexer-boost:doctor`.
- **Passive run log:** per indexer, the last successful full reindex (UTC start and seconds), the median of the
  previous ones, and full and changelog runs with their failures.

An unreadable report or run log is logged as a warning and shown as missing; it never breaks the page.

## Checks

| Code | What it measures | Why it matters |
|---|---|---|
| `INDEXER_MODE` | Indexers left in "Update on Save" (no `mview_state` row in `enabled` mode). `warn` from 5 000 products, `info` below (`warnFromProducts` in `di.xml`). Indexers listed in `excludedIndexers` and indexers without an mview view are skipped. The recommendation also says what a core import does to each realtime indexer (`import` metric): reindexed synchronously per batch of rows (`importReindexed`: `catalog_product_category`, `catalog_product_price`, `cataloginventory_stock`), invalidated when it finishes (`importInvalidated`: `catalog_product_attribute`, `catalogrule_rule`, `catalog_product_flat`, `customer_grid`) or not refreshed at all, e.g. `catalogsearch_fulltext` | Realtime indexers reindex synchronously on every save, slowing down the admin, imports and APIs. The import writes rows with SQL instead of saving products: in realtime it slows down every batch, its invalidations make cron run several full reindexes one after another, and the other indexers keep stale data until their next full reindex |
| `INDEXER_STATE` | Raw `indexer_state.status`: invalid, suspended, or `working` for more than 120 minutes (`workingWarnAfterMinutes`) | Invalid indexers serve stale data; suspended ones are skipped by cron while their changelog grows; long `working` may mean a stuck reindex |
| `INDEXER_STUCK` | Raw `indexer_state.status` `working` for more than 120 minutes (`stuckAfterMinutes`), then: `INDEXER<id>` lock held → `info` (running); with the lock free, running write statements (`INSERT`/`UPDATE`/`DELETE`/`ALTER`/...) on the indexer tables in `information_schema.PROCESSLIST` → `info` (alive, e.g. a multi-process reindex that released the lock); none → `critical` (stuck, `--repair`). Without the `PROCESS` privilege only the own connections are listed, so a free lock without visible writes is `warn` (doubtful). Without the application lock nothing can be verified → `info`. Indexer tables come from `indexTables` in `di.xml` (core indexers preset, otherwise the indexer id); only writes count because the storefront reads index tables all the time | A reindex that dies halfway leaves its indexer `working`: the index is never rebuilt and its changelog keeps growing. Best effort: a reindex between two statements, or writing only to the search engine (`catalogsearch_fulltext`), is not visible |
| `APPLICATION_LOCK` | `indexer/use_application_lock` in `env.php` | Without it, a reindex that dies halfway stays `working` forever and cron never rebuilds it |
| `PARALLEL_THREADS` | `MAGE_INDEXER_THREADS_COUNT` (read like the core `ProcessManager` does), `pcntl` and CPU cores. CLI only: PHP-FPM has a different environment | Price, catalog search and category-product indexers split a full reindex across processes only when threads > 1 and pcntl is available; more threads than cores slows it down |
| `BATCH_SIZE` | `indexer/batch_size/<indexer>[/<type>]` overrides in `env.php`; non-positive or non-numeric values are `warn`. On MariaDB, `warn` when `catalog_category_product` puts more than 1 000 product IDs in one batch (core default 100 000) | Batch size trades memory for fewer queries. With 42 040 products on MariaDB 10.11 the default batch made one `IN()` query per store take 400 s; batches of 1 000 took the full reindex from 1 258 s to 34 s, same output |
| `PRICE_DIMENSIONS` | `indexer/catalog_product_price/dimensions_mode`, evaluated from 50 000 products; recommends `website_and_customer_group` with more than 1 website or more than 3 customer groups (all configurable in `di.xml`) | Per-dimension price tables keep each reindex smaller and parallelizable |
| `DATA_EXPORTERS` | `*_data_exporter` indexers (Adobe Commerce Services) and their modules | If the services are not used, these indexers do work for nothing |
| `CATALOG_RULE_CRON` | Schedule of `catalogrule_apply_all` and active catalog rules | With active rules, every run invalidates `catalogrule_product` and triggers a full catalog rule reindex |
| `MVIEW_BACKLOG` | Pending entries per changelog (`MAX(version_id)` of `<view>_cl` minus `mview_state.version_id`) and approximate size of each changelog; reads only the primary key, never `COUNT(*)` | A growing backlog means the index cron cannot keep up and the storefront serves stale data; huge changelogs slow down every mview run |
| `CRON_INDEXER` | Last successful run of `indexer_update_all_views` and `indexer_reindex_all_invalid` in `cron_schedule`; `critical` if never or more than 15 minutes ago | Without these jobs scheduled indexers are never updated |
| `MYSQL_VARS` | Buffer pool vs data+index size (`critical` when still the 128 MB default with more than 256 MB of data), effective in-memory temporary table limit (lower of `tmp_table_size`/`max_heap_table_size`), redo log capacity, `innodb_flush_log_at_trx_commit`. Other variables are reported as metrics | Indexers rewrite large tables and build temporary tables; undersized memory sends that work to disk |
| `DB_SERVER_VERSION` | Server engine and series against the support matrix in `di.xml` (2.4.8/2.4.9: MySQL 8.4, MariaDB 11.4) | Unsupported versions are outside Magento QA and may plan index queries differently |
| `MARIADB_OPTIMIZER` | MariaDB only: `in_predicate_conversion_threshold` and `optimizer_use_condition_selectivity`. `warn` on 10.x, `info` on 11.x | Reported by a third party to slow `catalog_category_product` dramatically on MariaDB 10.5. Confirmed here on 10.11: `optimizer_use_condition_selectivity=1` took the root category query from 400 s to 1.1 s; `in_predicate_conversion_threshold=0` alone did not change the plan |
| `TABLE_STATS` | Largest EAV and index tables (changelogs excluded) and age of their InnoDB statistics; only tables of 100 MB or more are reported. Without SELECT on `mysql.innodb_table_stats` it reports sizes only | Outdated statistics after big imports lead to slow query plans; `ANALYZE TABLE` is suggested, never run |
| `CUSTOMER_GROUP_WEBSITES` | Customer group x website matrix, excluded pairs and pairs without customers (not proposed when accounts are shared globally). CLI only | Price and catalog rule indexes multiply by these combinations |
| `CATALOG_RULES` | Active rules, conditions per rule (`warn` above 10), store/website-scoped attributes in conditions, patch ACP2E-4695 on 2.4.7 | Rule conditions are validated product by product in PHP |
| `ATTRIBUTE_FLAGS` | Attributes with `is_filterable`, `used_for_sort_by`, `is_used_for_promo_rules` or `used_in_product_listing` that no product has a value for (one indexed `LIMIT 1` lookup per attribute) | These flags pull attributes into indexes and listings for nothing |
| `EAV_INDEXER` | `catalog/search/enable_eav_indexer` and a plain-text search of `vendor` and `app/code` (`.php`, `.phtml`, `.xml`) for `catalog_product_index_eav` and the legacy `Magento\Catalog` layered navigation filter classes. The indexer itself, core legacy layered navigation, two core classes that inject the resource but never use it (CatalogSearch `Layer\Filter\Decimal`, Swatches `RenderLayered`), tests and this module are excluded (`excludedPaths` in `di.xml`, Magento and Mage-OS paths). Enabled without readers: `info`; disabled with readers: `warn`. CLI only | Since 2.4 layered navigation and search use OpenSearch, so the EAV index is often built for nobody. The scan cannot see names built at runtime or external readers (BI, reports, SQL views): confirm before disabling |
| `EAV_REDUNDANT_STORE_VALUES` | Per `catalog_product_entity_{int,varchar,decimal,datetime,text}`: store-view rows whose value equals the `store_id = 0` value (byte comparison for varchar/text, so case differences are not counted). Counted in ranges of 10 000 entity ids (`rangeSize`), `warn` from 100 000 rows (`warnRows`). CLI only | Redundant rows add work to every EAV join of the indexers. The doctor only counts: deleting them makes store views follow future default changes, so clean on staging after business review |
| `EAV_ORPHANS` | Per EAV value table, in entity id ranges: values of missing products, missing stores, unknown or non-product attributes, and attributes whose `backend_type` points to another table. CLI only | Nothing reads these rows but EAV queries still scan them; missing products/stores reveal dropped foreign keys |
| `REINDEX_REGRESSION` | Passive run log: last successful full reindex of each indexer against the median of the 5 successful ones before it. `warn` when it takes more than twice the median **and** more than 60 s (`ratio`, `minSeconds` in `di.xml`); `info` while an indexer has fewer than 6 successful full runs or when the monitor is disabled | A reindex that suddenly takes much longer points to a change in data, rules, MySQL or code before that run; the 60 s floor ignores noise on fast indexers |
| `REINDEX_CHURN` | Last 24 h (`windowHours`): indexers with 3 or more full reindexes in the passive run log (`maxFullRuns`), full reindexes that started while another full reindex was running (the first 5 listed), and `indexer_update_all_views` / `indexer_reindex_all_invalid` runs in `cron_schedule` that were missed, failed or took longer than the 60 s cron interval (`cronJobs`, `cronIntervalSeconds`). `warn` per finding; with the monitor disabled only the cron jobs are checked | Indexing that piles up after imports and API bursts: repeated invalidations rebuild whole indexes one after another, concurrent full reindexes (usually a manual `indexer:reindex` during cron) slow each other down, and a slow changelog run delays every indexer behind it |
| `CATALOG_SIZE` | Products per type, categories, store views, websites, customer groups and catalog rules | Context for size-dependent checks and for comparing benchmarks |
| `BOOST_STATUS` | `info`: which rewritten indexers run the Indexer Boost implementation (flag `indexer_boost/<indexer_id>/enabled`) and which the core one | Tells whoever reads the report which code builds each index |
| `BOOST_COMPATIBILITY` | Plugins of other vendors (not `Magento\`, not Indexer Boost) on the core methods the rewrites hook into, in the `global`, `adminhtml` and `crontab` areas, and preferences that replace those classes (`interceptedMethods` in `di.xml`). `warn` per class, `ok` when there are none. CLI only: it switches the configuration scope of the process | Another module on the same class can run before the rewrite, skip it or override the method, so the rewrite was never tested with it: compare the output on a copy of the database before turning the rewrite on |

## Switching implementations (Phase C)

Rewritten indexers are switched at run time, without recompiling DI (SPEC 6.1):

```bash
bin/magento indexer-boost:toggle <indexer_id> on|off
```

- Flags: `indexer_boost/<indexer_id>/enabled` (default off), `fallback_on_error` (default on), `batch_size`.
- `Switcher` runs the core while the flag is off. When it is on and preparing the rewrite fails
  (`PreparationException`, before any index row is written), it logs the error and runs the core instead; a failure
  while writing is rethrown, as the core does. Only `catalog_category_product` has a preparation step (its per-store
  snapshots, built while only the replica tables were touched); the EAV and price rewrites have none, so any failure
  there stops the reindex as in the core.
- Parallel reindex (`MAGE_INDEXER_THREADS_COUNT`) works with the rewrites on (checked with 2 threads for
  `catalog_category_product` and for `catalog_product_price` in `website_and_customer_group` mode, same output).
  With a Redis/Valkey cache, the forked processes of the core `ProcessManager` share the cache connection of the
  parent and can fail with "protocol error, got '' as reply type byte" or "Fail in child process", with or without
  Indexer Boost; that is a core limitation of the environment, not of the rewrites.
- Rewritten indexers:

| Indexer | What changes | ADR |
|---|---|---|
| `catalog_category_product` (full reindex, also run by `catalog_product_category`) | empties the tmp table with `TRUNCATE` instead of `DELETE` before every batch (the core cost grows with the square of the batch count), and reads store view status, visibility, `is_active` and `is_anchor` from per-store snapshots built once instead of in every batch. 502 040 products: 1 852 s → 461 s | 0009, 0010 |
| `catalog_product_attribute` (full reindex) | batches product IDs by key instead of `LIMIT … OFFSET` (the core iterator stays with staging) and truncates the TEMPORARY work table before every batch; both core costs grow with the square of the batch count. 502 040 products: 664 s → 268 s | 0011 |
| `catalog_product_price` (full reindex) | reads customer groups after the product, website and EAV values of the base price select (a `STRAIGHT_JOIN` on that one join): MariaDB read them first and looked every value up once per group. 502 040 products: 1 039 s → 790 s | 0012 |

- On MariaDB also set `indexer/batch_size/catalog_category_product` to 1 000 (`BATCH_SIZE`, ADR-0008): with the
  rewrite on, small batches no longer slow the loop down.

## Testing

Unit tests are the main suite; integration tests cover real DI, SQL on the database server, locks and the admin
(ADR-0007).

```bash
# Unit
cd dev/tests/unit && ../../../vendor/bin/phpunit -c phpunit.xml.dist ../../../app/code/Orangecat/IndexerBoost/Test/Unit
# Integration (dev/tests/integration/phpunit.xml limits the suite to this module)
cd dev/tests/integration && ../../../vendor/bin/phpunit -c phpunit.xml
# Static checks
vendor/bin/phpcs --standard=Magento2 app/code/Orangecat/IndexerBoost
vendor/bin/phpstan analyse -c app/code/Orangecat/IndexerBoost/phpstan.neon
```

Integration setup (once, never against the store database):

1. Create a dedicated database and grant it to the Magento user, e.g. `CREATE DATABASE magento_integration_tests`
   and `GRANT ALL PRIVILEGES ON magento_integration_tests.* TO 'magento'@'%'`.
2. Copy `dev/tests/integration/etc/install-config-mysql.php.dist` to `install-config-mysql.php` with that database,
   the search engine host and its own `opensearch-index-prefix`; remove the `amqp-*` keys without RabbitMQ.
3. Copy `phpunit.xml.dist` to `phpunit.xml`, set `TESTS_CLEANUP` to `disabled` and point the test suite to
   `../../../app/code/Orangecat/IndexerBoost/Test/Integration`. The first run installs Magento (several minutes).
4. After changing `etc/di.xml`, clear `dev/tests/integration/tmp/sandbox-*/var/cache` and `generated`.

Parallel reindex (`MAGE_INDEXER_THREADS_COUNT`) is not covered by PHPUnit: the forked children of `ProcessManager`
run the shutdown handlers of the integration framework, which delete its test modules. It is checked with the
benchmark on a copy of the database instead (`docs/benchmarks/*-2026-11-02.md`).

Manual checks still worth doing on a non-production copy:

1. `bin/magento indexer-boost:doctor` in `table`, `json` and `markdown`; `echo $?` is `1` with a `critical` finding.
2. `--repair` interactively, with and without the application lock (`MAGENTO_DC_INDEXER__USE_APPLICATION_LOCK=1`
   enables it for one command), on an indexer forced to `working` 3 hours ago.
3. `System > Tools > Indexer Boost` in a browser.

## Design decisions

Architecture decision records live in [`docs/decisions`](docs/decisions):

| ADR | Decision |
|---|---|
| [0001](docs/decisions/0001-module-location-and-test-scope.md) | Module in `app/code` with its own `composer.json`; unit tests only in phase A |
| [0002](docs/decisions/0002-read-only-doctor.md) | Read-only doctor: `ReadOnlyConnection` at runtime, pool write-guard test |
| [0003](docs/decisions/0003-monitor-plugin-on-concrete-indexer.md) | Monitor plugins on the concrete `Indexer` and inside the core mview plugin |
| [0004](docs/decisions/0004-stuck-indexer-detection.md) | Stuck detection: raw status, lock, running writes on mapped tables, `PROCESS` privilege |
| [0005](docs/decisions/0005-repair-scope.md) | `--repair` scope and safeguards |
| [0006](docs/decisions/0006-admin-page-report-source.md) | Admin page: light checks live, heavy checks from the last CLI report |
| [0007](docs/decisions/0007-integration-tests.md) | Integration tests on a dedicated database; real lock fixture |

## Related packages

| Repository | Package | Purpose |
|---|---|---|
| [m2_indexer-boost](https://github.com/olivertar/m2_indexer-boost) | `orangecat/module-indexer-boost` | doctor, passive monitor, faster full reindexes (production) — [documentation](https://github.com/olivertar/m2_indexer-boost/tree/main/docs) |
| [m2_indexer-bench](https://github.com/olivertar/m2_indexer-bench) | `orangecat/module-indexer-bench` | benchmark (development and staging only) |
| [m2_indexer-boost-mago](https://github.com/olivertar/m2_indexer-boost-mago) | `orangecat/module-indexer-boost-mago` | Mago add-on for Indexer Boost |
| [m2_indexer-bench-mago](https://github.com/olivertar/m2_indexer-bench-mago) | `orangecat/module-indexer-bench-mago` | Mago add-on for the benchmark |

## Licence

MIT, see [LICENSE](LICENSE).
