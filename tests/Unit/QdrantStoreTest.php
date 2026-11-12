<?php

use AgenticGraph\Vector\EmbeddingsManager;
use AgenticGraph\Vector\Enums\FieldType;
use AgenticGraph\Vector\Enums\StoreCapability;
use AgenticGraph\Vector\Exceptions\VectorCollectionNotFoundException;
use AgenticGraph\Vector\Exceptions\VectorConnectionException;
use AgenticGraph\Vector\Exceptions\VectorDimensionMismatchException;
use AgenticGraph\Vector\Exceptions\VectorDuplicateDocumentException;
use AgenticGraph\Vector\Exceptions\VectorQueryException;
use AgenticGraph\Vector\Exceptions\VectorUnsupportedOperationException;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Stores\InstrumentedStore;
use AgenticGraph\Vector\Stores\QdrantStore;
use AgenticGraph\Vector\Support\MetadataValidator;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use AgenticGraph\Vector\ValueObjects\NormalizedFilter;
use AgenticGraph\Vector\ValueObjects\SearchQuery;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;

const QDRANT_FIELDS = ['tag' => 'keyword', 'n' => 'integer', 'price' => 'float', 'on' => 'bool', 'at' => 'datetime', 'tags' => 'keyword_list'];

function qdrantStore(array $config = []): QdrantStore
{
    return new QdrantStore(['url' => 'http://qdrant.test', ...$config], app(EmbeddingsManager::class), app(MetadataValidator::class), 'qdrant');
}

/**
 * The GET /collections/{name} answer of a package collection.
 */
function qdrantInfo(string $metric = 'cosine', array $filterable = QDRANT_FIELDS, int $dimensions = 2): array
{
    return ['result' => ['config' => [
        'params' => ['vectors' => ['size' => $dimensions, 'distance' => 'Cosine']],
        'hnsw_config' => ['m' => 16, 'ef_construct' => 100, 'full_scan_threshold' => 10000],
        'metadata' => ['agenticgraph' => ['format' => 1, 'collection' => 'docs', 'dimensions' => $dimensions, 'metric' => $metric, 'embedding' => null, 'filterable' => $filterable]],
    ]], 'status' => 'ok'];
}

/**
 * Fakes Qdrant: GET of a collection answers $info, every route in $routes ("METHOD path" without query) its body,
 * anything else {"result": true}.
 *
 * @param  array<string, mixed>  $routes
 */
function fakeQdrant(?array $info = null, array $routes = []): void
{
    $info ??= qdrantInfo();
    Http::fake(function (Request $request) use ($info, $routes) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $route = $request->method().' '.$path;

        if (array_key_exists($route, $routes)) {
            $answer = $routes[$route];

            return match (true) {
                $answer instanceof PromiseInterface => $answer,
                is_object($answer) && is_callable($answer) => $answer($request),
                default => Http::response($answer),
            };
        }

        return $request->method() === 'GET' && preg_match('#^/collections/[^/]+$#', $path)
            ? Http::response($info)
            : Http::response(['result' => true, 'status' => 'ok']);
    });
}

/**
 * The requests sent, except the registry read: [method, path with query, decoded body].
 *
 * @return list<array{0: string, 1: string, 2: mixed}>
 */
function qdrantSent(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => [$pair[0]->method(), substr($pair[0]->url(), strlen('http://qdrant.test/')), json_decode($pair[0]->body(), true)])
        ->reject(fn (array $sent) => $sent[0] === 'GET' && preg_match('#^collections/[^/]+$#', $sent[1]))
        ->values()
        ->all();
}

function pid(string|int $id): string
{
    return QdrantStore::pointId((string) $id);
}

it('declares exactly the capabilities of B2', function () {
    expect(qdrantStore()->capabilities()->capabilities)->toBe([
        StoreCapability::ListIds, StoreCapability::UpdateMetadata, StoreCapability::DeleteByFilter,
        StoreCapability::FilterNot, StoreCapability::FilterContains, StoreCapability::FilterRange, StoreCapability::Aliases,
    ]);
});

