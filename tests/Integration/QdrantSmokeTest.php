<?php

use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Tests\Support\QdrantTestHelper;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;

it('creates, writes, searches with a filter, and deletes on a live Qdrant', function () {
    if (! QdrantTestHelper::isAvailable()) {
        $this->markTestSkipped(QdrantTestHelper::skipReason());
    }

    config()->set('vector.stores.qdrant.url', QdrantTestHelper::url());
    $store = Vector::store('qdrant');
    $name = 'smoke_'.getmypid();

    $store->createCollection(new CollectionDefinition($name, dimensions: 2, filterable: ['lang' => 'keyword', 'price' => 'float']));

    try {
        $store->upsert($name, [
            new Document(id: 'a', content: 'A', metadata: ['lang' => 'en', 'price' => 3], vector: [1, 0]),
            new Document(id: 7, content: 'B', metadata: ['lang' => 'fr', 'price' => 1.5], vector: [0.6, 0.8]),
            new Document(id: 'c', content: 'C', metadata: ['lang' => 'en', 'price' => 9.0], vector: [0, 1]),
        ]);

        $hits = $store->search(new SearchQuery($name, [1, 0], NormalizedFilter::fromAssociative(['lang' => 'en']), topK: 5))->hits;

        expect(array_map(fn ($hit) => [$hit->id, round($hit->score, 6), $hit->metadata], $hits))->toBe([
            ['a', 1.0, ['lang' => 'en', 'price' => 3.0]],
            ['c', 0.0, ['lang' => 'en', 'price' => 9.0]],
        ])->and($store->describeCollection($name)['filterable'])->toBe(['lang' => 'keyword', 'price' => 'float']);
    } finally {
        $store->deleteCollection($name);
    }

    expect($store->collectionExists($name))->toBeFalse();
});
