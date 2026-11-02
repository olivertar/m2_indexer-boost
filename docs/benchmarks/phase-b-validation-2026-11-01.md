# Phase B validation — 2026-11-01

Acceptance check of SPEC 5.8 for `Orangecat_IndexerBench` 0.2.0, on `magento_bench`, a clone of the development
database with the `medium` performance profile on top (`setup:performance:generate-fixtures`, stopped at the tax
rates step by a sample-data tax rule: products, categories, websites, catalog rules and customers were generated,
orders and tax rates were not).

- Catalog: 42 040 products (41 251 simple, 787 configurable), 302 categories, 3 websites + admin, 20 catalog rules.
- Magento 2.4.9 Community, PHP 8.5.12, MariaDB 10.11.19, serial reindex (`MAGE_INDEXER_THREADS_COUNT` unset),
  indexers in schedule mode with an empty backlog, no cron, no traffic.
- `bin/magento` pointed at the clone with `MAGENTO_DC__OVERRIDE` (database `magento_bench`, cache prefix `bch_` on
  Valkey databases 2 and 3, OpenSearch prefix `magento2_bench`), see the README of the module.

## Noise: two baselines in a row (SPEC 5.8)

| Indexer | Series | `wall_time_s` core-a | `wall_time_s` core-b | Δ | Output |
|---|---|---:|---:|---:|---|
| `catalog_product_price` | warm-up + 3 repeats each, median | 38.791 | 38.661 | -0.3% | MATCH (488 163 rows) |
| `catalog_category_product` | 1 run each | 1 258.133 | 1 254.511 | -0.3% | MATCH (264 922 rows) |

Both under the 15 % limit. Every server counter (`handler_*`, temporary tables, statements) was identical between
the series; only `slow_queries` moved, 14 → 15 for the price indexer (+7.1 %, `same` at the 10 % threshold).

Price repeats ranged 38.616–39.017 s (spread 1.0 %). The global MD5 of the price snapshots was
`a189688e2496b75d567bdf2c7aae3add` both times, of the category snapshots `e70068a3d0213b95503bfc900dac2cb2`.

## Other checks

- **Partial run** of `catalog_product_price`: 500 IDs, seed 42, 3 repeats, median 1.153 s, 210 statements. The
  snapshot after it still matches `core-a`.
- **Profiled run** (`--profile`, mage-os/module-profiler 1.0.0): 39.570 s against 38.7 s plain (about +2 %). Where
  the full price reindex spends its time:

  | Timer | Calls | ms | Share |
  |---|---:|---:|---:|
  | `SQL:INSERT (catalog_product_index_price_temp +18)` | 10 | 20 470 | 52 % |
  | `SQL:INSERT (catalog_product_index_price_replica)` | 12 | 9 045 | 23 % |
  | `SQL:DELETE (catalog_product_index_price_temp +1)` | 31 | 6 523 | 16 % |
  | `SQL:UPDATE (catalog_product_index_price_temp +2)` | 11 | 990 | 3 % |

- **Allowed hosts**: with `MAGE_MODE=production` forced through the override, `snapshot` and `run` refused with
  exit 1 and measured nothing; with `indexer_bench/allowed_hosts = [m249-php-fpm, magento_bench]` they ran.
- `catalog_category_product` spends 1 257 of its 1 258 s in 217 statements: almost all of it is SQL. Worth a
  profiled run before deciding anything in Phase C (SPEC 6.4 recommends `SimpleMage_CategoryProductIndexer`
  instead of a rewrite).

## Exported runs

### catalog_product_price · core-b · run #6

| Started (UTC) | Mode | Kind | Repeat | Status |
|---|---|---|---:|---|
| 2026-11-01 09:48:55 | baseline | full | 1 | ok |

| Metric | Value |
|---|---:|
| wall_time_s | 38.626 |
| peak_memory_mb | 102.5 |
| sql_queries | 284 |
| sql_time_s | 37.805 |
| slow_queries | 14 |
| rows_read | n/a |
| rows_inserted | n/a |
| rows_updated | n/a |
| rows_deleted | n/a |
| tmp_tables | 96 |
| tmp_disk_tables | 1 |
| row_lock_waits | 0 |
| row_lock_time_ms | 0 |
| handler_read_rnd_next | 835 987 |
| handler_read | 12 308 486 |
| handler_write | 984 644 |
| handler_update | 23 712 |
| handler_delete | 8 323 |
| index_rows | 488 163 |

Environment:
- Magento 2.4.9 Community, PHP 8.5.12, MariaDB 10.11.19-MariaDB-ubu2204
- 42 040 products
- host m249-php-fpm

Notes: §5.8 noise check, medium profile

### catalog_category_product · core-b · run #14

| Started (UTC) | Mode | Kind | Repeat | Status |
|---|---|---|---:|---|
| 2026-11-01 10:13:48 | baseline | full | 0 | ok |

| Metric | Value |
|---|---:|
| wall_time_s | 1 254.511 |
| peak_memory_mb | 98.5 |
| sql_queries | 217 |
| sql_time_s | 1 253.438 |
| slow_queries | 9 |
| rows_read | n/a |
| rows_inserted | n/a |
| rows_updated | n/a |
| rows_deleted | n/a |
| tmp_tables | 92 |
| tmp_disk_tables | 3 |
| row_lock_waits | 0 |
| row_lock_time_ms | 0 |
| handler_read_rnd_next | 1 081 001 |
| handler_read | 9 219 466 |
| handler_write | 533 151 |
| handler_update | 7 |
| handler_delete | 191 137 |
| index_rows | 264 922 |

Environment:
- Magento 2.4.9 Community, PHP 8.5.12, MariaDB 10.11.19-MariaDB-ubu2204
- 42 040 products
- host m249-php-fpm

Notes: §5.8 noise check, medium profile
