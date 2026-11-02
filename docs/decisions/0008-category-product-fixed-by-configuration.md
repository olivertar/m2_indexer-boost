# ADR-0008: `catalog_category_product` fixed by configuration, not rewritten

- Status: accepted
- Date: 2026-11-01

## Context

Phase C started with `catalog_category_product`, the slowest indexer on the `medium` benchmark catalog (42 040
products, MariaDB 10.11.19): 1 258 s per full reindex. The plan was an own rewrite with store-resolved snapshot
tables (SPEC 6.3 A), assuming the cost was the EAV resolution of the anchor query (13 joins). No third-party code or
module is used (project decision, overriding SPEC 6.4.5).

The profile of the core run (mage-os/module-profiler) said otherwise:

- 98 % of the time was one statement per store: the root category query (`AbstractAction::getAllProducts()`,
  7 tables), about 400 s each. The anchor query took 2.4 s and the non-anchor one 1.5 s.
- `Full::reindexCategoriesBySelect()` adds `cp.entity_id IN (<batch>)`; the batch is
  `indexer/batch_size/catalog_category_product`, 100 000 by default, so the list held all 42 040 IDs.
- With that list MariaDB joined `catalog_product_website` last by a range on `website_id` (61 224 rows) through a
  block nested loop. With 1 000 IDs, or a `BETWEEN` range, it used `eq_ref` on the primary key: 14 ms per batch.
- `in_predicate_conversion_threshold = 0` did not change the plan. `optimizer_use_condition_selectivity = 1` did:
  400 s → 1.1 s for the same statement.

## Decision

- No rewrite of `catalog_category_product`. The doctor recommends the fix instead:
  - `BATCH_SIZE` warns on MariaDB when a `catalog_category_product` batch can hold more than 1 000 product IDs and
    gives `'indexer' => ['batch_size' => ['catalog_category_product' => 1000]]`;
  - `MARIADB_OPTIMIZER` keeps recommending `optimizer_use_condition_selectivity = 1`, now with this measurement.
- The snapshot tables planned for it are not created. The Phase C infrastructure (flags, `indexer-boost:toggle`,
  `Switcher`, `PreparationException`) stays for the next rewrites.

## Measurement

| Setup | Full reindex | Output |
|---|---:|---|
| Core defaults | 1 258 s | reference (`core-a`) |
| `indexer/batch_size/catalog_category_product = 1000` | 33.8 s | MATCH, 264 922 rows |

With batches of 1 000 the remaining time has no dominant statement: anchor inserts 11 s, copy to `_replica` 5.9 s,
non-anchor 5.8 s, `_tmp` deletes 4.4 s, root category 4.2 s. A rewrite could save part of that, not another order
of magnitude.

## Consequences

- Zero own code in the indexer path: nothing to keep in step with core changes.
- The gain needs a configuration change per installation; the doctor flags it.
- MySQL was not measured; the warning is MariaDB only.
- The `indexer/batch_size/catalog_category_product` path differs from `indexer/batch_rows_count/...`, which looks
  plausible but is ignored by the core.
