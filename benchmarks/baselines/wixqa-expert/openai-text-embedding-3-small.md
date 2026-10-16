# vector-bench: wixqa-expert, openai:text-embedding-3-small

- Created: 2026-10-16T11:41:22+00:00
- Dataset: WixQA ExpertWritten (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: 24000 characters
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, `CREATE INDEX vcol_cb600486bb02d3abd7f3221252a02a9e_embedding_idx ON public.vcol_cb600486bb02d3abd7f3221252a02a9e USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`

## Quality

| Metric | dense |
|---|---|
| ndcg_at_10 | 0.5748 |
| recall_at_1 | 0.3158 |
| recall_at_5 | 0.6492 |
| recall_at_10 | 0.7942 |
| hit_at_1 | 0.3650 |
| hit_at_5 | 0.7150 |
| hit_at_10 | 0.8550 |
| mrr_at_10 | 0.5279 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 40.8 | 24.24 | 27.466 | 29.479 |
| dense | 4 | 118.0 | 32.986 | 40.049 | 43.922 |
| dense | 8 | 209.9 | 36.342 | 44.998 | 47.967 |

Query embedding (one client): p50 589 ms, p95 818 ms, p99 958 ms; 200 calls, 4546 tokens.

## Ingestion

- 6221 documents in 256550 ms (24.2 documents per second)
- Embedding: 169133 ms, 63 calls, 2953324 tokens
- Index rebuild (REINDEX): 2863 ms

## Resources

- Storage: table 60555264 bytes, indexes 51716096 bytes, total 112271360 bytes
- Container memory: max 162.6 MiB over 71 samples (agenticgraph-vector-postgres-1)
- Infrastructure: PostgreSQL 16.14 (Debian 16.14-1.pgdg12+1) on aarch64-unknown-linux-gnu, compiled by gcc (Debian 12.2.0-14+deb12u1) 12.2.0, 64-bit, pgvector 0.8.3, PHP 8.4.23

## Cost

- At 0.02 per 1M tokens: ingest 0.059066, queries 9.1E-5

Runtime: 396.6 s for the whole run.
