# Indexer Boost — technical reference

Every command, option, check, configuration path, table, `di.xml` argument, ACL resource and Mago tool of the four
modules. For the reasoning behind them see the [architecture](architecture.md); for procedures see the
[user manual](user-manual.md).

## Commands

### `indexer-boost:doctor` (Orangecat_IndexerBoost)

```
bin/magento indexer-boost:doctor [--format=table|json|markdown] [--only=<CODE,...>] [--output=<file>] [--repair] [-n]
```

| Option | Description |
|---|---|
| `--format` | `table` (default), `json`, `markdown` |
| `--only` | comma-separated check codes, case-insensitive; unknown codes exit with `2` |
| `--output` | write the report to a file |
| `--repair` | after the report: release stuck indexers, then reindex every invalid one (CLI SAPI only) |
| `-n` | with `--repair`: release stuck indexers without asking, skip doubtful ones |

| Exit code | Meaning |
|---|---|
| `0` | no `critical` finding |
| `1` | a `critical` finding (after `--repair`: one remains, or a reindex failed) |
| `2` | usage error |

A run without `--only` saves `var/indexer-boost/doctor-last.json`. With `--repair`, progress goes to stderr and
`INDEXER_STATE`/`INDEXER_STUCK` run again afterwards.

JSON output:

```json
{
  "summary": {"critical": 1, "warn": 6, "info": 13, "ok": 10},
  "findings": [
    {"severity": "critical", "code": "CRON_INDEXER", "message": "…", "recommendation": "…", "fix": "…",
     "metrics": {"minutes_since_success": {"indexer_update_all_views": null}}}
  ]
}
```

### `indexer-boost:toggle` (Orangecat_IndexerBoost)

```
bin/magento indexer-boost:toggle <indexer> on|off
```

Writes `indexer_boost/<indexer>/enabled` in `core_config_data` (default scope) and cleans the config cache. Refuses
indexers without a rewrite: `catalog_category_product`, `catalog_product_attribute`, `catalog_product_price`.

### Benchmark commands (Orangecat_IndexerBench, staging only)

Run them through `app/code/Orangecat/IndexerBench/bin/bench-magento` (a shell script) against a copy of the database.

| Command | Options |
|---|---|
| `indexer-bench:run` | `-i <indexer>` `-l <label>` `--repeat=N` `--warmup` `--mode=baseline\|candidate` `--threads=N` `--skip-reset` `--profile` `--strict` `--notes=` |
| `indexer-bench:run-partial` | same, plus `-c <count>` `--seed=<n>` (default 42) or `--ids=1,2,3` |
| `indexer-bench:snapshot` | `-i` `-l` `--diff=<label>` `--limit=N` |
| `indexer-bench:compare` | `-i` `--baseline=<label\|run id>` `--candidate=<label\|run id>` `--threshold=10` `--strict` `--format=table\|markdown` |
| `indexer-bench:history` | `-i` `--limit=20` `--metric=wall_time_s` |
| `indexer-bench:export` | `--run=<id>` `--format=markdown\|json` `--output=<file>` |

`bench-magento` environment: `BENCH_DB` (default `magento_bench`), `BENCH_CACHE_PREFIX` (`bch_`), `BENCH_CACHE_DB` (2;
the page cache uses the next one).

