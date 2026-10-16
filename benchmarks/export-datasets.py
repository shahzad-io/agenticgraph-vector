#!/usr/bin/env python3
"""Export the benchmark datasets in benchmarks/datasets.json to JSON for bin/vector-bench.

Standard library only (no dependencies to pin). Sources are pinned in the manifest: the
SciFact archive by sha256, WixQA by Hugging Face revision. Every exported file is checked
against its manifest sha256.

  python3 benchmarks/export-datasets.py                    # export and verify
  python3 benchmarks/export-datasets.py --update-manifest  # export and record new checksums
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


def main() -> None:
    update = "--update-manifest" in sys.argv[1:]
    manifest = json.loads(MANIFEST.read_text())
    failures = []

    for name, dataset in manifest["datasets"].items():
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
        MANIFEST.write_text(json.dumps(manifest, indent=4) + "\n")
        print(f"Checksums written to {MANIFEST}")

    if failures:
        sys.exit("Checksum mismatch:\n  " + "\n  ".join(failures))


if __name__ == "__main__":
    main()
