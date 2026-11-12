<?php

use App\Application\ChainUnavailable;
use App\Domain\Booking\StaffBlock;

/*
 * The refusal that says the tenant is not open to the range asked for. The planner's three
 * spellings are exercised where the planner is; what is asked here is the fourth, which the
 * re-validation under the lock raises, because it is the one the API must not confuse with a
 * range somebody else already holds.
 */

it('names, for a segment the masks under the lock refused, the segment and its contended range in UTC, with a sentence about hours rather than an overlap', function () {
    $block = new StaffBlock(
        3,
        new DateTimeImmutable('2026-10-01 12:00', new DateTimeZone('Europe/Athens')),
        new DateTimeImmutable('2026-10-01 13:00', new DateTimeZone('Europe/Athens')),
    );

    $refusal = ChainUnavailable::underLock(1, $block);

    expect($refusal->segment)->toBe(1)
        ->and($refusal->getMessage())->toBe('Segment 1 cannot be held for staff member 3 between 2026-10-01 09:00:00 and 2026-10-01 10:00:00 UTC: the range is outside their availability.')
        ->and($refusal->getMessage())->not->toContain('overlap')
        ->and($refusal->getPrevious())->toBeNull();
});

it('refuses a negative segment index, the way every other refusal naming a segment does', function () {
    $block = new StaffBlock(1, new DateTimeImmutable('2026-10-01 09:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-10-01 10:00', new DateTimeZone('UTC')));

    expect(fn () => ChainUnavailable::underLock(-1, $block))->toThrow(InvalidArgumentException::class);
});
