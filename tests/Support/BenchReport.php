<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

/**
 * bin/vector-bench report pieces: quality metrics, percentiles, the markdown summary and the
 * comparison against a committed baseline.
 */
final class BenchReport
{
    public const QUALITY_KEYS = ['ndcg_at_10', 'recall_at_1', 'recall_at_5', 'recall_at_10', 'hit_at_1', 'hit_at_5', 'hit_at_10', 'mrr_at_10'];

    /** Run settings that must match for two reports to be compared. */
    public const COMPARABLE_KEYS = ['dataset.name', 'dataset.revision', 'model.name', 'model.dimensions', 'truncate', 'sizes.documents', 'sizes.queries', 'sizes.top_k'];

    /**
     * @param  array<string, list<string>>  $qrels
     * @param  array<string, list<string>>  $ranked
     * @return array<string, float>
     */
    public static function quality(array $qrels, array $ranked): array
    {
        return [
            'ndcg_at_10' => RetrievalMetrics::ndcgAtK($qrels, $ranked, 10),
            'recall_at_1' => RetrievalMetrics::recallAtK($qrels, $ranked, 1),
            'recall_at_5' => RetrievalMetrics::recallAtK($qrels, $ranked, 5),
            'recall_at_10' => RetrievalMetrics::recallAtK($qrels, $ranked, 10),
            'hit_at_1' => RetrievalMetrics::hitAtK($qrels, $ranked, 1),
            'hit_at_5' => RetrievalMetrics::hitAtK($qrels, $ranked, 5),
            'hit_at_10' => RetrievalMetrics::hitAtK($qrels, $ranked, 10),
            'mrr_at_10' => RetrievalMetrics::mrrAtK($qrels, $ranked, 10),
        ];
    }

