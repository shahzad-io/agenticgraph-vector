<?php

declare(strict_types=1);

namespace AgenticGraph\Vector;

use AgenticGraph\Vector\Stores\PgVectorStore;
use Illuminate\Support\Manager;

class VectorManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('vector.default', 'pgvector');
    }

    public function store(?string $name = null): PgVectorStore
    {
        /** @var PgVectorStore */
        return $this->driver($name);
    }

    protected function createPgvectorDriver(): PgVectorStore
    {
        /** @var array<string, mixed> $config */
        $config = $this->config->get('vector.stores.pgvector', []);

        return new PgVectorStore($config);
    }
}
