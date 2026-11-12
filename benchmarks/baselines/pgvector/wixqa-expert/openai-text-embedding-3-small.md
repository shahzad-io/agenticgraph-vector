# vector-bench: wixqa-expert, openai:text-embedding-3-small

> Recorded 2026-11-12 after rule L7 (spec section 12): the dense list of an unfiltered hybrid search now uses the HNSW index, where before it was an exact sequential scan. Hybrid: nDCG@10 0.6006 to 0.6016, store p50 at concurrency 1 69.555 ms to 45.385 ms. Dense was rerun and is unchanged in quality. Run on the kept collection (--collection). Dense and hybrid only; the +rerank modes of the 2026-10-17 baseline were not rerun (see git history).

- Created: 2026-11-12T19:46:11+00:00
- Dataset: WixQA ExpertWritten (200 queries) over the Wix KB corpus (revision `d662dc42479c14e202eccd832f8c4b66a035c4cc`, MIT)
- Primary metric: ndcg_at_10
- Model: `openai:text-embedding-3-small`, 1536 dimensions
- Truncation: none
- Sizes: 6221 documents, 200 queries, top 10, warmup 20
- Store: pgvector, engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1), index `CREATE INDEX vcol_75a68c06fe6d82b19a878e2455c45d14_embedding_idx ON public.vcol_75a68c06fe6d82b19a878e2455c45d14 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`, capabilities: list_ids, update_metadata, delete_by_filter, filter_not, filter_contains, filter_range, keyword, aliases, queued_ingest, rebuild_index, keyword_websearch, keyword_languages, atomic_batches, migrations, undeclared_filters

## Quality

| Metric | dense | hybrid (delta vs dense) |
|---|---|---|
| ndcg_at_10 | 0.5745 | 0.6016 (+0.0271) |
| recall_at_1 | 0.3158 | 0.3458 (+0.0300) |
| recall_at_5 | 0.6442 | 0.7000 (+0.0558) |
| recall_at_10 | 0.7942 | 0.8208 (+0.0267) |
| hit_at_1 | 0.3650 | 0.4050 (+0.0400) |
| hit_at_5 | 0.7100 | 0.7600 (+0.0500) |
| hit_at_10 | 0.8550 | 0.8650 (+0.0100) |
| mrr_at_10 | 0.5277 | 0.5543 (+0.0266) |

Queries with fewer than 10 hits: dense 0, hybrid 0.

Hybrid settings: hybrid match any, keyword weight 0.25.

## Candidate recall (same budget: top K of each mode)

| Mode | recall_at_30 | recall_at_50 | recall_at_100 |
|---|---|---|---|
| dense | 0.9325 | 0.9617 | 0.9717 |
| hybrid | 0.9467 (+0.0142) | 0.9617 (+0.0000) | 0.9717 (+0.0000) |

## Paired differences (per query, bootstrap 95% interval)

| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |
|---|---|---|---|---|
| hybrid | dense | +0.0271 [-0.0182, +0.0711] | +0.0558 [+0.0042, +0.1075] | +0.0142 [+0.0017, +0.0317] |

## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)

| Match | K | Recall | Documents (mean, max) |
|---|---|---|---|
| any | 30 | 0.9617 | 56.3, 60 |
| any | 50 | 0.9792 | 92.7, 100 |
| any | 100 | 0.9842 | 181.8, 200 |

## Query performance (store time, pre-embedded queries)

| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |
|---|---|---|---|---|---|
| dense | 1 | 436.9 | 2.176 | 2.919 | 3.506 |
| dense | 4 | 1145.6 | 3.323 | 4.799 | 5.315 |
| dense | 8 | 1984.7 | 3.788 | 5.331 | 6.238 |
| hybrid | 1 | 19.3 | 45.385 | 102.089 | 132.661 |
| hybrid | 4 | 62.4 | 55.21 | 119.3 | 152.04 |
| hybrid | 8 | 106.6 | 61.422 | 136.874 | 167.693 |

Query embedding (one client, from cache): p50 498 ms, p95 544 ms, p99 784 ms; 200 calls, 4546 tokens.

## Ingestion

- 6221 documents in 0 ms (0.0 documents per second)
- Embedding: 0 ms, 0 calls, n/a tokens
- Index rebuild: not available

## Resources

- Storage: table 68943872 bytes, indexes 56066048 bytes, total 125009920 bytes
- Container memory: max 173.8 MiB over 15 samples (agvector-multistore-postgres-1)
- Infrastructure: PHP 8.4.23, Darwin 24.6.0

## Cost

- No price given (`--price-per-1m-tokens`).

Runtime: 100.7 s for the whole run.