it('makes point ids as UUID v5 of the document id', function () {
    expect(pid('doc-1'))->toBe(Uuid::uuid5(QdrantStore::POINT_NAMESPACE, 'doc-1')->toString())
        ->and(pid('é/ü 🙂'))->toBe(Uuid::uuid5(QdrantStore::POINT_NAMESPACE, 'é/ü 🙂')->toString())
        ->and(pid(5))->toBe(pid('5'));
});

it('resolves the qdrant driver through the manager with its own batch size', function () {
    config()->set('vector.stores.qdrant', ['driver' => 'qdrant', 'url' => 'http://qdrant.test', 'batch_size' => 7]);

    expect(Vector::store('qdrant'))->toBeInstanceOf(InstrumentedStore::class)
        ->and(Vector::store('qdrant')->batchSize())->toBe(7);
});

it('creates a collection with the registry in one request, then a payload index per declared field', function () {
    fakeQdrant(routes: ['GET /collections/docs' => Http::response(['status' => ['error' => 'Not found: Collection `docs` does not exist!']], 404)]);

    qdrantStore()->createCollection(new CollectionDefinition('docs', dimensions: 3, metric: 'l2', index: 'hnsw', indexOptions: ['m' => 8, 'ef_construct' => 64], filterable: ['n' => FieldType::Integer, 'tags' => 'keyword_list', 'at' => 'datetime']));

    expect(qdrantSent())->toBe([
        ['PUT', 'collections/docs', [
            'vectors' => ['size' => 3, 'distance' => 'Euclid'],
            'hnsw_config' => ['m' => 8, 'ef_construct' => 64],
            'metadata' => ['agenticgraph' => ['format' => 1, 'collection' => 'docs', 'dimensions' => 3, 'metric' => 'l2', 'embedding' => ['provider' => 'deterministic', 'model' => 'deterministic-test', 'dimensions' => 3], 'filterable' => ['at' => 'datetime', 'n' => 'integer', 'tags' => 'keyword_list']]],
        ]],
        ['PUT', 'collections/docs/index?wait=true', ['field_name' => 'id', 'field_schema' => 'keyword']],
        ['PUT', 'collections/docs/index?wait=true', ['field_name' => 'metadata.at', 'field_schema' => 'datetime']],
        ['PUT', 'collections/docs/index?wait=true', ['field_name' => 'metadata.n', 'field_schema' => 'integer']],
        ['PUT', 'collections/docs/index?wait=true', ['field_name' => 'metadata.tags', 'field_schema' => 'keyword']],
    ]);
});

it('maps each metric to its Qdrant distance', function (string $metric, string $distance) {
    fakeQdrant(routes: ['GET /collections/docs' => Http::response([], 404)]);

    qdrantStore()->createCollection(new CollectionDefinition('docs', dimensions: 2, metric: $metric));

    expect(qdrantSent()[0][2]['vectors']['distance'])->toBe($distance);
})->with([['cosine', 'Cosine'], ['inner_product', 'Dot'], ['l2', 'Euclid']]);

it('deletes the new collection when a payload index fails', function () {
    fakeQdrant(routes: [
        'GET /collections/docs' => Http::response([], 404),
        'PUT /collections/docs/index' => Http::response(['status' => ['error' => 'bad index']], 400),
    ]);

    expect(fn () => qdrantStore()->createCollection(new CollectionDefinition('docs', dimensions: 2)))->toThrow(VectorQueryException::class, 'bad index')
        ->and(qdrantSent()[2])->toBe(['DELETE', 'collections/docs', null]);
});

it('is idempotent for the same definition and refuses a different one', function () {
    fakeQdrant();

    qdrantStore()->createCollection(new CollectionDefinition('docs', dimensions: 2, filterable: QDRANT_FIELDS));
    qdrantStore()->createCollection(new CollectionDefinition('docs', dimensions: 2, indexOptions: ['m' => 16]));
    expect(qdrantSent())->toBe([])
        ->and(fn () => qdrantStore()->createCollection(new CollectionDefinition('docs', dimensions: 2, filterable: ['tag' => 'keyword'])))->toThrow(VectorQueryException::class, 'switch an alias')
        ->and(fn () => qdrantStore()->createCollection(new CollectionDefinition('docs', dimensions: 4)))->toThrow(VectorQueryException::class, 'different definition')
        ->and(fn () => qdrantStore()->createCollection(new CollectionDefinition('docs', dimensions: 2, indexOptions: ['m' => 32])))->toThrow(VectorQueryException::class, 'different definition');
});

