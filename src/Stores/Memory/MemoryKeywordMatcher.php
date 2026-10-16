<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Stores\Memory;

/**
 * Keyword matching close to pgvector's `simple` configuration: lowercase, split on anything but letters and
 * digits, no stemming, no stop words. Match `any` (like plainto_tsquery with OR): a document matches with any
 * query word, and the score is how many distinct query words it contains; operators are plain words.
 * Match `websearch` (like websearch_to_tsquery): words (all must match), "quoted phrases" (adjacent words), a
 * leading - to exclude, `or` between alternatives; the score counts the matched words and phrases. Neither
 * score is ts_rank_cd.
 */
final class MemoryKeywordMatcher
{
    /** @var list<list<array{tokens: list<string>, negated: bool}>> alternatives of terms */
    private array $clauses = [];

    /** @var list<string>|null distinct query words in `any` mode */
    private ?array $any = null;

    public function __construct(string $query, string $match = 'websearch')
    {
        if ($match === 'any') {
            $this->any = array_values(array_unique(self::tokens($query)));

            return;
        }

        preg_match_all('/(-?)"([^"]*)"?|(-?)([^\s"]+)/u', $query, $parts, PREG_SET_ORDER);
        $clause = [];

        foreach ($parts as $part) {
            $quoted = str_starts_with(ltrim($part[0], '-'), '"');
            $text = $quoted ? $part[2] : ($part[4] ?? '');

            if (! $quoted && strtolower($text) === 'or') {
                $this->clauses[] = $clause;
                $clause = [];

                continue;
            }

            $tokens = self::tokens($text);

            if ($tokens !== []) {
                $clause[] = ['tokens' => $tokens, 'negated' => ($quoted ? $part[1] : ($part[3] ?? '')) === '-'];
            }
        }

        $this->clauses = array_values(array_filter([...$this->clauses, $clause], static fn (array $terms): bool => $terms !== []));
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The score of a matching document, or null when it does not match.
     */
    public function score(string $content): ?float
    {
        $tokens = self::tokens($content);

        if ($this->any !== null) {
            $found = count(array_intersect($this->any, $tokens));

            return $found > 0 ? (float) $found : null;
        }

        $matched = false;
        $score = 0;

        foreach ($this->clauses as $terms) {
            $clauseScore = 0;

            foreach ($terms as $term) {
                $count = self::occurrences($tokens, $term['tokens']);

                if ($term['negated'] ? $count > 0 : $count === 0) {
                    continue 2;
                }

                $clauseScore += $term['negated'] ? 0 : $count;
            }

            $matched = true;
            $score += $clauseScore;
        }

        return $matched ? (float) $score : null;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $phrase
     */
    private static function occurrences(array $tokens, array $phrase): int
    {
        $count = 0;
        $length = count($phrase);

        for ($i = 0, $last = count($tokens) - $length; $i <= $last; $i++) {
            if (array_slice($tokens, $i, $length) === $phrase) {
                $count++;
            }
        }

        return $count;
    }
}
