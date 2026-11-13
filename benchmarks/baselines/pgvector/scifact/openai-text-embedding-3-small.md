# vector-bench: scifact, openai:text-embedding-3-small

> Recorded 2026-11-13 after rule L9 (spec section 15): an unfiltered search uses hnsw.ef_search 80 (pgvector's default was 40), and the harness measures candidate recall at the store's default width (before: max(40, K) set by the harness). Candidate recall@30: dense 0.9117 to 0.9117, hybrid 0.9117 to 0.9250. Dense and hybrid only; the +rerank modes of the previous baseline (commit 94bc847) were not rerun (see git history).

- Created: 2026-11-13T16:15:08+00:00
- Dataset: BEIR SciFact, test split (300 queries) (revision `536e14446a0ba56ed1398ab1055f39fe852686ecad24a6306c80c490fa8e0165`, CC BY-SA 4.0)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Sizes: 5183 documents, 300 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7), index `CREATE INDEX vcol_52154f0de74caa81b5632786f1abcbd7_embedding_idx ON public.vcol_52154f0de74caa81b5632786f1abcbd7 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | hybrid (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.7323 | 0.6927 (-0.0396) |
| recall_at_1 | 0.5916 | 0.5209 (-0.0707) |
| recall_at_5 | 0.7933 | 0.7811 (-0.0122) |
| recall_at_10 | 0.8576 | 0.8524 (-0.0051) |
| hit_at_1 | 0.6233 | 0.5500 (-0.0733) |
| hit_at_5 | 0.8100 | 0.8067 (-0.0033) |
| hit_at_10 | 0.8667 | 0.8633 (-0.0033) |
| mrr_at_10 | 0.6997 | 0.6514 (-0.0483) |

Queries with fewer than 10 hits: dense 0, hybrid 0.

Hybrid settings: hybrid match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9117 | 0.9450 | 0.9733 |
| hybrid | 0.9250 (+0.0133) | 0.9450 (+0.0000) | 0.9733 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| hybrid | dense | -0.0396 [-0.0626, -0.0171] | -0.0122 [-0.0390, +0.0142] | +0.0133 [+0.0033, +0.0267] |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9317 | 54.4, 60 |
| any | 50 | 0.9583 | 90.4, 100 |
| any | 100 | 0.9867 | 178.2, 199 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 303.4 | 3.164 | 4.443 | 5.263 |
| dense | 4 | 1088.4 | 3.398 | 5.406 | 6.086 |
| dense | 8 | 2056.6 | 3.578 | 5.873 | 6.694 |
| hybrid | 1 | 35.2 | 25.703 | 55.861 | 74.329 |
| hybrid | 4 | 128.8 | 26.708 | 56.674 | 72.986 |
| hybrid | 8 | 215.2 | 30.523 | 68.608 | 85.28 |

Query embedding (one client, from cache): p50 515 ms, p95 902 ms, p99 1266 ms; 300 calls, 5898 tokens.

## Ingestion

- 5183 documents in 23459 ms (220.9 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens (vectors from the document cache)
- Index rebuild: 2896 ms

## Resources

- Storage: table 53051392 bytes, indexes 49020928 bytes, total 102072320 bytes
- Container memory: max 2536.4 MiB over 16 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)

## Cost

- At 0.02 per 1M tokens: ingest n/a (no token usage), queries 0.000118

Runtime: 95.3 s for the whole run.
