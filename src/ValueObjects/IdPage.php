<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

/**
 * One page of document ids. Pass $nextCursor back to listIds() for the next page; null means last page.
 * The cursor is opaque: do not build or parse it.
 */
final readonly class IdPage
{
    /**
     * @param  list<string>  $ids
     */
    public function __construct(
        public array $ids,
        public ?string $nextCursor = null,
    ) {}

    /**
     * Keep the first $limit ids of a limit + 1 fetch; the extra id only signals a next page.
     *
     * @param  list<string>  $ids
     */
    public static function fromOverfetch(array $ids, int $limit): self
    {
        if (count($ids) <= $limit) {
            return new self($ids);
        }

        $page = array_slice($ids, 0, $limit);

        return new self($page, $page[$limit - 1]);
    }
}
