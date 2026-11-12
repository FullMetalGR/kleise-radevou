<?php

use App\Domain\Enum\BookingStatus;

/*
 * The nine states of the status machine as the column stores them. The feature suite holds
 * this enum to the CHECK constraint; this file holds the list itself.
 */

it('spells the nine states of the status machine as the column stores them, in the machine order', function () {
    expect(array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::cases()))
        ->toBe(['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed', 'completed', 'no_show', 'cancelled', 'rejected', 'expired']);
});

it('is string-backed, so a row value round-trips', function () {
    expect(BookingStatus::from('no_show'))->toBe(BookingStatus::NoShow);
});

it('holds in exactly pending, awaiting_approval and awaiting_payment', function () {
    expect(BookingStatus::held())->toBe([BookingStatus::Pending, BookingStatus::AwaitingApproval, BookingStatus::AwaitingPayment])
        ->and(array_map(fn (BookingStatus $status): bool => $status->isHeld(), BookingStatus::cases()))
        ->toBe([true, true, true, false, false, false, false, false, false]);
});

it('frees the slot in exactly the five states the blocking predicate never counts, so a transition into one of them clears the flag and confirmed keeps it', function () {
    expect(array_map(fn (BookingStatus $status): bool => $status->releasesTheSlot(), BookingStatus::cases()))
        ->toBe([false, false, false, false, true, true, true, true, true])
        ->and(array_values(array_filter(BookingStatus::cases(), fn (BookingStatus $status): bool => $status->releasesTheSlot())))
        ->toBe([BookingStatus::Completed, BookingStatus::NoShow, BookingStatus::Cancelled, BookingStatus::Rejected, BookingStatus::Expired])
        ->and(BookingStatus::Confirmed->releasesTheSlot())->toBeFalse()
        ->and(array_values(array_filter(BookingStatus::cases(), fn (BookingStatus $status): bool => ! $status->releasesTheSlot() && ! $status->isHeld())))
        ->toBe([BookingStatus::Confirmed]);
});

it('awaits a decision in exactly awaiting_approval, which is the one state the customer is told is a request rather than a booking', function () {
    expect(array_map(
        fn (BookingStatus $status): string => $status->value,
        array_values(array_filter(BookingStatus::cases(), fn (BookingStatus $status): bool => $status->awaitsADecision())),
    ))->toBe(['awaiting_approval']);
});

it('came to nothing in exactly cancelled, rejected and expired, the three a My bookings prefill reads past, completed and no_show having kept their time', function () {
    expect(array_map(fn (BookingStatus $status): bool => $status->cameToNothing(), BookingStatus::cases()))
        ->toBe([false, false, false, false, false, false, true, true, true])
        ->and(array_values(array_filter(BookingStatus::cases(), fn (BookingStatus $status): bool => $status->cameToNothing())))
        ->toBe([BookingStatus::Cancelled, BookingStatus::Rejected, BookingStatus::Expired]);
});
