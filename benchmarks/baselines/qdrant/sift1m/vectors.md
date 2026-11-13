# vector-bench: sift1m on qdrant

- Created: 2026-11-13T08:24:44+00:00
- Dataset: TEXMEX ANN_SIFT1M: 1,000,000 SIFT descriptors, 10,000 queries, 128 dimensions, L2 (CC0 1.0); ground truth: source (sift_groundtruth.ivecs, top 100)
- Size: 1000000 rows, 1000 queries, 128 dimensions, l2
- Store: qdrant (qdrant), engine qdrant 1.20.0
- Machine: Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs, 33670656000 bytes)
- Embedding calls: 0 (no model, no API key)
- Self-check: 100 exact searches agree with the ground truth
- Run time: 3.8 minutes

## Build m8-ef64: ingest 30.5 s (32768 rows/s), build 2.0 s, size 3,730 MiB

Index: `hnsw m=8 ef_construct=64 full_scan_threshold=10000, 991900 of 1000000 points indexed`. Index size 108 MiB. Memory after the searches: 467.2MiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| hnsw_ef 40 | 0.9487 | 0.9685 | 3.493 | 5.099 | 7.332 | 305.6 | 3395.4 |
| hnsw_ef 80 | 0.9794 | 0.9685 | 3.085 | 5.66 | 6.829 | 277.6 | 3098.2 |
| hnsw_ef 160 | 0.9932 | 0.9854 | 3.501 | 6.213 | 7.426 | 254.1 | 2189.1 |
| hnsw_ef 320 | 0.9966 | 0.9954 | 3.596 | 6.792 | 9.058 | 249 | 1517.2 |

## Build m16-ef64: ingest 29.4 s (33988 rows/s), build 2.0 s, size 3,749 MiB

Index: `hnsw m=16 ef_construct=64 full_scan_threshold=10000, 985500 of 1000000 points indexed`. Index size 132 MiB. Memory after the searches: 483.6MiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| hnsw_ef 40 | 0.9766 | 0.9863 | 3.719 | 5.891 | 7.399 | 260.6 | 2850.8 |
| hnsw_ef 80 | 0.9915 | 0.9863 | 4.164 | 6.558 | 8.327 | 233.3 | 2428.7 |
| hnsw_ef 160 | 0.9965 | 0.9949 | 3.993 | 6.951 | 8.129 | 225.1 | 1851.6 |
| hnsw_ef 320 | 0.9981 | 0.9988 | 4.008 | 7.101 | 13.973 | 223.4 | 1285.2 |

## Build m16-ef128: ingest 32.6 s (30634 rows/s), build 3.0 s, size 3,824 MiB

Index: `hnsw m=16 ef_construct=128 full_scan_threshold=10000, 997100 of 1000000 points indexed`. Index size 152 MiB. Memory after the searches: 496.6MiB / 31.36GiB.

| Search | Recall@10 | Recall@100 | p50 ms | p95 ms | p99 ms | QPS c1 | QPS c8 |
|---|---|---|---|---|---|---|---|
| hnsw_ef 40 | 0.9818 | 0.9931 | 2.455 | 5.195 | 8.184 | 352.6 | 3052.5 |
| hnsw_ef 80 | 0.9951 | 0.9931 | 3.733 | 5.936 | 7.707 | 250.3 | 2460.6 |
| hnsw_ef 160 | 0.9988 | 0.9979 | 3.621 | 6.318 | 8.296 | 248.4 | 1829 |
| hnsw_ef 320 | 0.9991 | 0.9996 | 3.965 | 6.164 | 7.963 | 232 | 1316.1 |

