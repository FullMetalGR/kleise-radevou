<?php

use App\Domain\Booking\SegmentConflict;
use App\Domain\Booking\StaffBlock;

it('carries the zero-based segment and its block, and names both in UTC', function () {
    $block = new StaffBlock(
        7,
        new DateTimeImmutable('2026-10-01 12:00', new DateTimeZone('Europe/Athens')),
        new DateTimeImmutable('2026-10-01 13:00', new DateTimeZone('Europe/Athens')),
    );

    $conflict = SegmentConflict::at(2, $block);

    expect($conflict->segment)->toBe(2)
        ->and($conflict->block)->toBe($block)
        ->and($conflict->getMessage())->toBe('Segment 2 overlaps a blocking item of staff member 7 between 2026-10-01 09:00:00 and 2026-10-01 10:00:00 UTC.')
        ->and($conflict->getPrevious())->toBeNull();
});

it('refuses a negative segment index', function () {
    $block = new StaffBlock(1, new DateTimeImmutable('2026-10-01 09:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-10-01 10:00', new DateTimeZone('UTC')));

    expect(fn () => SegmentConflict::at(-1, $block))->toThrow(InvalidArgumentException::class);
});
