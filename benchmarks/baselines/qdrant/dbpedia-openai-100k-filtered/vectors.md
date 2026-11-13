# vector-bench: dbpedia-openai-100k-filtered on qdrant

- Created: 2026-11-13T08:58:34+00:00
- Dataset: H7 filtered set on dbpedia-openai-100k: declared fields tenant (keyword) and rank (integer); tenants t50, t10, t1 and t01 hold 50%, 10%, 1% and 0.1% of the rows (MIT); ground truth: computed offline: exact cosine top 100 among each tenant's rows
- Size: 100000 rows, 1000 queries, 1536 dimensions, cosine
- Store: qdrant (qdrant), engine qdrant 1.20.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)
- Embedding calls: 0 (no model, no API key)
- Self-check: 100 exact searches agree with the ground truth
- Run time: 1.3 minutes

## Build store default: ingest 17.1 s (5848 rows/s), build 6.1 s, size 2,773 MiB

Index: `hnsw m=16 ef_construct=100 full_scan_threshold=10000, 99000 of 100000 points indexed`. Index size 11 MiB. Memory after the searches: 281.4MiB / 31.36GiB.

## Filter tenant = t50 (0.5 of the rows, 50000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| default, declared fields | 0.9914 | 10.00 | 0 | 5.226 | 8.171 |
| exact | 1.0000 | 10.00 | 0 | 8.753 | 10.164 |

## Filter tenant = t10 (0.1 of the rows, 10000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| default, declared fields | 1.0000 | 10.00 | 0 | 4.857 | 8.051 |
| exact | 1.0000 | 10.00 | 0 | 5.067 | 8.393 |

## Filter tenant = t1 (0.01 of the rows, 1000 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| default, declared fields | 1.0000 | 10.00 | 0 | 4.18 | 7.022 |
| exact | 1.0000 | 10.00 | 0 | 3.376 | 6.669 |

## Filter tenant = t01 (0.001 of the rows, 100 rows)

| Variant | Recall@10 | Hits mean | Queries under 10 hits | p50 ms | p95 ms |
|---|---|---|---|---|---|
| default, declared fields | 1.0000 | 10.00 | 0 | 3.252 | 6.284 |
| exact | 1.0000 | 10.00 | 0 | 2.725 | 6.136 |