it('refuses keyword search, an unknown metric, index and index options before any request', function (CollectionDefinition $definition, string $exception, string $message) {
    fakeQdrant();

    expect(fn () => qdrantStore()->createCollection($definition))->toThrow($exception, $message);
    Http::assertNothingSent();
})->with([
    'keyword' => [new CollectionDefinition('docs', dimensions: 2, keyword: true), VectorUnsupportedOperationException::class, 'no keyword search'],
    'metric' => [new CollectionDefinition('docs', dimensions: 2, metric: 'hamming'), VectorQueryException::class, 'Metric [hamming]'],
    'index' => [new CollectionDefinition('docs', dimensions: 2, index: 'ivfflat'), VectorQueryException::class, 'Index [ivfflat]'],
    'option name' => [new CollectionDefinition('docs', dimensions: 2, indexOptions: ['lists' => 4]), VectorQueryException::class, 'Index option [lists]'],
    'option type' => [new CollectionDefinition('docs', dimensions: 2, indexOptions: ['m' => '16']), VectorQueryException::class, 'Index option [m]'],
]);

it('describes a collection from its registry, and refuses one without a registry', function () {
    fakeQdrant(routes: ['GET /collections/foreign' => ['result' => ['config' => ['metadata' => []]]]]);

    expect(qdrantStore()->describeCollection('docs'))->toBe([
        'collection' => 'docs', 'dimensions' => 2, 'metric' => 'cosine',
        'index' => ['type' => 'hnsw', 'options' => ['m' => 16, 'ef_construct' => 100]],
        'embedding' => null, 'keyword' => null,
        'filterable' => ['at' => 'datetime', 'n' => 'integer', 'on' => 'bool', 'price' => 'float', 'tag' => 'keyword', 'tags' => 'keyword_list'],
    ])->and(fn () => qdrantStore()->describeCollection('foreign'))->toThrow(VectorQueryException::class, 'was not created by agenticgraph/vector');
});

it('upserts one PUT per chunk with wait, the reserved payload keys and floats kept', function () {
    fakeQdrant();
    $progress = [];

    qdrantStore(['batch_size' => 2])->upsert('docs', [
        new Document(id: 'a', content: 'A', metadata: ['price' => 3, 'x' => 1.0], vector: [1, 0]),
        new Document(id: 7, content: 'B', vector: [0, 1]),
        new Document(id: 'c', content: 'C', metadata: ['tag' => 'own'], vector: [1, 1]),
    ], ['tag' => 'global'], function (int $done, int $total) use (&$progress) {
        $progress[] = [$done, $total];
    });

    $sent = qdrantSent();
    $bodies = Http::recorded()->map(fn (array $pair) => $pair[0]->body())->filter(fn (string $body) => str_contains($body, '"points"'))->values();

    expect(array_column($sent, 1))->toBe(['collections/docs/points?wait=true', 'collections/docs/points?wait=true'])
        ->and($sent[0][2]['points'][0])->toBe(['id' => pid('a'), 'vector' => [1.0, 0.0], 'payload' => ['id' => 'a', 'content' => 'A', 'metadata' => ['tag' => 'global', 'price' => 3.0, 'x' => 1.0]]])
        ->and($sent[0][2]['points'][1]['payload'])->toBe(['id' => '7', 'content' => 'B', 'metadata' => ['tag' => 'global']])
        ->and($sent[1][2]['points'][0]['payload']['metadata'])->toBe(['tag' => 'own'])
        ->and($bodies[0])->toContain('"price":3.0,"x":1.0')
        ->and($progress)->toBe([[2, 3], [3, 3]]);
});

it('sends empty metadata as an object', function () {
    fakeQdrant();

    qdrantStore()->upsert('docs', [new Document(id: 'a', content: 'A', vector: [1, 0])]);

    expect(Http::recorded()->last()[0]->body())->toContain('"metadata":{}');
});

