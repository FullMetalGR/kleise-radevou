<?php

declare(strict_types=1);

namespace App\Application\Dto;

use DateTimeImmutable;

/**
 * A customer as the Customers screen reads one: the bookings of one address as citext groups it,
 * never a row of its own. The address as the most recent booking stored it; every name and every
 * phone the bookings carried, each once, the most recent first; the first visit, a booking's
 * first start; and how many bookings the address holds in any status. The page's tiles beside
 * them: the bookings upcoming (confirmed, awaiting approval or awaiting payment, the first start
 * ahead of now), the completed, the no-shows and the cancelled, and the last visit, the latest
 * first start already passed in confirmed or completed, null where none is. A booking is more
 * recent than another when its first start is later, the later row breaking a tie. Which of
 * these reach a viewer is App\Http\CustomerPayload's decision.
 */
final readonly class CustomerRecord
{
    /**
     * @param  non-empty-list<string>  $names  the most recent first
     * @param  list<string>  $phones  the most recent first, empty where no booking carried one
     */
    public function __construct(
        public string $email,
        public array $names,
        public array $phones,
        public DateTimeImmutable $firstVisit,
        public ?DateTimeImmutable $lastVisit,
        public int $bookings,
        public int $upcoming,
        public int $completed,
        public int $noShows,
        public int $cancelled,
    ) {}
}
