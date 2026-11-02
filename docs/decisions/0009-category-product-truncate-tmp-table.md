# ADR-0009: `catalog_category_product` full reindex empties its tmp table with TRUNCATE

- Status: accepted
- Date: 2026-11-01

## Context

ADR-0008 fixed `catalog_category_product` on the `medium` catalog (42 040 products) by configuration. On the `large`
catalog (502 040 products, 5 stores, 3 002 categories, MariaDB 10.11.19) the full reindex still took 1 852 s with
batches of 1 000 and 1 796 s with the core default of 100 000.

The profile of the batch-1 000 run (mage-os/module-profiler) put 57 % of the time outside the selects:

| Step, per batch | Time | Share |
|---|---:|---:|
| `INSERT … SELECT` from `_tmp` to `_replica` | 570 s | 30 % |
| `DELETE FROM _tmp` before the batch | 532 s | 28 % |
| anchor select | 440 s | 23 % |
| non-anchor select | 182 s | 9 % |
| root category select | 128 s | 7 % |

`Full::reindexCategoriesBySelect()` empties the TEMPORARY `_tmp` table with `DELETE` before every batch. InnoDB keeps
the deleted rows as delete-marked records, so every batch scans the rows of all the previous ones: the `DELETE` went
from 0.3 ms on the first batch to 78 ms after 1 509 batches, and the copy to `_replica` grew the same way. The cost
grows with the square of the batch count, which the `medium` catalog (42 batches per store) did not show.

Reproduced in plain SQL on the same server: 1 500 cycles of inserting 800 rows into a TEMPORARY copy of the index
table and emptying it. With `DELETE` a cycle went from 20 ms to 122 ms; with `TRUNCATE` it stayed at about 8 ms.

## Decision

- Own full action, `Model/Indexer/CategoryProduct/Action/Full`, extending the core one. The core loop is private, so
  the three protected methods that call it (`reindexRootCategory`, `reindexAnchorCategories`,
  `reindexNonAnchorCategories`) are overridden with the same body and call our loop, `BatchWriter`.
- `BatchWriter` repeats the core steps (batch of IDs, `INSERT … ON DUPLICATE` into `_tmp`, copy to `_replica`) and
  empties `_tmp` with a plain `TRUNCATE TABLE`. Not `AdapterInterface::truncateTable()`: it checks that the table
  exists first, and that check misses TEMPORARY tables.
- `CategoryProductFullPlugin`, around `Full::execute()` of the core, runs it through the `Switcher` with the
  `catalog_category_product` flag (default off). `catalog_product_category` runs the same core action for a full
  reindex, so the flag covers both. Our action extends the core one, so the plugin intercepts it too and lets it run
  its own body.
- Everything else is the core: selects, tables, stores in parallel (`ProcessManager`), table switch. There is no
  preparation step, so no `PreparationException`: a failure is rethrown, as the core does.

## Consequences

- Depends on the protected methods above and on `getMainTmpTable()`/`getMainReplicaTable()` of the table maintainer.
  A core change to them must be checked against this class (output identity by snapshot, SPEC 6.2.1).
- Small batches become cheap, so the ADR-0008 recommendation (1 000 on MariaDB) no longer trades the root-query plan
  for a slower loop.
- Partial reindexes (`Rows` actions) do not use this loop and are unchanged.

## Measurement

| Catalog | Setup | Full reindex | Output |
|---|---|---:|---|
| large | core, batch 100 000 (default) | 1 796 s | reference (`core-large-default`) |
| large | core, batch 1 000 | 1 852 s | — |
| large | Indexer Boost, batch 1 000 | **876 s** (−53 % vs core batch 1 000, −51 % vs default) | MATCH, 6 063 046 rows |
| medium | core, batch 1 000 | 33.8 s | reference (`cfg-batch1000`) |
| medium | Indexer Boost, batch 1 000 | 28.4 s (−14 %, median of 3, idle server) | MATCH, 264 922 rows |
| medium | Indexer Boost, batch 1 000, 2 threads | 22.4 s (server busy, not comparable) | MATCH, 264 922 rows |

On `large` the 38 047 statements are now dominated by the selects themselves (anchor, non-anchor, root): the next
step for this indexer is cheaper selects (store-resolved snapshots, SPEC 6.3 A), measured against this result.