it('checks dimensions and declared types before the request', function () {
    fakeQdrant();

    expect(fn () => qdrantStore()->upsert('docs', [new Document(id: 'a', content: 'A', vector: [1, 0, 0])]))->toThrow(VectorDimensionMismatchException::class)
        ->and(fn () => qdrantStore()->upsert('docs', [new Document(id: 'a', content: 'A', metadata: ['n' => 'five'], vector: [1, 0])]))->toThrow(VectorQueryException::class, 'declared integer')
        ->and(qdrantSent())->toBe([]);
});

it('inserts with a check of the ids, then the write', function () {
    fakeQdrant(routes: ['POST /collections/docs/points' => ['result' => []]]);

    qdrantStore()->insert('docs', [new Document(id: 'a', content: 'A', vector: [1, 0])]);

    expect(array_map(fn ($sent) => [$sent[0], $sent[1]], qdrantSent()))->toBe([['POST', 'collections/docs/points'], ['PUT', 'collections/docs/points?wait=true']])
        ->and(qdrantSent()[0][2])->toBe(['ids' => [pid('a')], 'with_payload' => ['id']]);
});

it('refuses an insert of an existing id or a repeated id, and writes nothing', function () {
    fakeQdrant(routes: ['POST /collections/docs/points' => ['result' => [['id' => pid('b'), 'payload' => ['id' => 'b']]]]]);

    expect(fn () => qdrantStore()->insert('docs', [new Document(id: 'a', content: 'A', vector: [1, 0]), new Document(id: 'b', content: 'B', vector: [1, 0])]))
        ->toThrow(VectorDuplicateDocumentException::class, 'Document [b] already exists')
        ->and(fn () => qdrantStore()->insert('docs', [new Document(id: 'a', content: 'A', vector: [1, 0]), new Document(id: 'a', content: 'A', vector: [1, 0])]))
        ->toThrow(VectorDuplicateDocumentException::class, 'Document [a]')
        ->and(collect(qdrantSent())->where(0, 'PUT')->all())->toBe([]);
});

it('deletes by point ids and by filter', function () {
    fakeQdrant();

    qdrantStore()->delete('docs', ['a', 5]);
    qdrantStore()->deleteByFilter('docs', NormalizedFilter::fromAssociative(['tag' => 'x']));

    expect(qdrantSent())->toBe([
        ['POST', 'collections/docs/points/delete?wait=true', ['points' => [pid('a'), pid('5')]]],
        ['POST', 'collections/docs/points/delete?wait=true', ['filter' => ['must' => [['key' => 'metadata.tag', 'match' => ['value' => 'x']]]]]],
    ])->and(fn () => qdrantStore()->deleteByFilter('docs', new NormalizedFilter([])))->toThrow(VectorQueryException::class, 'at least one');
});

it('updates metadata of the known ids in one batch: merge under the metadata key, replace as a new object', function (bool $merge, array $operation) {
    fakeQdrant(routes: ['POST /collections/docs/points' => ['result' => [['id' => pid('a'), 'payload' => ['id' => 'a']]]]]);

    $result = qdrantStore()->updateMetadata('docs', ['a' => ['price' => 2], 'zz' => ['tag' => 'q']], $merge);

    expect($result->updated)->toBe(['a'])
        ->and($result->unknown)->toBe(['zz'])
        ->and(qdrantSent())->toBe([
            ['POST', 'collections/docs/points', ['ids' => [pid('a'), pid('zz')], 'with_payload' => ['id']]],
            ['POST', 'collections/docs/points/batch?wait=true', ['operations' => [['set_payload' => $operation]]]],
        ]);
})->with([
    'merge' => [true, ['payload' => ['price' => 2.0], 'key' => 'metadata', 'points' => [pid('a')]]],
    'replace' => [false, ['payload' => ['metadata' => ['price' => 2.0]], 'points' => [pid('a')]]],
]);

