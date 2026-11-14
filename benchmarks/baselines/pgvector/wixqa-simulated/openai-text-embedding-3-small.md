# vector-bench: wixqa-simulated, openai:text-embedding-3-small

> Recorded 2026-11-14 at search width 80 (rule L9, spec section 15; pgvector's default was 40), all modes in this file at that width. Truncation 24,000 characters (dataset default). Cohere rerank-v4.0-pro, 30 candidates, dense and hybrid with their +rerank modes. Candidate recall@30: dense 0.8958 to 0.8983, hybrid 0.9008 to 0.9158. nDCG@10 against the width 40 run (commit 94bc847): dense+rerank 0.6860 to 0.6878, hybrid+rerank 0.6863 to 0.6891. 214 of the 400 rerank requests were new, the rest came from the rerank cache; Cohere drift applies as on SciFact: part of the rerank difference is the vendor's, not the store's.

- Created: 2026-11-14T17:20:56+00:00
- Dataset: WixQA Simulated (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: 24000 characters
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7), index `CREATE INDEX vcol_143eecf521489c1821f3a41c214b8a0d_embedding_idx ON public.vcol_143eecf521489c1821f3a41c214b8a0d USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | dense+rerank (delta vs dense) | hybrid (delta vs dense) | hybrid+rerank (delta vs dense) |
|---|---|---|---|---|
| ndcg_at_10 | 0.5321 | 0.6878 (+0.1558) | 0.4840 (-0.0480) | 0.6891 (+0.1571) |
| recall_at_1 | 0.3125 | 0.4717 (+0.1592) | 0.2050 (-0.1075) | 0.4692 (+0.1567) |
| recall_at_5 | 0.6225 | 0.7675 (+0.1450) | 0.5933 (-0.0292) | 0.7800 (+0.1575) |
| recall_at_10 | 0.7442 | 0.8733 (+0.1292) | 0.7833 (+0.0392) | 0.8758 (+0.1317) |
| hit_at_1 | 0.3300 | 0.5150 (+0.1850) | 0.2350 (-0.0950) | 0.5100 (+0.1800) |
| hit_at_5 | 0.6450 | 0.7900 (+0.1450) | 0.6250 (-0.0200) | 0.8000 (+0.1550) |
| hit_at_10 | 0.7650 | 0.8900 (+0.1250) | 0.8000 (+0.0350) | 0.8900 (+0.1250) |
| mrr_at_10 | 0.4719 | 0.6418 (+0.1699) | 0.4014 (-0.0705) | 0.6404 (+0.1685) |

Queries with fewer than 10 hits: dense 0, dense+rerank 0, hybrid 0, hybrid+rerank 0.

Hybrid settings: hybrid match any, keyword weight 0.25; hybrid+rerank match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.8983 | 0.9525 | 0.9825 |
| dense+rerank | 0.8983 (+0.0000) | 0.9525 (+0.0000) | 0.9825 (+0.0000) |
| hybrid | 0.9158 (+0.0175) | 0.9525 (+0.0000) | 0.9825 (+0.0000) |
| hybrid+rerank | 0.9158 (+0.0175) | 0.9525 (+0.0000) | 0.9825 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| dense+rerank | dense | +0.1558 [+0.1146, +0.1977] | +0.1450 [+0.0875, +0.2050] | +0.0000 [+0.0000, +0.0000] |
| hybrid | dense | -0.0480 [-0.0935, -0.0032] | -0.0292 [-0.0892, +0.0317] | +0.0175 [-0.0075, +0.0450] |
| hybrid+rerank | dense+rerank | +0.0013 [-0.0129, +0.0172] | +0.0125 [-0.0025, +0.0325] | +0.0175 [-0.0075, +0.0450] |

## Reranking (one client; latency added on top of the store time)

| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |
|---|---|---|---|---|---|---|---|
| dense+rerank | cohere (`rerank-v4.0-pro`) | 30 | 60 (140) | 1242 | 1941 | 202 | n/a |
| hybrid+rerank | cohere (`rerank-v4.0-pro`) | 30 | 154 (46) | 1204 | 1652 | 202 | n/a |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9308 | 55.7, 60 |
| any | 50 | 0.9675 | 91.3, 100 |
| any | 100 | 0.9925 | 178.5, 197 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 256.1 | 3.651 | 5.495 | 6.552 |
| dense | 4 | 1000.6 | 3.642 | 5.59 | 7.15 |
| dense | 8 | 2074.9 | 3.377 | 5.12 | 6.238 |
| hybrid | 1 | 34.6 | 28.02 | 51.322 | 76.802 |
| hybrid | 4 | 97.4 | 34.513 | 60.551 | 81.794 |
| hybrid | 8 | 166.5 | 37.949 | 65.94 | 94.553 |

Query embedding (one client, from cache): p50 492 ms, p95 536 ms, p99 822 ms; 200 calls, 2695 tokens.

## Ingestion

- 6221 documents in 27547 ms (225.8 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens (vectors from the document cache)
- Index rebuild: 2981 ms

## Resources

- Storage on disk (pg_table_size, pg_indexes_size, pg_total_relation_size): table 68878336 bytes, indexes 59416576 bytes, total 128294912 bytes
- Container memory (whole container, docker stats every 2 s over the run): max 310.5 MiB over 15 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)

## Cost

- At 0.02 per 1M tokens: ingest n/a (no token usage), queries 5.4E-5
- Rerank (cohere, `rerank-v4.0-pro`): 214 requests this run (217 units, n/a tokens), cost 0.5425; per query 0.002525 (prices: 2.5 per 1K searches, none per 1M tokens)

Runtime: 350.3 s for the whole run.
