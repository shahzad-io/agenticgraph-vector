# vector-bench: scifact, openai:text-embedding-3-small

- Created: 2026-10-16T11:34:45+00:00
- Dataset: BEIR SciFact, test split (300 queries) (revision `536e14446a0ba56ed1398ab1055f39fe852686ecad24a6306c80c490fa8e0165`, CC BY-SA 4.0)
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Sizes: 5183 documents, 300 queries, top 10, warmup 20
- Store: pgvector, `CREATE INDEX vcol_4ba684dcad9f5237403b23ece41f03ef_embedding_idx ON public.vcol_4ba684dcad9f5237403b23ece41f03ef USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`

## Quality

| Metric | dense |
|---|---|
| ndcg_at_10 | 0.7318 |
| recall_at_1 | 0.5916 |
| recall_at_5 | 0.7933 |
| recall_at_10 | 0.8576 |
| hit_at_1 | 0.6233 |
| hit_at_5 | 0.8100 |
| hit_at_10 | 0.8667 |
| mrr_at_10 | 0.6991 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 48.4 | 20.425 | 21.565 | 25.73 |
| dense | 4 | 145.3 | 26.905 | 32.752 | 35.171 |
| dense | 8 | 250.7 | 30.833 | 36.992 | 39.377 |

Query embedding (one client): p50 543 ms, p95 774 ms, p99 925 ms; 300 calls, 5898 tokens.

## Ingestion

- 5183 documents in 204860 ms (25.3 documents per second)
- Embedding: 131261 ms, 52 calls, 1657884 tokens
- Index rebuild (REINDEX): 2876 ms

## Resources

- Storage: table 50774016 bytes, indexes 42721280 bytes, total 93495296 bytes
- Container memory: max 162.6 MiB over 61 samples (agenticgraph-vector-postgres-1)
- Infrastructure: PostgreSQL 16.14 (Debian 16.14-1.pgdg12+1) on aarch64-unknown-linux-gnu, compiled by gcc (Debian 12.2.0-14+deb12u1) 12.2.0, 64-bit, pgvector 0.8.3, PHP 8.4.23

## Cost

- At 0.02 per 1M tokens: ingest 0.033158, queries 0.000118

Runtime: 401.1 s for the whole run.
