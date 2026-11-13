# vector-bench: wixqa-simulated, openai:text-embedding-3-small

> Recorded 2026-11-13: the +rerank modes rerun after the harness change (H1 to H11) and the pgvector latency fix (rule L7): the rerank candidates now come from the vector index (HNSW). Every quality metric is within the 0.01 tolerance of the 2026-10-17 baseline (see git history); the change is expected, since the candidates changed. Reranker cohere `rerank-v4.0-pro`, 387 requests and 13 cached (12 from an attempt that stopped on a 5 s Cohere timeout , rerun with VECTOR_RERANK_TIMEOUT=15). dense+rerank nDCG@10 0.6848 to 0.6860, hybrid+rerank 0.6877 to 0.6863. Truncation 24000, as in the 2026-10-17 baseline (an untruncated ingest fails: one document exceeds the 8,192 token embedding limit); the 2026-11-12 file read truncate 0. Dense nDCG@10 0.5265 to 0.5315, hybrid 0.4830 to 0.4843 against it.

- Created: 2026-11-13T15:57:13+00:00
- Dataset: WixQA Simulated (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: 24000 characters
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7), index `CREATE INDEX vcol_c818090def7497f723ce62d0854cd574_embedding_idx ON public.vcol_c818090def7497f723ce62d0854cd574 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | dense+rerank (delta vs dense) | hybrid (delta vs dense) | hybrid+rerank (delta vs dense) |
|---|---|---|---|---|
| ndcg_at_10 | 0.5315 | 0.6860 (+0.1545) | 0.4843 (-0.0472) | 0.6863 (+0.1548) |
| recall_at_1 | 0.3125 | 0.4717 (+0.1592) | 0.2050 (-0.1075) | 0.4692 (+0.1567) |
| recall_at_5 | 0.6200 | 0.7700 (+0.1500) | 0.5933 (-0.0267) | 0.7775 (+0.1575) |
| recall_at_10 | 0.7417 | 0.8708 (+0.1292) | 0.7833 (+0.0417) | 0.8733 (+0.1317) |
| hit_at_1 | 0.3300 | 0.5150 (+0.1850) | 0.2350 (-0.0950) | 0.5100 (+0.1800) |
| hit_at_5 | 0.6450 | 0.7950 (+0.1500) | 0.6250 (-0.0200) | 0.8000 (+0.1550) |
| hit_at_10 | 0.7650 | 0.8900 (+0.1250) | 0.8000 (+0.0350) | 0.8900 (+0.1250) |
| mrr_at_10 | 0.4728 | 0.6414 (+0.1687) | 0.4018 (-0.0710) | 0.6394 (+0.1666) |

Queries with fewer than 10 hits: dense 0, dense+rerank 0, hybrid 0, hybrid+rerank 0.

Hybrid settings: hybrid match any, keyword weight 0.25; hybrid+rerank match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.8958 | 0.9525 | 0.9825 |
| dense+rerank | 0.8958 (+0.0000) | 0.9525 (+0.0000) | 0.9825 (+0.0000) |
| hybrid | 0.9008 (+0.0050) | 0.9525 (+0.0000) | 0.9825 (+0.0000) |
| hybrid+rerank | 0.9008 (+0.0050) | 0.9525 (+0.0000) | 0.9825 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| dense+rerank | dense | +0.1545 [+0.1137, +0.1965] | +0.1500 [+0.0925, +0.2100] | +0.0000 [+0.0000, +0.0000] |
| hybrid | dense | -0.0472 [-0.0934, -0.0020] | -0.0267 [-0.0875, +0.0342] | +0.0050 [-0.0150, +0.0275] |
| hybrid+rerank | dense+rerank | +0.0003 [-0.0107, +0.0117] | +0.0075 [-0.0050, +0.0250] | +0.0050 [-0.0150, +0.0275] |

## Reranking (one client; latency added on top of the store time)

| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |
|---|---|---|---|---|---|---|---|
| dense+rerank | cohere (`rerank-v4.0-pro`) | 30 | 188 (12) | 1305 | 2110 | 202 | n/a |
| hybrid+rerank | cohere (`rerank-v4.0-pro`) | 30 | 199 (1) | 1316 | 1893 | 202 | n/a |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9308 | 55.7, 60 |
| any | 50 | 0.9675 | 91.3, 100 |
| any | 100 | 0.9925 | 178.5, 197 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 402.2 | 2.463 | 3.42 | 4.076 |
| dense | 4 | 1091.1 | 3.42 | 5.437 | 6.355 |
| dense | 8 | 2180.6 | 3.255 | 5.021 | 5.748 |
| hybrid | 1 | 31.8 | 30.896 | 53.382 | 75.947 |
| hybrid | 4 | 92.8 | 34.952 | 63.322 | 84.341 |
| hybrid | 8 | 162.1 | 37.578 | 69.291 | 85.715 |

Query embedding (one client, from cache): p50 492 ms, p95 536 ms, p99 822 ms; 200 calls, 2695 tokens.

## Ingestion

- 6221 documents in 29378 ms (211.8 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens (vectors from the document cache)
- Index rebuild: 3211 ms

## Resources

- Storage: table 68878336 bytes, indexes 59416576 bytes, total 128294912 bytes
- Container memory: max 2520.1 MiB over 16 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)

## Cost

- At 0.02 per 1M tokens: ingest n/a (no token usage), queries 5.4E-5
- Rerank (cohere, `rerank-v4.0-pro`): 387 requests this run (391 units, n/a tokens), cost 0.9775; per query 0.002525 (prices: 2.5 per 1K searches, none per 1M tokens)

Runtime: 638.2 s for the whole run.
