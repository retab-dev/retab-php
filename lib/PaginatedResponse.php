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
     * `true` when the server advertised a non-null `after` cursor on the
     * current page, i.e. there's a follow-up page available.
     */
    public function hasMore(): bool
    {
        $after = $this->listMetadata['after'] ?? null;
        return is_string($after) && $after !== '';
    }

    /**
     * Auto-paginating iterator. Yields every item on the current page, then
     * — if the page advertises a non-null `after` cursor and a fetch
     * closure is wired in — fetches the next page and recursively walks it
     * via `yield from`. Matches the cross-language pagination contract in
     * `.notes/blueprints/sdk-pagination-contract.md`: a caller writing
     * `foreach ($client->workflows()->list() as $workflow) { ... }`
     * transparently walks the entire collection.
     *
     * When `fetchNext` is null — e.g. this page was reconstructed from a
     * stored fixture rather than a live `.list()` call — iteration stops
     * silently after the current page. That's the same forgiving behavior
     * the Python, Node, and Go SDKs implement.
     *
     * @return \Generator<int, T>
     */
    public function getIterator(): \Generator
    {
        yield from $this->data;

        if ($this->fetchNext === null || !$this->hasMore()) {
            return;
        }

        $after = $this->listMetadata['after'];
        // `hasMore()` already asserts the cursor is a non-empty string;
        // the explicit type check keeps PHPStan happy without weakening it.
        if (!is_string($after) || $after === '') {
            return;
        }

        $next = ($this->fetchNext)($after);
        yield from $next;
    }

    /**
     * Fetch the next page if a cursor is available, otherwise return null.
     * Retained as an escape hatch for callers that want to walk pages
     * explicitly without the auto-paginating iterator.
     *
     * @return self<T>|null
     */
    public function nextPage(): ?self
    {
        if (!$this->hasMore() || $this->fetchNext === null) {
            return null;
        }
        $after = $this->listMetadata['after'];
        if (!is_string($after) || $after === '') {
            return null;
        }
        return ($this->fetchNext)($after);
    }
}
