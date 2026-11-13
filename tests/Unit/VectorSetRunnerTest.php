<?php

use AgenticGraph\Vector\Tests\Support\BenchReport;
use AgenticGraph\Vector\Tests\Support\VectorSetRunner;

it('accepts an exact top 10 that differs from the ground truth only by ties', function () {
    $truth = array_map(strval(...), range(0, 9));
    $distance = static fn (string $id): float => ['10' => 9.0, '11' => 9.5][$id] ?? (float) $id;

    expect(VectorSetRunner::agrees($truth, $truth, $distance))->toBeTrue()
        // 10 is as near as the truth's 10th (9): a tie, so either is right.
        ->and(VectorSetRunner::agrees([...array_slice($truth, 0, 9), '10'], $truth, $distance))->toBeTrue()
        // 11 is farther than the 10th: a wrong neighbor.
        ->and(VectorSetRunner::agrees([...array_slice($truth, 0, 9), '11'], $truth, $distance))->toBeFalse()
        // Fewer than 10 hits never agree.
        ->and(VectorSetRunner::agrees(array_slice($truth, 0, 9), $truth, $distance))->toBeFalse();
});

it('measures squared L2 and cosine distance', function () {
    expect(VectorSetRunner::distance('l2', [1.0, 2.0], [4.0, 6.0]))->toBe(25.0)
        ->and(VectorSetRunner::distance('cosine', [1.0, 0.0], [2.0, 0.0]))->toEqualWithDelta(0.0, 1e-12)
        ->and(VectorSetRunner::distance('cosine', [1.0, 0.0], [0.0, 3.0]))->toEqualWithDelta(1.0, 1e-12);
});

it('reads exported vector files in chunks and refuses another width', function () {
    $base = sys_get_temp_dir().'/vector-set-'.uniqid();
    file_put_contents("{$base}.f32", pack('g*', 1.0, 2.0, 3.0, 4.0, 5.0, 6.0));
    file_put_contents("{$base}.index.jsonl", '{"model":"t","dimensions":2}'."\n".'{"id":"0","sha256":"a"}'."\n".'{"id":"1","sha256":"b"}'."\n".'{"id":"2","sha256":"c"}'."\n");

    expect(iterator_to_array(VectorSetRunner::rows($base, 2, 2), false))->toBe([
        [['id' => '0', 'vector' => [1.0, 2.0]], ['id' => '1', 'vector' => [3.0, 4.0]]],
        [['id' => '2', 'vector' => [5.0, 6.0]]],
    ])->and(fn () => iterator_to_array(VectorSetRunner::rows($base, 3)))->toThrow(RuntimeException::class, 'the registry says 3');

    unlink("{$base}.f32");
    unlink("{$base}.index.jsonl");
});

it('prints the machine of a run', function () {
    expect(BenchReport::machineLine(['cpu' => 'Apple M3 Max', 'cores' => 16, 'memory_bytes' => 64 * 1024 ** 3, 'store_in_docker' => true, 'docker' => 'OrbStack, 16 CPUs']))
        ->toBe('Apple M3 Max, 16 cores, 64 GiB memory; store in Docker (OrbStack, 16 CPUs)')
        ->and(BenchReport::machineLine(['cpu' => null, 'cores' => null, 'memory_bytes' => null, 'store_in_docker' => false, 'docker' => null]))
        ->toBe('not available, not available cores, not available memory; store not in Docker');
});
