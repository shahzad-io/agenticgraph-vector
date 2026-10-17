<?php

namespace AgenticGraph\Vector\Tests\Conformance;

use AgenticGraph\Vector\Contracts\RerankerContract;
use AgenticGraph\Vector\Facades\Vector;
use AgenticGraph\Vector\Testing\RerankerConformanceTests;
use AgenticGraph\Vector\Tests\TestCase;

final class DeterministicRerankerConformanceTest extends TestCase
{
    use RerankerConformanceTests;

    protected function makeReranker(): RerankerContract
    {
        return Vector::reranker('deterministic');
    }
}
