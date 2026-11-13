#!/usr/bin/env python3
"""Export the benchmark datasets in benchmarks/datasets.json for bin/vector-bench.

The text sets need the standard library only. Sources are pinned in the manifest: the
SciFact archive by sha256, WixQA by Hugging Face revision, the `vectors` sets by the sha256 of each source file.
The `vectors` sets (SIFT1M, DBpedia embeddings, the filtered set) need numpy and pyarrow from
benchmarks/requirements-vectors.txt; the text sets need nothing. Every exported file is checked
against its manifest sha256.

  python3 benchmarks/export-datasets.py                    # export and verify
  python3 benchmarks/export-datasets.py --update-manifest  # export and record new checksums
  python3 benchmarks/export-datasets.py sift1m             # only the named sets (a `vectors` set only when named)
"""

from __future__ import annotations

import hashlib
import io
import json
import sys
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MANIFEST = ROOT / "benchmarks" / "datasets.json"
OUT = ROOT / "build" / "benchmark" / "datasets"


def fetch(url: str) -> bytes:
    with urllib.request.urlopen(url, timeout=120) as response:
        return response.read()


def jsonl(data: bytes) -> list[dict]:
    return [json.loads(line) for line in data.decode("utf-8").splitlines() if line.strip()]


def scifact(source: dict) -> dict[str, object]:
    archive = fetch(source["url"])
    digest = hashlib.sha256(archive).hexdigest()
    if digest != source["sha256"]:
        sys.exit(f"SciFact archive sha256 {digest} does not match the manifest {source['sha256']}.")

    with zipfile.ZipFile(io.BytesIO(archive)) as zf:
        docs = jsonl(zf.read("scifact/corpus.jsonl"))
        all_queries = {q["_id"]: q["text"] for q in jsonl(zf.read("scifact/queries.jsonl"))}
        qrels: dict[str, list[str]] = {}
        for row in zf.read("scifact/qrels/test.tsv").decode("utf-8").splitlines()[1:]:
            query_id, doc_id, score = row.split("\t")
            if int(score) > 0:
                qrels.setdefault(query_id, []).append(doc_id)

    return {
        "corpus": [{"id": d["_id"], "text": f"{d['title']} {d['text']}".strip()} for d in docs],
        "queries": [{"id": qid, "text": all_queries[qid]} for qid in qrels],
        "qrels": qrels,
    }


def wixqa(source: dict, split: str, prefix: str) -> dict[str, object]:
    base = f"{source['url']}/resolve/{source['revision']}"
    corpus = [{"id": d["id"], "text": d["contents"]} for d in jsonl(fetch(f"{base}/wix_kb_corpus/wix_kb_corpus.jsonl"))]
    rows = jsonl(fetch(f"{base}/{split}/test.jsonl"))
    queries = [{"id": f"{prefix}-{i:04d}", "text": row["question"]} for i, row in enumerate(rows)]
    qrels = {f"{prefix}-{i:04d}": list(row["article_ids"]) for i, row in enumerate(rows)}

    return {"corpus": corpus, "queries": queries, "qrels": qrels}


# ---- `vectors` sets (H3): numpy and pyarrow from benchmarks/requirements-vectors.txt, imported only here. ----

SOURCES = ROOT / "build" / "benchmark" / "sources"
GROUND_TRUTH_K = 100


def download(url: str, path: Path, sha256: str) -> Path:
    """Fetch $url to $path once (curl: FTP and redirects), then refuse it unless it matches its pin."""
    import subprocess

    if not path.is_file():
        path.parent.mkdir(parents=True, exist_ok=True)
        subprocess.run(["curl", "-sSLf", "-o", f"{path}.part", url], check=True)
        Path(f"{path}.part").rename(path)

    digest = file_sha256(path)
    if digest != sha256:
        sys.exit(f"{path} sha256 {digest} does not match the manifest {sha256}.")

    return path


def file_sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


def write_vectors(base: Path, model: str, ids: list[str], vectors) -> tuple[Path, Path]:
    """The H2 cache format: <base>.f32 (float32, little-endian, row after row) and <base>.index.jsonl (a header line,
    then one line per row: its id and the sha256 of its float32 bytes)."""
    import numpy as np

    rows = np.ascontiguousarray(vectors, dtype="<f4")
    base.parent.mkdir(parents=True, exist_ok=True)
    Path(f"{base}.f32").write_bytes(rows.tobytes())
    with Path(f"{base}.index.jsonl").open("w") as f:
        f.write(json.dumps({"model": model, "dimensions": int(rows.shape[1])}) + "\n")
        for row_id, row in zip(ids, rows):
            f.write(json.dumps({"id": row_id, "sha256": hashlib.sha256(row.tobytes()).hexdigest()}) + "\n")

    return Path(f"{base}.f32"), Path(f"{base}.index.jsonl")


