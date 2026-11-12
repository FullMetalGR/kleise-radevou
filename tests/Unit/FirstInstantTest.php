<?php

use App\Application\Dto\BookingFilter;
use App\Application\Dto\CalendarWindow;
use App\Application\Dto\FigureRange;
use App\Application\Dto\FirstInstant;

/*
 * The first instant a bound can name: the first moment of the year 1 in UTC, which the midnight
 * of the calendar's own first day falls before in a zone east of UTC. The Bookings list reads
 * such a From as unbounded, and the calendar's window and the Reports screen's custom range start
 * at the first instant instead; every other day's midnight is the bound it always was.
 */

it('is the first moment of the year 1 in UTC, and precedes nothing at or after it', function () {
    $utc = new DateTimeZone('UTC');

    expect(FirstInstant::at()->format(DateTimeInterface::RFC3339))->toBe('0001-01-01T00:00:00+00:00')
        ->and(FirstInstant::precedes(new DateTimeImmutable('0001-01-01 00:00', new DateTimeZone('Europe/Athens'))))->toBeTrue()
        ->and(FirstInstant::precedes(new DateTimeImmutable('0001-01-01 00:00', $utc)))->toBeFalse()
        ->and(FirstInstant::precedes(new DateTimeImmutable('0001-01-01 00:00', new DateTimeZone('America/New_York'))))->toBeFalse()
        ->and(FirstInstant::precedes(new DateTimeImmutable('0001-01-02 00:00', new DateTimeZone('Europe/Athens'))))->toBeFalse();
});

it('reads the calendar\'s first day as a From that bounds nothing east of UTC, and as the bound it is elsewhere, its To unchanged', function () {
    $athens = (new BookingFilter(from: '0001-01-01', to: '0001-01-01'))->range(new DateTimeZone('Europe/Athens'));
    $utc = (new BookingFilter(from: '0001-01-01'))->range(new DateTimeZone('UTC'));
    $later = (new BookingFilter(from: '2026-11-03'))->range(new DateTimeZone('Europe/Athens'));

    expect($athens['from'])->toBeNull()
        ->and($athens['until']?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'))->toBe('0001-01-01 22:25')
        ->and($utc['from']?->format(DateTimeInterface::RFC3339))->toBe('0001-01-01T00:00:00+00:00')
        ->and($later['from']?->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339))->toBe('2026-11-02T22:00:00+00:00');
});

it('starts a calendar window on the first day at the first instant east of UTC, and at its midnight elsewhere', function () {
    $athens = (new CalendarWindow('0001-01-01', '0001-01-08'))->range(new DateTimeZone('Europe/Athens'));
    $utc = (new CalendarWindow('0001-01-01', '0001-01-08'))->range(new DateTimeZone('UTC'));

    expect($athens['from']->format(DateTimeInterface::RFC3339))->toBe('0001-01-01T00:00:00+00:00')
        ->and($athens['until']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'))->toBe('0001-01-07 22:25')
        ->and($utc['from']->format(DateTimeInterface::RFC3339))->toBe('0001-01-01T00:00:00+00:00');
});

it('starts a custom report range on the first day at the first instant east of UTC, and at its midnight elsewhere', function () {
    $range = FigureRange::custom('0001-01-01', '0001-01-08');
    assert($range instanceof FigureRange);
    $athens = $range->window(new DateTimeZone('Europe/Athens'));
    $utc = $range->window(new DateTimeZone('UTC'));

    expect($athens['from']->format(DateTimeInterface::RFC3339))->toBe('0001-01-01T00:00:00+00:00')
        ->and($athens['until']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'))->toBe('0001-01-08 22:25')
        ->and($utc['from']->format(DateTimeInterface::RFC3339))->toBe('0001-01-01T00:00:00+00:00');
});