Supported indexers: `catalog_category_product`, `catalog_product_price`, `catalogrule_rule`, `catalogrule_product`,
`catalog_product_attribute`, `cataloginventory_stock`, `inventory` (full only). Metrics, fingerprint keys and
safeguards are listed in [the IndexerBench README](https://github.com/olivertar/m2_indexer-bench#readme).

## Checks

26 checks. *Heavy* checks run only from the CLI; the admin page and Mago read them from the last full report. The
full description of each (what it measures, thresholds, why it matters) is the checks table of
[the module README](../README.md#checks), which a test keeps in line with the pool.

| Code | Area | Heavy | Configurable in `di.xml` |
|---|---|:---:|---|
| `INDEXER_MODE` | indexers | | `warnFromProducts` (5 000), `excludedIndexers`, `importReindexed`, `importInvalidated` |
| `INDEXER_STATE` | indexers | | `workingWarnAfterMinutes` (120) |
| `INDEXER_STUCK` | indexers | | `stuckAfterMinutes` (120), `indexTables` |
| `APPLICATION_LOCK` | indexers | | |
| `PARALLEL_THREADS` | configuration | ✓ | `warnFromProducts` (5 000) |
| `BATCH_SIZE` | configuration | | |
| `PRICE_DIMENSIONS` | configuration | | `evaluateFromProducts` (50 000), `maxWebsitesWithoutDimensions` (1), `maxCustomerGroupsWithoutDimensions` (3) |
| `DATA_EXPORTERS` | configuration | | |
| `CATALOG_RULE_CRON` | cron | | `warnFromProducts` (5 000) |
| `MVIEW_BACKLOG` | cron | | `warnPendingEntries` (10 000), `largeChangelogRows` (1 000 000) |
| `CRON_INDEXER` | cron | | `maxMinutesSinceSuccess` (15), `jobs` |
| `REINDEX_CHURN` | cron / history | | `windowHours` (24), `maxFullRuns` (3), `cronJobs`, `cronIntervalSeconds` (60) |
| `MYSQL_VARS` | database | | |
| `DB_SERVER_VERSION` | database | | `supportedSeries` |
| `MARIADB_OPTIMIZER` | database | | |
| `TABLE_STATS` | database | | `tablePatterns`, `limit` (10), `largeTableMb` (100), `staleAfterDays` (30) |
| `CUSTOMER_GROUP_WEBSITES` | data | ✓ | |
| `CATALOG_RULES` | data | | `maxConditionsPerRule` (10) |
| `ATTRIBUTE_FLAGS` | data | | |
| `EAV_INDEXER` | data / code | ✓ | `roots`, `needles`, `excludedPaths` |
| `EAV_REDUNDANT_STORE_VALUES` | data | ✓ | `rangeSize` (10 000), `warnRows` (100 000) |
| `EAV_ORPHANS` | data | ✓ | `rangeSize` (10 000) |
| `REINDEX_REGRESSION` | history | | `requiredPreviousRuns` (5), `ratio` (2), `minSeconds` (60) |
| `CATALOG_SIZE` | context | | |
| `BOOST_STATUS` | Indexer Boost | | |
| `BOOST_COMPATIBILITY` | Indexer Boost | ✓ | `interceptedMethods` |

Adding a check: implement `Orangecat\IndexerBoost\Model\Doctor\CheckInterface`, inject
`Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection` (never `ResourceConnection`), and add it to the `checks`
argument of `Orangecat\IndexerBoost\Model\Doctor\Doctor` in your module's `di.xml` under a new code.

### Admin pages

| Page | Route (GET only) | Module |
|---|---|---|
| Doctor, light checks, last report, run log | `indexerboost/doctor/index` | IndexerBoost |
| Benchmark overview per indexer | `indexerbench/run/index` | IndexerBench |
| Benchmark run detail | `indexerbench/run/view/id/<run>` | IndexerBench |
| Benchmark Markdown report download | `indexerbench/run/export/id/<run>` | IndexerBench |
| Benchmark comparison | `indexerbench/compare/index?indexer=&baseline=&candidate=&threshold=` | IndexerBench |

The benchmark pages read the runs of the installation's own database.

## Configuration

### `core_config_data`

| Path | Default | Set from | Meaning |
|---|---|---|---|
| `indexer_boost/monitor/enabled` | `1` | *Stores > Configuration > Advanced > Indexer Boost* | record reindex runs |
| `indexer_boost/monitor/retention_days` | `90` | same | days of run log kept; invalid values fall back to 90 |
| `indexer_boost/<indexer>/enabled` | off | `indexer-boost:toggle`, Mago | use the Indexer Boost full reindex |
| `indexer_boost/<indexer>/fallback_on_error` | on | `config:set` | run the core reindex when preparing the rewrite fails |
| `indexer_boost/<indexer>/batch_size` | — | `config:set` | read by `Config::getBatchSize()` but not used by the current rewrites; batch sizes come from `env.php` |

### `app/etc/env.php`

| Key | Used by | Meaning |
|---|---|---|
| `indexer/use_application_lock` | core, `APPLICATION_LOCK`, `INDEXER_STUCK` | lock per reindex; a `working` indexer without lock is invalid |
| `indexer/batch_size/<indexer>[/<type>]` | core, rewrites, `BATCH_SIZE` | entities per batch; `catalog_category_product` → 1 000 on MariaDB |
| `indexer_bench/allowed_hosts` | benchmark | host and database names allowed in production mode (both must be listed) |

### Environment

| Variable | Meaning |
|---|---|
| `MAGE_INDEXER_THREADS_COUNT` | processes for a full reindex of the indexers that support it (core); read by `PARALLEL_THREADS` and the benchmark fingerprint |
| `MAGENTO_DC__OVERRIDE` | set by `bench-magento` to point `bin/magento` at the benchmark database and cache |

## Database tables

| Table | Module | Columns |
|---|---|---|
| `orangecat_indexer_boost_run_log` | IndexerBoost | `run_id`, `indexer_id`, `kind` (`full`/`changes`), `started_at` (UTC, whole seconds), `duration_s` (3 decimals), `failed`, `error`; indexes on (`indexer_id`, `kind`, `started_at`) and `started_at` |
| `orangecat_indexer_bench_run` | IndexerBench | indexer, label, mode, kind, sample size and seed, repeat, `warmup`, `profiled`, UTC start and end, status, error, fingerprint (JSON), notes |
| `orangecat_indexer_bench_metric` | IndexerBench | run, metric code, value `DECIMAL(20,6)` (`NULL` = not available) |
| `orangecat_indexer_bench_snapshot` | IndexerBench | label, table (`*` = global), rows, MD5, dump path |

## Cron

| Job | Group | Schedule | What it does |
|---|---|---|---|
| `orangecat_indexer_boost_run_log_cleanup` | `default` | daily 03:30 | deletes run log rows older than the retention; runs even with the monitor off |

## Plugins on core classes

| Core class / method | Plugin | Purpose |
|---|---|---|
| `Magento\Indexer\Model\Indexer::reindexAll()` | `orangecat_indexer_boost_record_full_reindex` | monitor: record full reindexes |
| `Magento\Framework\Mview\ViewInterface::update()` | `orangecat_indexer_boost_record_view_update` | monitor: record changelog runs |
| `Magento\Catalog\Model\Indexer\Category\Product\Action\Full::execute()` | `orangecat_indexer_boost_category_product_full` | category rewrite switch |
| `Magento\Catalog\Model\Indexer\Product\Eav\Action\Full::execute()` | `orangecat_indexer_boost_product_eav_full` | EAV rewrite switch |
| `Magento\Catalog\Model\Indexer\Product\Price\Action\Full::execute()` | `orangecat_indexer_boost_price_full` | price rewrite scope |
| `Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\Query\BaseFinalPrice::getQuery()` | `orangecat_indexer_boost_price_query` | price join order, inside the scope only |

`BOOST_COMPATIBILITY` reports other vendors' plugins and preferences on the rewritten classes.

## ACL resources

| Resource | Title | Grants |
|---|---|---|
| `Orangecat_IndexerBoost::doctor` | *System > Tools > Indexer Boost* | admin page; Mago status and doctor tools |
| `Orangecat_IndexerBoost::config` | *Stores > Settings > Configuration > Indexer Boost* | monitor configuration |
| `Orangecat_IndexerBoostMago::toggle` | Switch Indexer Boost Implementations | Mago toggle tool and `/boost on|off` |
| `Orangecat_IndexerBench::view` | *System > Tools > Indexer Benchmarks* | benchmark admin pages |
| `Orangecat_IndexerBenchMago::view` | View Indexer Benchmarks | Mago benchmark tools |

## Mago tools

All results are classified `PiiClass::PUBLIC`. Tools never run `--repair`, heavy checks or benchmarks.

| Tool | Parameters | Returns |
|---|---|---|
| `indexer_boost_status` | — | `indexers[]`: `indexer_id`, `title`, `mode`, `status`, `last_full_started_at`, `last_full_duration_s`, `usual_full_duration_s`, `full_failures`, `implementation`, `last_full_implementation`; `rewritten_indexers` |
| `indexer_boost_doctor` | `min_severity` (`ok`/`info`/`warn`/`critical`, default `warn`), `codes[]` | `summary`, `findings[]` (max 20: `severity`, `code`, `message`, `recommendation`, `fix`, `source` = `live`/`last_cli_report`), `omitted_findings`, `checked_now`, `last_cli_report_at`, `last_cli_report_age`, `heavy_checks` |
| `indexer_boost_toggle` | `indexer` (rewritten ids), `state` (`on`/`off`) — write, confirmation card | `indexer_id`, `implementation` |
| `indexer_bench_history` | `indexer`, `metric`, `limit` (1–20) | `metric`, `runs[]`, `labels[]` (median, min, max, `trend_pct`) |
| `indexer_bench_compare` | `indexer`, `baseline`, `candidate`, `threshold` | `metrics[]` with verdicts, fingerprint differences, `output` (`MATCH`/`DIFFER`), `output_rows`, `regressions` |
| `indexer_bench_report` | `run_id` (default: latest) | `run_id`, `markdown` |

Slash command: `/boost status | doctor | on <indexer> | off <indexer>`.

Check a tool from the CLI:

```bash
bin/magento mago:tool:verify indexer_boost_doctor '{"min_severity":"ok"}'
bin/magento mago:tool:verify indexer_boost_toggle '{"indexer":"catalog_product_price","state":"off"}' --allow-write
```

## Development

```bash
# unit tests, per module
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Orangecat/<Module>/Test/Unit
# integration tests (dedicated database, see IndexerBoost/README.md#testing)
cd dev/tests/integration && ../../../vendor/bin/phpunit -c phpunit.xml
# static analysis
vendor/bin/phpcs --standard=Magento2 app/code/Orangecat/<Module>
vendor/bin/phpstan analyse -c app/code/Orangecat/<Module>/phpstan.neon
```
