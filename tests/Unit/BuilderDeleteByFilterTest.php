<?php

use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Stores\MemoryStore;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\StoreCapabilities;

function limitedStore(StoreCapability ...$capabilities)
{
    Vector::extend('limited', fn () => new MemoryStore([], app(EmbeddingsManager::class), new MetadataValidator, new StoreCapabilities(...$capabilities)));
    config()->set('vector.stores.limited', ['driver' => 'limited']);

    $store = Vector::store('limited');
    $store->createCollection(new CollectionDefinition('articles', dimensions: 3));
    $store->collection('articles')->upsert([Document::make('a', 'a', ['n' => 1, 'tags' => ['x']], [1.0, 0.0, 0.0])]);

    return $store;
}

it('throws a clear unsupported operation before delete by filter on incapable stores', function () {
    $builder = limitedStore()->collection('articles');

    expect(fn () => $builder->deleteByFilter(new NormalizedFilter([])))
        ->toThrow(VectorUnsupportedOperationException::class, 'Delete by filter is not supported by this store.')
        ->and(fn () => $builder->where('n', 1)->delete())
        ->toThrow(VectorUnsupportedOperationException::class, 'Delete by filter is not supported by this store.');
});

it('gates listIds, updateMetadata and each filter family on the store capabilities', function () {
    $builder = limitedStore(StoreCapability::DeleteByFilter, StoreCapability::UndeclaredFilters)->collection('articles')->usingVector([1.0, 0.0, 0.0]);

    expect(fn () => $builder->listIds())->toThrow(VectorUnsupportedOperationException::class, 'Listing ids is not supported')
        ->and(fn () => $builder->updateMetadata(['a' => ['n' => 2]]))->toThrow(VectorUnsupportedOperationException::class, 'Updating metadata is not supported')
        ->and(fn () => $builder->where('n', '!=', 1)->get())->toThrow(VectorUnsupportedOperationException::class, 'The ne filter is not supported')
        ->and(fn () => $builder->whereNotIn('n', [1])->delete())->toThrow(VectorUnsupportedOperationException::class, 'The not_in filter is not supported')
        ->and(fn () => $builder->whereContains('tags', 'x')->get())->toThrow(VectorUnsupportedOperationException::class, 'The contains filter is not supported')
        ->and(fn () => $builder->where('n', '>', 0)->get())->toThrow(VectorUnsupportedOperationException::class, 'The gt filter is not supported')
        ->and($builder->where('n', 1)->get()->count())->toBe(1);
});
