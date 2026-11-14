# vector-bench: scifact, openai:text-embedding-3-small

> Recorded 2026-11-14 at search width 80 (rule L9, spec section 15; pgvector's default was 40), all modes in this file at that width. Cohere rerank-v4.0-pro, 30 candidates, dense and hybrid with their +rerank modes. Candidate recall@30: dense 0.9117 (unchanged), hybrid 0.9117 to 0.9250. nDCG@10 against the width 40 run (commit 94bc847): dense+rerank 0.7937 (identical), hybrid+rerank 0.7942 to 0.7995. 311 of the 600 rerank requests were new, the rest came from the rerank cache; Cohere is not deterministic, so part of any rerank difference is the vendor's, not the store's (26 of 111 queries differed on identical candidates on WixQA-expert); cached answers keep their earlier order.

- Created: 2026-11-14T17:15:06+00:00
- Dataset: BEIR SciFact, test split (300 queries) (revision `536e14446a0ba56ed1398ab1055f39fe852686ecad24a6306c80c490fa8e0165`, CC BY-SA 4.0)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Sizes: 5183 documents, 300 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7), index `CREATE INDEX vcol_92c9e2879cbd086c18ae307b8e36866d_embedding_idx ON public.vcol_92c9e2879cbd086c18ae307b8e36866d USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | dense+rerank (delta vs dense) | hybrid (delta vs dense) | hybrid+rerank (delta vs dense) |
|---|---|---|---|---|
| ndcg_at_10 | 0.7323 | 0.7937 (+0.0614) | 0.6927 (-0.0396) | 0.7995 (+0.0672) |
| recall_at_1 | 0.5916 | 0.6674 (+0.0758) | 0.5209 (-0.0707) | 0.6708 (+0.0792) |
| recall_at_5 | 0.7933 | 0.8669 (+0.0736) | 0.7811 (-0.0122) | 0.8729 (+0.0796) |
| recall_at_10 | 0.8576 | 0.8899 (+0.0323) | 0.8524 (-0.0051) | 0.8999 (+0.0423) |
| hit_at_1 | 0.6233 | 0.6967 (+0.0733) | 0.5500 (-0.0733) | 0.7000 (+0.0767) |
| hit_at_5 | 0.8100 | 0.8767 (+0.0667) | 0.8067 (-0.0033) | 0.8833 (+0.0733) |
| hit_at_10 | 0.8667 | 0.8967 (+0.0300) | 0.8633 (-0.0033) | 0.9067 (+0.0400) |
| mrr_at_10 | 0.6997 | 0.7655 (+0.0658) | 0.6514 (-0.0483) | 0.7702 (+0.0706) |

Queries with fewer than 10 hits: dense 0, dense+rerank 0, hybrid 0, hybrid+rerank 0.

Hybrid settings: hybrid match any, keyword weight 0.25; hybrid+rerank match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9117 | 0.9450 | 0.9733 |
| dense+rerank | 0.9117 (+0.0000) | 0.9450 (+0.0000) | 0.9733 (+0.0000) |
| hybrid | 0.9250 (+0.0133) | 0.9450 (+0.0000) | 0.9733 (+0.0000) |
| hybrid+rerank | 0.9250 (+0.0133) | 0.9450 (+0.0000) | 0.9733 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| dense+rerank | dense | +0.0614 [+0.0380, +0.0854] | +0.0736 [+0.0422, +0.1067] | +0.0000 [+0.0000, +0.0000] |
| hybrid | dense | -0.0396 [-0.0626, -0.0171] | -0.0122 [-0.0390, +0.0142] | +0.0133 [+0.0033, +0.0267] |
| hybrid+rerank | dense+rerank | +0.0058 [-0.0002, +0.0148] | +0.0060 [-0.0013, +0.0167] | +0.0133 [+0.0033, +0.0267] |

## Reranking (one client; latency added on top of the store time)

| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |
|---|---|---|---|---|---|---|---|
| dense+rerank | cohere (`rerank-v4.0-pro`) | 30 | 91 (209) | 1105 | 1611 | 300 | n/a |
| hybrid+rerank | cohere (`rerank-v4.0-pro`) | 30 | 220 (80) | 1071 | 1440 | 300 | n/a |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9317 | 54.4, 60 |
| any | 50 | 0.9583 | 90.4, 100 |
| any | 100 | 0.9867 | 178.2, 199 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 350.5 | 2.95 | 3.758 | 4.227 |
| dense | 4 | 1170.3 | 3.155 | 4.764 | 5.532 |
| dense | 8 | 2145.8 | 3.4 | 4.811 | 5.482 |
| hybrid | 1 | 41.5 | 21.671 | 46.451 | 60.19 |
| hybrid | 4 | 126.7 | 27.406 | 57.418 | 75.804 |
| hybrid | 8 | 209.6 | 32.005 | 70.479 | 93.123 |

Query embedding (one client, from cache): p50 515 ms, p95 902 ms, p99 1266 ms; 300 calls, 5898 tokens.

## Ingestion

- 5183 documents in 23528 ms (220.3 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens (vectors from the document cache)
- Index rebuild: 2859 ms

## Resources

- Storage: table 53075968 bytes, indexes 50757632 bytes, total 103833600 bytes
- Container memory: max 301.2 MiB over 15 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)

## Cost

- At 0.02 per 1M tokens: ingest n/a (no token usage), queries 0.000118
- Rerank (cohere, `rerank-v4.0-pro`): 311 requests this run (311 units, n/a tokens), cost 0.7775; per query 0.0025 (prices: 2.5 per 1K searches, none per 1M tokens)

Runtime: 436.5 s for the whole run.
