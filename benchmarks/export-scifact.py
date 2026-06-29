#!/usr/bin/env python3
"""Export BEIR SciFact (test split) to JSON for the PHP retrieval benchmark."""

from __future__ import annotations

import json
import sys
from pathlib import Path

try:
    import ir_datasets
except ImportError:
    print(
        "ir_datasets is required. From repo root:\n"
        "  python3 -m venv build/benchmark/.venv\n"
        "  build/benchmark/.venv/bin/pip install ir-datasets",
        file=sys.stderr,
    )
    sys.exit(1)

OUT = Path(__file__).resolve().parent.parent / "build" / "benchmark" / "scifact-export"


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    ds = ir_datasets.load("beir/scifact/test")

    corpus = [
        {"id": doc.doc_id, "text": f"{doc.title} {doc.text}".strip()}
        for doc in ds.docs_iter()
    ]
    queries = [{"id": q.query_id, "text": q.text} for q in ds.queries_iter()]
    qrels: dict[str, list[str]] = {}
    for qrel in ds.qrels_iter():
        qrels.setdefault(qrel.query_id, []).append(qrel.doc_id)

    (OUT / "corpus.json").write_text(json.dumps(corpus))
    (OUT / "queries.json").write_text(json.dumps(queries))
    (OUT / "qrels.json").write_text(json.dumps(qrels))

    print(f"Exported SciFact to {OUT}")
    print(f"  corpus:  {len(corpus)} documents")
    print(f"  queries: {len(queries)}")
    print(f"  qrels:   {len(qrels)} queries with relevance labels")


if __name__ == "__main__":
    main()
