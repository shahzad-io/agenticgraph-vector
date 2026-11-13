# vector-bench: dbpedia-openai-100k-filtered on pgvector

- Created: 2026-11-13T13:04:43+00:00
- Dataset: H7 filtered set on dbpedia-openai-100k: declared fields tenant (keyword) and rank (integer); tenants t50, t10, t1 and t01 hold 50%, 10%, 1% and 0.1% of the rows (MIT); ground truth: computed offline: exact cosine top 100 among each tenant's rows
- Size: 100000 rows, 1000 queries, 1536 dimensions, cosine
- Store: pgvector (pgvector), engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7)
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)
- Embedding calls: 0 (no model, no API key)
- Self-check: 100 exact searches agree with the ground truth
- Run time: 2.3 minutes

## Build store default: ingest 84.4 s (1186 rows/s), build 20.7 s, size 1,588 MiB

Index: `CREATE INDEX vcol_2784251c802bc0d46097c386a56a72eb_embedding_idx ON public.vcol_2784251c802bc0d46097c386a56a72eb USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`. Index size 788 MiB. Memory after the searches: 2.44GiB / 31.36GiB.

## Filter tenant = t50 (0.5 of the rows, 50000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| driver, filter_plan auto | 0.9844 | 10.00 | 0 | 6.674 | 7.925 |

## Filter tenant = t10 (0.1 of the rows, 10000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| driver, filter_plan auto | 0.9631 | 10.00 | 0 | 6.784 | 8.573 |

## Filter tenant = t1 (0.01 of the rows, 1000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| driver, filter_plan auto | 1.0000 | 10.00 | 0 | 5.589 | 6.806 |

## Filter tenant = t01 (0.001 of the rows, 100 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| driver, filter_plan auto | 1.0000 | 10.00 | 0 | 2.124 | 3.219 |

