<?php

namespace AgenticGraph\Vector\Tests\Conformance;

use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Testing\StoreConformanceTests;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\Tests\TestCase;

final class PgVectorStoreConformanceTest extends TestCase
{
    use StoreConformanceTests;

    protected function makeStore(): VectorStoreContract
    {
        if (! PgvectorTestHelper::isAvailable()) {
            $this->markTestSkipped(PgvectorTestHelper::skipReason());
        }

        return Vector::store('pgvector');
    }

    protected function makeSecondStore(): VectorStoreContract
    {
        config()->set('vector.stores.pgvector_b', [...config('vector.stores.pgvector'), 'registry_table' => 'vector_registry_b', 'batch_size' => 37]);

        return Vector::store('pgvector_b');
    }
}
