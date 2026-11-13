<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * A store with no probe of its own: the contract only, every store-specific value not available.
 */
final class GenericBenchProbe implements BenchStoreProbe
{
    public function __construct(private readonly string $store) {}

    public static function configure(Repository $config, string $store): void {}

    public function engineVersion(): ?string
    {
        return null;
    }

    public function sizes(string $collection): ?array
    {
        return null;
    }

    public function waitUntilReady(string $collection): void {}

    public function rebuildIndex(string $collection): bool
    {
        return false;
    }

    public function index(string $collection): ?string
    {
        return null;
    }

    public function container(): ?string
    {
        return null;
    }

    public function searchOptions(?int $ef, ?string $iterativeScan): array
    {
        if ($iterativeScan !== null) {
            throw new RuntimeException("Store [{$this->store}] has no iterative scan setting.");
        }

        return [];
    }

    public function exactSearch(string $collection): array
    {
        throw new RuntimeException("Store [{$this->store}] has no probe for exact search.");
    }

    public function forceIndex(bool $on): void
    {
        throw new RuntimeException("Store [{$this->store}] has no probe to force its index.");
    }
}
