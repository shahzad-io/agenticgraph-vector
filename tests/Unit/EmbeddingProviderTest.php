<?php

use AgenticGraph\Vector\Events\VectorEmbeddingCompleted;
use AgenticGraph\Vector\Exceptions\VectorEmbeddingException;
use AgenticGraph\Vector\Providers\DeterministicEmbeddingProvider;
use AgenticGraph\Vector\Providers\PrismEmbeddingProvider;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;

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

it('asks the provider once for dimensions when none are configured', function () {
    $fake = Prism::fake([EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray([0.1, 0.2, 0.3, 0.4])])]);

    $provider = new PrismEmbeddingProvider(['provider' => 'openai', 'model' => 'text-embedding-3-small', 'dimensions' => null]);

    expect($provider->dimensions())->toBe(4)
        ->and($provider->dimensions())->toBe(4);
    $fake->assertCallCount(1);
    $fake->assertRequest(fn (array $recorded) => expect($recorded[0]->providerOptions('dimensions'))->toBeNull());
});

it('throws a clear error when no model is configured', function (array $config, string $message) {
    $fake = Prism::fake([]);

    expect(fn () => (new PrismEmbeddingProvider($config))->embed('hello'))
        ->toThrow(VectorEmbeddingException::class, $message);
    $fake->assertCallCount(0);
})->with([
    'nothing set' => [[], 'Set VECTOR_EMBEDDINGS_MODEL'],
    'provider only' => [['provider' => 'openai'], 'Set VECTOR_EMBEDDINGS_MODEL'],
    'model without provider' => [['model' => 'text-embedding-3-small'], 'Set VECTOR_EMBEDDINGS_PRISM_PROVIDER'],
    'empty provider prefix' => [['model' => ':text-embedding-3-small'], 'Set VECTOR_EMBEDDINGS_PRISM_PROVIDER'],
]);

it('reads provider:model from the model key', function () {
    $fake = Prism::fake([EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray([0.1])])]);

    (new PrismEmbeddingProvider(['model' => 'mistral:mistral-embed']))->embed('hello');

    $fake->assertRequest(fn (array $recorded) => expect($recorded[0]->provider())->toBe('mistral')
        ->and($recorded[0]->model())->toBe('mistral-embed'));
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

it('fires VectorEmbeddingCompleted with the token usage Prism reports, per embed and per batch', function () {
    Event::fake([VectorEmbeddingCompleted::class]);
    Prism::fake([
        EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray([0.1, 0.2, 0.3])])->withUsage(new EmbeddingsUsage(7)),
        EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray([0.1, 0.2, 0.3]), Embedding::fromArray([0.4, 0.5, 0.6])])->withUsage(new EmbeddingsUsage(19)),
    ]);

    $provider = new PrismEmbeddingProvider(['provider' => 'openai', 'model' => 'text-embedding-3-small', 'dimensions' => 3]);
    $provider->embed('hello');
    $provider->embedBatch(['hello', 'world']);

    $events = Event::dispatched(VectorEmbeddingCompleted::class)->map(fn (array $args): array => [
        $args[0]->model, $args[0]->inputCount, $args[0]->tokens, $args[0]->durationMs >= 0,
    ])->all();

    expect($events)->toBe([
        ['text-embedding-3-small', 1, 7, true],
        ['text-embedding-3-small', 2, 19, true],
    ]);
});

it('fires VectorEmbeddingCompleted without tokens from the deterministic provider', function () {
    Event::fake([VectorEmbeddingCompleted::class]);

    $provider = new DeterministicEmbeddingProvider(dimensions: 8);
    $provider->embed('hello');
    $provider->embedBatch(['a', 'b', 'c']);

    Event::assertDispatchedTimes(VectorEmbeddingCompleted::class, 2);
    Event::assertDispatched(VectorEmbeddingCompleted::class, fn (VectorEmbeddingCompleted $event): bool => $event->model === 'deterministic-test'
        && $event->inputCount === 3
        && $event->tokens === null);
});

it('guard: fires no VectorEmbeddingCompleted when vector events are disabled', function () {
    config()->set('vector.events.enabled', false);
    Event::fake([VectorEmbeddingCompleted::class]);

    (new DeterministicEmbeddingProvider(dimensions: 8))->embedBatch(['a']);

    Event::assertNotDispatched(VectorEmbeddingCompleted::class);
});
