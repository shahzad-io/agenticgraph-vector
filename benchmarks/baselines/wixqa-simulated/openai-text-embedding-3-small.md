# vector-bench: wixqa-simulated, openai:text-embedding-3-small

- Created: 2026-10-16T19:21:19+00:00
- Dataset: WixQA Simulated (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: 24000 characters
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, `CREATE INDEX vcol_4dd0bc3bf70216b919ea3ce261b876b0_embedding_idx ON public.vcol_4dd0bc3bf70216b919ea3ce261b876b0 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`

## Quality

| Metric | dense | hybrid (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.5320 | 0.4824 (-0.0495) |
| recall_at_1 | 0.3125 | 0.2050 (-0.1075) |
| recall_at_5 | 0.6225 | 0.5858 (-0.0367) |
| recall_at_10 | 0.7442 | 0.7833 (+0.0392) |
| hit_at_1 | 0.3300 | 0.2350 (-0.0950) |
| hit_at_5 | 0.6450 | 0.6200 (-0.0250) |
| hit_at_10 | 0.7650 | 0.8000 (+0.0350) |
| mrr_at_10 | 0.4719 | 0.3996 (-0.0722) |

Queries with fewer than 10 hits: dense 0, hybrid 0.

Hybrid settings: hybrid match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.8983 | 0.9508 | 0.9825 |
| hybrid | 0.9158 (+0.0175) | 0.9508 (+0.0000) | 0.9825 (+0.0000) |

## Paired differences against dense (per query, bootstrap 95% interval)

| Mode | nDCG@10 | candidate recall@30 |
|---|---|---|
| hybrid | -0.0495 [-0.0945, -0.0052] | +0.0175 [-0.0075, +0.0450] |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9308 | 55.8, 60 |
| any | 50 | 0.9658 | 91.4, 100 |
| any | 100 | 0.9925 | 178.6, 197 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 35.1 | 28.274 | 29.37 | 33.01 |
| dense | 4 | 110.1 | 35.676 | 41.281 | 45.047 |
| dense | 8 | 202.1 | 38.237 | 47.136 | 50.532 |
| hybrid | 1 | 18.2 | 53.816 | 76.74 | 94.071 |
| hybrid | 4 | 58.0 | 61.895 | 89.63 | 114.304 |
| hybrid | 8 | 104.8 | 67.93 | 94.479 | 124.009 |

Query embedding (one client, from cache): p50 553 ms, p95 1016 ms, p99 1805 ms; 200 calls, 2695 tokens.

## Ingestion

- 6221 documents in 239694 ms (26.0 documents per second)
- Embedding: 150118 ms, 63 calls, 2953324 tokens
- Index rebuild (REINDEX): 3040 ms

## Resources

- Storage: table 68894720 bytes, indexes 58523648 bytes, total 127418368 bytes
- Container memory: max 171.3 MiB over 17 samples (agenticgraph-vector-postgres-1)
- Infrastructure: PostgreSQL 16.14 (Debian 16.14-1.pgdg12+1) on aarch64-unknown-linux-gnu, compiled by gcc (Debian 12.2.0-14+deb12u1) 12.2.0, 64-bit, pgvector 0.8.3, PHP 8.4.23

## Cost

- At 0.02 per 1M tokens: ingest 0.059066, queries 5.4E-5

Runtime: 120.1 s for the whole run.
