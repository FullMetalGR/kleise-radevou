<?php

use App\Domain\Availability\Blackout;
use App\Domain\Availability\InvalidBlackout;
use App\Domain\Availability\WallClockTime;

function blackoutFor(?int $staffId, string $date, ?string $start = null, ?string $end = null): Blackout
{
    return new Blackout($staffId, $date, $start === null ? null : WallClockTime::of($start), $end === null ? null : WallClockTime::of($end));
}

it('is tenant-wide without a staff member and personal with one, on a date, with or without a window', function () {
    $wholeDay = blackoutFor(null, '2026-03-29');
    $lunch = blackoutFor(3, '2026-03-29', '12:00', '13:00');

    expect($wholeDay->staffId)->toBeNull()
        ->and($wholeDay->date)->toBe('2026-03-29')
        ->and($wholeDay->start)->toBeNull()
        ->and($wholeDay->end)->toBeNull()
        ->and($wholeDay->isWholeDay())->toBeTrue()
        ->and($lunch->staffId)->toBe(3)
        ->and($lunch->start?->format())->toBe('12:00:00')
        ->and($lunch->end?->format())->toBe('13:00:00')
        ->and($lunch->isWholeDay())->toBeFalse();
});

it('applies to every staff member on its date when tenant-wide', function () {
    $wholeDay = blackoutFor(null, '2026-03-29');

    expect($wholeDay->appliesTo(1, '2026-03-29'))->toBeTrue()
        ->and($wholeDay->appliesTo(42, '2026-03-29'))->toBeTrue()
        ->and($wholeDay->appliesTo(1, '2026-03-28'))->toBeFalse()
        ->and($wholeDay->appliesTo(1, '2026-03-30'))->toBeFalse();
});

it('applies to its own staff member on its date and to nobody else when personal', function () {
    $personal = blackoutFor(3, '2026-03-29', '12:00', '13:00');

    expect($personal->appliesTo(3, '2026-03-29'))->toBeTrue()
        ->and($personal->appliesTo(4, '2026-03-29'))->toBeFalse()
        ->and($personal->appliesTo(3, '2026-03-28'))->toBeFalse();
});

it('refuses a window with only one end, with a typed error', function () {
    expect(fn () => blackoutFor(null, '2026-03-29', '12:00', null))
        ->toThrow(InvalidBlackout::class, 'A blackout window has both ends or neither, not a start of 12:00:00 alone.');
    expect(fn () => blackoutFor(null, '2026-03-29', null, '13:00'))
        ->toThrow(InvalidBlackout::class, 'A blackout window has both ends or neither, not an end of 13:00:00 alone.');
});

it('refuses a window whose start is not before its end, with a typed error naming both', function (string $start, string $end) {
    expect(fn () => blackoutFor(null, '2026-03-29', $start, $end))
        ->toThrow(InvalidBlackout::class, sprintf('A blackout window opens before it closes, not from %s to %s.', WallClockTime::of($start)->format(), WallClockTime::of($end)->format()));
})->with(['inverted' => ['13:00', '12:00'], 'empty' => ['12:00', '12:00']]);

it('refuses a staff id below one', function (int $staffId) {
    expect(fn () => blackoutFor($staffId, '2026-03-29'))
        ->toThrow(InvalidBlackout::class, sprintf('A staff id is a positive integer, not %d.', $staffId));
})->with(['zero' => [0], 'negative' => [-3]]);

it('refuses a date that is not spelled YYYY-MM-DD', function () {
    expect(fn () => blackoutFor(null, 'christmas'))->toThrow(InvalidArgumentException::class, 'A day is spelled YYYY-MM-DD, not "christmas".');
});
