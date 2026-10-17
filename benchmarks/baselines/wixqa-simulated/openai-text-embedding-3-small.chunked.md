# vector-bench: wixqa-simulated, openai:text-embedding-3-small

- Created: 2026-10-17T19:22:53+00:00
- Dataset: WixQA Simulated (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Chunks: 2000 characters, overlap 200, 11850 chunks; hits collapsed by `parent_id` before scoring (over-fetch 3x, rerank before collapse)
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, `CREATE INDEX vcol_172a092516a37766ba43751fdd54297c_embedding_idx ON public.vcol_172a092516a37766ba43751fdd54297c USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`

## Quality

| Metric | dense | dense+rerank (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.5432 | 0.6814 (+0.1382) |
| recall_at_1 | 0.3233 | 0.4800 (+0.1567) |
| recall_at_5 | 0.6567 | 0.7825 (+0.1258) |
| recall_at_10 | 0.7725 | 0.8500 (+0.0775) |
| hit_at_1 | 0.3450 | 0.5200 (+0.1750) |
| hit_at_5 | 0.6900 | 0.8100 (+0.1200) |
| hit_at_10 | 0.7950 | 0.8700 (+0.0750) |
| mrr_at_10 | 0.4794 | 0.6410 (+0.1616) |

Queries with fewer than 10 hits: dense 0, dense+rerank 0.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9208 | 0.9575 | 0.9850 |
| dense+rerank | 0.9208 (+0.0000) | 0.9575 (+0.0000) | 0.9850 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| dense+rerank | dense | +0.1382 [+0.1024, +0.1756] | +0.1258 [+0.0700, +0.1850] | +0.0000 [+0.0000, +0.0000] |

## Reranking (one client; latency added on top of the store time)

| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |
|---|---|---|---|---|---|---|---|
| dense+rerank | voyage (`rerank-3`) | 30 | 200 (0) | 1201 | 1629 | n/a | 1703493 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 380.0 | 2.512 | 3.351 | 4.987 |
| dense | 4 | 895.3 | 4.127 | 6.594 | 7.132 |
| dense | 8 | 1520.3 | 4.593 | 8.812 | 9.975 |

Query embedding (one client): p50 492 ms, p95 536 ms, p99 822 ms; 200 calls, 2695 tokens.

## Ingestion

- 6221 documents in 480156 ms (13.0 documents per second)
- Embedding: 315071 ms, 119 calls, 3195444 tokens
- Index rebuild (REINDEX): 3975 ms

## Resources

- Storage: table 113704960 bytes, indexes 97878016 bytes, total 211582976 bytes
- Container memory: max 157.3 MiB over 4 samples (agenticgraph-vector-postgres-1)
- Infrastructure: PostgreSQL 16.14 (Debian 16.14-1.pgdg12+1) on aarch64-unknown-linux-gnu, compiled by gcc (Debian 12.2.0-14+deb12u1) 12.2.0, 64-bit, pgvector 0.8.3, PHP 8.4.23

## Cost

- At 0.02 per 1M tokens: ingest 0.063909, queries 5.4E-5
- Rerank (voyage, `rerank-3`): 200 requests this run (n/a units, 1703493 tokens), cost 0.085175; per query 0.000426 (prices: none per 1K searches, 0.05 per 1M tokens)

Runtime: 404.7 s for the whole run.
