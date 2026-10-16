<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use RuntimeException;

/**
 * A benchmark dataset from benchmarks/datasets.json, exported by benchmarks/export-datasets.py
 * and checked against the manifest sha256 of every file before use.
 */
final class BenchDataset
{
    public const MANIFEST = __DIR__.'/../../benchmarks/datasets.json';

    public const EXPORT_DIR = __DIR__.'/../../build/benchmark/datasets';

    /**
     * @return array{
     *     name: string,
     *     meta: array<string, mixed>,
     *     corpus: list<array{id: string, text: string}>,
     *     queries: list<array{id: string, text: string}>,
     *     qrels: array<string, list<string>>
     * }
     */
    public static function load(string $name, string $manifest = self::MANIFEST, string $exportDir = self::EXPORT_DIR): array
    {
        /** @var array{datasets: array<string, array<string, mixed>>} $datasets */
        $datasets = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $meta = $datasets['datasets'][$name] ?? throw new RuntimeException(
            "Unknown dataset [{$name}]. Known: ".implode(', ', array_keys($datasets['datasets'])).'.',
        );

        $data = [];

        /** @var array<string, array{path: string, sha256: string}> $files */
        $files = $meta['files'];

        foreach ($files as $part => $file) {
            $path = $exportDir.'/'.$file['path'];

            if (! is_file($path)) {
                throw new RuntimeException("Dataset file missing: {$path}. Run: python3 benchmarks/export-datasets.py");
            }

            $contents = (string) file_get_contents($path);
            $sha256 = hash('sha256', $contents);

            if ($sha256 !== $file['sha256']) {
                throw new RuntimeException("Dataset file {$path} has sha256 {$sha256}, the manifest pins {$file['sha256']}.");
            }

            $data[$part] = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        }

        /** @var list<array{id: string, text: string}> $corpus */
        $corpus = $data['corpus'];
        /** @var list<array{id: string, text: string}> $queries */
        $queries = $data['queries'];
        /** @var array<string, list<string>> $qrels */
        $qrels = $data['qrels'];

        return ['name' => $name, 'meta' => $meta, 'corpus' => $corpus, 'queries' => $queries, 'qrels' => $qrels];
    }

    /**
     * A fixed-size run: the first $queries queries, and a corpus of $documents that holds their
     * relevant documents first. Queries whose relevant documents do not all fit are dropped.
     *
     * @template T of array{corpus: list<array{id: string, text: string}>, queries: list<array{id: string, text: string}>, qrels: array<string, list<string>>}
     *
     * @param  T  $dataset
     * @return T
     */
    public static function subset(array $dataset, ?int $documents = null, ?int $queries = null): array
    {
        if ($queries !== null) {
            $dataset['queries'] = array_slice($dataset['queries'], 0, $queries);
        }

        if ($documents !== null && $documents < count($dataset['corpus'])) {
            $keep = [];

            foreach ($dataset['queries'] as $query) {
                $relevant = $dataset['qrels'][$query['id']] ?? [];

                if (count($keep + array_flip($relevant)) <= $documents) {
                    $keep += array_flip($relevant);
                }
            }

            foreach ($dataset['corpus'] as $document) {
                if (count($keep) >= $documents) {
                    break;
                }

                $keep[$document['id']] ??= 0;
            }

            $dataset['corpus'] = array_values(array_filter($dataset['corpus'], static fn (array $document): bool => isset($keep[$document['id']])));
            $dataset['queries'] = array_values(array_filter(
                $dataset['queries'],
                static fn (array $query): bool => array_diff($dataset['qrels'][$query['id']] ?? [], array_keys($keep)) === [],
            ));
        }

        $dataset['qrels'] = array_intersect_key($dataset['qrels'], array_flip(array_column($dataset['queries'], 'id')));

        return $dataset;
    }

    /**
     * Cut a document to $maxChars characters (for models with a short context window).
     */
    public static function truncate(string $text, int $maxChars): string
    {
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: '';

        if ($maxChars <= 0 || mb_strlen($text, 'UTF-8') <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxChars - 3, 'UTF-8')).'...';
    }
}
