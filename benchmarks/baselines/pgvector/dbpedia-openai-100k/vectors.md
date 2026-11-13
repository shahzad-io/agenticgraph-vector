# vector-bench: dbpedia-openai-100k on pgvector

- Created: 2026-11-13T08:38:40+00:00
- Dataset: DBpedia entities embedded with OpenAI text-embedding-3-large at 1,536 dimensions: the first 100,000 rows; 1,000 held-out rows of the same set as queries; cosine (MIT); ground truth: computed offline: exact cosine top 100 (numpy, float32)
- Size: 100000 rows, 1000 queries, 1536 dimensions, cosine
- Store: pgvector (pgvector), engine pgvector 0.8.3, Postgres 16.14 (Debian 16.14-1.pgdg12+1) (shared_buffers 4GB, maintenance_work_mem 4GB, max_parallel_maintenance_workers 7)
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)
- Embedding calls: 0 (no model, no API key)
- Self-check: 100 exact searches agree with the ground truth
- Run time: 13.9 minutes

## Build m8-ef64: ingest 37.8 s (2647 rows/s), build 67.3 s, size 1,580 MiB

Index: `CREATE INDEX vcol_173521ae17b7224051fa2de6e7e8c2fc_embedding_idx ON public.vcol_173521ae17b7224051fa2de6e7e8c2fc USING hnsw (embedding vector_cosine_ops) WITH (m='8', ef_construction='64')`. Index size 785 MiB. Memory after the searches: 2.422GiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| ef_search 40 | 0.8924 | 0.8590 | 3.247 | 4.77 | 5.485 | 285.5 | 1707.1 |
| ef_search 80 | 0.9383 | 0.8590 | 3.745 | 5.12 | 6.318 | 254.6 | 1481.3 |
| ef_search 160 | 0.9669 | 0.9100 | 4.595 | 5.543 | 7.99 | 213.3 | 1163.8 |
| ef_search 320 | 0.9807 | 0.9550 | 6.445 | 7.702 | 9.093 | 154.3 | 881.4 |

## Build m16-ef64: ingest 84.7 s (1180 rows/s), build 117.5 s, size 1,580 MiB

Index: `CREATE INDEX vcol_0667337b5133bad4dafd96dfd2783638_embedding_idx ON public.vcol_0667337b5133bad4dafd96dfd2783638 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='64')`. Index size 785 MiB. Memory after the searches: 2.423GiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| ef_search 40 | 0.9505 | 0.9321 | 3.691 | 4.691 | 6.415 | 260.5 | 1537.6 |
| ef_search 80 | 0.9769 | 0.9321 | 4.554 | 5.525 | 7.91 | 216.2 | 1206.2 |
| ef_search 160 | 0.9878 | 0.9615 | 6.168 | 7.421 | 8.23 | 161.6 | 867.4 |
| ef_search 320 | 0.9937 | 0.9841 | 9.083 | 11.241 | 13.113 | 109.8 | 654.6 |

## Build m16-ef128: ingest 115.5 s (866 rows/s), build 203.7 s, size 1,580 MiB

Index: `CREATE INDEX vcol_0e58f86418412fe3af0440ddda3912d5_embedding_idx ON public.vcol_0e58f86418412fe3af0440ddda3912d5 USING hnsw (embedding vector_cosine_ops) WITH (m='16', ef_construction='128')`. Index size 785 MiB. Memory after the searches: 2.424GiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| ef_search 40 | 0.9631 | 0.9444 | 3.495 | 4.59 | 6.745 | 274.2 | 1395.2 |
| ef_search 80 | 0.9867 | 0.9444 | 4.508 | 5.39 | 6.214 | 218.4 | 1175.8 |
| ef_search 160 | 0.9945 | 0.9731 | 6.38 | 7.724 | 9.706 | 154.9 | 879.2 |
| ef_search 320 | 0.9975 | 0.9920 | 9.783 | 11.719 | 12.696 | 103 | 609.5 |

