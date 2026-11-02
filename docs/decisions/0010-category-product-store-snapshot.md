# ADR-0010: `catalog_category_product` selects read a per-store snapshot

- Status: accepted
- Date: 2026-11-01

## Context

With the tmp table emptied by `TRUNCATE` (ADR-0009) the full reindex of the `large` catalog (502 040 products,
5 stores) took 876 s, and the profile put 84 % of it in the three selects, run once per batch of 1 000 products:

| Select, per batch | Time | Per call |
|---|---:|---:|
| anchor (`createAnchorSelect`, 18 tables) | 443 s | 176 ms |
| non-anchor (`getNonAnchorCategoriesSelect`, 14 tables) | 166 s | 66 ms |
| root category (`getAllProducts`, 7 tables) | 129 s | 51 ms |

Every one of them resolves the store view status and visibility of the product (four joins on
`catalog_product_entity_int`), of the children of composite products (four more joins, then `GROUP BY`) and the store
view `is_active`/`is_anchor` of the category (four joins on `catalog_category_entity_int`), in every batch. The values
do not change during the reindex. SimpleMage_CategoryProductIndexer uses permanent snapshot tables for the same
reason; it was read to learn the technique, no code or dependency is taken from it (project decision).

## Decision

- `StoreSnapshot` writes, once per store and full reindex, two TEMPORARY tables on the connection that reindexes the
  store (the same one as the core `_tmp` and category tree tables, so forked workers keep their own):
  - `orangecat_ib_cp_product_<store>`: the products of the store website, enabled and visible in the store view, with
    the store view visibility and `children_enabled` (no child in `catalog_product_relation`, or one child enabled in
    the store view; the filter of `addFilteringByChildProductsToSelect`, which the root select does not apply);
  - `orangecat_ib_cp_category_<store>`: categories with a default `is_active`, with `is_active` and `is_anchor` of the
    store view (`is_anchor` NULL without a default row, as the core inner join).
- `Action\Full` overrides `getAllProducts`, `createAnchorSelect` and `getNonAnchorCategoriesSelect` with the same
  columns and conditions over these tables. `createAnchorSelect` first calls the core one: it sets the current store
  (private) and builds the temporary category tree, which ours joins as the core does.
- The product snapshot select starts from `catalog_product_website`: from `catalog_product_entity`, MariaDB joined
  that table last by a range on `website_id` through a block nested loop, the plan of ADR-0008, and one store took
  minutes. Correction (2026-11-02): the first version also called `Select::useStraightJoin()`, which does nothing in
  Magento (ADR-0012); starting from the website table is what fixed the plan. Forcing the order for real
  (`STRAIGHT_JOIN` on `cpe`) made one store slower, 26 s → 35 s, so the optimizer keeps the order.
- Temporary rather than permanent tables: nothing to keep in sync, no schema, nothing left after a failed run. The
  partial reindex (`Rows` actions) is unchanged; a permanent snapshot would be needed to speed it up as well.

## Consequences

- Overridden protected methods of the core: `getAllProducts`, `createAnchorSelect`, `getNonAnchorCategoriesSelect`,
  plus those of ADR-0009. Output identity is checked by `Test/Integration/Indexer/CategoryProductFullIdentityTest`
  (store view overrides of status, visibility, `is_anchor`, `is_active`, inactive branch, product outside the website,
  product in two categories of one anchor branch, parent with children enabled in one store view only) and by
  benchmark snapshots on real catalogs.
- `entity_id` is the link field in Magento Open Source; the snapshot joins use `MetadataPool` link fields, but the
  staging (`row_id`) case is not tested here.

## Measurement

Batches of 1 000, MariaDB 10.11.19, `magento_bench*` clones.

| Catalog | Core | TRUNCATE (ADR-0009) | TRUNCATE + snapshot | Output |
|---|---:|---:|---:|---|
| large, 502 040 products, 5 stores | 1 852 s (1 796 s with the default batch) | 876 s | **461 s** (−75 %) | MATCH, 6 063 046 rows |
| medium, 42 040 products, 3 stores | 33.8 s | 28.4 s | **18.7 s** (−45 %) | MATCH, 264 922 rows |
