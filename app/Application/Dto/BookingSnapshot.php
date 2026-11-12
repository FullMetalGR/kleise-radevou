<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * A booking as a reader hands it to a caller: the public identifier, where it stands, the hold
 * it is under while it is under one, the customer it belongs to, the total, and its items in
 * chain order. What of it any given caller may see is the payload whitelist's decision, not
 * this object's: a snapshot is the whole row, read once, and a caller is shown a view of it.
 *
 * The serial id and the manage token's hash are absent by construction. They reach nobody, so
 * nothing that could carry them to a caller ever holds them.
 *
 * The customer is null once erased, the three customer columns nulled together (tenant migration
 * 000028, App\Retention\Erasure): the booking stands as the record it is and the person is gone
 * from it. A surface showing the booking reads the null as "Customer erased"; a message asks
 * person(), and every sender ends before it builds one on an erased booking
 * (App\Jobs\ErasedBooking).
 *
 * The language is the one the booking was made in, bookings.language as its code, en or el:
 * written once at the hold and never updated, and every message to the customer about the
 * booking is written in it, App\Tenancy\Languages::ofBooking reading the code. A code and not
 * the site's enum, since this layer imports nothing outside Application and Domain, as Message
 * carries its language; en where a builder names none, which is what a row written before the
 * column holds.
 */
final readonly class BookingSnapshot
{
    /** @var non-empty-list<BookingItemSnapshot> in chain order */
    public array $items;

    /**
     * The items arrive as the readers build them, a list that the type cannot promise is
     * non-empty; the guard below refuses an empty one, so the property is the non-empty-list
     * every reader of items[0] relies on (BookAgainLine, startsAt).
     *
     * @param  list<BookingItemSnapshot>  $items  in chain order, at least one
     * @param  string  $language  the booking's language as bookings.language holds it, en or el
     */
    public function __construct(
        public string $uuid,
        public BookingStatus $status,
        public ?HoldClass $holdClass,
        public ?DateTimeImmutable $holdExpiresAt,
        public ?Customer $customer,
        public Money $total,
        array $items,
        public string $language = 'en',
    ) {
        if ($items === []) {
            throw new InvalidArgumentException('A booking has at least one item.');
        }

        $this->items = $items;
    }

    /**
     * Who the booking is for, where a message names them or is written to them. An erased
     * booking has nobody to name, and every sender ends on one before it builds a message
     * (App\Jobs\ErasedBooking), so a message asked of one is a defect and is refused here rather
     * than written to nobody.
     */
    public function person(): Customer
    {
        return $this->customer ?? throw new LogicException(sprintf('The booking %s names no customer: its customer was erased.', $this->uuid));
    }

    /**
     * When the booking begins: the earliest start across the chain, which is the first item's
     * today and would stop being it the day a chain is allowed to hold two segments at once,
     * the same day endsAt() stops being the last item's. It is the instant a message means by
     * "your appointment is on", the one a move announces as the start it took, and the one
     * everything that asks when a booking is for should ask here rather than of items[0].
     */
    public function startsAt(): DateTimeImmutable
    {
        $start = $this->items[0]->start;

        foreach ($this->items as $item) {
            if ($item->start < $start) {
                $start = $item->start;
            }
        }

        return $start;
    }

    /**
     * When the booking is over: the latest end across the chain, and not the end of its last
     * segment by sort, which is the same instant today and would stop being it the day a chain
     * is allowed to hold two segments at once. There is always one, because there is always an
     * item. Everything that asks how long a booking's aftermath lasts - the manage token's
     * derived lifetime, and the cancellation and reschedule windows behind it - asks here, so
     * one of them cannot acquire a different idea of when a booking ended.
     */
    public function endsAt(): DateTimeImmutable
    {
        $end = $this->items[0]->end;

        foreach ($this->items as $item) {
            if ($item->end > $end) {
                $end = $item->end;
            }
        }

        return $end;
    }
}
