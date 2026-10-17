<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Ingest;

use AgenticGraph\Vector\ValueObjects\Document;
use InvalidArgumentException;

/**
 * Splits text into chunks of at most $size characters (UTF-8 characters, no tokenizer), each starting $overlap
 * characters before the previous one ended. A chunk ends at the last paragraph break (a blank line) in the
 * second half of its window, else at the last sentence end (., !, ? or a line break), else it is cut at $size.
 * Chunks are trimmed; blank text gives no chunks.
 */
final readonly class TextSplitter
{
    private const PARAGRAPH = '/\n[ \t]*\n\s*/u';

    private const SENTENCE = '/[.!?]["\')\]]*\s+|\n\s*/u';

    public function __construct(
        public int $size,
        public int $overlap = 0,
    ) {
        if ($size < 1 || $overlap < 0 || $overlap >= $size) {
            throw new InvalidArgumentException("TextSplitter needs a size of at least 1 and an overlap from 0 to size - 1. Got size [{$size}], overlap [{$overlap}].");
        }
    }

    /**
     * @return list<string>
     */
    public function split(string $text): array
    {
        $length = mb_strlen($text);
        $chunks = [];
        $start = 0;

        while ($start < $length) {
            if ($length - $start <= $this->size) {
                $chunks[] = trim(mb_substr($text, $start));

                break;
            }

            $window = mb_substr($text, $start, $this->size);
            $end = $start + $this->boundary($window);
            $chunks[] = trim(mb_substr($text, $start, $end - $start));
            $start = $this->overlap === 0 ? $end : $this->overlapStart($text, $end);
        }

        return array_values(array_filter($chunks, static fn (string $chunk): bool => $chunk !== ''));
    }

    /**
     * The parent's chunks as documents: ids `{parentId}#{n}` from 0, metadata the parent's plus `parent_id` (the
     * parent id as a string) and `chunk` (n). Store them with Builder::upsertChunks() to replace a parent's chunks.
     *
     * @return list<Document>
     */
    public function chunk(Document $parent): array
    {
        if ($parent->vector !== null) {
            throw new InvalidArgumentException("Document [{$parent->id}] has a precomputed vector: chunks are embedded one by one, so split documents without vectors.");
        }

        $parentId = (string) $parent->id;
        $documents = [];

        foreach ($this->split($parent->content) as $n => $content) {
            $documents[] = new Document("{$parentId}#{$n}", $content, [...$parent->metadata, 'parent_id' => $parentId, 'chunk' => $n]);
        }

        return $documents;
    }

    /**
     * Where a full window ends, in characters from its start: never in its first half (nor before the overlap),
     * so every chunk is at least half the size and the next one starts further on.
     */
    private function boundary(string $window): int
    {
        $earliest = max(intdiv($this->size, 2), $this->overlap + 1);

        foreach ([self::PARAGRAPH, self::SENTENCE] as $pattern) {
            if (preg_match_all($pattern, $window, $matches, PREG_OFFSET_CAPTURE) > 0) {
                [$match, $byteOffset] = $matches[0][array_key_last($matches[0])];
                $end = mb_strlen(substr($window, 0, $byteOffset + strlen($match)));

                if ($end >= $earliest) {
                    return $end;
                }
            }
        }

        return $this->size;
    }

    /**
     * $overlap characters before $end, moved forward to the next word start when one is inside the overlap.
     */
    private function overlapStart(string $text, int $end): int
    {
        $start = $end - $this->overlap;
        $overlap = mb_substr($text, $start, $this->overlap);

        if (preg_match('/\s+/u', $overlap, $match, PREG_OFFSET_CAPTURE) === 1) {
            $wordStart = mb_strlen(substr($overlap, 0, $match[0][1] + strlen($match[0][0])));

            if ($wordStart < $this->overlap) {
                return $start + $wordStart;
            }
        }

        return $start;
    }
}