def vector_files(part: str, files: tuple[Path, Path]) -> dict[str, Path]:
    return {f"{part}_vectors": files[0], f"{part}_index": files[1]}


def exact_neighbors(base, queries, metric: str, k: int = GROUND_TRUTH_K) -> list[list[int]]:
    """Exact top k rows of $base per query (cosine: highest similarity; l2: lowest distance), ties by row number."""
    import numpy as np

    base = base.astype(np.float32)
    queries = queries.astype(np.float32)
    if metric == "cosine":
        base = base / np.linalg.norm(base, axis=1, keepdims=True)
        queries = queries / np.linalg.norm(queries, axis=1, keepdims=True)
    k = min(k, len(base))
    result = []
    for start in range(0, len(queries), 256):
        block = queries[start:start + 256]
        scores = block @ base.T
        if metric == "l2":
            scores = 2 * scores - (base * base).sum(axis=1)  # -|b|^2 + 2qb: higher is nearer
        for row in scores:
            top = np.argpartition(-row, k - 1)[:k]
            result.append(sorted(top.tolist(), key=lambda i: (-row[i], i)))
    return result


def ground_truth(path: Path, query_ids: list[str], neighbors: list[list[int]], extra: dict | None = None) -> Path:
    path.write_text(json.dumps({"k": len(neighbors[0]), **(extra or {}), "neighbors": {q: [str(i) for i in n] for q, n in zip(query_ids, neighbors)}}))
    return path


def sift1m(dataset: dict) -> dict[str, Path]:
    """TEXMEX SIFT1M: fvecs and ivecs from the archive; the ground truth is the source's (top 100, L2)."""
    import tarfile

    import numpy as np

    archive = download(dataset["source"]["url"], SOURCES / "sift" / "sift.tar.gz", dataset["source"]["sha256"])

    def vecs(tar, member: str, dtype: str):
        raw = np.frombuffer(tar.extractfile(member).read(), dtype="<i4")
        dim = int(raw[0])
        return raw.reshape(-1, dim + 1)[:, 1:].copy().view(dtype)

    with tarfile.open(archive) as tar:
        base = vecs(tar, "sift/sift_base.fvecs", "<f4")
        queries = vecs(tar, "sift/sift_query.fvecs", "<f4")
        truth = vecs(tar, "sift/sift_groundtruth.ivecs", "<i4")

    out = OUT / dataset["directory"]
    query_ids = [f"q{i}" for i in range(len(queries))]
    return {
        **vector_files("base", write_vectors(out / "base", "sift1m", [str(i) for i in range(len(base))], base)),
        **vector_files("queries", write_vectors(out / "queries", "sift1m", query_ids, queries)),
        "ground_truth": ground_truth(out / "ground_truth.json", query_ids, truth.tolist()),
    }


def dbpedia_rows(dataset: dict):
    """The rows of the pinned parquet shards in order: (ids, float32 vectors)."""
    import numpy as np
    import pyarrow.parquet as pq

    source = dataset["source"]
    ids, vectors = [], []
    for shard in source["files"]:
        name = shard["path"].rsplit("/", 1)[-1]
        path = download(f"{source['url']}/resolve/{source['revision']}/{shard['path']}", SOURCES / "dbpedia" / name, shard["sha256"])
        table = pq.read_table(path, columns=["_id", source["column"]])
        ids += table.column("_id").to_pylist()
        column = table.column(source["column"]).combine_chunks()
        vectors.append(column.values.to_numpy().astype(np.float32).reshape(len(column), -1))
    return ids, np.concatenate(vectors)


