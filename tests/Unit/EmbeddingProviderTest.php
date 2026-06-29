<?php

use AgenticGraph\Vector\Exceptions\VectorEmbeddingException;
use AgenticGraph\Vector\Providers\DeterministicEmbeddingProvider;
use AgenticGraph\Vector\Providers\PrismEmbeddingProvider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\ValueObjects\Embedding;

it('embeds text deterministically with aligned batch output', function () {
    $provider = new DeterministicEmbeddingProvider(dimensions: 8);

    $first = $provider->embed('hello');
    $batch = $provider->embedBatch(['hello', 'world']);

    expect($first)->toHaveCount(8)
        ->and($batch)->toHaveCount(2)
        ->and($batch[0])->toBe($first)
        ->and($batch[1])->not->toBe($first)
        ->and($provider->dimensions())->toBe(8)
        ->and($provider->model())->toBe('deterministic-test');
});

it('uses Prism embeddings API with native batch support', function () {
    Prism::fake([
        EmbeddingsResponseFake::make()->withEmbeddings([
            Embedding::fromArray([0.1, 0.2, 0.3]),
        ]),
        EmbeddingsResponseFake::make()->withEmbeddings([
            Embedding::fromArray([0.1, 0.2, 0.3]),
            Embedding::fromArray([0.4, 0.5, 0.6]),
        ]),
    ]);

    $provider = new PrismEmbeddingProvider([
        'provider' => 'openai',
        'model' => 'text-embedding-3-small',
        'dimensions' => 3,
    ]);

    expect($provider->embed('hello'))->toBe([0.1, 0.2, 0.3])
        ->and($provider->embedBatch(['hello', 'world']))->toBe([
            [0.1, 0.2, 0.3],
            [0.4, 0.5, 0.6],
        ])
        ->and($provider->model())->toBe('text-embedding-3-small')
        ->and($provider->dimensions())->toBe(3);
});

it('forwards configured dimensions to Prism provider options', function () {
    $dimensions = 1024;
    $vector = array_fill(0, $dimensions, 0.01);

    $fake = Prism::fake([
        EmbeddingsResponseFake::make()->withEmbeddings([
            Embedding::fromArray($vector),
        ]),
    ]);

    $provider = new PrismEmbeddingProvider([
        'provider' => 'openai',
        'model' => 'text-embedding-3-small',
        'dimensions' => $dimensions,
    ]);

    $embedding = $provider->embed('dimension probe');

    expect($embedding)->toHaveCount($dimensions)
        ->and($provider->dimensions())->toBe($dimensions);

    $fake->assertRequest(function (array $recorded) use ($dimensions): void {
        expect($recorded)->toHaveCount(1)
            ->and($recorded[0]->providerOptions('dimensions'))->toBe($dimensions);
    });
});

it('wraps Prism failures in VectorEmbeddingException', function () {
    Prism::fake([]);

    $provider = new PrismEmbeddingProvider([
        'provider' => 'openai',
        'model' => 'text-embedding-3-small',
    ]);

    expect(fn () => $provider->embed('hello'))
        ->toThrow(VectorEmbeddingException::class, 'Prism returned no embeddings.');
});

it('wraps PrismException in VectorEmbeddingException', function () {
    $manager = Mockery::mock(PrismManager::class);
    $manager->shouldReceive('resolve')->andThrow(new PrismException('provider unavailable'));
    app()->instance(PrismManager::class, $manager);

    $provider = new PrismEmbeddingProvider([
        'provider' => 'openai',
        'model' => 'text-embedding-3-small',
    ]);

    expect(fn () => $provider->embed('hello'))
        ->toThrow(VectorEmbeddingException::class, 'Embedding generation failed');
});
