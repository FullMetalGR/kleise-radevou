<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\BookingRecord;
use RuntimeException;

/**
 * A closure that was not written because bookings fall inside it that nobody has answered for:
 * none was seen at all, or those seen are not the ones there now. It carries the bookings as
 * they stand, soonest first, so the answer can show them.
 */
final class BookingsInside extends RuntimeException
{
    /**
     * @param  list<BookingRecord>  $bookings
     * @param  bool  $changed  whether a list was seen and the bookings found differ from it
     */
    public function __construct(public readonly array $bookings, public readonly bool $changed)
    {
        parent::__construct(sprintf('%d bookings fall inside the closure, and %s.', count($bookings), $changed ? 'they differ from those seen' : 'none was seen'));
    }
}
