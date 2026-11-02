# catalog_category_product — 2026-11-01

`magento_bench`, `medium` profile (42 040 products, 302 categories, 3 store views), Magento 2.4.9 Community,
PHP 8.5.12, MariaDB 10.11.19 (`optimizer_use_condition_selectivity = 4`, `in_predicate_conversion_threshold = 1000`),
serial reindex. Decision: IndexerBoost ADR-0008.

## Full reindex

| Label | Setup | `wall_time_s` | Output vs `core-a` |
|---|---|---:|---|
| `core-a` | core defaults (batch 100 000) | 1 258.133 | — |
| `core-b` | core defaults | 1 254.511 | MATCH |
| `cfg-batch1000` | `indexer/batch_size/catalog_category_product = 1000` | 33.795 | MATCH (264 922 rows) |

37× faster with the same index content.

## Where the time goes

Core defaults (profiled run #15, 1 256.8 s):

| Statement | Calls | Time |
|---|---:|---:|
| `INSERT … _tmp` from the root category query (7 tables), per store | 3 | 1 238.3 s |
| `INSERT … _tmp` from the anchor query (18 tables) | 3 | 6.8 s |
| `INSERT … _tmp` from the non-anchor query (14 tables) | 3 | 4.4 s |

Batch of 1 000 (profiled run #17, 34.4 s):

| Statement | Time |
|---|---:|
| `INSERT … _tmp` anchor | 11.1 s |
| `INSERT … _replica` (publish) | 5.9 s |
| `INSERT … _tmp` non-anchor | 5.8 s |
| `DELETE … _tmp` | 4.4 s |
| `INSERT … _tmp` root category | 4.2 s |

## The root category query

The same statement with all 42 040 IDs in `cp.entity_id IN (…)`:

| Variant | Plan for `catalog_product_website` | Time |
|---|---|---:|
| as the core runs it | range on `website_id`, 61 224 rows, block nested loop | ~400 s |
| `in_predicate_conversion_threshold = 0` | same | 400 s |
| `optimizer_use_condition_selectivity = 1` | `eq_ref` on the primary key | 1.1 s |
| 1 000 IDs per batch | `eq_ref` | 14 ms per batch |
| `cp.entity_id BETWEEN …` instead of `IN (…)` | `eq_ref` | 0.7 s |
