# vector-bench: wixqa-simulated, openai:text-embedding-3-small

> Recorded 2026-11-13 after rule L9 (spec section 15): an unfiltered search uses hnsw.ef_search 80 (pgvector's default was 40), and the harness measures candidate recall at the store's default width (before: max(40, K) set by the harness). Truncation 24,000 characters, now the dataset default. Candidate recall@30: dense 0.8958 to 0.8983, hybrid 0.9008 to 0.9158. Dense and hybrid only; the +rerank modes of the previous baseline (commit 94bc847) were not rerun (see git history).

- Created: 2026-11-13T16:18:52+00:00
- Dataset: WixQA Simulated (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: 24000 characters
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7), index `CREATE INDEX vcol_89ff6ee7cd075425dd85159d3c6908cd_embedding_idx ON public.vcol_89ff6ee7cd075425dd85159d3c6908cd USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | hybrid (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.5320 | 0.4833 (-0.0486) |
| recall_at_1 | 0.3125 | 0.2050 (-0.1075) |
| recall_at_5 | 0.6225 | 0.5933 (-0.0292) |
| recall_at_10 | 0.7442 | 0.7833 (+0.0392) |
| hit_at_1 | 0.3300 | 0.2350 (-0.0950) |
| hit_at_5 | 0.6450 | 0.6250 (-0.0200) |
| hit_at_10 | 0.7650 | 0.8000 (+0.0350) |
| mrr_at_10 | 0.4719 | 0.4005 (-0.0713) |

Queries with fewer than 10 hits: dense 0, hybrid 0.

Hybrid settings: hybrid match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.8983 | 0.9525 | 0.9825 |
| hybrid | 0.9158 (+0.0175) | 0.9525 (+0.0000) | 0.9825 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| hybrid | dense | -0.0486 [-0.0939, -0.0039] | -0.0292 [-0.0892, +0.0317] | +0.0175 [-0.0075, +0.0450] |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9308 | 55.7, 60 |
| any | 50 | 0.9675 | 91.3, 100 |
| any | 100 | 0.9925 | 178.5, 197 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 284.4 | 3.435 | 4.203 | 4.967 |
| dense | 4 | 1076.0 | 3.469 | 5.246 | 6.337 |
| dense | 8 | 1819.5 | 4.004 | 6.092 | 7.698 |
| hybrid | 1 | 28.5 | 33.725 | 61.167 | 82.824 |
| hybrid | 4 | 88.7 | 36.199 | 66.913 | 89.966 |
| hybrid | 8 | 154.3 | 39.607 | 72.425 | 91.406 |

Query embedding (one client, from cache): p50 492 ms, p95 536 ms, p99 822 ms; 200 calls, 2695 tokens.

## Ingestion

- 6221 documents in 28875 ms (215.4 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens (vectors from the document cache)
- Index rebuild: 3397 ms

## Resources

- Storage: table 68878336 bytes, indexes 59416576 bytes, total 128294912 bytes
- Container memory: max 2518.0 MiB over 16 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)

## Cost

- At 0.02 per 1M tokens: ingest n/a (no token usage), queries 5.4E-5

Runtime: 94.7 s for the whole run.
