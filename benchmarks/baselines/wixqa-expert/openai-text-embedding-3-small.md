# vector-bench: wixqa-expert, openai:text-embedding-3-small

- Created: 2026-10-16T19:19:18+00:00
- Dataset: WixQA ExpertWritten (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: 24000 characters
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, `CREATE INDEX vcol_e03c7ea8e6b844b9ebd738e66f4b675b_embedding_idx ON public.vcol_e03c7ea8e6b844b9ebd738e66f4b675b USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`

## Quality

| Metric | dense | hybrid (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.5749 | 0.6006 (+0.0256) |
| recall_at_1 | 0.3158 | 0.3458 (+0.0300) |
| recall_at_5 | 0.6542 | 0.6933 (+0.0392) |
| recall_at_10 | 0.7942 | 0.8158 (+0.0217) |
| hit_at_1 | 0.3650 | 0.4050 (+0.0400) |
| hit_at_5 | 0.7200 | 0.7550 (+0.0350) |
| hit_at_10 | 0.8550 | 0.8600 (+0.0050) |
| mrr_at_10 | 0.5280 | 0.5545 (+0.0264) |

Queries with fewer than 10 hits: dense 0, hybrid 0.

Hybrid settings: hybrid match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9375 | 0.9617 | 0.9717 |
| hybrid | 0.9417 (+0.0042) | 0.9617 (+0.0000) | 0.9717 (+0.0000) |

## Paired differences against dense (per query, bootstrap 95% interval)

| Mode | nDCG@10 | candidate recall@30 |
|---|---|---|
| hybrid | +0.0256 [-0.0190, +0.0691] | +0.0042 [-0.0167, +0.0250] |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9667 | 56.3, 60 |
| any | 50 | 0.9792 | 92.7, 100 |
| any | 100 | 0.9842 | 182.0, 200 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 37.9 | 26.177 | 27.578 | 28.879 |
| dense | 4 | 116.9 | 33.357 | 39.628 | 45.652 |
| dense | 8 | 208.2 | 36.937 | 44.57 | 48.914 |
| hybrid | 1 | 13.0 | 69.363 | 128.671 | 162.692 |
| hybrid | 4 | 43.3 | 82.806 | 147.047 | 186.532 |
| hybrid | 8 | 76.8 | 88.202 | 163.498 | 204.438 |

Query embedding (one client, from cache): p50 500 ms, p95 754 ms, p99 991 ms; 200 calls, 4546 tokens.

## Ingestion

- 6221 documents in 249000 ms (25.0 documents per second)
- Embedding: 161041 ms, 63 calls, 2953324 tokens
- Index rebuild (REINDEX): 2794 ms

## Resources

- Storage: table 68886528 bytes, indexes 58646528 bytes, total 127533056 bytes
- Container memory: max 198.1 MiB over 20 samples (agenticgraph-vector-postgres-1)
- Infrastructure: PostgreSQL 16.14 (Debian 16.14-1.pgdg12+1) on aarch64-unknown-linux-gnu, compiled by gcc (Debian 12.2.0-14+deb12u1) 12.2.0, 64-bit, pgvector 0.8.3, PHP 8.4.23

## Cost

- At 0.02 per 1M tokens: ingest 0.059066, queries 9.1E-5

Runtime: 154.7 s for the whole run.
