<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

/**
 * BEIR SciFact test split, the pinned `scifact` entry of benchmarks/datasets.json
 * (export: python3 benchmarks/export-datasets.py).
 */
final class BeirSciFactDataset
{
    /**
     * @return array{
     *     corpus: list<array{id: string, text: string}>,
     *     queries: list<array{id: string, text: string}>,
     *     qrels: array<string, list<string>>
     * }
     */
    public static function load(): array
    {
        $dataset = BenchDataset::load('scifact');

        return ['corpus' => $dataset['corpus'], 'queries' => $dataset['queries'], 'qrels' => $dataset['qrels']];
    }
}
