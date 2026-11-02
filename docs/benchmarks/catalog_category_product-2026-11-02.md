# catalog_category_product with Indexer Boost — 2026-11-02

Magento 2.4.9 Community, PHP 8.5.12, MariaDB 10.11.19 (`optimizer_use_condition_selectivity = 4`, `in_predicate_conversion_threshold = 1000`, `innodb_buffer_pool_size` 128 MB), serial reindex, `indexer-bench:run` (full reindex of the indexer only). Both catalogs are generated fixtures (`setup:performance:generate-fixtures`), not a copy of a real store. Rewrite: IndexerBoost ADR-0009 (TRUNCATE of the tmp table) and ADR-0010 (per-store snapshots).

## large: 502 040 products, 5 store views (`magento_bench_large`)

| Label | Setup | Run | `wall_time_s` | Output |
|---|---|---:|---:|---|
| `core-large-default` | core, default batch (100 000) | #9 | 1 796.362 | baseline |
| `core-large-b1000` | core, `batch_size` 1 000 | #7 | 1 851.940 | MATCH |
| `boost-large-b1000` | TRUNCATE only, batch 1 000 | #10 | 875.711 | MATCH |
| `boost-snap-large-b1000` | TRUNCATE + snapshots, batch 1 000 (current code) | #12 | **461.256** | MATCH (6 063 046 rows) |

Run #21 (`boost-joinorder-large`, 526.7 s) forced the join order of the product snapshot; that made it slower and was removed (ADR-0010, correction).

## medium: 42 040 products, 3 store views (`magento_bench`)

Baseline `core-a` is the core with its default batch of 100 000, which hits the MariaDB nested-loop plan of ADR-0008 (with a batch of 1 000 the core takes 33.8 s).

### catalog_category_product: rep-boost-2026-11-02 vs core-a

Baseline: core-a (median of 1 run). Candidate: rep-boost-2026-11-02 (median of 3 runs).

Fingerprint: OK (same catalog, database variables and versions)

| Metric | Baseline | Candidate | Δ | Verdict |
|---|---:|---:|---:|---|
| wall_time_s | 1 258.133 | 15.201 | -98.8% | ✅ improved |
| peak_memory_mb | 98.5 | 131 | +33.0% | ❌ regression |
| sql_queries | 217 | 252 | +16.1% | ❌ regression |
| sql_time_s | 1 257.039 | 14.136 | -98.9% | ✅ improved |
| slow_queries | 9 | 3 | -66.7% | ✅ improved |
| rows_read | n/a | n/a | — | n/a |
| rows_inserted | n/a | n/a | — | n/a |
| rows_updated | n/a | n/a | — | n/a |
| rows_deleted | n/a | n/a | — | n/a |
| tmp_tables | 92 | 121 | +31.5% | ❌ regression |
| tmp_disk_tables | 3 | 3 | +0.0% | same |
| row_lock_waits | 0 | 0 | +0.0% | same |
| row_lock_time_ms | 0 | 0 | +0.0% | same |
| handler_read_rnd_next | 1 081 001 | 992 451 | -8.2% | same |
| handler_read | 9 219 466 | 4 548 144 | -50.7% | ✅ improved |
| handler_write | 533 151 | 608 155 | +14.1% | ❌ regression |
| handler_update | 7 | 7 | +0.0% | same |
| handler_delete | 191 137 | 0 | -100.0% | ✅ improved |
| index_rows | 264 922 | 264 922 | +0.0% | same |

Output: MATCH (264 922 rows)

Regressions over 10%: peak_memory_mb, sql_queries, tmp_tables, handler_write

**Peak memory.** The Indexer Boost runs above started right after `indexer-boost:toggle`, which cleans the config cache: a process that rebuilds it keeps about 28 MB more allocated, whatever runs afterwards. Checked on `catalog_product_price`: Indexer Boost with a warm cache 104.5 MB (run #98), core with a cold cache 131 MB (run #99). It is not a cost of the rewrite. `sql_queries`, `tmp_tables` and `handler_write` grow by the statements the rewrite adds (one `TRUNCATE` per batch, the snapshots).

Medium-catalog wall times vary by about ±5 s between series on this machine (core price 40.6–42.5 s, Indexer Boost price 35.0–40.8 s); the medians above are from consecutive series of 3.

## Also checked

- 2 processes (`MAGE_INDEXER_THREADS_COUNT=2`, file cache): 10.8 s, MATCH (run #62). No PHPUnit test: forked children run the shutdown handlers of the integration framework, which
  delete its test modules.
- Partial reindex (`run-partial`, 500 IDs, seed 42) with the rewrite on: MATCH (run #54). The partial path is the core one.
- `indexer:reindex` with the rewrite on: MATCH; mview (`indexer_update_all_views`) after product changes: same rows as a full core reindex.