it('fetches in the order asked, with score 1.0, skipping unknown ids', function () {
    // Encoded as Qdrant does, so 1.0 stays a float on the way back.
    fakeQdrant(routes: ['POST /collections/docs/points' => Http::response(json_encode(['result' => [
        ['id' => pid('b'), 'payload' => ['id' => 'b', 'content' => 'B', 'metadata' => ['price' => 1.0]]],
        ['id' => pid('a'), 'payload' => ['id' => 'a', 'content' => 'A', 'metadata' => (object) []]],
    ]], JSON_PRESERVE_ZERO_FRACTION))]);

    $result = qdrantStore()->fetch('docs', ['a', 'nope', 'b', 'a']);

    expect(array_map(fn ($hit) => [$hit->id, $hit->score, $hit->content, $hit->metadata], $result->hits))->toBe([['a', 1.0, 'A', []], ['b', 1.0, 'B', ['price' => 1.0]]])
        ->and(qdrantSent()[0][2])->toBe(['ids' => [pid('a'), pid('nope'), pid('b')], 'with_payload' => true]);
});

it('lists ids with a scroll of limit + 1, the cursor being the next point id', function () {
    $points = fn (array $ids) => ['result' => ['points' => array_map(fn ($id) => ['id' => pid($id), 'payload' => ['id' => $id]], $ids), 'next_page_offset' => null]];
    fakeQdrant(routes: ['POST /collections/docs/points/scroll' => Http::sequence()->push($points(['a', 'b', 'c']))->push($points(['c']))]);

    $first = qdrantStore()->listIds('docs', NormalizedFilter::fromAssociative(['on' => true]), limit: 2);
    $second = qdrantStore()->listIds('docs', cursor: $first->nextCursor, limit: 2);

    expect([$first->ids, $first->nextCursor, $second->ids, $second->nextCursor])->toBe([['a', 'b'], pid('c'), ['c'], null])
        ->and(qdrantSent()[0][2])->toBe(['limit' => 3, 'with_payload' => ['id'], 'with_vector' => false, 'filter' => ['must' => [['key' => 'metadata.on', 'match' => ['value' => true]]]]])
        ->and(qdrantSent()[1][2])->toBe(['limit' => 3, 'with_payload' => ['id'], 'with_vector' => false, 'offset' => pid('c')]);
});

it('searches with the query API, the filter, the options and the payload', function () {
    fakeQdrant(routes: ['POST /collections/docs/points/query' => ['result' => ['points' => []]]]);

    qdrantStore()->search(new SearchQuery('docs', [1, 0], NormalizedFilter::fromAssociative(['tag' => 'x']), topK: 5, options: ['exact' => true, 'hnsw_ef' => 128]));

    expect(qdrantSent())->toBe([['POST', 'collections/docs/points/query', [
        'query' => [1.0, 0.0], 'limit' => 5, 'with_payload' => true,
        'filter' => ['must' => [['key' => 'metadata.tag', 'match' => ['value' => 'x']]]],
        'params' => ['exact' => true, 'hnsw_ef' => 128],
    ]]]);
});

it('converts scores and the minScore threshold for each metric', function (string $metric, float $minScore, float $threshold, array $raw, array $scores) {
    fakeQdrant(qdrantInfo($metric), ['POST /collections/docs/points/query' => ['result' => ['points' => array_map(
        fn ($id, $score) => ['id' => pid($id), 'score' => $score, 'payload' => ['id' => $id, 'content' => '', 'metadata' => []]],
        array_keys($raw), $raw,
    )]]]);

    $hits = qdrantStore()->search(new SearchQuery('docs', [1, 0], minScore: $minScore))->hits;

    expect(qdrantSent()[0][2]['score_threshold'])->toEqualWithDelta($threshold, 1e-9)
        ->and(array_map(fn ($hit) => $hit->score, $hits))->toEqualWithDelta($scores, 1e-9);
})->with([
    'cosine as is' => ['cosine', 0.5, 0.5, ['a' => 1.0, 'b' => 0.6], [1.0, 0.6]],
    'cosine at 1.0' => ['cosine', 1.0, 1.0, ['a' => 1.0], [1.0]],
    'dot as is' => ['inner_product', -2.0, -2.0, ['a' => 3.5, 'b' => -1.0], [3.5, -1.0]],
    'l2 distance to 1 / (1 + d), threshold an upper distance' => ['l2', 0.5, 1.0, ['a' => 0.0, 'b' => 0.8944272], [1.0, 1 / 1.8944272]],
    'l2 at 0 counts as 0.0001' => ['l2', 0.0, 9999.0, ['a' => 3.0], [0.25]],
    'l2 below 0 counts as 0.0001' => ['l2', -1.0, 9999.0, ['a' => 1.0], [0.5]],
]);

