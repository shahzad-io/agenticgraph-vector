<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

final readonly class StoreCapabilities
{
    public function __construct(
        private bool $deleteByFilter = false,
        private bool $namespaces = false,
        private bool $hybridSearch = false,
        private bool $reranking = false,
        private bool $tenantIsolation = false,
    ) {}

    public static function pgvector(): self
    {
        return new self(
            deleteByFilter: true,
            namespaces: false,
            hybridSearch: false,
            reranking: false,
            tenantIsolation: false,
        );
    }

    public function supportsDeleteByFilter(): bool
    {
        return $this->deleteByFilter;
    }

    public function supportsNamespaces(): bool
    {
        return $this->namespaces;
    }

    public function supportsHybridSearch(): bool
    {
        return $this->hybridSearch;
    }

    public function supportsReranking(): bool
    {
        return $this->reranking;
    }

    public function supportsTenantIsolation(): bool
    {
        return $this->tenantIsolation;
    }
}
