<?php

use App\Domain\Audit\AuditAction;
use App\Domain\Enum\BookingOutcome;
use App\Domain\Enum\BookingStatus;

/*
 * The two answers to the one question the owner is asked about an appointment that has been
 * and gone. It is an enum rather than two use cases, because what differs between them is
 * three values and nothing else, and a guard that must be the same for both is easiest to keep
 * the same by there being one of it.
 */

it('is the two outcomes and no others, so a third way for an appointment to end is a decision somebody makes rather than a string somebody passes', function () {
    expect(array_map(fn (BookingOutcome $outcome): string => $outcome->value, BookingOutcome::cases()))
        ->toBe(['completed', 'no_show']);
});

it('names the status it lands on and the action the trail records, each spelled out rather than read off its own value, so renaming one of the three does not silently restate the other two', function () {
    expect(BookingOutcome::Completed->status())->toBe(BookingStatus::Completed)
        ->and(BookingOutcome::Completed->audit())->toBe(AuditAction::Completed)
        ->and(BookingOutcome::NoShow->status())->toBe(BookingStatus::NoShow)
        ->and(BookingOutcome::NoShow->audit())->toBe(AuditAction::NoShow);
});

it('lands on a status the blocking predicate never counts, whichever outcome it was, because an appointment that is over occupies nothing', function (BookingOutcome $outcome) {
    expect($outcome->status()->releasesTheSlot())->toBeTrue()
        ->and($outcome->status()->isHeld())->toBeFalse();
})->with([
    'completed' => [BookingOutcome::Completed],
    'no_show' => [BookingOutcome::NoShow],
]);
