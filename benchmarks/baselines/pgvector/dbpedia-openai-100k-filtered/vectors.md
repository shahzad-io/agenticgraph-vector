# vector-bench: dbpedia-openai-100k-filtered on pgvector

- Created: 2026-11-13T08:57:15+00:00
- Dataset: H7 filtered set on dbpedia-openai-100k: declared fields tenant (keyword) and rank (integer); tenants t50, t10, t1 and t01 hold 50%, 10%, 1% and 0.1% of the rows (MIT); ground truth: computed offline: exact cosine top 100 among each tenant's rows
- Size: 100000 rows, 1000 queries, 1536 dimensions, cosine
- Store: pgvector (pgvector), engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7)
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)
- Embedding calls: 0 (no model, no API key)
- Self-check: 100 exact searches agree with the ground truth
- Run time: 14.1 minutes

## Build store default: ingest 85.3 s (1173 rows/s), build 21.1 s, size 1,584 MiB

Index: `CREATE INDEX vcol_cbc5926058e54a7a32ecbd2e86fe6583_embedding_idx ON public.vcol_cbc5926058e54a7a32ecbd2e86fe6583 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`. Index size 785 MiB. Memory after the searches: 2.429GiB / 31.36GiB.

## Filter tenant = t50 (0.5 of the rows, 50000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| planner default | 1.0000 | 10.00 | 0 | 170.084 | 177.435 |
| index, iterative scan off | 0.9343 | 10.00 | 0 | 4.348 | 6.009 |
| index, iterative scan relaxed_order | 0.9343 | 10.00 | 0 | 3.692 | 4.605 |
| index, iterative scan strict_order | 0.9343 | 10.00 | 0 | 3.78 | 5.364 |
| exact | 1.0000 | 10.00 | 0 | 169.98 | 177.653 |

## Filter tenant = t10 (0.1 of the rows, 10000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| planner default | 1.0000 | 10.00 | 0 | 54.116 | 57.405 |
| index, iterative scan off | 0.4009 | 4.02 | 995 | 3.842 | 5.7 |
| index, iterative scan relaxed_order | 0.9417 | 10.00 | 0 | 5.438 | 7.314 |
| index, iterative scan strict_order | 0.8899 | 10.00 | 0 | 5.451 | 7.683 |
| exact | 1.0000 | 10.00 | 0 | 54.271 | 57.382 |

## Filter tenant = t1 (0.01 of the rows, 1000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| planner default | 1.0000 | 10.00 | 0 | 23.226 | 25.501 |
| index, iterative scan off | 0.0382 | 0.38 | 1000 | 3.777 | 5.785 |
| index, iterative scan relaxed_order | 0.9644 | 10.00 | 0 | 20 | 29.457 |
| index, iterative scan strict_order | 0.8999 | 10.00 | 0 | 20.628 | 29.333 |
| exact | 1.0000 | 10.00 | 0 | 23.494 | 26.031 |

## Filter tenant = t01 (0.001 of the rows, 100 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| planner default | 1.0000 | 10.00 | 0 | 20.571 | 21.96 |
| index, iterative scan off | 0.0034 | 0.03 | 1000 | 4.077 | 5.729 |
| index, iterative scan relaxed_order | 0.7890 | 10.00 | 4 | 37.459 | 43.004 |
| index, iterative scan strict_order | 0.7790 | 10.00 | 4 | 37.075 | 42.348 |
| exact | 1.0000 | 10.00 | 0 | 20.934 | 22.512 |

