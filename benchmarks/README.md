# Retrieval benchmarks

Dev only: the runner, the scripts and the baselines are export-ignored.

## Datasets

`datasets.json` pins every dataset: its source (the SciFact archive by sha256, WixQA by Hugging Face revision) and the sha256 of each exported file.

| Name | Corpus | Queries | License |
|---|---|---|---|
| `scifact` | BEIR SciFact, 5,183 abstracts | 300 (test split) | CC BY-SA 4.0 |
| `wixqa-expert` | Wix KB, 6,221 articles | 200 ExpertWritten | MIT, Wix.com AI Research |
| `wixqa-simulated` | Wix KB, 6,221 articles | 200 Simulated | MIT, Wix.com AI Research |

Export them (Python standard library only, nothing to install) into `build/benchmark/datasets/`:

```bash
python3 benchmarks/export-datasets.py
```

The script fails when an exported file does not match its manifest sha256. `--update-manifest` records new checksums after a deliberate source change.

## Running

`bin/vector-bench` needs pgvector (the `docker-compose.yml` database on 5433, or the `VECTOR_PG_*` env keys) and the embedding provider's key (`OPENAI_API_KEY` for `openai:`).

A batch of 100 documents returns about 3 MB of vectors at 1536 dimensions, which can outlast Prism's 30 second default. Set `PRISM_REQUEST_TIMEOUT=120` for full runs.

```bash
PRISM_REQUEST_TIMEOUT=120 bin/vector-bench --dataset=scifact --model=openai:text-embedding-3-small --dimensions=1536 \
    --price-per-1m-tokens=0.02 --compare
```

| Option | Default | |
|---|---|---|
| `--dataset` | required | A name from `datasets.json` |
| `--model` | required | `provider:model` through Prism, or `deterministic` (plumbing checks only) |
| `--dimensions` | provider | Sent to the provider |
| `--mode` | `dense` | Comma separated: `dense`, `hybrid` (vector plus keyword search, the query text as keywords in match mode `any`, RRF). With `hybrid` the collection is created with keyword search. Rerank arrives with B7 |
| `--filtered` | off | Filtered query set: documents get a derived field at ingest (WixQA `product`, the title before its first `:`; SciFact `bucket`, crc32 of the id modulo 20) and each query searches only its relevant documents' value. Queries whose relevant documents have more than one value, or none, are left out. The dataset files and pins are unchanged |
| `--iterative-scan` | store config | Comma separated `off`, `relaxed_order`, `strict_order`: every mode runs once per setting (report key `mode@setting`) |
| `--match` | `vector.hybrid.match` | Comma separated `any`, `websearch`: hybrid match modes to sweep |
| `--keyword-weight` | `vector.hybrid.keyword_weight` | Comma separated weights (at least 0) to sweep. With `--match` or `--keyword-weight`, hybrid report keys are `hybrid:<match>:<weight>` |
| `--keep` | off | Keep the ingested collection; a later `--collection` run reuses it with no embedding calls when the query vectors are cached, and reports the ingest that built it |
| `--concurrency` | `1,4,8` | Client processes per throughput run |
| `--warmup` | `20` | Untimed queries before the timed runs |
| `--queries`, `--docs` | whole dataset | A fixed smaller run; the corpus keeps the relevant documents of the kept queries |
| `--truncate` | `0` (none) | Cut documents to N characters, recorded in the report |
| `--out` | `/tmp/ag2/bench` | Report folder. A folder inside this repo is refused |
| `--compare[=file]` | `baselines/<dataset>/<model-slug>.json` | Exit 1 on a quality drop beyond the tolerance |
| `--tolerance` | `0.01` | Absolute drop allowed per quality metric |
| `--price-per-1m-tokens` | none | Embedding price for the cost section; never built in |
| `--report=<file>` | none | With `--compare`: compare an existing report, no run |
| `--collection=<name>` | none | Search an existing collection or alias that holds exactly the corpus (for example after `vector:migrate`): no ingest, no `REINDEX`, not dropped. The ingest section reads 0. `hybrid` needs it migrated with `--keyword`; `--filtered` cannot reuse one |

Preflight checks the env keys, pgvector, the dataset checksums and one embedding call, and stops with exit 2 naming what is missing.

## What a run does

1. Ingests the corpus once into a fresh collection (HNSW, cosine), every document with `lang: en` metadata (the datasets are English, so keyword search stems and drops stop words), timing the upsert and the embedding part of it (`embeddingMs` on the upsert events), and counting embedding calls and tokens from `VectorEmbeddingCompleted`.
2. Index build time is a timed `REINDEX` of the embedding index (HNSW grows during inserts, so it has no separate build step). Storage is `pg_table_size` and `pg_indexes_size`.
3. Embeds each query once (one call per query, timed) and caches the vectors in `<out>/cache/` per dataset, model and dimensions.
4. Runs the warmup, then for each concurrency level starts N processes that wait at a ready/go barrier and search with the cached vectors (`usingVector`), so the latency is store time only. Children write one JSON line per query; the parent merges them.
5. Samples the pgvector container's memory (`docker stats`) during the run, and drops the collection at the end.

## Report

`<out>/<dataset>.<model-slug>.<timestamp>.json` and `.md`:

- Quality (from the run's rankings, top 10): nDCG@10, recall@1/5/10 (BEIR: relevant found in the top k divided by all relevant), hit@1/5/10 (any relevant in the top k), MRR@10. Every mode is shown, each as a delta against the first one (`dense` when it ran), and the number of queries that returned fewer than 10 hits per mode. A filtered run names its field and query count, and is compared only with a filtered baseline.
- Candidate recall at the same budget: recall@30 (the reranker's default candidate count), @50 and @100 of each mode's own top K (`hnsw.ef_search` raised to K). The raw union of the dense and keyword top K is reported separately with its document count (up to 2K); it is not a same-budget comparison.
- Paired differences against dense: per-query nDCG@10 and candidate recall@30, mean and a seeded bootstrap 95% interval (10,000 resamples).
- Query performance: QPS and p50/p95/p99 store latency per concurrency level; query embedding latency separately.
- Ingestion: documents per second, embedding time, index rebuild time.
- Resources, cost (calls, tokens, and the price you give), infrastructure versions.

## Baselines

Copy a full run's JSON report to `baselines/<dataset>/<model-slug>.json` (for example `baselines/scifact/openai-text-embedding-3-small.json`) and commit it. `--compare` refuses a baseline with other settings (dataset revision, model, dimensions, truncation, sizes) and fails only on quality drops; latency and cost changes are printed as notes. The optional Ollama baseline (`--model=ollama:mxbai-embed-large --dimensions=1024 --truncate=512`) is never compared with an untruncated OpenAI run.
