# vector-bench: dbpedia-openai-100k on qdrant

- Created: 2026-11-13T08:43:10+00:00
- Dataset: DBpedia entities embedded with OpenAI text-embedding-3-large at 1,536 dimensions: the first 100,000 rows; 1,000 held-out rows of the same set as queries; cosine (MIT); ground truth: computed offline: exact cosine top 100 (numpy, float32)
- Size: 100000 rows, 1000 queries, 1536 dimensions, cosine
- Store: qdrant (qdrant), engine qdrant 1.20.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)
- Embedding calls: 0 (no model, no API key)
- Self-check: 100 exact searches agree with the ground truth
- Run time: 4.5 minutes

## Build m8-ef64: ingest 14.5 s (6901 rows/s), build 2.0 s, size 3,510 MiB

Index: `hnsw m=8 ef_construct=64 full_scan_threshold=10000, 99500 of 100000 points indexed`. Index size 10 MiB. Memory after the searches: 316MiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| hnsw_ef 40 | 0.9605 | 0.9717 | 3.81 | 6.792 | 9.296 | 234.2 | 1570.4 |
| hnsw_ef 80 | 0.9815 | 0.9717 | 5.42 | 8.512 | 9.773 | 174.9 | 1142.4 |
| hnsw_ef 160 | 0.9924 | 0.9853 | 5.572 | 8.748 | 10.341 | 167.7 | 751.4 |
| hnsw_ef 320 | 0.9973 | 0.9948 | 6.749 | 9.914 | 14.244 | 136.8 | 500.4 |

## Build m16-ef64: ingest 14.7 s (6805 rows/s), build 2.0 s, size 3,519 MiB

Index: `hnsw m=16 ef_construct=64 full_scan_threshold=10000, 99000 of 100000 points indexed`. Index size 14 MiB. Memory after the searches: 327.8MiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| hnsw_ef 40 | 0.9821 | 0.9900 | 4.028 | 6.846 | 9.531 | 224.7 | 1135.5 |
| hnsw_ef 80 | 0.9931 | 0.9900 | 6.171 | 10.284 | 12.942 | 146.1 | 800.7 |
| hnsw_ef 160 | 0.9979 | 0.9955 | 6.733 | 9.452 | 11.05 | 144.1 | 528.1 |
| hnsw_ef 320 | 0.9997 | 0.9988 | 8.65 | 11.977 | 14.434 | 112.4 | 312.5 |

## Build m16-ef128: ingest 18.0 s (5552 rows/s), build 6.1 s, size 2,887 MiB

Index: `hnsw m=16 ef_construct=128 full_scan_threshold=10000, 100000 of 100000 points indexed`. Index size 11 MiB. Memory after the searches: 322MiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| hnsw_ef 40 | 0.9874 | 0.9935 | 4.269 | 7.645 | 10.758 | 200.2 | 1152.3 |
| hnsw_ef 80 | 0.9962 | 0.9935 | 5.732 | 9.849 | 11.711 | 158.5 | 775 |
| hnsw_ef 160 | 0.9989 | 0.9976 | 7.235 | 10.303 | 11.943 | 132.9 | 488.8 |
| hnsw_ef 320 | 0.9999 | 0.9995 | 9.131 | 12.959 | 18.512 | 103.9 | 335.1 |

