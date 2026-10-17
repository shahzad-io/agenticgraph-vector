<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Testing;

use AgenticGraph\Vector\Contracts\RerankerContract;
use AgenticGraph\Vector\ValueObjects\SearchHit;
use Illuminate\Support\Facades\Http;

/**
 * The behavior every reranker must share. Use it in a Laravel or Testbench test case and return the reranker
 * under test from makeReranker(), for example `return Vector::reranker('cohere');`. A reranker that calls an
 * API makes one request per test except the empty-input one.
 */
trait RerankerConformanceTests
{
    abstract protected function makeReranker(): RerankerContract;

    public function test_reranker_conformance_returns_at_most_top_n(): void
    {
        $this->assertCount(3, $this->makeReranker()->rerank(self::conformanceQuery(), self::conformanceHits(), 3)->hits);
    }

    public function test_reranker_conformance_returns_a_subset_of_the_input_without_duplicates(): void
    {
        $ids = array_map(static fn (SearchHit $hit): string => (string) $hit->id, $this->makeReranker()->rerank(self::conformanceQuery(), self::conformanceHits(), 5)->hits);
        $inputIds = array_map(static fn (SearchHit $hit): string => (string) $hit->id, self::conformanceHits());

        $this->assertSame(array_values(array_unique($ids)), $ids, 'A hit came back twice.');
        $this->assertSame([], array_values(array_diff($ids, $inputIds)), 'A hit that was not in the input came back.');
    }

    public function test_reranker_conformance_rerank_scores_never_increase(): void
    {
        $scores = array_map(static fn (SearchHit $hit): ?float => $hit->scores['rerank'] ?? null, $this->makeReranker()->rerank(self::conformanceQuery(), self::conformanceHits(), 7)->hits);
        $seenNull = false;

        foreach ($scores as $i => $score) {
            $this->assertFalse($seenNull && $score !== null, 'A scored hit follows an unscored one.');
            $seenNull = $seenNull || $score === null;

            if ($i > 0 && $score !== null) {
                $this->assertLessThanOrEqual($scores[$i - 1], $score, 'Rerank scores increased at position '.$i.'.');
            }
        }

        $this->assertNotNull($scores[0] ?? null, 'The best hit has no rerank score.');
    }

    public function test_reranker_conformance_hits_are_unchanged_except_the_rerank_score(): void
    {
        $byId = [];

        foreach (self::conformanceHits() as $hit) {
            $byId[(string) $hit->id] = $hit;
        }

        foreach ($this->makeReranker()->rerank(self::conformanceQuery(), self::conformanceHits(), 7)->hits as $hit) {
            $input = $byId[(string) $hit->id];
            $this->assertSame([$input->id, $input->score, $input->content, $input->metadata, $input->vector], [$hit->id, $hit->score, $hit->content, $hit->metadata, $hit->vector]);
            $this->assertArrayHasKey('rerank', $hit->scores ?? []);
            $scores = $hit->scores ?? [];
            unset($scores['rerank']);
            $this->assertSame($input->scores ?? ['vector' => $input->score], $scores);
        }
    }

    public function test_reranker_conformance_empty_input_makes_no_request(): void
    {
        Http::fake();

        $this->assertSame([], $this->makeReranker()->rerank(self::conformanceQuery(), [], 5)->hits);
        Http::assertNothingSent();
    }

    public function test_reranker_conformance_top_n_above_the_count_returns_every_hit(): void
    {
        $this->assertCount(7, $this->makeReranker()->rerank(self::conformanceQuery(), self::conformanceHits(), 50)->hits);
    }

    public function test_reranker_conformance_ties_keep_the_input_order(): void
    {
        $position = array_flip(array_map(static fn (SearchHit $hit): string => (string) $hit->id, self::conformanceHits()));
        $hits = $this->makeReranker()->rerank(self::conformanceQuery(), self::conformanceHits(), 7)->hits;

        for ($i = 1; $i < count($hits); $i++) {
            if (($hits[$i]->scores['rerank'] ?? null) === ($hits[$i - 1]->scores['rerank'] ?? null)) {
                $this->assertLessThan($position[(string) $hits[$i]->id], $position[(string) $hits[$i - 1]->id], 'Hits with equal rerank scores left the input order.');
            }
        }
    }

    protected static function conformanceQuery(): string
    {
        return 'How do I reset my account password?';
    }

    /**
     * Seven hits in vector order: one answers the query, two are the same text (a tie), one carries hybrid scores.
     *
     * @return list<SearchHit>
     */
    protected static function conformanceHits(): array
    {
        return [
            new SearchHit('ship', 0.71, 'Orders ship within two business days. Tracking numbers arrive by email.', ['topic' => 'shipping']),
            new SearchHit('refund', 0.69, 'Refunds go back to the original payment method within ten days.', ['topic' => 'billing']),
            new SearchHit('reset', 0.66, 'To reset your account password, open Settings, choose Security, then Reset password and follow the email link.', ['topic' => 'account']),
            new SearchHit('hours-a', 0.61, 'Our support team answers chat messages from 9am to 5pm on weekdays.', ['topic' => 'support']),
            new SearchHit('hours-b', 0.60, 'Our support team answers chat messages from 9am to 5pm on weekdays.', ['topic' => 'support']),
            new SearchHit('login', 0.58, 'If you cannot sign in, check that caps lock is off and that your email address is spelled correctly.', ['topic' => 'account'], scores: ['vector' => 0.58, 'keyword' => 0.2, 'fused' => 0.03]),
            new SearchHit('gift', 0.55, 'Gift cards never expire and can be used on any order.', ['topic' => 'billing']),
        ];
    }
}