it('sorts equal scores by id inside the page', function () {
    fakeQdrant(routes: ['POST /collections/docs/points/query' => ['result' => ['points' => array_map(
        fn ($id) => ['id' => pid($id), 'score' => 0.5, 'payload' => ['id' => $id, 'content' => '', 'metadata' => []]],
        ['c', 'a', 'b'],
    )]]]);

    expect(array_map(fn ($hit) => $hit->id, qdrantStore()->search(new SearchQuery('docs', [1, 0]))->hits))->toBe(['a', 'b', 'c']);
});

it('refuses a hybrid search, an unknown option, and a wrong vector size before the search', function (SearchQuery $query, string $exception) {
    fakeQdrant();

    expect(fn () => qdrantStore()->search($query))->toThrow($exception)
        ->and(qdrantSent())->toBe([]);
})->with([
    'keywords' => [new SearchQuery('docs', [1, 0], keywords: 'x'), VectorUnsupportedOperationException::class],
    'option' => [new SearchQuery('docs', [1, 0], options: ['ef_search' => 10]), VectorQueryException::class],
    'option type' => [new SearchQuery('docs', [1, 0], options: ['exact' => 'yes']), VectorQueryException::class],
    'dimensions' => [new SearchQuery('docs', [1, 0, 0]), VectorDimensionMismatchException::class],
]);

it('refuses a filter on an undeclared field before the request', function (Closure $call) {
    fakeQdrant();

    expect(fn () => $call(qdrantStore()))->toThrow(VectorUnsupportedOperationException::class, 'not declared filterable')
        ->and(qdrantSent())->toBe([]);
})->with([
    'search' => [fn (QdrantStore $store) => $store->search(new SearchQuery('docs', [1, 0], NormalizedFilter::fromAssociative(['lang' => 'en'])))],
    'deleteByFilter' => [fn (QdrantStore $store) => $store->deleteByFilter('docs', NormalizedFilter::fromAssociative(['lang' => 'en']))],
    'listIds' => [fn (QdrantStore $store) => $store->listIds('docs', NormalizedFilter::fromAssociative(['lang' => 'en']))],
]);

