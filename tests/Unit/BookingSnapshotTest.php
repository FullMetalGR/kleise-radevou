<?php

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\Customer;
use App\Domain\Enum\BookingStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;

/*
 * When a booking begins and when it ends, the two things a snapshot answers about itself rather
 * than carrying. Everything that measures an aftermath - the manage token's derived lifetime,
 * and the cancellation and reschedule windows after it - asks the end here; everything that
 * says when an appointment is for, the messages among them, asks the start.
 */

function snapshotItem(int $sort, string $ends): BookingItemSnapshot
{
    $end = new DateTimeImmutable($ends, new DateTimeZone('Europe/Athens'));

    return new BookingItemSnapshot(
        $sort,
        7,
        'Haircut',
        3,
        'Elena Papadaki',
        false,
        $end->sub(new DateInterval('PT1H')),
        $end,
        new Money(3500, Currency::of('EUR')),
    );
}

function snapshotOf(BookingItemSnapshot ...$items): BookingSnapshot
{
    return new BookingSnapshot(
        '7b7f0e5a-0000-4000-8000-000000000001',
        BookingStatus::Completed,
        null,
        null,
        new Customer('Casey Customer', 'casey@example.com', null),
        new Money(3500, Currency::of('EUR')),
        array_values($items),
    );
}

it('ends when its only segment ends', function () {
    expect(snapshotOf(snapshotItem(0, '2026-11-03 10:00'))->endsAt())
        ->toEqual(new DateTimeImmutable('2026-11-03 10:00', new DateTimeZone('Europe/Athens')));
});

it('ends at the latest end across a chain and never at the first segment\'s', function () {
    $athens = new DateTimeZone('Europe/Athens');
    $chain = snapshotOf(
        snapshotItem(0, '2026-11-03 10:00'),
        snapshotItem(1, '2026-11-03 13:00'),
        snapshotItem(2, '2026-11-03 12:00'),
    );

    expect($chain->endsAt())->toEqual(new DateTimeImmutable('2026-11-03 13:00', $athens));
});

it('begins when its only segment begins', function () {
    expect(snapshotOf(snapshotItem(0, '2026-11-03 11:00'))->startsAt())
        ->toEqual(new DateTimeImmutable('2026-11-03 10:00', new DateTimeZone('Europe/Athens')));
});

it('begins at the earliest start across a chain and never at the first segment by sort', function () {
    $athens = new DateTimeZone('Europe/Athens');
    $chain = snapshotOf(
        snapshotItem(0, '2026-11-03 13:00'),
        snapshotItem(1, '2026-11-03 11:00'),
        snapshotItem(2, '2026-11-03 12:00'),
    );

    expect($chain->startsAt())->toEqual(new DateTimeImmutable('2026-11-03 10:00', $athens));
});

it('refuses a booking with no items at all, since there would be no end to answer', function () {
    expect(fn () => snapshotOf())->toThrow(InvalidArgumentException::class, 'A booking has at least one item.');
});
