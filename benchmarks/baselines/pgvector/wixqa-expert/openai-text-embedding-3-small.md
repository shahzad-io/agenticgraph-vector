# vector-bench: wixqa-expert, openai:text-embedding-3-small

> Recorded 2026-11-13 after rule L9 (spec section 15): an unfiltered search uses hnsw.ef_search 80 (pgvector's default was 40), and the harness measures candidate recall at the store's default width (before: max(40, K) set by the harness). Truncation 24,000 characters, now the dataset default (the previous baseline had none). Cohere rerank-v4.0-pro, 30 candidates. Candidate recall@30 before (2026-11-13 run at width 40) and after: dense 0.9300 to 0.9375, hybrid 0.9425 to 0.9417, which is October's (2026-10-17: 0.9375 and 0.9417). nDCG@10: dense+rerank 0.7552 (October), 0.7432 (width 40), 0.7494 (now); hybrid+rerank 0.7599, 0.7540, 0.7591. Cohere returned a different order for 26 of 111 queries with identical candidates, so part of the rerank difference is the vendor's, not the store's.

- Created: 2026-11-13T16:25:51+00:00
- Dataset: WixQA ExpertWritten (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: 24000 characters
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7), index `CREATE INDEX vcol_f0831a8a750a86da26e01a3c155dee30_embedding_idx ON public.vcol_f0831a8a750a86da26e01a3c155dee30 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | dense+rerank (delta vs dense) | hybrid (delta vs dense) | hybrid+rerank (delta vs dense) |
|---|---|---|---|---|
| ndcg_at_10 | 0.5751 | 0.7494 (+0.1743) | 0.6020 (+0.0269) | 0.7591 (+0.1840) |
| recall_at_1 | 0.3158 | 0.4958 (+0.1800) | 0.3458 (+0.0300) | 0.5033 (+0.1875) |
| recall_at_5 | 0.6542 | 0.8392 (+0.1850) | 0.6933 (+0.0392) | 0.8592 (+0.2050) |
| recall_at_10 | 0.7942 | 0.9258 (+0.1317) | 0.8208 (+0.0267) | 0.9325 (+0.1383) |
| hit_at_1 | 0.3650 | 0.5900 (+0.2250) | 0.4050 (+0.0400) | 0.6000 (+0.2350) |
| hit_at_5 | 0.7200 | 0.8950 (+0.1750) | 0.7550 (+0.0350) | 0.9200 (+0.2000) |
| hit_at_10 | 0.8550 | 0.9600 (+0.1050) | 0.8650 (+0.0100) | 0.9650 (+0.1100) |
| mrr_at_10 | 0.5283 | 0.7253 (+0.1971) | 0.5550 (+0.0267) | 0.7361 (+0.2078) |

Queries with fewer than 10 hits: dense 0, dense+rerank 0, hybrid 0, hybrid+rerank 0.

Hybrid settings: hybrid match any, keyword weight 0.25; hybrid+rerank match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9375 | 0.9617 | 0.9717 |
| dense+rerank | 0.9375 (+0.0000) | 0.9617 (+0.0000) | 0.9717 (+0.0000) |
| hybrid | 0.9417 (+0.0042) | 0.9617 (+0.0000) | 0.9717 (+0.0000) |
| hybrid+rerank | 0.9417 (+0.0042) | 0.9617 (+0.0000) | 0.9717 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| dense+rerank | dense | +0.1743 [+0.1330, +0.2153] | +0.1850 [+0.1333, +0.2400] | +0.0000 [+0.0000, +0.0000] |
| hybrid | dense | +0.0269 [-0.0179, +0.0706] | +0.0392 [-0.0100, +0.0883] | +0.0042 [-0.0167, +0.0250] |
| hybrid+rerank | dense+rerank | +0.0097 [-0.0019, +0.0245] | +0.0200 [+0.0008, +0.0433] | +0.0042 [-0.0167, +0.0250] |

## Reranking (one client; latency added on top of the store time)

| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |
|---|---|---|---|---|---|---|---|
| dense+rerank | cohere (`rerank-v4.0-pro`) | 30 | 70 (130) | 1228 | 1808 | 200 | n/a |
| hybrid+rerank | cohere (`rerank-v4.0-pro`) | 30 | 142 (58) | 1301 | 1984 | 202 | n/a |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9667 | 56.3, 60 |
| any | 50 | 0.9792 | 92.7, 100 |
| any | 100 | 0.9842 | 181.8, 200 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 402.4 | 2.305 | 3.487 | 3.715 |
| dense | 4 | 1079.2 | 3.452 | 5.347 | 6.087 |
| dense | 8 | 1870.2 | 3.852 | 5.814 | 6.56 |
| hybrid | 1 | 18.8 | 48.411 | 104.718 | 133.934 |
| hybrid | 4 | 61.9 | 57.372 | 121.03 | 155.37 |
| hybrid | 8 | 101.9 | 63.434 | 139.975 | 181.701 |

Query embedding (one client, from cache): p50 498 ms, p95 544 ms, p99 784 ms; 200 calls, 4546 tokens.

## Ingestion

- 6221 documents in 29296 ms (212.3 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens (vectors from the document cache)
- Index rebuild: 2995 ms

## Resources

- Storage: table 68894720 bytes, indexes 58359808 bytes, total 127254528 bytes
- Container memory: max 2545.7 MiB over 19 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)

## Cost

- At 0.02 per 1M tokens: ingest n/a (no token usage), queries 9.1E-5
- Rerank (cohere, `rerank-v4.0-pro`): 212 requests this run (214 units, n/a tokens), cost 0.535; per query 0.002512 (prices: 2.5 per 1K searches, none per 1M tokens)

Runtime: 418.4 s for the whole run.
