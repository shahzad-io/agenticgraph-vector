# vector-bench: wixqa-simulated, openai:text-embedding-3-small

> Recorded 2026-11-14 at search width 80 (rule L9, spec section 15; pgvector's default was 40), all modes in this file at that width. Chunked 2,000 characters with 200 overlap, collapse by parent, overfetch 3. Truncation 24,000 characters, now the dataset default (the width 40 baseline had none; 11,820 chunks instead of 11,850). Voyage rerank-3, 30 candidates, dense and dense+rerank. Candidate recall@30 0.9208 (unchanged). nDCG@10: dense 0.5424 to 0.5381, dense+rerank 0.6814 to 0.6813. 16 of the 200 rerank requests were new.

- Created: 2026-11-14T17:23:43+00:00
- Dataset: WixQA Simulated (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: 24000 characters
- Chunks: 2000 characters, overlap 200, 11820 chunks; hits collapsed by `parent_id` before scoring (over-fetch 3x, rerank before collapse)
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7), index `CREATE INDEX vcol_5c90cbd61500158983b400d365656226_embedding_idx ON public.vcol_5c90cbd61500158983b400d365656226 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | dense+rerank (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.5381 | 0.6813 (+0.1431) |
| recall_at_1 | 0.3233 | 0.4783 (+0.1550) |
| recall_at_5 | 0.6592 | 0.7775 (+0.1183) |
| recall_at_10 | 0.7575 | 0.8500 (+0.0925) |
| hit_at_1 | 0.3450 | 0.5150 (+0.1700) |
| hit_at_5 | 0.6900 | 0.8050 (+0.1150) |
| hit_at_10 | 0.7800 | 0.8700 (+0.0900) |
| mrr_at_10 | 0.4770 | 0.6395 (+0.1625) |

Queries with fewer than 10 hits: dense 0, dense+rerank 0.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9208 | 0.9575 | 0.9850 |
| dense+rerank | 0.9208 (+0.0000) | 0.9575 (+0.0000) | 0.9850 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| dense+rerank | dense | +0.1431 [+0.1069, +0.1811] | +0.1183 [+0.0608, +0.1783] | +0.0000 [+0.0000, +0.0000] |

## Reranking (one client; latency added on top of the store time)

| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |
|---|---|---|---|---|---|---|---|
| dense+rerank | voyage (`rerank-3`) | 30 | 16 (184) | 966 | 1609 | n/a | 1707645 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 285.6 | 3.461 | 4.548 | 4.906 |
| dense | 4 | 1012.1 | 3.727 | 5.882 | 6.746 |
| dense | 8 | 1775.2 | 3.941 | 6.503 | 7.34 |

Query embedding (one client, from cache): p50 492 ms, p95 536 ms, p99 822 ms; 200 calls, 2695 tokens.

## Ingestion

- 6221 documents in 47979 ms (129.7 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens (vectors from the document cache)
- Index rebuild: 1413 ms

## Resources

- Storage on disk (pg_table_size, pg_indexes_size, pg_total_relation_size): table 113401856 bytes, indexes 97296384 bytes, total 210698240 bytes
- Container memory (whole container, docker stats every 2 s over the run): max 415.0 MiB over 16 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)

## Cost

- At 0.02 per 1M tokens: ingest n/a (no token usage), queries 5.4E-5
- Rerank (voyage, `rerank-3`): 16 requests this run (n/a units, 146918 tokens), cost 0.007346; per query 0.000427 (prices: none per 1K searches, 0.05 per 1M tokens)

Runtime: 76.9 s for the whole run.
