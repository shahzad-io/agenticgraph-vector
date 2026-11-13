# vector-bench: wixqa-simulated, openai:text-embedding-3-small

> Recorded 2026-11-12 after rule L7 (spec section 12): the dense list of an unfiltered hybrid search now uses the HNSW index, where before it was an exact sequential scan. Hybrid: nDCG@10 0.4826 to 0.4830, store p50 at concurrency 1 49.095 ms to 28.42 ms. Dense was rerun and is unchanged in quality. Run on the kept collection (--collection). Dense and hybrid only; the +rerank modes of the 2026-10-17 baseline were not rerun (see git history).

- Created: 2026-11-12T19:47:11+00:00
- Dataset: WixQA Simulated (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1), index `CREATE INDEX vcol_94425b11e4be831591755a3653818f1b_embedding_idx ON public.vcol_94425b11e4be831591755a3653818f1b USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | hybrid (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.5265 | 0.4830 (-0.0435) |
| recall_at_1 | 0.3075 | 0.2050 (-0.1025) |
| recall_at_5 | 0.6150 | 0.5933 (-0.0217) |
| recall_at_10 | 0.7367 | 0.7783 (+0.0417) |
| hit_at_1 | 0.3250 | 0.2350 (-0.0900) |
| hit_at_5 | 0.6400 | 0.6250 (-0.0150) |
| hit_at_10 | 0.7600 | 0.7950 (+0.0350) |
| mrr_at_10 | 0.4678 | 0.4016 (-0.0661) |

Queries with fewer than 10 hits: dense 0, hybrid 0.

Hybrid settings: hybrid match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.8908 | 0.9458 | 0.9775 |
| hybrid | 0.9008 (+0.0100) | 0.9458 (+0.0000) | 0.9775 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| hybrid | dense | -0.0435 [-0.0887, +0.0018] | -0.0217 [-0.0817, +0.0383] | +0.0100 [-0.0125, +0.0350] |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9258 | 55.7, 60 |
| any | 50 | 0.9608 | 91.3, 100 |
| any | 100 | 0.9875 | 178.5, 197 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 497.9 | 1.964 | 2.48 | 2.853 |
| dense | 4 | 1209.1 | 3.057 | 4.497 | 5.467 |
| dense | 8 | 2158.4 | 3.447 | 5.061 | 5.432 |
| hybrid | 1 | 34.5 | 28.42 | 53.581 | 73.249 |
| hybrid | 4 | 99.2 | 32.345 | 60.776 | 80.231 |
| hybrid | 8 | 171.6 | 36.246 | 71.188 | 90.262 |

Query embedding (one client, from cache): p50 492 ms, p95 536 ms, p99 822 ms; 200 calls, 2695 tokens.

## Ingestion

- 6221 documents in 0 ms (0.0 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens
- Index rebuild: not available

## Resources

- Storage: table 68935680 bytes, indexes 56066048 bytes, total 125001728 bytes
- Container memory: max 165.6 MiB over 11 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0

## Cost

- No price given (`--price-per-1m-tokens`).

Runtime: 59.8 s for the whole run.
