# catalog_product_price with Indexer Boost — 2026-11-02

Magento 2.4.9 Community, PHP 8.5.12, MariaDB 10.11.19 (`optimizer_use_condition_selectivity = 4`, `in_predicate_conversion_threshold = 1000`, `innodb_buffer_pool_size` 128 MB), serial reindex, `indexer-bench:run` (full reindex of the indexer only). Both catalogs are generated fixtures (`setup:performance:generate-fixtures`), not a copy of a real store. Rewrite: IndexerBoost ADR-0012 (customer groups read after the product values). Price dimension mode `none` unless stated.

## large: 502 040 products, 5 websites, 4 customer groups (`magento_bench_large`)

| Label | Run | `wall_time_s` | Output |
|---|---:|---:|---|
| `core-large` | #6 | 963.441 | — |
| `core-price-sampled` | #17 | 1 023.708 | — |
| `boost-large` (toggle rejected by a stale cache: core) | #18 | 1 039.113 | baseline `core-large-d2` |
| `boost-large` (first version, no join order) | #19 | 970.733 | MATCH |
| `boost-joinorder-large` (current code) | #20 | **790.125** | MATCH (10 008 165 rows) |

Core range 963–1 039 s (median 1 024 s): −23 % against the median, −18 % against the fastest core run.

## medium: 42 040 products, 3 websites, 4 customer groups (`magento_bench`)

### catalog_product_price: rep-boost-2026-11-02 vs rep-core-2026-11-02

Baseline: rep-core-2026-11-02 (median of 3 runs). Candidate: rep-boost-2026-11-02 (median of 3 runs).

Fingerprint: OK (same catalog, database variables and versions)

| Metric | Baseline | Candidate | Δ | Verdict |
|---|---:|---:|---:|---|
| wall_time_s | 41.721 | 35.898 | -14.0% | ✅ improved |
| peak_memory_mb | 104.5 | 131 | +25.4% | ❌ regression |
| sql_queries | 284 | 284 | +0.0% | same |
| sql_time_s | 40.817 | 34.694 | -15.0% | ✅ improved |
| slow_queries | 15 | 15 | +0.0% | same |
| rows_read | n/a | n/a | — | n/a |
| rows_inserted | n/a | n/a | — | n/a |
| rows_updated | n/a | n/a | — | n/a |
| rows_deleted | n/a | n/a | — | n/a |
| tmp_tables | 96 | 96 | +0.0% | same |
| tmp_disk_tables | 1 | 1 | +0.0% | same |
| row_lock_waits | 0 | 0 | +0.0% | same |
| row_lock_time_ms | 0 | 0 | +0.0% | same |
| handler_read_rnd_next | 835 987 | 712 207 | -14.8% | ✅ improved |
| handler_read | 12 308 506 | 9 406 841 | -23.6% | ✅ improved |
| handler_write | 984 644 | 984 644 | +0.0% | same |
| handler_update | 23 712 | 23 712 | +0.0% | same |
| handler_delete | 8 323 | 8 323 | +0.0% | same |
| index_rows | 488 163 | 488 163 | +0.0% | same |

Output: MATCH (488 163 rows)

Regressions over 10%: peak_memory_mb

**Peak memory.** The Indexer Boost runs above started right after `indexer-boost:toggle`, which cleans the config cache: a process that rebuilds it keeps about 28 MB more allocated, whatever runs afterwards. Checked on `catalog_product_price`: Indexer Boost with a warm cache 104.5 MB (run #98), core with a cold cache 131 MB (run #99). It is not a cost of the rewrite. `sql_queries`, `tmp_tables` and `handler_write` grow by the statements the rewrite adds (one `TRUNCATE` per batch, the snapshots).

Medium-catalog wall times vary by about ±5 s between series on this machine (core price 40.6–42.5 s, Indexer Boost price 35.0–40.8 s); the medians above are from consecutive series of 3.

## Price dimensions, `website_and_customer_group` (medium)

| Run | Setup | `wall_time_s` | 12 dimension tables |
|---|---|---:|---|
| #72 | core, 1 process | 82.889 | 488 160 rows, checksum `bf9aa2d4b337` |
| #73 | Indexer Boost, 1 process | 78.704 | same checksum |
| #74 | Indexer Boost, 2 processes (file cache) | 46.782 | same checksum |

In this mode every dimension already selects one customer group, so the rewrite has little left to gain.

## Also checked

- Partial reindex (500 IDs, seed 42) with the rewrite on: same price rows (run #55). The partial path is the core one.
- `indexer:reindex` with the rewrite on: every sampled base price `INSERT` used `STRAIGHT_JOIN customer_group` (29 samples, 0 of 40 with it off), same rows.
- mview after product changes: same rows with the rewrite on and off. Both leave catalog rule prices stale until `catalogrule_product` runs (core order of the views), unlike a full reindex.
