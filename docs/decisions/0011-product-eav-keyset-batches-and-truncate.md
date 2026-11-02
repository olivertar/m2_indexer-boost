# ADR-0011: `catalog_product_attribute` full reindex batches by key and truncates its work table

- Status: accepted
- Date: 2026-11-01

## Context

On the `large` catalog (502 040 products, 5 stores, MariaDB 10.11.19) the full EAV reindex took 664 s, 51 times the
`medium` catalog (13.6 s) for 12 times the products. Profile and a `processlist` sample every 0.5 s (914 samples):

| Statement, per batch of 1 000 products | Share |
|---|---:|
| `INSERT … SELECT` of the select attributes (13 joins) | 22 % |
| `SELECT DISTINCT e.entity_id … ORDER BY entity_id LIMIT 1000 OFFSET <n>` | 22 % |
| `INSERT` into the `transit_` table (parents of composite products) | 17 % |
| copy of `_temp` to `_replica` (`syncData`) | 14 % |
| `DELETE FROM catalog_product_index_eav_temp` (`clearTemporaryIndexTable`) | 13 % |
| multiselect values read into PHP | 8 % |

Two of them grow with the square of the batch count:

- `Eav\Action\Full::execute()` asks `Query\Generator` for `NON_UNIQUE_FIELD_ITERATOR`, which pages with `OFFSET`:
  every batch reads all the previous IDs again. The core needs it with staging, where `entity_id` repeats in
  `catalog_product_entity` (one row per version); in Magento Open Source `entity_id` is the primary key.
- The work table (`catalog_product_index_eav_temp`, TEMPORARY, created by `TemporaryTableStrategy`) is emptied with
  `DELETE` before every batch, with the effect described in ADR-0009 for `catalog_category_product`. The copy to
  `_replica` reads the same table and grows with it.

## Decision

- Own full action, `Model/Indexer/ProductEav/Action/Full`, extending the core one. `execute()` has the same steps as
  the core one (its collaborators are private, so they are injected again), with two changes:
  - `UNIQUE_FIELD_ITERATOR` (`entity_id > last … LIMIT n`) when the link field is the identifier, as in Magento Open
    Source; the core `NON_UNIQUE_FIELD_ITERATOR` otherwise (staging);
  - `TemporaryTable::truncate()` on the indexer work table before `reindexEntities()`, so the core `DELETE` finds it
    empty. Plain `TRUNCATE TABLE`, not the adapter one (it misses TEMPORARY tables). Only in the full reindex: it
    runs outside any transaction, and `TRUNCATE` commits implicitly.
- `ProductEavFullPlugin`, around `Eav\Action\Full::execute()`, through the `Switcher` with the
  `catalog_product_attribute` flag (default off), with the same guard as ADR-0009 for our own subclass.
- Everything else is the core: the select, multiselect and relation statements, `syncData`, the table switch.

## Consequences

- Depends on `getIndexers()`, `processRelations()` and `syncData()` of the core action and on `getIdxTable()` of the
  EAV resource models. Identity is checked by `Test/Integration/Indexer/ProductEavFullIdentityTest` (batches of two,
  so a parent and its children fall in different batches; select, multiselect and decimal values by store view;
  disabled, not visible and disabled-in-one-store products) and by benchmark snapshots.
- The remaining time is the core statements themselves (select attributes, relations, copy to `_replica`).

## Measurement

| Catalog | Core | Indexer Boost | Output |
|---|---:|---:|---|
| large, 502 040 products, 5 stores | 664 s | **268 s** (−60 %) | MATCH, 1 555 093 rows |
| medium, 42 040 products, 3 stores | 13.6 s (median of 3) | **12.1 s** (−11 %, median of 3) | MATCH, 82 803 rows |
