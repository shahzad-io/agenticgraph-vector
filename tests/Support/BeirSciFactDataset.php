<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use RuntimeException;

/**
 * BEIR SciFact test split exported to JSON (see benchmarks/export-scifact.py).
 */
final class BeirSciFactDataset
{
    private const EXPORT_DIR = __DIR__.'/../../build/benchmark/scifact-export';

    /**
     * @return array{
     *     corpus: list<array{id: string, text: string}>,
     *     queries: list<array{id: string, text: string}>,
     *     qrels: array<string, list<string>>
     * }
     */
    public static function load(): array
    {
        self::assertExportExists();

        /** @var list<array{id: string, text: string}> $corpus */
        $corpus = json_decode(
            (string) file_get_contents(self::EXPORT_DIR.'/corpus.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        /** @var list<array{id: string, text: string}> $queries */
        $queries = json_decode(
            (string) file_get_contents(self::EXPORT_DIR.'/queries.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        /** @var array<string, list<string>> $qrels */
        $qrels = json_decode(
            (string) file_get_contents(self::EXPORT_DIR.'/qrels.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return compact('corpus', 'queries', 'qrels');
    }

    public static function exportPath(): string
    {
        return self::EXPORT_DIR;
    }

    public static function assertExportExists(): void
    {
        foreach (['corpus.json', 'queries.json', 'qrels.json'] as $file) {
            if (! is_file(self::EXPORT_DIR.'/'.$file)) {
                throw new RuntimeException(
                    'SciFact export missing at '.self::EXPORT_DIR.'. Run: build/benchmark/.venv/bin/python benchmarks/export-scifact.py',
                );
            }
        }
    }
}
