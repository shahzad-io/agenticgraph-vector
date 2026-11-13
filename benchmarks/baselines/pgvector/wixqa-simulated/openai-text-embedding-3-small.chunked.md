# vector-bench: wixqa-simulated, openai:text-embedding-3-small

> Recorded 2026-11-13: the +rerank modes rerun after the harness change (H1 to H11) and the pgvector latency fix (rule L7): the rerank candidates now come from the vector index (HNSW). Every quality metric is within the 0.01 tolerance of the 2026-10-17 baseline (see git history); the change is expected, since the candidates changed. Reranker voyage `rerank-3`, 200 requests. dense+rerank nDCG@10 0.6814 to 0.6814.

- Created: 2026-11-13T15:32:55+00:00
- Dataset: WixQA Simulated (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Chunks: 2000 characters, overlap 200, 11850 chunks; hits collapsed by `parent_id` before scoring (over-fetch 3x, rerank before collapse)
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7), index `CREATE INDEX vcol_00e7b22d97e566d396cb000c5aa41f3e_embedding_idx ON public.vcol_00e7b22d97e566d396cb000c5aa41f3e USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | dense+rerank (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.5424 | 0.6814 (+0.1390) |
| recall_at_1 | 0.3233 | 0.4783 (+0.1550) |
| recall_at_5 | 0.6567 | 0.7775 (+0.1208) |
| recall_at_10 | 0.7675 | 0.8500 (+0.0825) |
| hit_at_1 | 0.3450 | 0.5150 (+0.1700) |
| hit_at_5 | 0.6900 | 0.8050 (+0.1150) |
| hit_at_10 | 0.7900 | 0.8700 (+0.0800) |
| mrr_at_10 | 0.4797 | 0.6396 (+0.1599) |

Queries with fewer than 10 hits: dense 0, dense+rerank 0.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9208 | 0.9575 | 0.9850 |
| dense+rerank | 0.9208 (+0.0000) | 0.9575 (+0.0000) | 0.9850 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| dense+rerank | dense | +0.1390 [+0.1028, +0.1761] | +0.1208 [+0.0625, +0.1800] | +0.0000 [+0.0000, +0.0000] |

## Reranking (one client; latency added on top of the store time)

| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |
|---|---|---|---|---|---|---|---|
| dense+rerank | voyage (`rerank-3`) | 30 | 200 (0) | 987 | 1609 | n/a | 1706403 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 280.3 | 3.526 | 4.502 | 5.141 |
| dense | 4 | 1129.0 | 3.37 | 4.877 | 5.435 |
| dense | 8 | 2044.8 | 3.631 | 5.579 | 6.591 |

Query embedding (one client, from cache): p50 492 ms, p95 536 ms, p99 822 ms; 200 calls, 2695 tokens.

## Ingestion

- 6221 documents in 48138 ms (129.2 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens (vectors from the document cache)
- Index rebuild: 1338 ms

## Resources

- Storage: table 113672192 bytes, indexes 97542144 bytes, total 211214336 bytes
- Container memory: max 2516.0 MiB over 16 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)

## Cost

- At 0.02 per 1M tokens: ingest n/a (no token usage), queries 5.4E-5
- Rerank (voyage, `rerank-3`): 200 requests this run (n/a units, 1706403 tokens), cost 0.08532; per query 0.000427 (prices: none per 1K searches, 0.05 per 1M tokens)

Runtime: 276.9 s for the whole run.
