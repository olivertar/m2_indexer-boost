# What I learned making Magento reindexes 74% faster without changing a single row

*Suggested cover image: `images/benchmark-large.svg` (export to PNG at 1200 px). Suggested tags: #Magento #AdobeCommerce
#MageOS #Ecommerce #PHP #MariaDB #Performance*

---

Ask anyone who runs a large Magento store about indexing and you will hear the same stories. A full reindex that
takes most of the night. An indexer stuck in "working" since a deployment killed it. A CSV import after which the
indexers run one after another for an hour. And nobody can say whether last night's reindex was slower than usual,
because nothing records it.

Over the last weeks I built **Indexer Boost**, a set of Magento 2 modules that diagnose, monitor and speed up
indexing. Here is what it does, what I measured, and a few things I did not expect.

## Measure first, and prove the output is the same

Before touching any indexer I built the tool to judge the result: a benchmark module that runs a reindex on a copy
of the database, stores its metrics with a fingerprint of the environment (versions, database variables, catalog
size, threads, batch sizes), and dumps every index table in a canonical order with a checksum.

That last part matters most. A faster indexer that writes one wrong row is a bug that costs money on the storefront.
Every result below compares two checksums of the index tables. Each one says MATCH, on 502 040 products and 5 store
views.

## The results

On a generated catalog of 502 040 products (Magento 2.4.9, PHP 8.5, MariaDB 10.11, one process):

- **Category products** (`catalog_category_product`): 1 796 s → 461 s, **−74 %**
- **Product attributes** (`catalog_product_attribute`): 664 s → 268 s, **−60 %**
- **Product prices** (`catalog_product_price`): 1 024 s → 790 s, **−23 %**

None of the three is a clever algorithm. Each fixes a cost that grows faster than the catalog:

1. **`DELETE` on a temporary table, before every batch.** The core category indexer empties its work table with
   `DELETE` before each batch. On InnoDB that cost grows with the table, so the total grows with the square of the
   number of batches. `TRUNCATE` is constant. The catch: Magento's `truncateTable()` cannot see TEMPORARY tables, so
   it has to be issued directly.
2. **Resolving the same store values in every batch.** The category query joins 13 tables to resolve status,
   visibility, `is_active` and `is_anchor` per store view, in every batch. Building small per-store snapshots once per
   reindex and joining them by primary key took the large catalog from 876 s to 461 s.
3. **`LIMIT … OFFSET` batching.** The attribute indexer pages with an offset, so each batch rereads everything before
   it. Paging by key instead removes that.
4. **Join order.** For prices, MariaDB chose to read customer groups first and looked every product value up once per
   group. Forcing that one join to come later was enough. A detail that cost me an afternoon:
   `Select::useStraightJoin()` does nothing in Magento, because the select renderer ignores it. The join type of a
   single `FROM` entry has to be set to `straight_join` instead.

## Sometimes the problem is configuration

On a medium catalog (42 040 products) the category reindex took **1 258 seconds**. Profiling showed 98 % of that
time in one query: the core sends 100 000 product IDs per batch, and MariaDB 10.x answered that `IN()` list with a
nested-loop plan. With batches of 1 000, the same core code took **34 seconds** and wrote the same rows.

That changed how I approached the project. Rewriting code is the last step. The first step is a diagnosis that finds
problems like this one on any store.

## A doctor that only reads

`bin/magento indexer-boost:doctor` runs 26 checks, among them:

- indexers left in "Update on Save", and exactly what an import does to each of them;
- index cron jobs that do not run or fall behind, changelog backlog, full reindexes that repeat or overlap;
- stuck indexers (status `working`, no lock held, no write running on their tables);
- database memory, server version and the MariaDB optimizer settings that change these plans;
- data that slows indexing for nothing: orphan EAV rows, store values identical to the default, attribute flags no
  product uses.

Every finding comes with the measured values, why it matters and the exact fix. The doctor never changes anything:
checks receive a database connection that rejects anything but reads, and a test runs every check against a database
and filesystem that record any write attempt.

## Imports deserve their own paragraph

While writing the documentation I went back to a question I hear often: why do indexers pile up after an import?
The answer in the 2.4.9 code is specific. With indexers in "Update on Save", the product import reindexes categories,
prices and stock for every batch of rows (so the import itself slows down), invalidates the attribute and catalog
rule indexers when it finishes (so cron runs their full reindexes one after another), and does not refresh the
search index at all, which keeps stale data until its next full reindex.

The doctor now says this per store, and a new check looks at the last 24 hours for repeated full reindexes,
overlapping ones and index cron jobs that fall behind.

## Safe to switch on, and to switch off

The rewrites are plugins around the core actions, off by default, and switched per indexer at run time:
`bin/magento indexer-boost:toggle catalog_product_price on`. Turning one off brings the core code back on the next
reindex, without recompiling. A passive monitor records every real reindex in production, so you can compare before
and after.

## Asking in plain language

The last piece was an add-on for [Mago](https://askmago.com/), an AI assistant inside the Magento admin. An
administrator can ask "Why is my reindex slow?" and get every finding with its fix, or ask to switch a rewrite on and
confirm it on a card that quotes the compatibility check. Testing it taught me something about tools for language
models: a good tool description matters as much as the code. When I asked for the "indexer status" in English, the
model picked a generic indexer tool over mine until the description said when to prefer it.

## What I would tell another Magento team

- Measure your indexers before optimizing them, and keep the history.
- Check the batch size and the MariaDB optimizer settings before anything else.
- Put every indexer on "Update by Schedule", especially if you import.
- When you optimize, prove the output is identical. A checksum per index table is cheap.

If you run Magento and indexing is a pain point, I'd like to hear what you are seeing. Comments and messages are
open.