    /**
     * Nearest-rank percentile; null for no samples.
     *
     * @param  list<int|float>  $values
     */
    public static function percentile(array $values, float $percent): int|float|null
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return $values[max(0, (int) ceil($percent / 100 * count($values)) - 1)];
    }

    /**
     * Quality drops beyond $tolerance (absolute) fail; latency and cost differences are notes.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $baseline
     * @return array{failures: list<string>, notes: list<string>}
     */
    public static function compare(array $report, array $baseline, float $tolerance): array
    {
        $failures = [];
        $notes = [];

        foreach (self::COMPARABLE_KEYS as $key) {
            if (data_get($report, $key) !== data_get($baseline, $key)) {
                $failures[] = sprintf('Not comparable: %s is %s, the baseline has %s.', $key, json_encode(data_get($report, $key)), json_encode(data_get($baseline, $key)));
            }
        }

        if ($failures !== []) {
            return ['failures' => $failures, 'notes' => $notes];
        }

        /** @var array<string, array<string, mixed>> $baselineModes */
        $baselineModes = $baseline['modes'] ?? [];

        foreach ($baselineModes as $mode => $baselineMode) {
            if (! isset($report['modes'][$mode])) {
                $notes[] = "Mode {$mode} is in the baseline but was not run.";

                continue;
            }

            foreach (self::QUALITY_KEYS as $metric) {
                $was = (float) data_get($baselineMode, "quality.{$metric}");
                $now = (float) data_get($report, "modes.{$mode}.quality.{$metric}");

                if ($now < $was - $tolerance) {
                    $failures[] = sprintf('%s %s dropped from %.4f to %.4f (%+.4f, tolerance %.4f).', $mode, $metric, $was, $now, $now - $was, $tolerance);
                }
            }

            $wasP50 = data_get($baselineMode, 'concurrency.1.p50_ms');
            $nowP50 = data_get($report, "modes.{$mode}.concurrency.1.p50_ms");

            if ($wasP50 !== null && $nowP50 !== null) {
                $notes[] = "{$mode} store p50 at concurrency 1: {$wasP50} ms -> {$nowP50} ms (informational).";
            }
        }

        $wasTokens = data_get($baseline, 'ingest.embedding_tokens');
        $nowTokens = data_get($report, 'ingest.embedding_tokens');

        if ($wasTokens !== $nowTokens) {
            $notes[] = 'Ingest embedding tokens: '.json_encode($wasTokens).' -> '.json_encode($nowTokens).' (informational).';
        }

        return ['failures' => $failures, 'notes' => $notes];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function markdown(array $report): string
    {
        $lines = [
            "# vector-bench: {$report['dataset']['name']}, {$report['model']['name']}",
            '',
            "- Created: {$report['created_at']}",
            "- Dataset: {$report['dataset']['description']} (revision `{$report['dataset']['revision']}`, {$report['dataset']['license']})",
            "- Model: `{$report['model']['name']}`, {$report['model']['dimensions']} dimensions",
            '- Truncation: '.($report['truncate'] > 0 ? "{$report['truncate']} characters" : 'none'),
            "- Sizes: {$report['sizes']['documents']} documents, {$report['sizes']['queries']} queries, top {$report['sizes']['top_k']}, warmup {$report['sizes']['warmup']}".($report['sizes']['subset'] ? ' (subset)' : ''),
            "- Store: {$report['store']['name']}, `{$report['store']['index']}`",
            '',
            '## Quality',
            '',
        ];

        $modes = $report['modes'];
        $dense = $modes['dense']['quality'];
        $lines[] = '| Metric | '.implode(' | ', array_map(static fn (string $mode): string => $mode === 'dense' ? 'dense' : "{$mode} (delta vs dense)", array_keys($modes))).' |';
        $lines[] = '|---|'.str_repeat('---|', count($modes));

        foreach (self::QUALITY_KEYS as $metric) {
            $cells = [];

            foreach ($modes as $mode => $data) {
                $value = $data['quality'][$metric];
                $cells[] = $mode === 'dense' ? sprintf('%.4f', $value) : sprintf('%.4f (%+.4f)', $value, $value - $dense[$metric]);
            }

            $lines[] = "| {$metric} | ".implode(' | ', $cells).' |';
        }

        $lines = [...$lines, '', '## Query performance (store time, pre-embedded queries)', '', '| Mode | Concurrency | QPS | p50 ms | p95 ms | p99 ms |', '|---|---|---|---|---|---|'];

        foreach ($modes as $mode => $data) {
            foreach ($data['concurrency'] as $level => $run) {
                $lines[] = sprintf('| %s | %d | %.1f | %s | %s | %s |', $mode, $level, $run['qps'], $run['p50_ms'], $run['p95_ms'], $run['p99_ms']);
            }
        }

        $embed = $report['query_embedding'];
        $ingest = $report['ingest'];
        $resources = $report['resources'];
        $cost = $report['cost'];

        return implode("\n", [
            ...$lines,
            '',
            sprintf('Query embedding (one client%s): p50 %s ms, p95 %s ms, p99 %s ms; %d calls, %s tokens.', $embed['cached'] ? ', from cache' : '', $embed['p50_ms'], $embed['p95_ms'], $embed['p99_ms'], $embed['calls'], $embed['tokens'] ?? 'n/a'),
            '',
            '## Ingestion',
            '',
            sprintf('- %d documents in %d ms (%.1f documents per second)', $ingest['documents'], $ingest['wall_ms'], $ingest['docs_per_second']),
            sprintf('- Embedding: %d ms, %d calls, %s tokens', $ingest['embedding_ms'], $ingest['embedding_calls'], $ingest['embedding_tokens'] ?? 'n/a'),
            sprintf('- Index rebuild (REINDEX): %d ms', $ingest['index_rebuild_ms']),
            '',
            '## Resources',
            '',
            sprintf('- Storage: table %s bytes, indexes %s bytes, total %s bytes', $resources['table_bytes'], $resources['index_bytes'], $resources['total_bytes']),
            '- Container memory: '.($resources['memory_mib_max'] !== null
                ? sprintf('max %.1f MiB over %d samples (%s)', $resources['memory_mib_max'], $resources['memory_samples'], $resources['container'])
                : 'not sampled ('.$resources['memory_note'].')'),
            "- Infrastructure: {$report['infrastructure']['postgres']}, pgvector {$report['infrastructure']['pgvector']}, PHP {$report['infrastructure']['php']}",
            '',
            '## Cost',
            '',
            $cost['price_per_1m_tokens'] === null
                ? '- No price given (`--price-per-1m-tokens`).'
                : sprintf('- At %s per 1M tokens: ingest %s, queries %s', $cost['price_per_1m_tokens'], $cost['ingest'] ?? 'n/a (no token usage)', $cost['queries'] ?? 'n/a (no token usage)'),
            '',
            isset($report['runtime_ms']) ? sprintf('Runtime: %.1f s for the whole run.', $report['runtime_ms'] / 1000) : 'Runtime: not recorded.',
            '',
        ]);
    }
}
