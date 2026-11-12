<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * What the benchmark needs from a store beyond VectorStoreContract. BenchRunner resolves the probe of a store's
 * driver by name (`<Driver>BenchProbe`, for example QdrantBenchProbe), else GenericBenchProbe. A null is a value the
 * probe cannot give; the report prints "not available".
 */
interface BenchStoreProbe
{
    public function __construct(string $store);

    /**
     * Config the store needs in the bench app (a connection, a url), set before the package boots.
     */
    public static function configure(Repository $config, string $store): void;

    public function engineVersion(): ?string;

    /**
     * @return array{table_bytes: int|null, index_bytes: int|null, total_bytes: int|null}|null
     */
    public function sizes(string $collection): ?array;

    /**
     * Return once the store's index of the collection is built and searches use it.
     */
    public function waitUntilReady(string $collection): void;

    /**
     * Rebuild the vector index in place (timed by the caller); false when the store has no such step.
     */
    public function rebuildIndex(string $collection): bool;

    /**
     * The index setting of the collection, as the store reports it.
     */
    public function index(string $collection): ?string;

    /**
     * The docker container to read the store's memory from, or null.
     */
    public function container(): ?string;

    /**
     * The search options of an index setting: the candidate depth $ef (null: the store default) and the HNSW
     * iterative scan (null: the store default). Throws a RuntimeException for a setting the store does not have.
     *
     * @return array<string, mixed>
     */
    public function searchOptions(?int $ef, ?string $iterativeScan): array;

    /**
     * The search options of an exact search (no approximate index). The collection is not changed, so a reused --collection works.
     *
     * @return array<string, mixed>
     */
    public function exactSearch(string $collection): array;
}
