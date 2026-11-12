<?php

use App\Domain\Availability\FreeBusyMask;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\SlotSpan;
use App\Domain\Availability\StaffDayGeometry;
use App\Domain\Availability\StaffDayMasks;
use App\Domain\Booking\Overridable;

/*
 * The per-day fit of one explicit item over stored ranges: the contended span must be free
 * of bookings, the service span must lie inside opening hours, and a span that does not
 * touch the day asks nothing of it.
 */

function fitDay(): StaffDayGeometry
{
    return StaffDayGeometry::of('2026-04-06', new DateTimeZone('Europe/Athens'), Granularity::default());
}

function fitSpan(string $from, string $to): SlotSpan
{
    $zone = new DateTimeZone('Europe/Athens');

    return SlotSpan::outward(fitDay(), new DateTimeImmutable($from, $zone), new DateTimeImmutable($to, $zone));
}

/**
 * Open 09:00 to 17:00, slots 108 to 203, and booked 10:00 to 11:00, slots 120 to 131.
 */
function fitMasks(): StaffDayMasks
{
    return new StaffDayMasks(
        FreeBusyMask::allClear(288)->set(108, 204),
        FreeBusyMask::allSet(288)->clear(120, 132),
    );
}

it('admits an item whose contended span is free and whose service span is inside the hours', function () {
    expect(fitMasks()->admits(fitSpan('2026-04-06 11:00', '2026-04-06 12:00'), fitSpan('2026-04-06 11:00', '2026-04-06 12:15')))->toBeTrue()
        ->and(fitMasks()->admits(fitSpan('2026-04-06 16:00', '2026-04-06 17:00'), fitSpan('2026-04-06 15:50', '2026-04-06 17:10')))->toBeTrue();
});

it('refuses an item whose contended span touches a booked slot, buffers included', function () {
    expect(fitMasks()->admits(fitSpan('2026-04-06 11:00', '2026-04-06 12:00'), fitSpan('2026-04-06 10:55', '2026-04-06 12:00')))->toBeFalse()
        ->and(fitMasks()->admits(fitSpan('2026-04-06 09:00', '2026-04-06 09:55'), fitSpan('2026-04-06 09:00', '2026-04-06 10:05')))->toBeFalse();
});

it('refuses an item whose service span leaves the hours, while its buffers may', function () {
    expect(fitMasks()->admits(fitSpan('2026-04-06 16:30', '2026-04-06 17:30'), fitSpan('2026-04-06 16:30', '2026-04-06 17:30')))->toBeFalse()
        ->and(fitMasks()->admits(fitSpan('2026-04-06 08:30', '2026-04-06 09:30'), fitSpan('2026-04-06 08:30', '2026-04-06 09:30')))->toBeFalse()
        ->and(fitMasks()->admits(fitSpan('2026-04-06 09:00', '2026-04-06 10:00'), fitSpan('2026-04-06 08:30', '2026-04-06 10:00')))->toBeTrue();
});

it('admits any item on a day neither of its spans touches, and reads an empty span as nothing asked', function () {
    expect(fitMasks()->admits(fitSpan('2026-04-07 03:00', '2026-04-07 04:00'), fitSpan('2026-04-07 03:00', '2026-04-07 04:00')))->toBeTrue()
        ->and((new StaffDayMasks(FreeBusyMask::allClear(288), FreeBusyMask::allClear(288)))->admits(fitSpan('2026-04-07 03:00', '2026-04-07 04:00'), fitSpan('2026-04-07 03:00', '2026-04-07 04:00')))->toBeTrue();
});

it('names what of the open half a service span crosses, the member\'s hours, a closure or both, from the two factors it was built with', function () {
    // Hours 09:00 to 17:00, slots 108 to 203; a closure 15:00 to 16:00, slots 180 to 191.
    $hours = FreeBusyMask::allClear(288)->set(108, 204);
    $unclosed = FreeBusyMask::allSet(288)->clear(180, 192);
    $masks = new StaffDayMasks($hours->and($unclosed), FreeBusyMask::allSet(288), $hours, $unclosed);

    expect($masks->crossed(fitSpan('2026-04-06 10:00', '2026-04-06 11:00')))->toBe([])
        ->and($masks->crossed(fitSpan('2026-04-06 08:30', '2026-04-06 09:30')))->toBe([Overridable::Hours])
        ->and($masks->crossed(fitSpan('2026-04-06 15:30', '2026-04-06 16:30')))->toBe([Overridable::Closure])
        ->and($masks->crossed(fitSpan('2026-04-06 15:30', '2026-04-06 17:30')))->toBe([Overridable::Hours, Overridable::Closure])
        ->and($masks->booked(fitSpan('2026-04-06 10:00', '2026-04-06 11:00')))->toBeFalse();
});

it('reads every crossing as the member\'s hours where it was built without the factors, which is the open mask\'s own reading', function () {
    expect(fitMasks()->crossed(fitSpan('2026-04-06 17:00', '2026-04-06 18:00')))->toBe([Overridable::Hours])
        ->and(fitMasks()->booked(fitSpan('2026-04-06 10:30', '2026-04-06 11:00')))->toBeTrue()
        ->and(fn () => new StaffDayMasks(FreeBusyMask::allSet(288), FreeBusyMask::allSet(288), FreeBusyMask::allSet(100)))->toThrow(InvalidArgumentException::class);
});
