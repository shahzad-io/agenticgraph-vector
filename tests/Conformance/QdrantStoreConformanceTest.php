<?php

namespace AgenticGraph\Vector\Tests\Conformance;

use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Testing\StoreConformanceTests;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\Tests\Support\QdrantTestHelper;
use AgenticGraph\Vector\Tests\TestCase;

final class QdrantStoreConformanceTest extends TestCase
{
    use StoreConformanceTests;

    protected function makeStore(): VectorStoreContract
    {
        if (! QdrantTestHelper::isAvailable()) {
            $this->markTestSkipped(QdrantTestHelper::skipReason());
        }

        config()->set('vector.stores.qdrant.url', QdrantTestHelper::url());

        return Vector::store('qdrant');
    }

    protected function makeSecondStore(): VectorStoreContract
    {
        // Two Qdrant stores on one server share its collections, so the second store is pgvector (exit E3).
        if (! PgvectorTestHelper::isAvailable()) {
            $this->markTestSkipped(PgvectorTestHelper::skipReason());
        }

        config()->set('vector.stores.pgvector_b', [...config('vector.stores.pgvector'), 'registry_table' => null, 'table_prefix' => 'conf_q_', 'batch_size' => 37]);

        return Vector::store('pgvector_b');
    }
}
