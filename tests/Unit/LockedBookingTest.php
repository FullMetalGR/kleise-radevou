<?php

use App\Application\Dto\LockedBooking;
use App\Application\Dto\LockedItem;
use App\Domain\Booking\ServiceWindows;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;

/*
 * The only account of a booking a guard may judge.
 *
 * The blocking predicate is not spelled here and is not meant to be: the expiry comparison
 * comes back as the server's own verdict, made where the predicate makes it, and the limb about
 * an auto-approving request is spelled in SQL by the reap's exclusion and by the availability
 * read. No guard asks it. Approve and reject meet awaiting_approval head on and read the status
 * alone.
 *
 * 2026-11-03 is a Tuesday. Every instant here is UTC, which is how the re-read hands them back.
 */

function lockedInstant(string $text): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone('UTC'));
}

/**
 * One item: the contended range, the customer's start inside it, and the service's windows.
 */
function lockedItem(int $staffId, string $start, string $end, int $cancelHours = 24, ?string $blockStart = null): LockedItem
{
    return new LockedItem(
        new StaffBlock($staffId, lockedInstant($blockStart ?? $start), lockedInstant($end)),
        lockedInstant($start),
        new ServiceWindows($cancelHours, 24),
    );
}

/**
 * @param  list<LockedItem>  $items
 */
function lockedBooking(
    BookingStatus $status = BookingStatus::Pending,
    array $items = [],
): LockedBooking {
    return new LockedBooking(
        '11111111-0000-4000-8000-000000000001',
        $status,
        $status->isHeld() ? HoldClass::Checkout : null,
        true,
        PaymentMode::Onsite,
        $items === [] ? [lockedItem(3, '2026-11-03 09:00:00', '2026-11-03 09:45:00')] : $items,
    );
}

it('answers the contended ranges, the first segment\'s own start and every segment\'s windows, in chain order', function () {
    $booking = lockedBooking(items: [
        lockedItem(3, '2026-11-03 09:00:00', '2026-11-03 09:45:00', cancelHours: 2, blockStart: '2026-11-03 08:45:00'),
        lockedItem(4, '2026-11-03 10:00:00', '2026-11-03 11:00:00', cancelHours: 48),
    ]);

    expect(array_map(fn (StaffBlock $block): int => $block->staffId, $booking->blocks()))->toBe([3, 4])
        // The range starts at the buffer and the segment starts where the customer was told to
        // arrive; a window is measured back from the second.
        ->and($booking->blocks()[0]->start->format('H:i'))->toBe('08:45')
        ->and($booking->firstStart()->format('H:i'))->toBe('09:00')
        ->and(array_map(fn (ServiceWindows $windows): int => $windows->cancelHours, $booking->windows()))->toBe([2, 48]);
});

it('refuses a booking read back with no items, because a booking has at least one', function () {
    expect(fn () => new LockedBooking(
        '11111111-0000-4000-8000-000000000001',
        BookingStatus::Pending,
        HoldClass::Checkout,
        true,
        PaymentMode::Onsite,
        [],
    ))->toThrow(InvalidArgumentException::class, 'at least one');
});
