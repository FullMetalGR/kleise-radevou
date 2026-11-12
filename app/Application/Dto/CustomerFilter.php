<?php

declare(strict_types=1);

namespace App\Application\Dto;

/**
 * The Customers list's query as the server applies it: a search matching the customer columns
 * named in searchIn, the name alone until the caller names the columns this viewer may see, as
 * the Bookings list's does; and a page counted from one at a fixed PAGE_SIZE, twenty-five as the
 * Bookings list's is. Nothing else narrows it: the list is every address the bookings know.
 */
final readonly class CustomerFilter
{
    public const int PAGE_SIZE = 25;

    public function __construct(
        public ?string $search = null,
        public int $page = 1,
        /** @var list<string> */
        public array $searchIn = ['customer_name'],
    ) {}

    /**
     * The same query searching the given customer columns, the ones the viewer is shown.
     *
     * @param  list<string>  $columns
     */
    public function searchingIn(array $columns): self
    {
        return new self($this->search, $this->page, $columns);
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PAGE_SIZE;
    }

    /**
     * The query as the page's URL spells it, read back for the screen to seed its search from.
     *
     * @return array{search: string, page: int}
     */
    public function toQuery(): array
    {
        return ['search' => $this->search ?? '', 'page' => $this->page];
    }
}
