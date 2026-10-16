# vector-bench: wixqa-simulated, openai:text-embedding-3-small

- Created: 2026-10-16T11:47:38+00:00
- Dataset: WixQA Simulated (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: 24000 characters
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, `CREATE INDEX vcol_ea3fa92b1db2c048e1b59a27e8ff0710_embedding_idx ON public.vcol_ea3fa92b1db2c048e1b59a27e8ff0710 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`

## Quality

| Metric | dense |
|---|---|
| ndcg_at_10 | 0.5320 |
| recall_at_1 | 0.3125 |
| recall_at_5 | 0.6225 |
| recall_at_10 | 0.7442 |
| hit_at_1 | 0.3300 |
| hit_at_5 | 0.6450 |
| hit_at_10 | 0.7650 |
| mrr_at_10 | 0.4719 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 40.9 | 24.142 | 25.533 | 29.103 |
| dense | 4 | 120.7 | 32.84 | 38.036 | 40.944 |
| dense | 8 | 213.3 | 36.423 | 42.139 | 45.716 |

Query embedding (one client): p50 556 ms, p95 820 ms, p99 922 ms; 200 calls, 2695 tokens.

## Ingestion

- 6221 documents in 239649 ms (26.0 documents per second)
- Embedding: 152129 ms, 63 calls, 2953324 tokens
- Index rebuild (REINDEX): 2823 ms

## Resources

- Storage: table 60555264 bytes, indexes 51716096 bytes, total 112271360 bytes
- Container memory: max 163.0 MiB over 71 samples (agenticgraph-vector-postgres-1)
- Infrastructure: PostgreSQL 16.14 (Debian 16.14-1.pgdg12+1) on aarch64-unknown-linux-gnu, compiled by gcc (Debian 12.2.0-14+deb12u1) 12.2.0, 64-bit, pgvector 0.8.3, PHP 8.4.23

## Cost

- At 0.02 per 1M tokens: ingest 0.059066, queries 5.4E-5

Runtime: 376.1 s for the whole run.
