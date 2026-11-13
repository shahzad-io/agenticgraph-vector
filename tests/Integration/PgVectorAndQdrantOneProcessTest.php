<?php

use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use AgenticGraph\Vector\Tests\Support\QdrantTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;

it('keeps the data of a pgvector store and a Qdrant store apart in one process (E3)', function () {
    foreach ([PgvectorTestHelper::class, QdrantTestHelper::class] as $helper) {
        if (! $helper::isAvailable()) {
            $this->markTestSkipped($helper::skipReason());
        }
    }

    config()->set('vector.stores.qdrant.url', QdrantTestHelper::url());
    $stores = ['pg' => Vector::store('pgvector'), 'qd' => Vector::store('qdrant')];
    $name = 'e3_'.getmypid();

    try {
        foreach ($stores as $id => $store) {
            $store->createCollection(new CollectionDefinition($name, dimensions: 2, metric: 'cosine'));
            $store->collection($name)->upsert([Document::make($id, $id, vector: [1.0, 0.0])]);
        }

        foreach ($stores as $id => $store) {
            expect($store->collection($name)->usingVector([1.0, 0.0])->get()->pluck('id'))->toBe([$id]);
        }
    } finally {
        foreach ($stores as $store) {
            if ($store->collectionExists($name)) {
                $store->deleteCollection($name);
            }
        }
    }
});
