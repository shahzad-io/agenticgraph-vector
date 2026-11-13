# vector-bench: dbpedia-openai-100k-filtered on qdrant

- Created: 2026-11-13T10:10:17+00:00
- Dataset: H7 filtered set on dbpedia-openai-100k: declared fields tenant (keyword) and rank (integer); tenants t50, t10, t1 and t01 hold 50%, 10%, 1% and 0.1% of the rows (MIT); ground truth: computed offline: exact cosine top 100 among each tenant's rows
- Size: 100000 rows, 1000 queries, 1536 dimensions, cosine
- Store: qdrant (qdrant), engine qdrant 1.20.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)
- Embedding calls: 0 (no model, no API key)
- Self-check: 100 exact searches agree with the ground truth
- Run time: 1.3 minutes

## Build store default: ingest 17.5 s (5722 rows/s), build 5.1 s, size 2,754 MiB

Index: `hnsw m=16 ef_construct=100 full_scan_threshold=10000, 99700 of 100000 points indexed`. Index size 10 MiB. Memory after the searches: 317.9MiB / 31.36GiB.

## Filter tenant = t50 (0.5 of the rows, 50000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| default, declared fields | 0.9880 | 10.00 | 0 | 4.291 | 7.643 |
| exact | 1.0000 | 10.00 | 0 | 8.816 | 9.8 |

## Filter tenant = t10 (0.1 of the rows, 10000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| default, declared fields | 0.9999 | 10.00 | 0 | 4.349 | 7.763 |
| exact | 1.0000 | 10.00 | 0 | 5.214 | 8.151 |

## Filter tenant = t1 (0.01 of the rows, 1000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| default, declared fields | 1.0000 | 10.00 | 0 | 3.303 | 6.384 |
| exact | 1.0000 | 10.00 | 0 | 3.151 | 6.529 |

## Filter tenant = t01 (0.001 of the rows, 100 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| default, declared fields | 1.0000 | 10.00 | 0 | 3.021 | 5.662 |
| exact | 1.0000 | 10.00 | 0 | 4.571 | 5.776 |

