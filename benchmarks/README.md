# Retrieval benchmarks

Dev only: the runner, the scripts and the baselines are export-ignored.

## Datasets

`datasets.json` pins every dataset: its source (the SciFact archive by sha256, WixQA by Hugging Face revision) and the sha256 of each exported file (the checksum a run checks). Each entry also names its `tier` (`small`, `medium`, `large`), its `kind` (`text`, or `vectors` for sets that ship vectors), its `primary_metric` and a `license_note`; a run refuses an entry without them.

A qrels file holds, per query, a list of relevant document ids (binary) or a map of document id to grade. Grades are kept: nDCG@10 uses them as linear gains (grade 0 is not relevant), so binary sets give the same numbers as before.

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

### Vector sets (no embedding model)

| Name | Rows | Queries | Dimensions | Ground truth | License |
|---|---|---|---|---|---|
| `sift1m` | 1,000,000 SIFT descriptors (TEXMEX, IRISA FTP) | 10,000 | 128, L2 | the source's top 100 | CC0 1.0 |
| `dbpedia-openai-100k` | 100,000 DBpedia entities, OpenAI text-embedding-3-large at 1,536 (Qdrant on Hugging Face, three parquet shards) | 1,000 held-out rows | 1,536, cosine | computed offline, top 100 | MIT (texts: DBpedia, CC BY-SA 3.0) |
| `dbpedia-openai-100k-filtered` | the set above with declared fields `tenant` (keyword) and `rank` (integer), seeded | 1,000, at each of 4 selectivities | 1,536, cosine | computed offline per selectivity | as above |

`tenant` values `t50`, `t10`, `t1` and `t01` hold exactly 50%, 10%, 1% and 0.1% of the rows; the rest go to tenants of 100 rows. The export pins every source file by sha256 (the SIFT archive, each parquet shard) and every exported file. It needs numpy and pyarrow, so a `vectors` set exports only when named:

```bash
python3 -m venv build/benchmark/venv && build/benchmark/venv/bin/pip install -r benchmarks/requirements-vectors.txt
build/benchmark/venv/bin/python -I benchmarks/export-datasets.py sift1m dbpedia-openai-100k dbpedia-openai-100k-filtered
```

Each set is written in the vector cache format (below): `base` and `queries` as `.f32` and `.index.jsonl` (the sha256 of a row is that of its float32 bytes), and `ground_truth.json`. About 1.3 GB on disk for the three sets, plus 1.3 GB of sources in `build/benchmark/sources/`.

A run on a `vectors` set has no `--model`, makes no embedding call and needs no API key (it fails if anything embeds):

```bash
bin/vector-bench --dataset=sift1m --store=pgvector --queries=1000 --ingest-workers=8
bin/vector-bench --dataset=dbpedia-openai-100k-filtered --store=qdrant --ingest-workers=8
```

