<?php

declare(strict_types=1);

// @oagen-ignore-file
//
// Hand-maintained pagination envelope for cursor-paginated list endpoints.
// The generator returns `PaginatedResponse` from every `list*()` method; the
// envelope owns the cursor state and re-fetches the next page lazily when
// the caller iterates past the current chunk. Keep the surface minimal —
// `Iterator` + `data`/`listMetadata` accessors is enough for v1.
//
// @template T

namespace Retab;

/**
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class PaginatedResponse implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T>               $data         Items returned on this page.
     * @param array<string, mixed>  $listMetadata Wire envelope describing the page boundary.
     * @param (callable(string): self<T>)|null $fetchNext When set, called with the next cursor to fetch the next page.
     */
    public function __construct(
        public readonly array $data,
        public readonly array $listMetadata,
        private $fetchNext = null,
    ) {
    }

    public function count(): int
    {
        return count($this->data);
    }

    /**
     * Iterate the current page only. Pagination across pages is the caller's
     * responsibility — call `nextPage()` to walk forward explicitly. Keeping
     * iteration to a single page matches the wire shape and avoids surprising
     * implicit HTTP calls inside `foreach` loops.
     *
     * @return \Generator<int, T>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->data as $i => $item) {
            yield $i => $item;
        }
    }

    /**
     * Fetch the next page if a cursor is available, otherwise return null.
     *
     * @return self<T>|null
     */
    public function nextPage(): ?self
    {
        $after = $this->listMetadata['after'] ?? null;
        if ($after === null || $this->fetchNext === null) {
            return null;
        }
        return ($this->fetchNext)($after);
    }
}