def dbpedia(dataset: dict) -> dict[str, Path]:
    """The first `base_rows` rows are the set; `queries` held-out rows after them, evenly spaced, are the queries.
    Ground truth: exact cosine top 100, computed here (the source has none)."""
    ids, vectors = dbpedia_rows(dataset)
    rows, count = dataset["base_rows"], dataset["queries"]
    held_out = list(range(rows, len(ids)))[:: (len(ids) - rows) // count][:count]
    base, queries = vectors[:rows], vectors[held_out]
    out = OUT / dataset["directory"]
    query_ids = [f"q{i}" for i in range(count)]
    return {
        **vector_files("base", write_vectors(out / "base", dataset["model"], [str(i) for i in range(rows)], base)),
        **vector_files("queries", write_vectors(out / "queries", dataset["model"], query_ids, queries)),
        "ground_truth": ground_truth(out / "ground_truth.json", query_ids, exact_neighbors(base, queries, "cosine"),
                                     {"source_ids": {q: ids[i] for q, i in zip(query_ids, held_out)}}),
    }


def filtered(dataset: dict, parent: dict) -> dict[str, Path]:
    """H7 on the vectors of `built_from`: each row gets `tenant` (keyword) and `rank` (integer, 0 to 999), seeded.
    The `selectivities` tenants hold exactly their share of the rows; the other rows go to tenants of 100 rows each.
    Ground truth: the exact top 100 among each selective tenant's rows, per query."""
    import numpy as np

    ids, vectors = dbpedia_rows(parent)
    rows = parent["base_rows"]
    base = vectors[:rows]
    rng = np.random.default_rng(dataset["seed"])
    order = rng.permutation(rows)
    tenant = np.empty(rows, dtype=object)
    start = 0
    for name, share in dataset["selectivities"].items():
        size = round(share * rows)
        tenant[order[start:start + size]] = name
        start += size
    for i, row in enumerate(order[start:]):
        tenant[row] = f"o{i // 100:03d}"
    rank = rng.integers(0, 1000, size=rows)

    out = OUT / dataset["directory"]
    out.mkdir(parents=True, exist_ok=True)
    fields = out / "fields.json"
    fields.write_text(json.dumps([[str(t), int(r)] for t, r in zip(tenant, rank)]))

    queries = np.fromfile(OUT / parent["directory"] / "queries.f32", dtype="<f4").reshape(-1, base.shape[1])
    query_ids = [f"q{i}" for i in range(len(queries))]
    truth = {}
    for name, share in dataset["selectivities"].items():
        members = np.flatnonzero(tenant == name)
        neighbors = exact_neighbors(base[members], queries, "cosine")
        truth[name] = {"share": share, "rows": len(members), "neighbors": {q: [str(int(members[i])) for i in n] for q, n in zip(query_ids, neighbors)}}
    path = out / "ground_truth.json"
    path.write_text(json.dumps({"k": GROUND_TRUTH_K, "field": "tenant", "selectivities": truth}))
    return {"fields": fields, "ground_truth": path}


def export_vectors(name: str, dataset: dict, manifest: dict, update: bool) -> list[str]:
    exporter = {"sift1m": sift1m, "dbpedia": dbpedia, "filtered": filtered}[dataset["exporter"]]
    parts = exporter(dataset, manifest["datasets"][dataset["built_from"]]) if "built_from" in dataset else exporter(dataset)
    failures = []
    for key, path in parts.items():
        entry = dataset["files"].setdefault(key, {"path": str(path.relative_to(OUT)), "sha256": ""})
        digest = file_sha256(path)
        if update:
            entry["sha256"] = digest
        elif digest != entry["sha256"]:
            failures.append(f"{name} {key}: {digest} != manifest {entry['sha256']}")
    print(f"{name}: {', '.join(parts)} -> {OUT / dataset['directory']}")
    return failures


def main() -> None:
    update = "--update-manifest" in sys.argv[1:]
    only = [arg for arg in sys.argv[1:] if not arg.startswith("--")]
    manifest = json.loads(MANIFEST.read_text())
    failures = []

    for name, dataset in manifest["datasets"].items():
        # The vectors sets need the optional packages, so they export only when named.
        if (only and name not in only) or (not only and dataset["kind"] == "vectors"):
            continue

        if dataset["kind"] == "vectors":
            failures += export_vectors(name, dataset, manifest, update)
            continue

        source = dataset["source"]
        if name == "scifact":
            exported = scifact(source)
        else:
            exported = wixqa(source, dataset["split"], dataset["query_prefix"])

        for part, entry in dataset["files"].items():
            path = OUT / entry["path"]
            path.parent.mkdir(parents=True, exist_ok=True)
            data = json.dumps(exported[part]).encode("utf-8")
            path.write_bytes(data)
            digest = hashlib.sha256(data).hexdigest()
            if update:
                entry["sha256"] = digest
            elif digest != entry["sha256"]:
                failures.append(f"{name} {part}: {digest} != manifest {entry['sha256']}")

        print(f"{name}: {len(exported['corpus'])} documents, {len(exported['queries'])} queries -> {OUT}")

    if update:
        MANIFEST.write_text(json.dumps(manifest, indent=4, ensure_ascii=False) + "\n")
        print(f"Checksums written to {MANIFEST}")

    if failures:
        sys.exit("Checksum mismatch:\n  " + "\n  ".join(failures))


if __name__ == "__main__":
    main()
