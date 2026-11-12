<?php

use App\Domain\Availability\BlockRange;

function blockBetween(string $start, string $end): BlockRange
{
    // Both name UTC, though every string here carries its own offset and overrides it.
    $utc = new DateTimeZone('UTC');

    return new BlockRange(new DateTimeImmutable($start, $utc), new DateTimeImmutable($end, $utc));
}

it('is a start and an end, as instants, and nothing else', function () {
    $block = blockBetween('2026-03-28T09:00:00+02:00', '2026-03-28T10:00:00+02:00');
    $properties = array_map(
        fn (ReflectionProperty $property): string => $property->getName().': '.$property->getType(),
        (new ReflectionClass(BlockRange::class))->getProperties(),
    );

    expect($block->start->format(DATE_RFC3339))->toBe('2026-03-28T09:00:00+02:00')
        ->and($block->end->format(DATE_RFC3339))->toBe('2026-03-28T10:00:00+02:00')
        ->and($properties)->toBe(['start: DateTimeImmutable', 'end: DateTimeImmutable']);
});

it('accepts an empty range, the end on the start', function () {
    $block = blockBetween('2026-03-28T09:00:00+02:00', '2026-03-28T09:00:00+02:00');

    expect($block->start->getTimestamp())->toBe($block->end->getTimestamp());
});

it('refuses an end before its start, to the microsecond, whatever zones the two are spelled in', function () {
    expect(fn () => blockBetween('2026-03-28T10:00:00+02:00', '2026-03-28T09:00:00+02:00'))
        ->toThrow(InvalidArgumentException::class, 'A block range ends at or after it starts, not from 2026-03-28T10:00:00+02:00 to 2026-03-28T09:00:00+02:00.');
    expect(fn () => blockBetween('2026-03-28T09:00:00.000001+02:00', '2026-03-28T09:00:00+02:00'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => blockBetween('2026-03-28T09:00:00+02:00', '2026-03-28T08:00:00+00:00'))
        ->not->toThrow(InvalidArgumentException::class);
    expect(fn () => blockBetween('2026-03-28T09:00:00+02:00', '2026-03-28T06:59:59+00:00'))
        ->toThrow(InvalidArgumentException::class);
});
