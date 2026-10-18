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
    public const COMPARABLE_KEYS = ['dataset.name', 'dataset.revision', 'model.name', 'model.dimensions', 'truncate', 'chunk', 'sizes.documents', 'sizes.queries', 'sizes.top_k', 'filter.field'];

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
     * Paired difference of two per-query metrics (same queries, same order): the mean of $candidate - $reference
     * and a percentile bootstrap 95% interval over queries. Seeded, so a report is reproducible.
     *
     * @param  array<string, float>  $candidate
     * @param  array<string, float>  $reference
     * @return array{mean: float, low: float, high: float, queries: int, resamples: int}
     */
    public static function pairedBootstrap(array $candidate, array $reference, int $resamples = 10000, int $seed = 6): array
    {
        if (array_keys($candidate) !== array_keys($reference) || $candidate === []) {
            throw new \InvalidArgumentException('A paired comparison needs the same non-empty query set on both sides.');
        }

        $differences = array_map(static fn (float $a, float $b): float => $a - $b, $candidate, $reference);
        $n = count($differences);
        mt_srand($seed);
        $means = [];

        for ($i = 0; $i < $resamples; $i++) {
            $sum = 0.0;

            for ($j = 0; $j < $n; $j++) {
                $sum += $differences[mt_rand(0, $n - 1)];
            }

            $means[] = $sum / $n;
        }

        sort($means);
        mt_srand();

        return [
            'mean' => array_sum($differences) / $n,
            'low' => $means[(int) floor(0.025 * ($resamples - 1))],
            'high' => $means[(int) ceil(0.975 * ($resamples - 1))],
            'queries' => $n,
            'resamples' => $resamples,
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
            ...(isset($report['chunk']) ? ["- Chunks: {$report['chunk']['size']} characters, overlap {$report['chunk']['overlap']}, {$report['chunk']['chunks']} chunks; hits collapsed by `{$report['chunk']['collapse']}` before scoring (over-fetch {$report['chunk']['overfetch']}x, rerank before collapse)"] : []),
            "- Sizes: {$report['sizes']['documents']} documents, {$report['sizes']['queries']} queries, top {$report['sizes']['top_k']}, warmup {$report['sizes']['warmup']}".($report['sizes']['subset'] ? ' (subset)' : ''),
            "- Store: {$report['store']['name']}, `{$report['store']['index']}`",
            ...(isset($report['filter']) ? ["- Filtered set: field `{$report['filter']['field']}`, {$report['filter']['queries']} of {$report['filter']['unfiltered_queries']} queries (relevant documents share one value)"] : []),
            '',
            '## Quality',
            '',
        ];

        // The first mode (dense when it ran) is the reference for the deltas.
        $modes = $report['modes'];
        $reference = (string) array_key_first($modes);
        $base = $modes[$reference]['quality'];
        $lines[] = '| Metric | '.implode(' | ', array_map(static fn (string $mode): string => $mode === $reference ? $mode : "{$mode} (delta vs {$reference})", array_keys($modes))).' |';
        $lines[] = '|---|'.str_repeat('---|', count($modes));

        foreach (self::QUALITY_KEYS as $metric) {
            $cells = [];

            foreach ($modes as $mode => $data) {
                $value = $data['quality'][$metric];
                $cells[] = $mode === $reference ? sprintf('%.4f', $value) : sprintf('%.4f (%+.4f)', $value, $value - $base[$metric]);
            }

            $lines[] = "| {$metric} | ".implode(' | ', $cells).' |';
        }

        $belowTake = array_map(static fn (string $mode, array $data): string => "{$mode} ".($data['below_take'] ?? 'n/a'), array_keys($modes), $modes);
        $lines = [...$lines, '', "Queries with fewer than {$report['sizes']['top_k']} hits: ".implode(', ', $belowTake).'.'];
        $settings = array_filter(array_map(static fn (array $data): ?array => $data['settings'] ?? null, $modes));

        if ($settings !== []) {
            $lines = [...$lines, '', 'Hybrid settings: '.implode('; ', array_map(static fn (string $mode, array $s): string => sprintf('%s match %s, keyword weight %s', $mode, $s['match'], $s['keyword_weight']), array_keys($settings), $settings)).'.'];
        }

        if (isset($modes[$reference]['candidates'])) {
            $ks = array_keys($modes[$reference]['candidates']);
            $lines = [...$lines, '', '## Candidate recall (same budget: top K of each mode)', '', '| Mode | '.implode(' | ', $ks).' |', '|---|'.str_repeat('---|', count($ks))];

            foreach ($modes as $mode => $data) {
                $cells = array_map(static fn (string $k): string => $mode === $reference
                    ? sprintf('%.4f', $data['candidates'][$k])
                    : sprintf('%.4f (%+.4f)', $data['candidates'][$k], $data['candidates'][$k] - $modes[$reference]['candidates'][$k]), $ks);
                $lines[] = "| {$mode} | ".implode(' | ', $cells).' |';
            }
        }

        $paired = array_filter(array_map(static fn (array $data): ?array => $data['paired'] ?? null, $modes));

        if ($paired !== []) {
            $interval = static fn (array $d): string => sprintf('%+.4f [%+.4f, %+.4f]', $d['mean'], $d['low'], $d['high']);
            $lines = [...$lines, '', '## Paired differences (per query, bootstrap 95% interval)', '', '| Mode | Reference | nDCG@10 | recall@5 | candidate recall@30 |', '|---|---|---|---|---|'];

            foreach ($paired as $mode => $p) {
                $lines[] = "| {$mode} | {$p['reference']} | {$interval($p['ndcg_at_10'])} | ".(isset($p['recall_at_5']) ? $interval($p['recall_at_5']) : 'n/a')." | {$interval($p['candidate_recall_at_30'])} |";
            }
        }

        $reranked = array_filter(array_map(static fn (array $data): ?array => $data['rerank'] ?? null, $modes));

        if ($reranked !== []) {
            $lines = [...$lines, '', '## Reranking (one client; latency added on top of the store time)', '', '| Mode | Reranker | Candidates | Requests (cached) | p50 ms | p95 ms | Units | Tokens |', '|---|---|---|---|---|---|---|---|'];

            foreach ($reranked as $mode => $r) {
                $lines[] = sprintf('| %s | %s (`%s`) | %d | %d (%d) | %s | %s | %s | %s |', $mode, $r['reranker'], $r['model'], $r['candidates'], $r['calls'], $r['cached'], $r['p50_ms'] ?? 'n/a', $r['p95_ms'] ?? 'n/a', $r['units'] ?? 'n/a', $r['tokens'] ?? 'n/a');
            }
        }

        if (! empty($report['union'])) {
            $lines = [...$lines, '', '## Raw union (dense top K plus keyword top K: up to 2K documents, not a same-budget comparison)', '', '| Match | K | Recall | Documents (mean, max) |', '|---|---|---|---|'];

            foreach ($report['union'] as $match => $byK) {
                foreach ($byK as $k => $u) {
                    $lines[] = sprintf('| %s | %s | %.4f | %.1f, %d |', $match, substr((string) $k, 2), $u['recall'], $u['documents_mean'], $u['documents_max']);
                }
            }
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
            sprintf('- Embedding: %d ms, %d calls, %s tokens%s', $ingest['embedding_ms'], $ingest['embedding_calls'], $ingest['embedding_tokens'] ?? 'n/a', ($ingest['cached'] ?? false) ? ' (vectors from the document cache)' : ''),
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
            ...(empty($cost['rerank']) ? [] : [sprintf(
                '- Rerank (%s, `%s`): %d requests this run (%s units, %s tokens), cost %s; per query %s (prices: %s per 1K searches, %s per 1M tokens)',
                $cost['rerank']['reranker'], $cost['rerank']['model'], $cost['rerank']['calls'], $cost['rerank']['units'] ?? 'n/a', $cost['rerank']['tokens'] ?? 'n/a',
                $cost['rerank']['run'] ?? 'n/a', $cost['rerank']['per_query'] ?? 'n/a', $cost['rerank']['price_per_1k_searches'] ?? 'none', $cost['rerank']['price_per_1m_tokens'] ?? 'none',
            )]),
            ...(isset($cost['rerank']['price_per_1m_input_tokens']) ? [sprintf(
                '- Rerank token prices split: %s per 1M input tokens (%s this run), %s per 1M output tokens (%s this run); %d cached requests without the split priced all as input',
                $cost['rerank']['price_per_1m_input_tokens'], $cost['rerank']['input_tokens'] ?? 'n/a', $cost['rerank']['price_per_1m_output_tokens'], $cost['rerank']['output_tokens'] ?? 'n/a', $cost['rerank']['unsplit'],
            )] : []),
            '',
            isset($report['runtime_ms']) ? sprintf('Runtime: %.1f s for the whole run.', $report['runtime_ms'] / 1000) : 'Runtime: not recorded.',
            '',
        ]);
    }
}
