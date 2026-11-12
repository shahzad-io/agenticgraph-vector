<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use RuntimeException;

/**
 * Vectors keyed by the sha256 of their text, independent of any store: `<base>.f32` holds the rows as 32-bit floats in
 * little-endian order, `<base>.index.jsonl` a header line (model, dimensions) and one line per row (id, sha256, and
 * extra fields such as a query's embedding time). Rows are only appended. The index lives in memory; vectors are read
 * row by row, so a caller that asks for a chunk at a time never holds the whole corpus.
 */
final class VectorCache
{
    /** @var array<string, int> sha256 => row */
    private array $rows = [];

    /** @var array<string, array<string, mixed>> sha256 => extra fields */
    private array $extra = [];

    public function __construct(public readonly string $base, public readonly string $model, public readonly int $dimensions)
    {
        if (! is_file($this->indexFile())) {
            return;
        }

        $lines = file($this->indexFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        /** @var array{model: string, dimensions: int} $header */
        $header = json_decode((string) array_shift($lines), true, flags: JSON_THROW_ON_ERROR);

        if ($header['model'] !== $model || $header['dimensions'] !== $dimensions) {
            throw new RuntimeException("Vector cache {$this->indexFile()} holds {$header['model']} at {$header['dimensions']} dimensions, not {$model} at {$dimensions}.");
        }

        foreach ($lines as $row => $line) {
            /** @var array{id: string|null, sha256: string} $entry */
            $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $this->rows[$entry['sha256']] = $row;
            $this->extra[$entry['sha256']] = array_diff_key($entry, ['id' => 0, 'sha256' => 0]);
        }

        // A write stopped between the vector and its index line leaves bytes no index line names: drop them.
        $expected = count($this->rows) * $this->rowBytes();
        clearstatcache();

        if (filesize($this->vectorFile()) < $expected) {
            throw new RuntimeException("Vector cache {$this->vectorFile()} is shorter than its index.");
        }

        if (filesize($this->vectorFile()) > $expected) {
            $handle = fopen($this->vectorFile(), 'r+') ?: throw new RuntimeException("Cannot open {$this->vectorFile()}.");
            ftruncate($handle, $expected);
            fclose($handle);
        }
    }

    public static function sha(string $text): string
    {
        return hash('sha256', $text);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function has(string $sha): bool
    {
        return isset($this->rows[$sha]);
    }

    /**
     * @return array<string, mixed>
     */
    public function extra(string $sha): array
    {
        return $this->extra[$sha] ?? [];
    }

    /**
     * The vectors of the given checksums that the cache holds, read in file order.
     *
     * @param  list<string>  $shas
     * @return array<string, list<float>>
     */
    public function get(array $shas): array
    {
        $rows = array_intersect_key($this->rows, array_flip($shas));
        asort($rows);
        $vectors = [];

        if ($rows === []) {
            return [];
        }

        $handle = fopen($this->vectorFile(), 'rb') ?: throw new RuntimeException("Cannot open {$this->vectorFile()}.");

        foreach ($rows as $sha => $row) {
            fseek($handle, $row * $this->rowBytes());
            $vectors[$sha] = array_values(unpack('g*', (string) fread($handle, $this->rowBytes())) ?: []);
        }

        fclose($handle);

        return $vectors;
    }

    /**
     * Append rows not cached yet: vectors first, then their index lines, so an index line never names missing bytes.
     *
     * @param  list<array{id: string|null, sha256: string, vector: list<float>, extra?: array<string, mixed>}>  $entries
     */
    public function put(array $entries): void
    {
        $entries = array_values(array_filter($entries, fn (array $entry): bool => ! $this->has($entry['sha256'])));
        $entries = array_values(array_column($entries, null, 'sha256'));

        if ($entries === []) {
            return;
        }

        @mkdir(dirname($this->base), 0777, true);
        $bytes = '';
        $lines = is_file($this->indexFile()) ? '' : json_encode(['model' => $this->model, 'dimensions' => $this->dimensions], JSON_THROW_ON_ERROR)."\n";

        foreach ($entries as $entry) {
            if (count($entry['vector']) !== $this->dimensions) {
                throw new RuntimeException('A vector of '.count($entry['vector'])." dimensions cannot go into a cache of {$this->dimensions}.");
            }

            $bytes .= pack('g*', ...$entry['vector']);
            $lines .= json_encode(['id' => $entry['id'], 'sha256' => $entry['sha256'], ...($entry['extra'] ?? [])], JSON_THROW_ON_ERROR)."\n";
        }

        file_put_contents($this->vectorFile(), $bytes, FILE_APPEND | LOCK_EX);
        file_put_contents($this->indexFile(), $lines, FILE_APPEND | LOCK_EX);

        foreach ($entries as $entry) {
            $this->rows[$entry['sha256']] = count($this->rows);
            $this->extra[$entry['sha256']] = $entry['extra'] ?? [];
        }
    }

    /**
     * Convert a JSON cache of the earlier format once: rows of $rows (each with its id, text checksum and vector) go
     * into this cache, and the JSON file is renamed to `.converted`.
     *
     * @param  list<array{id: string|null, sha256: string, vector: list<float>, extra?: array<string, mixed>}>  $rows
     */
    public function convert(string $jsonFile, array $rows): void
    {
        $this->put($rows);
        rename($jsonFile, "{$jsonFile}.converted");
    }

    public function vectorFile(): string
    {
        return "{$this->base}.f32";
    }

    public function indexFile(): string
    {
        return "{$this->base}.index.jsonl";
    }

    private function rowBytes(): int
    {
        return $this->dimensions * 4;
    }
}
