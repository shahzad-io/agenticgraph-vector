# vector-bench: scifact, openai:text-embedding-3-small

> Recorded 2026-11-12 after rule L7 (spec section 12): the dense list of an unfiltered hybrid search now uses the HNSW index, where before it was an exact sequential scan. Hybrid: nDCG@10 0.6927 to 0.6926, store p50 at concurrency 1 39.548 ms to 21.038 ms. Dense was rerun and is unchanged in quality. Run on the kept collection (--collection). Dense and hybrid only; the +rerank modes of the 2026-10-17 baseline were not rerun (see git history).

- Created: 2026-11-12T19:44:30+00:00
- Dataset: BEIR SciFact, test split (300 queries) (revision `536e14446a0ba56ed1398ab1055f39fe852686ecad24a6306c80c490fa8e0165`, CC BY-SA 4.0)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Sizes: 5183 documents, 300 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1), index `CREATE INDEX vcol_1c1541b7e9f4859152df7d4a55e71536_embedding_idx ON public.vcol_1c1541b7e9f4859152df7d4a55e71536 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | hybrid (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.7324 | 0.6926 (-0.0398) |
| recall_at_1 | 0.5916 | 0.5209 (-0.0707) |
| recall_at_5 | 0.7933 | 0.7811 (-0.0122) |
| recall_at_10 | 0.8576 | 0.8524 (-0.0051) |
| hit_at_1 | 0.6233 | 0.5500 (-0.0733) |
| hit_at_5 | 0.8100 | 0.8067 (-0.0033) |
| hit_at_10 | 0.8667 | 0.8633 (-0.0033) |
| mrr_at_10 | 0.6997 | 0.6513 (-0.0484) |

Queries with fewer than 10 hits: dense 0, hybrid 0.

Hybrid settings: hybrid match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9117 | 0.9450 | 0.9733 |
| hybrid | 0.9117 (+0.0000) | 0.9450 (+0.0000) | 0.9733 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| hybrid | dense | -0.0398 [-0.0627, -0.0173] | -0.0122 [-0.0390, +0.0142] | +0.0000 [+0.0000, +0.0000] |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9317 | 54.4, 60 |
| any | 50 | 0.9583 | 90.4, 100 |
| any | 100 | 0.9867 | 178.2, 199 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 522.2 | 1.881 | 2.217 | 2.444 |
| dense | 4 | 1233.0 | 2.988 | 4.567 | 5.148 |
| dense | 8 | 2051.8 | 3.495 | 5.029 | 6.283 |
| hybrid | 1 | 43.5 | 21.038 | 44.621 | 62.918 |
| hybrid | 4 | 129.3 | 26.809 | 56.728 | 74.91 |
| hybrid | 8 | 218.9 | 31.023 | 66.481 | 86.134 |

Query embedding (one client, from cache): p50 515 ms, p95 902 ms, p99 1266 ms; 300 calls, 5898 tokens.

## Ingestion

- 5183 documents in 0 ms (0.0 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens
- Index rebuild: not available

## Resources

- Storage: table 53125120 bytes, indexes 47210496 bytes, total 100335616 bytes
- Container memory: max 178.8 MiB over 11 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0

## Cost

- No price given (`--price-per-1m-tokens`).

Runtime: 67.9 s for the whole run.
