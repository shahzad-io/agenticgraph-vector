# vector-bench: wixqa-simulated, openai:text-embedding-3-small

> Recorded 2026-11-12 after the pgvector search latency fix (spec section 12): an unfiltered search now uses the HNSW index. The earlier numbers of this baseline (2026-10-17) were exact searches: the planner chose a sequential scan over the index on this collection size. Dense and hybrid only; the +rerank modes of the earlier baseline were not rerun (see git history).

- Created: 2026-11-12T19:10:58+00:00
- Dataset: WixQA Simulated (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1), index `CREATE INDEX vcol_94425b11e4be831591755a3653818f1b_embedding_idx ON public.vcol_94425b11e4be831591755a3653818f1b USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | hybrid (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.5265 | 0.4826 (-0.0439) |
| recall_at_1 | 0.3075 | 0.2050 (-0.1025) |
| recall_at_5 | 0.6150 | 0.5858 (-0.0292) |
| recall_at_10 | 0.7367 | 0.7833 (+0.0467) |
| hit_at_1 | 0.3250 | 0.2350 (-0.0900) |
| hit_at_5 | 0.6400 | 0.6200 (-0.0200) |
| hit_at_10 | 0.7600 | 0.8000 (+0.0400) |
| mrr_at_10 | 0.4678 | 0.3999 (-0.0679) |

Queries with fewer than 10 hits: dense 0, hybrid 0.

Hybrid settings: hybrid match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.8908 | 0.9458 | 0.9775 |
| hybrid | 0.9158 (+0.0250) | 0.9508 (+0.0050) | 0.9825 (+0.0050) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| hybrid | dense | -0.0439 [-0.0891, +0.0011] | -0.0292 [-0.0883, +0.0308] | +0.0250 [+0.0000, +0.0550] |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9258 | 55.7, 60 |
| any | 50 | 0.9608 | 91.3, 100 |
| any | 100 | 0.9875 | 178.5, 197 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 457.5 | 2.116 | 2.674 | 3.11 |
| dense | 4 | 1146.1 | 3.246 | 5.044 | 5.322 |
| dense | 8 | 2111.3 | 3.41 | 5.303 | 6.06 |
| hybrid | 1 | 20.1 | 49.095 | 71.287 | 92.841 |
| hybrid | 4 | 67.7 | 50.97 | 75.084 | 98.765 |
| hybrid | 8 | 121.9 | 55.295 | 83.224 | 103.238 |

Query embedding (one client, from cache): p50 492 ms, p95 536 ms, p99 822 ms; 200 calls, 2695 tokens.

## Ingestion

- 6221 documents in 0 ms (0.0 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens
- Index rebuild: not available

## Resources

- Storage: table 68935680 bytes, indexes 56066048 bytes, total 125001728 bytes
- Container memory: max 164.5 MiB over 13 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0

## Cost

- No price given (`--price-per-1m-tokens`).

Runtime: 90.0 s for the whole run.
