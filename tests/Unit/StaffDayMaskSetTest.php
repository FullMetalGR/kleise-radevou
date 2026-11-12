<?php

use App\Domain\Availability\FreeBusyMask;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\StaffDayMasks;
use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Availability\WindowGeometry;

function maskSetWindow(string $firstDay = '2026-03-28', int $days = 2): WindowGeometry
{
    return WindowGeometry::spanning($firstDay, $days, new DateTimeZone('Europe/Athens'), Granularity::default());
}

function maskSetDay(int $width, int $from, int $to): StaffDayMasks
{
    return new StaffDayMasks(FreeBusyMask::allClear($width)->set($from, $to), FreeBusyMask::allSet($width));
}

it('holds one pair of masks per staff member per day of the window, in the window\'s order, and names the members asked for', function () {
    $set = new StaffDayMaskSet(maskSetWindow(), [
        3 => [maskSetDay(288, 108, 204), maskSetDay(276, 96, 192)],
        1 => [maskSetDay(288, 0, 0), maskSetDay(276, 0, 0)],
    ]);

    expect($set->staffIds())->toBe([3, 1])
        ->and($set->window->width)->toBe(564)
        ->and($set->daysOf(3))->toHaveCount(2)
        ->and($set->on(3, '2026-03-28')->open->setIndices())->toBe(range(108, 203))
        ->and($set->on(3, '2026-03-29')->open->setIndices())->toBe(range(96, 191))
        ->and($set->on(1, '2026-03-29')->open->setIndices())->toBe([]);
});

it('concatenates a member\'s days into the window masks the resolver takes', function () {
    $set = new StaffDayMaskSet(maskSetWindow(), [3 => [maskSetDay(288, 108, 204), maskSetDay(276, 96, 192)]]);

    $window = $set->windowOf(3);

    expect($window->window)->toBe($set->window)
        ->and($window->open->width)->toBe(564)
        ->and($window->open->setIndices())->toBe([...range(108, 203), ...range(288 + 96, 288 + 191)])
        ->and($window->notBooked->equals(FreeBusyMask::allSet(564)))->toBeTrue();
});

it('refuses a member it was not built for, and a day outside the window', function () {
    $set = new StaffDayMaskSet(maskSetWindow(), [3 => [maskSetDay(288, 0, 0), maskSetDay(276, 0, 0)]]);

    expect(fn () => $set->daysOf(4))->toThrow(InvalidArgumentException::class, 'The mask set holds no staff member 4.')
        ->and(fn () => $set->on(3, '2026-03-30'))->toThrow(InvalidArgumentException::class, 'has no day 2026-03-30');
});

it('refuses a member with the wrong number of days, a day of the wrong width, an empty set and a staff id below one', function () {
    expect(fn () => new StaffDayMaskSet(maskSetWindow(), [3 => [maskSetDay(288, 0, 0)]]))
        ->toThrow(InvalidArgumentException::class, 'Staff member 3 has masks for 1 days of a window of 2.')
        ->and(fn () => new StaffDayMaskSet(maskSetWindow(), [3 => [maskSetDay(288, 0, 0), maskSetDay(288, 0, 0)]]))
        ->toThrow(InvalidArgumentException::class, 'The masks of staff member 3 for 2026-03-29 are 288 wide, not its 276.')
        ->and(fn () => new StaffDayMaskSet(maskSetWindow(), []))
        ->toThrow(InvalidArgumentException::class, 'A mask set covers at least one staff member.')
        ->and(fn () => new StaffDayMaskSet(maskSetWindow(), [0 => [maskSetDay(288, 0, 0), maskSetDay(276, 0, 0)]]))
        ->toThrow(InvalidArgumentException::class, 'A staff id is a positive integer, not 0.');
});
