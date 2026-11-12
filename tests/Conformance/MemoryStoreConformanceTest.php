<?php

namespace AgenticGraph\Vector\Tests\Conformance;

use AgenticGraph\Vector\Contracts\VectorStoreContract;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Testing\StoreConformanceTests;
use AgenticGraph\Vector\Tests\TestCase;

final class MemoryStoreConformanceTest extends TestCase
{
    use StoreConformanceTests;

    protected function makeStore(): VectorStoreContract
    {
        return Vector::store('memory');
    }

    protected function makeSecondStore(): VectorStoreContract
    {
        config()->set('vector.stores.memory_b', ['driver' => 'memory', 'batch_size' => 7]);

        return Vector::store('memory_b');
    }
}
