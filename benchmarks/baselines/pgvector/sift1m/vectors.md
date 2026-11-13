# vector-bench: sift1m on pgvector

- Created: 2026-11-13T08:20:56+00:00
- Dataset: TEXMEX ANN_SIFT1M: 1,000,000 SIFT descriptors, 10,000 queries, 128 dimensions, L2 (CC0 1.0); ground truth: source (sift_groundtruth.ivecs, top 100)
- Size: 1000000 rows, 1000 queries, 128 dimensions, l2
- Store: pgvector (pgvector), engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7)
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)
- Embedding calls: 0 (no model, no API key)
- Self-check: 100 exact searches agree with the ground truth
- Run time: 14.9 minutes

## Build m8-ef64: ingest 109.4 s (9140 rows/s), build 23.5 s, size 1,291 MiB

Index: `CREATE INDEX vcol_ce6eae8cb83e2d536d4d00645cded87f_embedding_idx ON public.vcol_ce6eae8cb83e2d536d4d00645cded87f USING hnsw (embedding vector_l2_ops) WITH (m='8', ef_construction='64')`. Index size 733 MiB. Memory after the searches: 2.06GiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| ef_search 40 | 0.8428 | 0.8447 | 1.646 | 1.942 | 2.336 | 593.6 | 2195.5 |
| ef_search 80 | 0.9226 | 0.8447 | 1.945 | 2.342 | 2.674 | 502.1 | 1924.6 |
| ef_search 160 | 0.9641 | 0.9055 | 2.71 | 3.29 | 4.445 | 361.3 | 1641.7 |
| ef_search 320 | 0.9859 | 0.9608 | 3.82 | 4.804 | 5.687 | 258.3 | 1245.3 |

## Build m16-ef64: ingest 212.4 s (4708 rows/s), build 42.0 s, size 1,374 MiB

Index: `CREATE INDEX vcol_5f657eef4a56d26295d8876e9324145c_embedding_idx ON public.vcol_5f657eef4a56d26295d8876e9324145c USING hnsw (embedding vector_l2_ops) WITH (m='16', ef_construction='64')`. Index size 815 MiB. Memory after the searches: 2.219GiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| ef_search 40 | 0.9268 | 0.9329 | 2.156 | 2.883 | 3.578 | 445.9 | 1836.8 |
| ef_search 80 | 0.9746 | 0.9329 | 2.741 | 3.356 | 3.774 | 359.9 | 1529.8 |
| ef_search 160 | 0.9918 | 0.9668 | 4.085 | 5.343 | 5.953 | 243.2 | 1230.3 |
| ef_search 320 | 0.9974 | 0.9905 | 6.042 | 7.367 | 8.362 | 167.3 | 914.5 |

## Build m16-ef128: ingest 318.8 s (3137 rows/s), build 68.3 s, size 1,373 MiB

Index: `CREATE INDEX vcol_d6634db4b0a2d766a7104311d0be44f5_embedding_idx ON public.vcol_d6634db4b0a2d766a7104311d0be44f5 USING hnsw (embedding vector_l2_ops) WITH (m='16', ef_construction='128')`. Index size 815 MiB. Memory after the searches: 2.22GiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| ef_search 40 | 0.9381 | 0.9424 | 2.283 | 3.041 | 3.408 | 420.6 | 1698.8 |
| ef_search 80 | 0.9802 | 0.9424 | 2.862 | 4.019 | 4.582 | 337.7 | 1499.4 |
| ef_search 160 | 0.9948 | 0.9738 | 4.214 | 5.068 | 6.473 | 238.6 | 1169.4 |
| ef_search 320 | 0.9982 | 0.9940 | 6.208 | 7.63 | 8.79 | 162.8 | 836.9 |

