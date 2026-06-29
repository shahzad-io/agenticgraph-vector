# Score Ergonomics

How to read similarity scores from `agenticgraph-vector` search results and avoid common misconfiguration.

## Cosine scores are not percentages

The default pgvector metric is **cosine**. The store converts distance to a score with `1 - cosine_distance`, which yields **[-1, 1]**:

| Score | Meaning |
|-------|---------|
| **1.0** | Same direction as the query vector |
| **0.0** | Orthogonal |
| **Negative** | Opposing direction |

`0.54` is **not** “54% relevant.” Calibrate thresholds per corpus and embedding model.

## Healthy separation on heterogeneous corpora

With OpenAI `text-embedding-3-small` on a **mixed-topic** corpus, strong matches often land around **0.75–0.85** while unrelated filler sits near **0.1–0.2**. That gap makes `minScore` useful.

## Compressed bands on homogeneous corpora are normal

Domain-specific collections where every document shares vocabulary — **refund policies, FAQs, support macros, legal clauses** — often show a **compressed score band** (e.g. top hit **0.45**, rest **0.30–0.40**) even when ranking is correct.

This is **normal embedding geometry**, not a metric or normalization bug, when:

- Vectors are **unit-normalized** at write and query time (OpenAI / Prism default path).
- The collection metric, index ops, SQL ranking, and trace `vector.score_min` / `vector.score_max` all use the same cosine transform.
- The correct document still ranks **#1**.

Example (13 refund policies, `text-embedding-3-small`): digital-ebook query top score **0.46** for the right policy; never-shipped query **0.61** — both with correct #1 ranking.

Do **not** apply mixed-corpus thresholds (e.g. `minScore(0.7)`) to homogeneous sets without re-calibration — they may return zero hits.

## Applying thresholds

```php
$results = Vector::store('pgvector')
    ->collection('articles')
    ->similarTo('How do queue workers use Redis?')
    ->minScore(0.7)   // corpus-specific — tune empirically
    ->take(10)
    ->get();
```

Post-filter in PHP when experimenting:

```php
$strong = $results->filterByScore(0.35); // example floor for a homogeneous policy set
```

## Metric-specific semantics

| Metric | Operator | Score | Typical range |
|--------|----------|-------|---------------|
| `cosine` (default) | `<=>` | `1 - distance` | **[-1, 1]** |
| `l2` | `<->` | `1 / (1 + distance)` | **(0, 1]** |
| `inner_product` | `<#>` | `-distance` | unbounded — re-tune |

Set metric explicitly on the collection or via `vector.stores.pgvector.metric` when omitted from `CollectionDefinition`.

## pgvector search tuning (no raw SQL)

Pass store-specific options on the builder:

```php
Vector::store('pgvector')
    ->collection('articles')
    ->similarTo('vector database indexing')
    ->options([
        'hnsw.ef_search' => 100,   // HNSW recall vs latency
        'ivfflat.probes' => 10,    // IVFFlat recall vs latency
    ])
    ->get();
```

Options apply via `SET LOCAL` inside the search transaction.

## `fetch()` scores are placeholders

Documents loaded by ID always return `score: 1.0`. Use search scores only from `similarTo()` / `usingVector()` → `get()`.

## Local / test embeddings

The `deterministic` provider produces different score ranges than production models. Calibrate thresholds against your **production** embedding provider.
