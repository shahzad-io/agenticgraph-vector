# vector-bench: scifact, openai:text-embedding-3-small

- Created: 2026-10-16T19:16:44+00:00
- Dataset: BEIR SciFact, test split (300 queries) (revision `536e14446a0ba56ed1398ab1055f39fe852686ecad24a6306c80c490fa8e0165`, CC BY-SA 4.0)
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Sizes: 5183 documents, 300 queries, top 10, warmup 20
- Store: pgvector, `CREATE INDEX vcol_6ed714e8cd8f6e889eb557f24b8afade_embedding_idx ON public.vcol_6ed714e8cd8f6e889eb557f24b8afade USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`

## Quality

| Metric | dense | hybrid (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.7318 | 0.6927 (-0.0392) |
| recall_at_1 | 0.5916 | 0.5209 (-0.0707) |
| recall_at_5 | 0.7933 | 0.7811 (-0.0122) |
| recall_at_10 | 0.8576 | 0.8524 (-0.0051) |
| hit_at_1 | 0.6233 | 0.5500 (-0.0733) |
| hit_at_5 | 0.8100 | 0.8067 (-0.0033) |
| hit_at_10 | 0.8667 | 0.8633 (-0.0033) |
| mrr_at_10 | 0.6991 | 0.6514 (-0.0477) |

Queries with fewer than 10 hits: dense 0, hybrid 0.

Hybrid settings: hybrid match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9117 | 0.9450 | 0.9733 |
| hybrid | 0.9250 (+0.0133) | 0.9450 (+0.0000) | 0.9733 (+0.0000) |

## Paired differences against dense (per query, bootstrap 95% interval)

| Mode | nDCG@10 | candidate recall@30 |
|---|---|---|
| hybrid | -0.0392 [-0.0622, -0.0168] | +0.0133 [+0.0033, +0.0267] |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9317 | 54.4, 60 |
| any | 50 | 0.9583 | 90.4, 100 |
| any | 100 | 0.9867 | 178.2, 199 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 40.0 | 24.86 | 26.911 | 29.687 |
| dense | 4 | 127.2 | 30.81 | 36.816 | 41.654 |
| dense | 8 | 226.9 | 33.544 | 40.754 | 42.113 |
| hybrid | 1 | 22.8 | 41.831 | 66.222 | 81.879 |
| hybrid | 4 | 73.8 | 49.969 | 78.777 | 94.909 |
| hybrid | 8 | 131.9 | 55.947 | 86.815 | 103.485 |

Query embedding (one client, from cache): p50 526 ms, p95 792 ms, p99 886 ms; 300 calls, 5898 tokens.

## Ingestion

- 5183 documents in 199380 ms (26.0 documents per second)
- Embedding: 125281 ms, 52 calls, 1657884 tokens
- Index rebuild (REINDEX): 2848 ms

## Resources

- Storage: table 53067776 bytes, indexes 49987584 bytes, total 103055360 bytes
- Container memory: max 191.9 MiB over 19 samples (agenticgraph-vector-postgres-1)
- Infrastructure: PostgreSQL 16.14 (Debian 16.14-1.pgdg12+1) on aarch64-unknown-linux-gnu, compiled by gcc (Debian 12.2.0-14+deb12u1) 12.2.0, 64-bit, pgvector 0.8.3, PHP 8.4.23

## Cost

- At 0.02 per 1M tokens: ingest 0.033158, queries 0.000118

Runtime: 143.6 s for the whole run.
