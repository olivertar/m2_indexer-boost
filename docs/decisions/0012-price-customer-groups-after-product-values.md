# ADR-0012: `catalog_product_price` full reindex reads customer groups after the product values

- Status: accepted
- Date: 2026-11-02

## Context

On the `large` catalog (502 040 products, 5 websites, 4 customer groups, 10 008 165 index rows, MariaDB 10.11.19) the
full price reindex took 1 024 s and 1 039 s. A `processlist` sample every 0.5 s (1 365 samples) split it into:

| Statement, per batch of 5 000 products | Share |
|---|---:|
| `INSERT … SELECT` of `BaseFinalPrice::getQuery()` into the price temp table | 47 % |
| copy of the temp table to `_replica` | 28 % |
| `DELETE` of out of stock products (MSI `ModifySelectInProductPriceIndexFilter`, once per website) | 20 % |

No statement grows with the batch count (the core creates and drops its temp table per batch and batches by key).

`EXPLAIN` of the base select: MariaDB reads `customer_group` (4 rows) first, then the product, website and the 12
EAV joins (status, tax class, price, special price and dates, default and store values), so it looks every value up
once per customer group. One batch: 4.8 s.

## Decision

- `PriceFullPlugin`, around the core `Price\Action\Full::execute()`, through the `Switcher` with the
  `catalog_product_price` flag (default off). When on, it runs the core action inside `FullReindexScope`.
- `PriceQueryPlugin`, after `BaseFinalPrice::getQuery()`, only inside that scope: the `cg` join becomes a
  `STRAIGHT_JOIN` (`JoinOrder`), so `customer_group` is read after every table written before it (product, website,
  EAV values) and only multiplies the rows. The optimizer still orders the other tables; the batch `IN()` keeps its
  semi-join, so `catalog_product_entity` is not scanned. One batch: 4.8 s → 2.6 s, same rows.
- `Select::useStraightJoin()` cannot be used: `SelectRenderer` has no renderer for that part, so it never reaches
  the SQL. `FromRenderer` renders the join type as written, which is what `JoinOrder` changes, for one alias only.
- Partial reindexes (`Rows`) and the core action otherwise are unchanged.

## Consequences

- Depends on the `cg` alias of `BaseFinalPrice::getQuery()`; if it disappears, `JoinOrder` does nothing and the core
  plan stays. Joins added by other modules through `prepare_catalog_product_index_select` keep their place after it.
- Not changed, for now: the copy to `_replica` (cost of its three secondary indexes) and the MSI stock `DELETE`, which
  could run once per stock instead of once per website (one statement for the 5 websites of the default stock: 1.85 s
  → 0.63 s per batch), but would mean replacing an MSI plugin.
- Identity is checked by `Test/Integration/Indexer/PriceFullIdentityTest` (batches of two; special price with store
  view dates, tier prices per group, for all groups, per website and as percentage; disabled and out of stock
  products; a configurable product) and by benchmark snapshots. `JoinOrderTest` checks the rendered SQL.

## Measurement

| Catalog | Core | Indexer Boost | Output |
|---|---:|---:|---|
| large, 502 040 products, 5 websites | 1 039 s | **790 s** (−24 %) | MATCH, 10 008 165 rows |
| medium, 42 040 products, 3 websites | 39.5 s (median of 3) | **33.9 s** (−14 %, median of 3) | same price and tier price rows (md5); `catalog_product_index_website` differs only by the date of the run |
