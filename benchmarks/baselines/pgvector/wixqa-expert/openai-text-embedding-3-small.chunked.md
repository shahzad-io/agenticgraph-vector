# vector-bench: wixqa-expert, openai:text-embedding-3-small

- Created: 2026-10-17T19:16:09+00:00
- Dataset: WixQA ExpertWritten (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Chunks: 2000 characters, overlap 200, 11850 chunks; hits collapsed by `parent_id` before scoring (over-fetch 3x, rerank before collapse)
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, `CREATE INDEX vcol_172a092516a37766ba43751fdd54297c_embedding_idx ON public.vcol_172a092516a37766ba43751fdd54297c USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`

## Quality

| Metric | dense | dense+rerank (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.6013 | 0.7327 (+0.1314) |
| recall_at_1 | 0.3708 | 0.4917 (+0.1208) |
| recall_at_5 | 0.6883 | 0.8233 (+0.1350) |
| recall_at_10 | 0.7967 | 0.8900 (+0.0933) |
| hit_at_1 | 0.4250 | 0.5700 (+0.1450) |
| hit_at_5 | 0.7550 | 0.8900 (+0.1350) |
| hit_at_10 | 0.8600 | 0.9350 (+0.0750) |
| mrr_at_10 | 0.5658 | 0.7130 (+0.1472) |

Queries with fewer than 10 hits: dense 2, dense+rerank 2.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9283 | 0.9642 | 0.9642 |
| dense+rerank | 0.9283 (+0.0000) | 0.9642 (+0.0000) | 0.9642 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| dense+rerank | dense | +0.1314 [+0.0902, +0.1724] | +0.1350 [+0.0833, +0.1875] | +0.0000 [+0.0000, +0.0000] |

## Reranking (one client; latency added on top of the store time)

| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |
|---|---|---|---|---|---|---|---|
| dense+rerank | voyage (`rerank-3`) | 30 | 200 (0) | 1177 | 1616 | n/a | 1784647 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 331.9 | 2.813 | 4.107 | 5.196 |
| dense | 4 | 957.9 | 3.862 | 6.181 | 7.158 |
| dense | 8 | 1835.6 | 3.7 | 5.825 | 7.59 |

Query embedding (one client): p50 498 ms, p95 544 ms, p99 784 ms; 200 calls, 4546 tokens.

## Ingestion

- 6221 documents in 480156 ms (13.0 documents per second)
- Embedding: 315071 ms, 119 calls, 3195444 tokens
- Index rebuild (REINDEX): 3975 ms

## Resources

- Storage: table 113704960 bytes, indexes 97878016 bytes, total 211582976 bytes
- Container memory: max 257.2 MiB over 123 samples (agenticgraph-vector-postgres-1)
- Infrastructure: PostgreSQL 16.14 (Debian 16.14-1.pgdg12+1) on aarch64-unknown-linux-gnu, compiled by gcc (Debian 12.2.0-14+deb12u1) 12.2.0, 64-bit, pgvector 0.8.3, PHP 8.4.23

## Cost

- At 0.02 per 1M tokens: ingest 0.063909, queries 9.1E-5
- Rerank (voyage, `rerank-3`): 200 requests this run (n/a units, 1784647 tokens), cost 0.089232; per query 0.000446 (prices: none per 1K searches, 0.05 per 1M tokens)

Runtime: 891.0 s for the whole run.
