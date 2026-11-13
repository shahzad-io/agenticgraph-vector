# vector-bench: wixqa-expert, openai:text-embedding-3-small

> Recorded 2026-11-13: the +rerank modes rerun after the harness change (H1 to H11) and the pgvector latency fix (rule L7): the rerank candidates now come from the vector index (HNSW). Every quality metric is within the 0.01 tolerance of the 2026-10-17 baseline (see git history); the change is expected, since the candidates changed. Reranker voyage `rerank-3`, 200 requests. dense+rerank nDCG@10 0.7327 to 0.7347.

- Created: 2026-11-13T15:28:18+00:00
- Dataset: WixQA ExpertWritten (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Chunks: 2000 characters, overlap 200, 11850 chunks; hits collapsed by `parent_id` before scoring (over-fetch 3x, rerank before collapse)
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7), index `CREATE INDEX vcol_89b220f061bb040155eefc6669b52985_embedding_idx ON public.vcol_89b220f061bb040155eefc6669b52985 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | dense+rerank (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.6027 | 0.7347 (+0.1320) |
| recall_at_1 | 0.3708 | 0.4933 (+0.1225) |
| recall_at_5 | 0.6908 | 0.8283 (+0.1375) |
| recall_at_10 | 0.7992 | 0.8925 (+0.0933) |
| hit_at_1 | 0.4250 | 0.5750 (+0.1500) |
| hit_at_5 | 0.7600 | 0.8950 (+0.1350) |
| hit_at_10 | 0.8650 | 0.9400 (+0.0750) |
| mrr_at_10 | 0.5671 | 0.7161 (+0.1491) |

Queries with fewer than 10 hits: dense 2, dense+rerank 2.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9308 | 0.9642 | 0.9642 |
| dense+rerank | 0.9308 (+0.0000) | 0.9642 (+0.0000) | 0.9642 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| dense+rerank | dense | +0.1320 [+0.0912, +0.1727] | +0.1375 [+0.0858, +0.1908] | +0.0000 [+0.0000, +0.0000] |

## Reranking (one client; latency added on top of the store time)

| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |
|---|---|---|---|---|---|---|---|
| dense+rerank | voyage (`rerank-3`) | 30 | 200 (0) | 1006 | 1329 | n/a | 1790938 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 267.5 | 3.648 | 4.47 | 4.813 |
| dense | 4 | 1097.9 | 3.412 | 5.13 | 5.896 |
| dense | 8 | 1967.3 | 3.772 | 5.795 | 6.808 |

Query embedding (one client, from cache): p50 498 ms, p95 544 ms, p99 784 ms; 200 calls, 4546 tokens.

## Ingestion

- 6221 documents in 489591 ms (12.7 documents per second)
- Embedding: 339108 ms, 98 calls, 2882950 tokens
- Index rebuild: 1366 ms

## Resources

- Storage: table 113688576 bytes, indexes 97550336 bytes, total 211238912 bytes
- Container memory: max 2507.8 MiB over 114 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)

## Cost

- At 0.02 per 1M tokens: ingest 0.057659, queries 9.1E-5
- Rerank (voyage, `rerank-3`): 200 requests this run (n/a units, 1790938 tokens), cost 0.089547; per query 0.000448 (prices: none per 1K searches, 0.05 per 1M tokens)

Runtime: 709.4 s for the whole run.