it('maps each declared type and operator as in B7', function (string $field, string $operator, mixed $value, array $filter) {
    fakeQdrant();

    qdrantStore()->deleteByFilter('docs', NormalizedFilter::from([['field' => $field, 'operator' => $operator, 'value' => $value]]));

    expect(qdrantSent()[0][2]['filter'])->toBe($filter);
})->with([
    'keyword eq' => ['tag', 'eq', 'x', ['must' => [['key' => 'metadata.tag', 'match' => ['value' => 'x']]]]],
    'keyword in' => ['tag', 'in', ['x', 'y'], ['must' => [['key' => 'metadata.tag', 'match' => ['any' => ['x', 'y']]]]]],
    'keyword ne' => ['tag', 'ne', 'x', ['must_not' => [['key' => 'metadata.tag', 'match' => ['value' => 'x']]]]],
    'keyword not_in' => ['tag', 'not_in', ['x', 'y'], ['must_not' => [['key' => 'metadata.tag', 'match' => ['any' => ['x', 'y']]]]]],
    'integer eq' => ['n', 'eq', 5, ['must' => [['key' => 'metadata.n', 'match' => ['value' => 5]]]]],
    'integer in' => ['n', 'in', [1, 2], ['must' => [['key' => 'metadata.n', 'match' => ['any' => [1, 2]]]]]],
    'integer ne' => ['n', 'ne', 5, ['must_not' => [['key' => 'metadata.n', 'match' => ['value' => 5]]]]],
    'integer gt' => ['n', 'gt', 5, ['must' => [['key' => 'metadata.n', 'range' => ['gt' => 5]]]]],
    'integer lte' => ['n', 'lte', 5, ['must' => [['key' => 'metadata.n', 'range' => ['lte' => 5]]]]],
    'float eq' => ['price', 'eq', 2.5, ['must' => [['key' => 'metadata.price', 'range' => ['gte' => 2.5, 'lte' => 2.5]]]]],
    'float eq integer value' => ['price', 'eq', 2, ['must' => [['key' => 'metadata.price', 'range' => ['gte' => 2, 'lte' => 2]]]]],
    'float in' => ['price', 'in', [1.5, 2.5], ['must' => [['should' => [
        ['key' => 'metadata.price', 'range' => ['gte' => 1.5, 'lte' => 1.5]],
        ['key' => 'metadata.price', 'range' => ['gte' => 2.5, 'lte' => 2.5]],
    ]]]]],
    'float not_in' => ['price', 'not_in', [1.5], ['must_not' => [['should' => [['key' => 'metadata.price', 'range' => ['gte' => 1.5, 'lte' => 1.5]]]]]]],
    'float ne' => ['price', 'ne', 1.5, ['must_not' => [['key' => 'metadata.price', 'range' => ['gte' => 1.5, 'lte' => 1.5]]]]],
    'float gte' => ['price', 'gte', 1.5, ['must' => [['key' => 'metadata.price', 'range' => ['gte' => 1.5]]]]],
    'bool eq' => ['on', 'eq', false, ['must' => [['key' => 'metadata.on', 'match' => ['value' => false]]]]],
    'bool ne' => ['on', 'ne', true, ['must_not' => [['key' => 'metadata.on', 'match' => ['value' => true]]]]],
    'datetime eq' => ['at', 'eq', '2026-01-15T10:00:00Z', ['must' => [['key' => 'metadata.at', 'range' => ['gte' => '2026-01-15T10:00:00Z', 'lte' => '2026-01-15T10:00:00Z']]]]],
    'datetime ne' => ['at', 'ne', '2026-01-15T10:00:00Z', ['must_not' => [['key' => 'metadata.at', 'range' => ['gte' => '2026-01-15T10:00:00Z', 'lte' => '2026-01-15T10:00:00Z']]]]],
    'datetime lt' => ['at', 'lt', '2026-01-01T00:00:00Z', ['must' => [['key' => 'metadata.at', 'range' => ['lt' => '2026-01-01T00:00:00Z']]]]],
    'keyword_list contains' => ['tags', 'contains', 'x', ['must' => [['key' => 'metadata.tags', 'match' => ['value' => 'x']]]]],
]);

it('keeps the float form of a float filter value in the request body', function () {
    fakeQdrant();

    qdrantStore()->deleteByFilter('docs', NormalizedFilter::fromAssociative(['price' => 3.0]));

    expect(Http::recorded()->last()[0]->body())->toContain('"gte":3.0,"lte":3.0');
});

it('combines conditions: positives under must, negations under must_not', function () {
    fakeQdrant();

    qdrantStore()->deleteByFilter('docs', NormalizedFilter::from([
        ['field' => 'tag', 'operator' => 'eq', 'value' => 'x'],
        ['field' => 'n', 'operator' => 'ne', 'value' => 1],
        ['field' => 'n', 'operator' => 'gte', 'value' => 0],
    ]));

    expect(qdrantSent()[0][2]['filter'])->toBe([
        'must' => [['key' => 'metadata.tag', 'match' => ['value' => 'x']], ['key' => 'metadata.n', 'range' => ['gte' => 0]]],
        'must_not' => [['key' => 'metadata.n', 'match' => ['value' => 1]]],
    ]);
});

it('reads the registry once per collection until a create, delete or alias change', function () {
    fakeQdrant(routes: ['POST /collections/docs/points/query' => ['result' => ['points' => []]], 'GET /collections' => ['result' => ['collections' => []]]]);
    $store = qdrantStore();

    $store->search(new SearchQuery('docs', [1, 0]));
    $store->search(new SearchQuery('docs', [1, 0]));
    $store->pointAlias('live', 'docs');
    $store->search(new SearchQuery('docs', [1, 0]));

    expect(Http::recorded()->filter(fn (array $pair) => $pair[0]->method() === 'GET' && str_ends_with($pair[0]->url(), '/collections/docs'))->count())->toBe(2);
});

