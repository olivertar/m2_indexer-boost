# catalog_product_attribute with Indexer Boost — 2026-11-02

Magento 2.4.9 Community, PHP 8.5.12, MariaDB 10.11.19 (`optimizer_use_condition_selectivity = 4`, `in_predicate_conversion_threshold = 1000`, `innodb_buffer_pool_size` 128 MB), serial reindex, `indexer-bench:run` (full reindex of the indexer only). Both catalogs are generated fixtures (`setup:performance:generate-fixtures`), not a copy of a real store. Rewrite: IndexerBoost ADR-0011 (batches by key, TRUNCATE of the work table).

## large: 502 040 products, 5 store views (`magento_bench_large`)

| Label | Run | `wall_time_s` | Output |
|---|---:|---:|---|
| `core-large` | #3 | 663.578 | baseline |
| `boost-large` | #16 | **268.276** (−60 %) | MATCH (1 555 093 rows) |

## medium: 42 040 products, 3 store views (`magento_bench`)

### catalog_product_attribute: rep-boost-2026-11-02 vs rep-core-2026-11-02

Baseline: rep-core-2026-11-02 (median of 3 runs). Candidate: rep-boost-2026-11-02 (median of 3 runs).

Fingerprint: OK (same catalog, database variables and versions)

| Metric | Baseline | Candidate | Δ | Verdict |
|---|---:|---:|---:|---|
| wall_time_s | 13.86 | 12.445 | -10.2% | ✅ improved |
| peak_memory_mb | 100.5 | 129 | +28.4% | ❌ regression |
| sql_queries | 1 516 | 1 774 | +17.0% | ❌ regression |
| sql_time_s | 12.81 | 11.547 | -9.9% | same |
| slow_queries | 0 | 0 | +0.0% | same |
| rows_read | n/a | n/a | — | n/a |
| rows_inserted | n/a | n/a | — | n/a |
| rows_updated | n/a | n/a | — | n/a |
| rows_deleted | n/a | n/a | — | n/a |
| tmp_tables | 490 | 576 | +17.6% | ❌ regression |
| tmp_disk_tables | 45 | 45 | +0.0% | same |
| row_lock_waits | 0 | 0 | +0.0% | same |
| row_lock_time_ms | 0 | 0 | +0.0% | same |
| handler_read_rnd_next | 912 333 | 827 585 | -9.3% | same |
| handler_read | 6 060 361 | 4 170 733 | -31.2% | ✅ improved |
| handler_write | 169 687 | 169 687 | +0.0% | same |
| handler_update | 4 | 4 | +0.0% | same |
| handler_delete | 84 834 | 0 | -100.0% | ✅ improved |
| index_rows | 82 803 | 82 803 | +0.0% | same |

Output: MATCH (82 803 rows)

Regressions over 10%: peak_memory_mb, sql_queries, tmp_tables

**Peak memory.** The Indexer Boost runs above started right after `indexer-boost:toggle`, which cleans the config cache: a process that rebuilds it keeps about 28 MB more allocated, whatever runs afterwards. Checked on `catalog_product_price`: Indexer Boost with a warm cache 104.5 MB (run #98), core with a cold cache 131 MB (run #99). It is not a cost of the rewrite. `sql_queries`, `tmp_tables` and `handler_write` grow by the statements the rewrite adds (one `TRUNCATE` per batch, the snapshots).

Medium-catalog wall times vary by about ±5 s between series on this machine (core price 40.6–42.5 s, Indexer Boost price 35.0–40.8 s); the medians above are from consecutive series of 3.

## Also checked

- Partial reindex (500 IDs, seed 42) with the rewrite on: MATCH (run #53). The partial path is the core one.
- `indexer:reindex` with the rewrite on: 88 `TRUNCATE` statements (2 with it off), MATCH; mview after product changes: same rows as a full core reindex.
- The EAV indexer does not fork: `MAGE_INDEXER_THREADS_COUNT` does not apply.
