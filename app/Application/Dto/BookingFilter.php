<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Enum\BookingStatus;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The bookings list's filters as the server applies them, every one ANDed. From and To are the
 * operator's dates in the tenant's zone and null means unbounded; staff and service are row ids;
 * search matches the customer columns named in searchIn, the name alone until the caller names
 * the columns this viewer may see; page counts from one at a fixed PAGE_SIZE. email narrows to
 * one customer's bookings, the address matched as citext compares it, for a customer's page; the
 * Bookings screen never sets it, and it is no part of that screen's query.
 *
 * **The To date is inclusive to the operator and exclusive on the wire, and this is the one
 * place that turns it.** range() hands the reader a half-open window of instants: From's
 * midnight in the tenant's zone, and the midnight after To's, so a booking starting at any time
 * on the To day is in and one starting on the next is out.
 */
final readonly class BookingFilter
{
    public const int PAGE_SIZE = 25;

    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?BookingStatus $status = null,
        public ?int $staff = null,
        public ?int $service = null,
        public ?string $search = null,
        public int $page = 1,
        /** @var list<string> */
        public array $searchIn = ['customer_name'],
        public ?string $email = null,
    ) {}

    /**
     * The same filters searching the given customer columns, the ones the viewer is shown.
     *
     * @param  list<string>  $columns
     */
    public function searchingIn(array $columns): self
    {
        return new self($this->from, $this->to, $this->status, $this->staff, $this->service, $this->search, $this->page, $columns, $this->email);
    }

    /**
     * The window a booking's start must fall in: from inclusive, until exclusive, either end
     * null where the operator left it empty.
     *
     * A From whose midnight falls before FirstInstant, which only the calendar's own first day
     * can, in a zone east of UTC, bounds nothing and is null too, as the console's tenant list
     * reads its own From.
     *
     * @return array{from: DateTimeImmutable|null, until: DateTimeImmutable|null}
     */
    public function range(DateTimeZone $zone): array
    {
        $from = $this->from === null ? null : new DateTimeImmutable($this->from.' 00:00', $zone);

        return [
            'from' => $from === null || FirstInstant::precedes($from) ? null : $from,
            'until' => $this->to === null ? null : (new DateTimeImmutable($this->to.' 00:00', $zone))->add(new DateInterval('P1D')),
        ];
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PAGE_SIZE;
    }

    /**
     * The filters as the page's query spells them, the defaults left out, for the client to
     * seed its bar from.
     *
     * @return array{from: string, to: string, status: string, staff: int|null, service: int|null, search: string, page: int}
     */
    public function toQuery(): array
    {
        return [
            'from' => $this->from ?? '',
            'to' => $this->to ?? '',
            'status' => $this->status->value ?? '',
            'staff' => $this->staff,
            'service' => $this->service,
            'search' => $this->search ?? '',
            'page' => $this->page,
        ];
    }
}
