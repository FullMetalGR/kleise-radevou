<?php

use App\Application\Dto\BookingAsRead;
use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingRecord;
use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\LockedBooking;
use App\Application\Dto\LockedItem;
use App\Domain\Booking\ServiceWindows;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;

/*
 * A booking as a caller read it, against the same booking as the re-read under its lock finds
 * it: the reading holds while the status, the members, the starts and the contended ranges are
 * the ones read, and stops holding the moment any one of them is not.
 *
 * The booking is a Cut on member 3 from 09:00 to 09:45, its range starting a quarter of an hour
 * before it, then a Colour on member 4 from 10:00 to 11:00, on 2026-11-03, a Tuesday. A record
 * spells its ranges as the API does, RFC 3339 in UTC, and the re-read hands back instants.
 */

const AS_READ_ITEMS = [[3, '09:00', '08:45', '09:45'], [4, '10:00', '10:00', '11:00']];

function asReadInstant(string $time, string $zone = 'UTC'): DateTimeImmutable
{
    return (new DateTimeImmutable('2026-11-03 '.$time.':00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone));
}

/**
 * The booking as a reader hands it over.
 *
 * @param  list<array<string, string|int|bool|null>>|null  $itemOperations  each item's operational columns, the ranges of the booking above where none are given
 */
function asReadRecord(BookingStatus $status = BookingStatus::Confirmed, ?array $itemOperations = null): BookingRecord
{
    $euro = Currency::of('EUR');

    return new BookingRecord(
        new BookingSnapshot('11111111-0000-4000-8000-000000000001', $status, null, null, null, new Money(6000, $euro), [
            new BookingItemSnapshot(0, 1, 'Cut', 3, 'Elena', false, asReadInstant('09:00'), asReadInstant('09:45'), new Money(2500, $euro)),
            new BookingItemSnapshot(1, 2, 'Colour', 4, 'Nikos', false, asReadInstant('10:00'), asReadInstant('11:00'), new Money(3500, $euro)),
        ]),
        [],
        $itemOperations ?? [
            ['block_start_utc' => '2026-11-03T08:45:00+00:00', 'block_end_utc' => '2026-11-03T09:45:00+00:00'],
            ['block_start_utc' => '2026-11-03T10:00:00+00:00', 'block_end_utc' => '2026-11-03T11:00:00+00:00'],
        ],
        false,
    );
}

/**
 * The booking as the lock finds it, each item as its member, its start, and where its range
 * starts and ends.
 *
 * @param  list<array{int, string, string, string}>  $items
 */
function asReadFound(BookingStatus $status = BookingStatus::Confirmed, array $items = AS_READ_ITEMS, string $zone = 'UTC'): LockedBooking
{
    return new LockedBooking(
        '11111111-0000-4000-8000-000000000001',
        $status,
        null,
        false,
        PaymentMode::Onsite,
        array_map(fn (array $item): LockedItem => new LockedItem(
            new StaffBlock($item[0], asReadInstant($item[2], $zone), asReadInstant($item[3], $zone)),
            asReadInstant($item[1], $zone),
            new ServiceWindows(24, 24),
        ), $items),
    );
}

it('holds while the booking found under the lock is the one read, in whatever zone its instants are written', function () {
    $read = BookingAsRead::of(asReadRecord());

    expect($read->status)->toBe(BookingStatus::Confirmed)
        ->and($read->items)->toHaveCount(2)
        ->and($read->stillHolds(asReadFound()))->toBeTrue()
        ->and($read->stillHolds(asReadFound(zone: 'Europe/Athens')))->toBeTrue();
});

it('stops holding once the booking found differs from the one read in its status, a member, a start, a range or its items', function (LockedBooking $found) {
    expect(BookingAsRead::of(asReadRecord())->stillHolds($found))->toBeFalse();
})->with([
    'another status' => [asReadFound(BookingStatus::Cancelled)],
    'the first item on another member' => [asReadFound(items: [[5, '09:00', '08:45', '09:45'], AS_READ_ITEMS[1]])],
    'the first item at another start over the same range' => [asReadFound(items: [[3, '09:15', '08:45', '09:45'], AS_READ_ITEMS[1]])],
    'the first item\'s range starting earlier' => [asReadFound(items: [[3, '09:00', '08:30', '09:45'], AS_READ_ITEMS[1]])],
    'the first item\'s range ending later' => [asReadFound(items: [[3, '09:00', '08:45', '10:00'], AS_READ_ITEMS[1]])],
    'the last item moved alone' => [asReadFound(items: [AS_READ_ITEMS[0], [4, '11:00', '11:00', '12:00']])],
    'an item gone' => [asReadFound(items: [AS_READ_ITEMS[0]])],
    'an item more' => [asReadFound(items: [...AS_READ_ITEMS, [4, '11:00', '11:00', '12:00']])],
]);

it('reads the status a record was read in, so a booking read while it waited does not hold once it is confirmed', function () {
    $read = BookingAsRead::of(asReadRecord(BookingStatus::AwaitingPayment));

    expect($read->stillHolds(asReadFound(BookingStatus::AwaitingPayment)))->toBeTrue()
        ->and($read->stillHolds(asReadFound(BookingStatus::Confirmed)))->toBeFalse();
});

it('states no reading of a record whose item carries no contended range', function (BookingRecord $record) {
    expect(fn () => BookingAsRead::of($record))
        ->toThrow(UnexpectedValueException::class, 'carries no contended range');
})->with([
    'no operational columns at all' => [asReadRecord(itemOperations: [])],
    'the last item without its range' => [asReadRecord(itemOperations: [['block_start_utc' => '2026-11-03T08:45:00+00:00', 'block_end_utc' => '2026-11-03T09:45:00+00:00']])],
    'a range with no end' => [asReadRecord(itemOperations: [
        ['block_start_utc' => '2026-11-03T08:45:00+00:00', 'block_end_utc' => null],
        ['block_start_utc' => '2026-11-03T10:00:00+00:00', 'block_end_utc' => '2026-11-03T11:00:00+00:00'],
    ])],
]);
