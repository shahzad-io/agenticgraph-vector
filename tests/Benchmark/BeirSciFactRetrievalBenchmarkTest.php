<?php

/**
 * BEIR SciFact retrieval accuracy baseline (local Ollama embeddings + pgvector).
 *
 * Requires: VECTOR_BENCHMARK=true, pgvector, Ollama with mxbai-embed-large pulled.
 * Export data first: build/benchmark/.venv/bin/python benchmarks/export-scifact.py
 *
 * @group benchmark
 */

use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\BeirSciFactDataset;
use AgenticGraph\Vector\Tests\Support\OllamaBenchmarkHelper;
use AgenticGraph\Vector\Tests\Support\RetrievalMetrics;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;

/**
 * mxbai-embed-large has a short context window; truncate long BEIR abstracts.
 */
function truncateForEmbedding(string $text, int $maxChars = 512): string
{
    $text = iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: '';

    if (mb_strlen($text, 'UTF-8') <= $maxChars) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $maxChars - 3, 'UTF-8')).'...';
}

describe('BEIR SciFact retrieval benchmark', function (): void {
    beforeEach(function (): void {
        OllamaBenchmarkHelper::guardOrSkip($this);
        OllamaBenchmarkHelper::configureOllamaEmbeddings($this->app);

        $this->dataset = BeirSciFactDataset::load();
        $this->collection = 'beir_scifact_'.uniqid();
        $this->dimensions = OllamaBenchmarkHelper::DIMENSIONS;
        $this->topK = 10;
        $this->store = Vector::store('pgvector');
    });

    afterEach(function (): void {
        try {
            if (isset($this->store, $this->collection) && $this->store->collectionExists($this->collection)) {
                $this->store->deleteCollection($this->collection);
            }
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    });

    it('reports recall, MRR, and nDCG on SciFact with Ollama mxbai-embed-large at 1024 dimensions', function (): void {
        $started = microtime(true);

        expect($this->dimensions)->toBe(1024)
            ->and(Embeddings::using('prism')->model())->toBe(OllamaBenchmarkHelper::MODEL);

        $this->store->createCollection(new CollectionDefinition(
            name: $this->collection,
            dimensions: $this->dimensions,
        ));

        $documents = array_map(
            static fn (array $row): Document => Document::make(
                $row['id'],
                truncateForEmbedding($row['text']),
                ['source' => 'beir:scifact'],
            ),
            $this->dataset['corpus'],
        );

        fwrite(STDOUT, "\n[benchmark] Ingesting ".count($documents)." SciFact documents...\n");

        Vector::store('pgvector')
            ->collection($this->collection)
            ->onProgress(function (int $done, int $total): void {
                if ($done === $total || $done % 500 === 0) {
                    fwrite(STDOUT, "[benchmark] Ingested {$done}/{$total}\n");
                }
            })
            ->upsert($documents);

        $ranked = [];
        $scoreSamples = [];
        $queryCount = count($this->dataset['queries']);

        fwrite(STDOUT, "[benchmark] Running {$queryCount} queries (top_k={$this->topK})...\n");

        foreach ($this->dataset['queries'] as $index => $query) {
            $results = Vector::store('pgvector')
                ->collection($this->collection)
                ->similarTo($query['text'])
                ->take($this->topK)
                ->get();

            $ranked[$query['id']] = $results->pluck('id');

            if ($index < 3) {
                $relevant = $this->dataset['qrels'][$query['id']] ?? [];
                $top = $results->first();
                $scoreSamples[] = [
                    'query_id' => $query['id'],
                    'query' => $query['text'],
                    'relevant' => $relevant,
                    'top_id' => $top?->id,
                    'top_score' => $top?->score,
                    'hit_at_1' => in_array($top?->id, $relevant, true),
                ];
            }

            if (($index + 1) % 50 === 0) {
                fwrite(STDOUT, '[benchmark] Queries '.($index + 1)."/{$queryCount}\n");
            }
        }

        $qrels = $this->dataset['qrels'];
        $metrics = [
            'recall_at_1' => RetrievalMetrics::recallAtK($qrels, $ranked, 1),
            'recall_at_5' => RetrievalMetrics::recallAtK($qrels, $ranked, 5),
            'recall_at_10' => RetrievalMetrics::recallAtK($qrels, $ranked, 10),
            'mrr' => RetrievalMetrics::mrr($qrels, $ranked),
            'ndcg_at_10' => RetrievalMetrics::ndcgAtK($qrels, $ranked, 10),
        ];

        $elapsed = round(microtime(true) - $started, 1);

        $report = [
            'dataset' => 'BEIR SciFact (test split)',
            'source' => 'ir_datasets beir/scifact/test via benchmarks/export-scifact.py',
            'corpus_documents' => count($this->dataset['corpus']),
            'queries' => $queryCount,
            'qrels_queries' => count($qrels),
            'embedding_provider' => 'prism',
            'embedding_backend' => 'ollama',
            'embedding_model' => OllamaBenchmarkHelper::MODEL,
            'embedding_dimensions' => $this->dimensions,
            'ollama_url' => OllamaBenchmarkHelper::ollamaUrl(),
            'vector_store' => 'pgvector',
            'collection_metric' => 'cosine',
            'top_k' => $this->topK,
            'text_truncation_chars' => 512,
            'elapsed_seconds' => $elapsed,
            'metrics' => $metrics,
            'score_samples' => $scoreSamples,
        ];

        $resultsPath = dirname(BeirSciFactDataset::exportPath()).'/scifact-results.json';
        file_put_contents($resultsPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        fwrite(STDOUT, "\n=== SciFact Retrieval Baseline ===\n");
        fwrite(STDOUT, 'Model: '.OllamaBenchmarkHelper::MODEL.' @ '.$this->dimensions."d (Ollama)\n");
        fwrite(STDOUT, 'Corpus: '.count($this->dataset['corpus'])." docs | Queries: {$queryCount}\n");
        fwrite(STDOUT, sprintf("Recall@1:  %.4f\n", $metrics['recall_at_1']));
        fwrite(STDOUT, sprintf("Recall@5:  %.4f\n", $metrics['recall_at_5']));
        fwrite(STDOUT, sprintf("Recall@10: %.4f\n", $metrics['recall_at_10']));
        fwrite(STDOUT, sprintf("MRR:       %.4f\n", $metrics['mrr']));
        fwrite(STDOUT, sprintf("nDCG@10:   %.4f\n", $metrics['ndcg_at_10']));
        fwrite(STDOUT, "Elapsed:   {$elapsed}s\n");
        fwrite(STDOUT, "Results:   {$resultsPath}\n\n");

        foreach ($scoreSamples as $sample) {
            fwrite(STDOUT, "Query {$sample['query_id']}: top={$sample['top_id']} score=".
                round((float) $sample['top_score'], 4).
                ' hit@1='.($sample['hit_at_1'] ? 'yes' : 'no')."\n");
            fwrite(STDOUT, '  Q: '.substr($sample['query'], 0, 100)."\n");
        }

        fwrite(STDOUT, "\n");

        expect($metrics['recall_at_10'])->toBeGreaterThan(0.0)
            ->and($this->dimensions)->toBe(1024);
    });
});
