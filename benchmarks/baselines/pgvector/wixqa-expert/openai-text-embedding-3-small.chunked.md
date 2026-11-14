# vector-bench: wixqa-expert, openai:text-embedding-3-small

> Recorded 2026-11-14 at search width 80 (rule L9, spec section 15; pgvector's default was 40), all modes in this file at that width. Chunked 2,000 characters with 200 overlap, collapse by parent, overfetch 3. Truncation 24,000 characters, now the dataset default (the width 40 baseline had none; 11,820 chunks instead of 11,850). Voyage rerank-3, 30 candidates, dense and dense+rerank. Candidate recall@30 0.9308 (unchanged). nDCG@10: dense 0.6027 to 0.6041, dense+rerank 0.7347 to 0.7351. 25 of the 200 rerank requests were new.

- Created: 2026-11-14T17:22:26+00:00
- Dataset: WixQA ExpertWritten (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: 24000 characters
- Chunks: 2000 characters, overlap 200, 11820 chunks; hits collapsed by `parent_id` before scoring (over-fetch 3x, rerank before collapse)
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7), index `CREATE INDEX vcol_03b0bc37387d649257964d19aab17aa0_embedding_idx ON public.vcol_03b0bc37387d649257964d19aab17aa0 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | dense+rerank (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.6041 | 0.7351 (+0.1311) |
| recall_at_1 | 0.3708 | 0.4958 (+0.1250) |
| recall_at_5 | 0.6933 | 0.8283 (+0.1350) |
| recall_at_10 | 0.8017 | 0.8900 (+0.0883) |
| hit_at_1 | 0.4250 | 0.5800 (+0.1550) |
| hit_at_5 | 0.7600 | 0.8950 (+0.1350) |
| hit_at_10 | 0.8650 | 0.9350 (+0.0700) |
| mrr_at_10 | 0.5675 | 0.7180 (+0.1505) |

Queries with fewer than 10 hits: dense 1, dense+rerank 1.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9308 | 0.9642 | 0.9642 |
| dense+rerank | 0.9308 (+0.0000) | 0.9642 (+0.0000) | 0.9642 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| dense+rerank | dense | +0.1311 [+0.0902, +0.1717] | +0.1350 [+0.0833, +0.1883] | +0.0000 [+0.0000, +0.0000] |

## Reranking (one client; latency added on top of the store time)

| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |
|---|---|---|---|---|---|---|---|
| dense+rerank | voyage (`rerank-3`) | 30 | 25 (175) | 968 | 1318 | n/a | 1789688 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 325.9 | 2.833 | 4.578 | 5.773 |
| dense | 4 | 995.0 | 3.727 | 5.709 | 6.989 |
| dense | 8 | 2011.9 | 3.574 | 5.3 | 6.178 |

Query embedding (one client, from cache): p50 498 ms, p95 544 ms, p99 784 ms; 200 calls, 4546 tokens.

## Ingestion

- 6221 documents in 51063 ms (121.8 documents per second)
- Embedding: 828 ms, 1 calls, 2119 tokens
- Index rebuild: 1423 ms

## Resources

- Storage on disk (pg_table_size, pg_indexes_size, pg_total_relation_size): table 113393664 bytes, indexes 97304576 bytes, total 210698240 bytes
- Container memory (whole container, docker stats every 2 s over the run): max 413.6 MiB over 16 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)

## Cost

- At 0.02 per 1M tokens: ingest 4.2E-5, queries 9.1E-5
- Rerank (voyage, `rerank-3`): 25 requests this run (n/a units, 227075 tokens), cost 0.011354; per query 0.000447 (prices: none per 1K searches, 0.05 per 1M tokens)

Runtime: 89.8 s for the whole run.
