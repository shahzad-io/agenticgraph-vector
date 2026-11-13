# vector-bench: scifact, openai:text-embedding-3-small

> Recorded 2026-11-13: the +rerank modes rerun after the harness change (H1 to H11) and the pgvector latency fix (rule L7): the rerank candidates now come from the vector index (HNSW). Every quality metric is within the 0.01 tolerance of the 2026-10-17 baseline (see git history); the change is expected, since the candidates changed. Reranker cohere `rerank-v4.0-pro`, 593 requests. dense+rerank nDCG@10 0.7921 to 0.7937, hybrid+rerank 0.8001 to 0.7942. Dense and hybrid unchanged from the 2026-11-12 baseline.

- Created: 2026-11-13T15:13:16+00:00
- Dataset: BEIR SciFact, test split (300 queries) (revision `536e14446a0ba56ed1398ab1055f39fe852686ecad24a6306c80c490fa8e0165`, CC BY-SA 4.0)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Sizes: 5183 documents, 300 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7), index `CREATE INDEX vcol_be8119952e71572a37acb0cbabfb4403_embedding_idx ON public.vcol_be8119952e71572a37acb0cbabfb4403 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | dense+rerank (delta vs dense) | hybrid (delta vs dense) | hybrid+rerank (delta vs dense) |
|---|---|---|---|---|
| ndcg_at_10 | 0.7324 | 0.7937 (+0.0613) | 0.6926 (-0.0398) | 0.7942 (+0.0618) |
| recall_at_1 | 0.5916 | 0.6674 (+0.0758) | 0.5209 (-0.0707) | 0.6674 (+0.0758) |
| recall_at_5 | 0.7933 | 0.8669 (+0.0736) | 0.7811 (-0.0122) | 0.8662 (+0.0729) |
| recall_at_10 | 0.8576 | 0.8899 (+0.0323) | 0.8524 (-0.0051) | 0.8916 (+0.0340) |
| hit_at_1 | 0.6233 | 0.6967 (+0.0733) | 0.5500 (-0.0733) | 0.6967 (+0.0733) |
| hit_at_5 | 0.8100 | 0.8767 (+0.0667) | 0.8067 (-0.0033) | 0.8767 (+0.0667) |
| hit_at_10 | 0.8667 | 0.8967 (+0.0300) | 0.8633 (-0.0033) | 0.8967 (+0.0300) |
| mrr_at_10 | 0.6997 | 0.7655 (+0.0657) | 0.6513 (-0.0484) | 0.7655 (+0.0657) |

Queries with fewer than 10 hits: dense 0, dense+rerank 0, hybrid 0, hybrid+rerank 0.

Hybrid settings: hybrid match any, keyword weight 0.25; hybrid+rerank match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9117 | 0.9450 | 0.9733 |
| dense+rerank | 0.9117 (+0.0000) | 0.9450 (+0.0000) | 0.9733 (+0.0000) |
| hybrid | 0.9117 (+0.0000) | 0.9450 (+0.0000) | 0.9733 (+0.0000) |
| hybrid+rerank | 0.9117 (+0.0000) | 0.9450 (+0.0000) | 0.9733 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| dense+rerank | dense | +0.0613 [+0.0379, +0.0853] | +0.0736 [+0.0422, +0.1067] | +0.0000 [+0.0000, +0.0000] |
| hybrid | dense | -0.0398 [-0.0627, -0.0173] | -0.0122 [-0.0390, +0.0142] | +0.0000 [+0.0000, +0.0000] |
| hybrid+rerank | dense+rerank | +0.0005 [-0.0003, +0.0018] | -0.0007 [-0.0020, +0.0000] | +0.0000 [+0.0000, +0.0000] |

## Reranking (one client; latency added on top of the store time)

| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |
|---|---|---|---|---|---|---|---|
| dense+rerank | cohere (`rerank-v4.0-pro`) | 30 | 300 (0) | 1125 | 1647 | 300 | n/a |
| hybrid+rerank | cohere (`rerank-v4.0-pro`) | 30 | 293 (7) | 1137 | 1631 | 300 | n/a |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9317 | 54.4, 60 |
| any | 50 | 0.9583 | 90.4, 100 |
| any | 100 | 0.9867 | 178.2, 199 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 339.2 | 2.865 | 3.671 | 4.085 |
| dense | 4 | 1168.1 | 3.261 | 4.744 | 5.898 |
| dense | 8 | 2411.2 | 3.125 | 4.585 | 5.103 |
| hybrid | 1 | 43.2 | 20.755 | 46.701 | 61.121 |
| hybrid | 4 | 130.8 | 26.451 | 55.437 | 72.132 |
| hybrid | 8 | 222.6 | 30.217 | 66.653 | 82.217 |

Query embedding (one client, from cache): p50 515 ms, p95 902 ms, p99 1266 ms; 300 calls, 5898 tokens.

## Ingestion

- 5183 documents in 23219 ms (223.2 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens (vectors from the document cache)
- Index rebuild: 2792 ms

## Resources

- Storage: table 53067776 bytes, indexes 49119232 bytes, total 102187008 bytes
- Container memory: max 2523.1 MiB over 15 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)

## Cost

- At 0.02 per 1M tokens: ingest n/a (no token usage), queries 0.000118
- Rerank (cohere, `rerank-v4.0-pro`): 593 requests this run (593 units, n/a tokens), cost 1.4825; per query 0.0025 (prices: 2.5 per 1K searches, none per 1M tokens)

Runtime: 794.0 s for the whole run.