it('points an alias with one create_alias action, and refuses a physical collection name', function () {
    fakeQdrant(routes: ['GET /collections' => ['result' => ['collections' => [['name' => 'docs'], ['name' => 'old']]]]]);

    qdrantStore()->pointAlias('live', 'docs');

    expect(qdrantSent())->toBe([
        ['GET', 'collections', null],
        ['POST', 'collections/aliases', ['actions' => [['create_alias' => ['collection_name' => 'docs', 'alias_name' => 'live']]]]],
    ])->and(fn () => qdrantStore()->pointAlias('old', 'docs'))->toThrow(VectorQueryException::class, 'is a collection, not an alias');
});

it('lists aliases and physical collections, and deletes a known alias only', function () {
    fakeQdrant(routes: [
        'GET /aliases' => ['result' => ['aliases' => [['alias_name' => 'live', 'collection_name' => 'docs'], ['alias_name' => 'a', 'collection_name' => 'old']]]],
        'GET /collections' => ['result' => ['collections' => [['name' => 'old'], ['name' => 'docs']]]],
    ]);

    expect(qdrantStore()->listAliases())->toBe(['a' => 'old', 'live' => 'docs'])
        ->and(qdrantStore()->listCollections())->toBe(['docs', 'old'])
        ->and(fn () => qdrantStore()->deleteAlias('nope'))->toThrow(VectorCollectionNotFoundException::class);

    qdrantStore()->deleteAlias('live');

    expect(collect(qdrantSent())->last())->toBe(['POST', 'collections/aliases', ['actions' => [['delete_alias' => ['alias_name' => 'live']]]]]);
});

it('deletes a collection, refusing an alias, an alias target and an unknown name', function () {
    fakeQdrant(routes: [
        'GET /aliases' => ['result' => ['aliases' => [['alias_name' => 'live', 'collection_name' => 'docs']]]],
        'DELETE /collections/nope' => ['result' => false],
    ]);

    qdrantStore()->deleteCollection('old');

    expect(collect(qdrantSent())->last())->toBe(['DELETE', 'collections/old', null])
        ->and(fn () => qdrantStore()->deleteCollection('live'))->toThrow(VectorQueryException::class, 'is an alias')
        ->and(fn () => qdrantStore()->deleteCollection('docs'))->toThrow(VectorQueryException::class, 'target of alias [live]')
        ->and(fn () => qdrantStore()->deleteCollection('nope'))->toThrow(VectorCollectionNotFoundException::class);
});

it('counts exactly and checks existence', function () {
    fakeQdrant(routes: [
        'POST /collections/docs/points/count' => ['result' => ['count' => 3]],
        'GET /collections/docs/exists' => ['result' => ['exists' => true]],
    ]);

    expect(qdrantStore()->countDocuments('docs'))->toBe(3)
        ->and(qdrantStore()->collectionExists('docs'))->toBeTrue()
        ->and(qdrantSent()[0][2])->toBe(['exact' => true]);
});

it('maps errors: no connection, missing collection, wrong size, anything else', function (Closure $response, string $exception, string $message) {
    Http::fake(['*' => $response]);

    expect(fn () => qdrantStore()->countDocuments('docs'))->toThrow($exception, $message);
})->with([
    'connection' => [fn () => throw new ConnectionException('refused'), VectorConnectionException::class, 'could not reach Qdrant'],
    '404' => [fn () => Http::response(['status' => ['error' => 'Not found: Collection `docs` does not exist!']], 404), VectorCollectionNotFoundException::class, 'does not exist'],
    'dimension' => [fn () => Http::response(['status' => ['error' => 'Wrong input: Vector dimension error: expected dim: 2, got 3']], 400), VectorDimensionMismatchException::class, 'expected dim: 2'],
    'other' => [fn () => Http::response(['status' => ['error' => 'Bad request: nope']], 400), VectorQueryException::class, 'Bad request: nope'],
]);

it('sends the api key header and needs a url', function () {
    fakeQdrant(routes: ['POST /collections/docs/points/count' => ['result' => ['count' => 0]]]);

    qdrantStore(['api_key' => 'secret'])->countDocuments('docs');

    Http::assertSent(fn (Request $request) => $request->header('api-key') === ['secret']);
    expect(fn () => qdrantStore(['url' => null])->countDocuments('docs'))->toThrow(VectorConnectionException::class, 'VECTOR_QDRANT_URL');
});