- Index sweep: `benchmarks/sweeps/<driver>.json` lists three build settings (a collection each, ingested in full) and four search settings. Per build: ingest time and rate, build time (pgvector: `REINDEX` of the full table at the server's settings; Qdrant: the wait after the last write until the collection is green on two reads a second apart), the index and its indexed point count, sizes, and the store container's memory after the searches. Per search setting: recall@10 (the timed run's top 10), recall@100 (a separate pass at `take(100)` with the candidate depth raised to at least 100), latency p50/p95/p99 at concurrency 1 and QPS at each `--concurrency` (default `1,8`).
- Self-check (H3): before the sweep, the store's exact search on the first 100 queries must give the ground truth top 10 (ties allowed: an id outside it must be as near as its 10th). A mismatch stops the run with exit 2 (stop rule S3).
- Filtered set (H7): one collection at the store's default index with both fields declared; every `filtered` variant of the sweep file at each selectivity: recall@10, hits returned (mean, and queries under 10), latency p50/p95 at concurrency 1. On pgvector a variant may force the index (`SET enable_seqscan = off` for the session, through the probe); rule L2 does not apply to filtered searches. The self-check runs on 25 queries per selectivity. The pgvector baseline runs the driver as shipped (`filter_plan` auto, spec F5 to F7) with `--sweep=benchmarks/sweeps/pgvector-filter-auto.json`; the forced variants of `pgvector.json` set session options that now mix with that rule. The earlier five-variant numbers are in the history of the baseline file.
- `--ingest-workers=N` ingests with N processes, each upserting every N-th chunk through the store contract (the store batches as usual). One Postgres backend inserting into HNSW is CPU bound: SIFT1M takes about 45 minutes with one worker, 10 with eight.
- `--builds=a,b` runs only the named build settings; `--sweep=file` takes another sweep file; `--queries=N` the first N queries.

Reports (`<out>/<dataset>.<store>.<timestamp>.json` and `.md`) record the dataset, the store and its engine version, and the machine: CPU model, core count, memory, and whether the store runs in Docker (a container publishes its port) with the Docker host's CPUs and memory. Text runs record the machine too.

## Running

`bin/vector-bench` runs on any store in `config/vector.php`, chosen with `--store` (default: `vector.default`). It talks to the store through the store contract; what differs by store (engine version, sizes, the wait for the index, the index rebuild, the container to read memory from, the search options of an index setting) sits behind a `BenchStoreProbe`: `PgvectorBenchProbe` (the `docker-compose.yml` database on 5433, or the `VECTOR_PG_*` env keys), `QdrantBenchProbe` (`VECTOR_QDRANT_URL`, else the `docker-compose.yml` Qdrant on 16333), and `GenericBenchProbe` for any other driver, whose values the report prints as "not available". A probe is found by the driver name (`<Driver>BenchProbe`). A run that embeds needs the embedding provider's key (`OPENAI_API_KEY` for `openai:`); a run whose vectors are all cached needs none.

A batch of 100 documents returns about 3 MB of vectors at 1536 dimensions, which can outlast Prism's 30 second default. Set `PRISM_REQUEST_TIMEOUT=120` for full runs.

```bash
PRISM_REQUEST_TIMEOUT=120 bin/vector-bench --dataset=scifact --model=openai:text-embedding-3-small --dimensions=1536 \
    --price-per-1m-tokens=0.02 --compare
```

| Option | Default | |
|---|---|---|
| `--dataset` | required | A name from `datasets.json` |
| `--store` | `vector.default` | A `vector.stores` name. `hybrid` is refused on a store without the `keyword` capability |
| `--exact` | off | Exact search (the store's `exact: true` search option on pgvector and Qdrant; the index is kept), for cross-store agreement |
| `--yes` | off | Start an embedding run above `--max-tokens` |
| `--max-tokens` | `1000000` | Estimated embedding tokens (characters / 4) above which a run needs `--yes` |
| `--model` | required | `provider:model` through Prism, or `deterministic` (plumbing checks only) |
| `--dimensions` | provider | Sent to the provider |
| `--mode` | `dense` | Comma separated: `dense`, `hybrid` (vector plus keyword search, the query text as keywords in match mode `any`, RRF). With `hybrid` the collection is created with keyword search |
| `--filtered` | off | Filtered query set: documents get a derived field at ingest (WixQA `product`, the title before its first `:`; SciFact `bucket`, crc32 of the id modulo 20) and each query searches only its relevant documents' value. Queries whose relevant documents have more than one value, or none, are left out. The dataset files and pins are unchanged |
| `--iterative-scan` | store config | Comma separated `off`, `relaxed_order`, `strict_order` (pgvector only): every mode runs once per setting (report key `mode@setting`) |
| `--match` | `vector.hybrid.match` | Comma separated `any`, `websearch`: hybrid match modes to sweep |
| `--keyword-weight` | `vector.hybrid.keyword_weight` | Comma separated weights (at least 0) to sweep. With `--match` or `--keyword-weight`, hybrid report keys are `hybrid:<match>:<weight>` |
| `--rerank` | none | A `vector.rerank.providers` name. Adds a `+rerank` twin of every mode (report keys `dense+rerank`, `hybrid:any:0.25+rerank`, `mode+rerank@setting`): each query's own top 30 of that mode reranked to the top 10, one request at a time. Fail-open is off for the run, and a preflight rerank request checks the key and model |
| `--rerank-price-per-1k-searches` | none | Reranker price per 1,000 search units (Cohere), for the cost section |
| `--rerank-price-per-1m-tokens` | none | Reranker price per 1M tokens (Voyage, an LLM), for the cost section |
| `--rerank-price-per-1m-input-tokens`, `--rerank-price-per-1m-output-tokens` | none | Split token prices (an LLM reranker through Prism, which reports input and output tokens). Pass both, without `--rerank-price-per-1m-tokens`. A cached request from before the split (or from a reranker that reports one count) is priced at the input price for all its tokens; the report counts those as `unsplit` |
| `--keep` | off | Keep the ingested collection; a later `--collection` run reuses it with no embedding calls when the query vectors are cached, and reports the ingest that built it |
| `--concurrency` | `1,4,8` | Client processes per throughput run |
| `--warmup` | `20` | Untimed queries before the timed runs |
| `--queries`, `--docs` | whole dataset | A fixed smaller run; the corpus keeps the relevant documents of the kept queries |
| `--truncate` | `0` (none) | Cut documents to N characters, recorded in the report |
| `--out` | `~/ag2-scratch/bench` | Report folder (long-lived, survives a reboot). A folder inside this repo is refused |
| `--compare[=file]` | `baselines/<store>/<dataset>/<model-slug>.json` | Exit 1 on a quality drop beyond the tolerance |
| `--compare-stores=a.json,b.json` | none | No run: one table for runs of one dataset on several stores (quality, latency, ingest, size, and the top 10 agreement of each run with the first) |
| `--tolerance` | `0.01` | Absolute drop allowed per quality metric |
| `--price-per-1m-tokens` | none | Embedding price for the cost section; never built in |
| `--report=<file>` | none | With `--compare`: compare an existing report, no run |
| `--collection=<name>` | none | Search an existing collection or alias that holds exactly the corpus (for example after `vector:migrate`): no ingest, no `REINDEX`, not dropped. The ingest section reads 0. `hybrid` needs it migrated with `--keyword`; `--filtered` cannot reuse one |

Preflight checks the env keys, the store (its health check), the dataset checksums and, when the run embeds anything, one embedding call, and stops with exit 2 naming what is missing. Before it embeds, a run prints the documents and queries to embed, an estimate of the tokens, and the cost when `--price-per-1m-tokens` is given; above `--max-tokens` it stops unless `--yes` is passed.

## What a run does

1. Embeds the documents the vector cache lacks (one request per store batch, timed as `embedding_ms`, calls and tokens counted from `VectorEmbeddingCompleted`) and writes each batch to the cache as it returns. Vectors are never read back from a store. Then ingests the corpus into a fresh collection (HNSW, cosine) a thousand documents at a time, each chunk's vectors read from the cache, every document with `lang: en` metadata (the datasets are English, so keyword search stems and drops stop words). The ingest time covers the embedding and the upserts. When every document came from the cache, the ingest section records `cached: true` (0 embedding calls), so its ingest timing is store time only.
2. Waits until the store reports the index ready (Qdrant: the collection is green), then times the index rebuild where the probe has one (pgvector: `REINDEX`, since HNSW grows during inserts and has no separate build step). Storage comes from the probe (pgvector: `pg_table_size` and `pg_indexes_size`; Qdrant: not available).
3. Embeds each query text the cache lacks once (one call per query, timed) and caches it with its time and tokens.
4. Runs the warmup, then for each concurrency level starts N processes that wait at a ready/go barrier and search with the cached vectors (`usingVector`), so the latency is store time only. Children write one JSON line per query; the parent merges them.
5. With `--rerank`, reranks each mode's top 30 per query (the candidate recall@30 search) to the top 10, sequentially, and caches the result in `<out>/cache/rerank.<dataset>.<reranker>.<model>.json` per query id and ordered candidate ids, with the request's time, units and tokens. A rerun, or a mode with the same candidates (hybrid at keyword weight 0), makes no request.
6. Samples the memory of the store's container (`docker stats`, the container the probe names) during the run, and drops the collection at the end. Writes the report, and `<report>.ranked.json` with each mode's top 10 ids per query (for `--compare-stores`).

## Vector cache

`<out>/cache/corpus-<corpus sha256, 12 characters>.<model-slug>.<dimensions>.documents` and `<out>/cache/<dataset>.<model-slug>.<dimensions>.queries`, each two files: `.f32`, the vectors as 32-bit floats in little-endian order, one row after another, and `.index.jsonl`, a header line (model, dimensions) and one line per row (id, the sha256 of the text, and for queries the embedding time and tokens). Rows are keyed by the sha256 of the text (after `--truncate` and `--chunk`), so datasets that share a corpus file (the two WixQA sets) share their document vectors, and a query is found by its text, not its id. Rows are only appended; the loader keeps the index in memory and reads the vectors a chunk at a time. The JSON caches of the earlier format (`<dataset>.<model-slug>.<dimensions>.json` and `.documents.json`) are converted on the first run that finds them and renamed to `.converted`.

## Report

`<out>/<dataset>.<model-slug>.<timestamp>.json` and `.md`:

- Quality (from the run's rankings, top 10): nDCG@10, recall@1/5/10 (BEIR: relevant found in the top k divided by all relevant), hit@1/5/10 (any relevant in the top k), MRR@10. Every mode is shown, each as a delta against the first one (`dense` when it ran), and the number of queries that returned fewer than 10 hits per mode. A filtered run names its field and query count, and is compared only with a filtered baseline.
- Candidate recall at the same budget: recall@30 (the reranker's default candidate count), @50 and @100 of each mode's own top K (`hnsw.ef_search` raised to K). The raw union of the dense and keyword top K is reported separately with its document count (up to 2K); it is not a same-budget comparison.
- Paired differences: per-query nDCG@10, recall@5 and candidate recall@30, mean and a seeded bootstrap 95% interval (10,000 resamples), against `dense` (`dense+rerank` for `+rerank` modes) at the same iterative scan setting. A `+rerank` mode's candidate recall is its base mode's (the same candidates).
- Reranking (with `--rerank`): per `+rerank` mode, requests made and cached, rerank latency p50/p95 (one client; measured when the request was made, so cached entries keep their original time), units and tokens. Higher concurrency is not run for reranking: it would measure the vendor's rate limiter.
- Query performance: QPS and p50/p95/p99 store latency per concurrency level; query embedding latency separately.
- Ingestion: documents per second, embedding time, index rebuild time.
- The store: name, driver, engine version, index setting, exact or not, and the capability flags; the dataset's primary metric. A value a probe cannot give reads "not available".
- Resources, cost (calls, tokens, and the price you give; with `--rerank`, this run's rerank requests and the cost per query), PHP and OS versions.

## Baselines

Copy a full run's JSON report to `baselines/<store>/<dataset>/<model-slug>.json` (for example `baselines/pgvector/scifact/openai-text-embedding-3-small.json`) and commit it. Each store has its own folder; the pgvector baselines moved there with their numbers unchanged. `--compare` refuses a baseline with other settings (dataset revision, model, dimensions, truncation, sizes) and fails only on quality drops; latency and cost changes are printed as notes. The optional Ollama baseline (`--model=ollama:mxbai-embed-large --dimensions=1024 --truncate=512`) is never compared with an untruncated OpenAI run.
