# Indexer Boost documentation

Indexer Boost diagnoses, monitors and speeds up Magento 2 indexing. It is made of four modules:

| Module | Package | Repository | Where it runs |
|---|---|---|---|
| `Orangecat_IndexerBoost` | `orangecat/module-indexer-boost` 0.4.0 | [m2_indexer-boost](https://github.com/olivertar/m2_indexer-boost) | production |
| `Orangecat_IndexerBench` | `orangecat/module-indexer-bench` 0.4.0 | [m2_indexer-bench](https://github.com/olivertar/m2_indexer-bench) | development and staging only |
| `Orangecat_IndexerBoostMago` | `orangecat/module-indexer-boost-mago` 0.1.1 | [m2_indexer-boost-mago](https://github.com/olivertar/m2_indexer-boost-mago) | production, with [Mago](https://askmago.com/) |
| `Orangecat_IndexerBenchMago` | `orangecat/module-indexer-bench-mago` 0.1.1 | [m2_indexer-bench-mago](https://github.com/olivertar/m2_indexer-bench-mago) | development and staging, with Mago |

All four are MIT licensed.

## Documents

| Document | For | Content |
|---|---|---|
| [Overview](overview.md) | everyone | What the modules do, why, and the measured results |
| [User manual](user-manual.md) | merchants, administrators, developers | Installing, reading the doctor, the admin page, switching rewrites, benchmarking, asking Mago |
| [Architecture](architecture.md) | developers, reviewers | Components, data flow, safety guarantees and design decisions |
| [Technical reference](technical-reference.md) | developers, DevOps | Every command, option, check, configuration path, table, `di.xml` argument, ACL and Mago tool |

Module-level details stay next to the code: each module has its own `README.md`, `CHANGELOG.md` and
`docs/decisions/` (architecture decision records).

## Other material

- [Landing page](landing.html) — product page in English; published as a private artifact. Open it next to
  `images/` so the screenshots load.
- [LinkedIn article](linkedin-article.md) — English draft, written in the author's voice.
- [Benchmark reports](benchmarks/) — the raw measurements behind every number in these documents.
- `images/` — screenshots taken on the development store on 2026-11-02 (2 040 products, so durations there are
  sub-second) and the benchmark chart.
