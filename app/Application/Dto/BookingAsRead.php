<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\BookingStatus;
use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

/**
 * A booking as a caller read it before asking for a transition, kept so the transition can be
 * held to it: its status and, of each item in chain order, the member, the customer's own start
 * and the contended range.
 *
 * It is for the caller that decides over many bookings at once and then acts on each in a write
 * of its own. Between that read and the write somebody may move a booking or pay for it, and a
 * move leaves the status as it was, so the status the guard reads would not show it. A cancel or
 * a reject handed one of these goes through only while the re-read under the booking's lock
 * still says the same, and is refused otherwise.
 *
 * It can only refuse. What allows a transition is still the transition's own guard, asked of the
 * re-read and the clock alone, so nothing a caller read ever lets through what the booking's own
 * state would not.
 *
 * An item's end is held as its contended range rather than as the end the customer was sold. The
 * re-read under the lock carries the range and not that end, and a move writes the two together,
 * so an item that no longer ends when it did no longer holds the range it did.
 */
final readonly class BookingAsRead
{
    /**
     * @param  list<array{block: StaffBlock, start: DateTimeImmutable}>  $items  in chain order: each item's contended range with its member, and the customer's own start
     */
    public function __construct(
        public BookingStatus $status,
        public array $items,
    ) {}

    /**
     * The expectation a record states: the snapshot's status and items, and each item's
     * contended range from the record's operational columns, which spell an instant with its
     * offset.
     *
     * @throws UnexpectedValueException when an item of the record carries no contended range
     */
    public static function of(BookingRecord $record): self
    {
        $utc = new DateTimeZone('UTC');
        $items = [];

        foreach ($record->snapshot->items as $index => $item) {
            $from = $record->itemOperations[$index]['block_start_utc'] ?? null;
            $to = $record->itemOperations[$index]['block_end_utc'] ?? null;

            if (! is_string($from) || ! is_string($to)) {
                throw new UnexpectedValueException(sprintf('Booking %s was read with an item that carries no contended range.', $record->snapshot->uuid));
            }

            $items[] = [
                'block' => new StaffBlock($item->staffId, new DateTimeImmutable($from, $utc), new DateTimeImmutable($to, $utc)),
                'start' => $item->start,
            ];
        }

        return new self($record->snapshot->status, $items);
    }

    /**
     * Whether the booking found under the lock is still the one read: the same status, as many
     * items, and each item on the same member, at the same start, over the same contended range.
     */
    public function stillHolds(LockedBooking $found): bool
    {
        if ($found->status !== $this->status || count($found->items) !== count($this->items)) {
            return false;
        }

        foreach ($found->items as $index => $item) {
            $read = $this->items[$index] ?? null;

            if ($read === null
                || $item->block->staffId !== $read['block']->staffId
                || ! self::same($item->start, $read['start'])
                || ! self::same($item->block->start, $read['block']->start)
                || ! self::same($item->block->end, $read['block']->end)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Two instants at the same second, whatever zone each is written in: a record spells its
     * ranges to the second, and every time a booking holds is on a whole minute.
     */
    private static function same(DateTimeImmutable $one, DateTimeImmutable $other): bool
    {
        return $one->getTimestamp() === $other->getTimestamp();
    }
}
