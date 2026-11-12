<?php

namespace AgenticGraph\Vector\Tests\Conformance;

use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Testing\StoreConformanceTests;
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
        // A collection_prefix keeps the second store's collections apart on the same server (Q4).
        config()->set('vector.stores.qdrant_b', [...config('vector.stores.qdrant'), 'url' => QdrantTestHelper::url(), 'collection_prefix' => 'conf_b_', 'batch_size' => 37]);

        return Vector::store('qdrant_b');
    }
}
