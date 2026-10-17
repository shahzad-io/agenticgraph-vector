<?php

use AgenticGraph\Vector\Events\VectorOperationCompleted;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Ingest\TextSplitter;
use AgenticGraph\Vector\ValueObjects\CollectionDefinition;
use AgenticGraph\Vector\ValueObjects\Document;
use Illuminate\Support\Facades\Event;

it('keeps a text that fits in one chunk, trimmed, and gives no chunks for blank text', function (): void {
    $splitter = new TextSplitter(size: 50, overlap: 10);

    expect($splitter->split("  Short text.\n"))->toBe(['Short text.'])
        ->and($splitter->split(" \n\t "))->toBe([])
        ->and($splitter->split(''))->toBe([]);
});

it('ends a chunk at the last paragraph break in the second half of its window', function (): void {
    $text = "First sentence. Second sentence.\n\nThird sentence. Fourth one here.";

    expect((new TextSplitter(size: 40))->split($text))->toBe([
        'First sentence. Second sentence.',
        'Third sentence. Fourth one here.',
    ]);
});

it('falls back to the last sentence end, then to a hard cut at size', function (): void {
    expect((new TextSplitter(size: 30))->split('One two three four. Five six seven eight nine ten.'))
        ->toBe(['One two three four.', 'Five six seven eight nine ten.'])
        ->and((new TextSplitter(size: 10))->split('abcdefghijklmnopqrstuvwxy'))
        ->toBe(['abcdefghij', 'klmnopqrst', 'uvwxy']);
});

it('ignores a boundary in the first half of the window', function (): void {
    // The only sentence end is at 4 of 20: a 4-character chunk would follow, so the window is cut at size.
    expect((new TextSplitter(size: 20))->split('Hi. abcdefghijklmnopqrstuvwxyz'))
        ->toBe(['Hi. abcdefghijklmnop', 'qrstuvwxyz']);
});

it('repeats up to overlap characters, starting at a word when the overlap holds one', function (): void {
    $chunks = (new TextSplitter(size: 30, overlap: 12))->split('alpha bravo charlie delta echo foxtrot golf hotel india juliet');

    expect($chunks)->toBe([
        'alpha bravo charlie delta echo',
        'delta echo foxtrot golf hotel',
        'golf hotel india juliet',
    ]);

    $hardCut = (new TextSplitter(size: 10, overlap: 3))->split('abcdefghijklmnopqrstuvwxy');
    expect($hardCut)->toBe(['abcdefghij', 'hijklmnopq', 'opqrstuvwx', 'vwxy']);
});

it('counts characters, not bytes', function (): void {
    $chunks = (new TextSplitter(size: 4))->split('ñandú über');

    expect($chunks)->toBe(['ñand', 'ú üb', 'er']);
});

it('never makes a chunk longer than size and always moves forward', function (): void {
    $text = str_repeat("Lorem ipsum dolor sit amet. Consectetur adipiscing elit!\n\nSed do eiusmod tempor? ", 40);
    $splitter = new TextSplitter(size: 200, overlap: 150);
    $chunks = $splitter->split($text);

    expect(count($chunks))->toBeGreaterThan(20);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(200)->toBeGreaterThan(0);
    }

    expect(str_ends_with(trim($text), end($chunks)))->toBeTrue();
});

it('rejects a size below one and an overlap outside 0 to size - 1', function (int $size, int $overlap): void {
    new TextSplitter($size, $overlap);
})->throws(InvalidArgumentException::class, 'TextSplitter needs a size of at least 1')->with([
    [0, 0],
    [10, 10],
    [10, -1],
]);

it('makes chunk documents with parent ids, chunk numbers and the parent metadata', function (): void {
    $chunks = (new TextSplitter(size: 10))->chunk(Document::make(42, 'abcdefghijklmno', ['title' => 'T', 'chunk' => 'mine', 'parent_id' => 'x']));

    expect(array_map(fn (Document $chunk): array => [$chunk->id, $chunk->content, $chunk->metadata, $chunk->vector], $chunks))->toBe([
        ['42#0', 'abcdefghij', ['title' => 'T', 'chunk' => 0, 'parent_id' => '42'], null],
        ['42#1', 'klmno', ['title' => 'T', 'chunk' => 1, 'parent_id' => '42'], null],
    ]);
});

it('refuses to chunk a document with a precomputed vector', function (): void {
    (new TextSplitter(size: 10))->chunk(Document::make('a', 'text', vector: [1.0]));
})->throws(InvalidArgumentException::class, 'precomputed vector');

it('upserts the new chunks before deleting the stale ones', function (): void {
    config()->set('vector.embeddings.default', 'deterministic');
    $store = Vector::store('memory');
    $store->createCollection(new CollectionDefinition('chunked'));
    $builder = $store->collection('chunked');
    $splitter = new TextSplitter(size: 10);
    $builder->upsertChunks([Document::make('doc', str_repeat('x', 50))], $splitter);

    $seen = [];
    Event::listen(VectorOperationCompleted::class, function (VectorOperationCompleted $event) use ($builder, &$seen): void {
        if ($event->operation !== 'list_ids') {
            $seen[] = [$event->operation, count($builder->where('parent_id', 'doc')->listIds()->ids)];
        }
    });

    $builder->upsertChunks([Document::make('doc', str_repeat('y', 20))], $splitter);

    // The parent's chunk count when each operation completes: never below the new count.
    expect($seen)->toBe([
        ['upsert', 5],
        ['delete_by_filter', 2],
    ]);
});
