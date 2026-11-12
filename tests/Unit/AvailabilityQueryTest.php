<?php

use App\Application\Dto\AvailabilityQuery;
use App\Application\Dto\SegmentChoice;
use App\Domain\Chain\Chain;

function choice(int $serviceId = 1, ?int $staffId = null): SegmentChoice
{
    return new SegmentChoice($serviceId, $staffId);
}

it('is the chain as a list of choices, a from date, a to date with to exclusive, and the same-staff preference off unless given', function () {
    $query = new AvailabilityQuery([choice(1, 5), choice(2)], '2026-04-06', '2026-04-13');

    expect($query->items)->toHaveCount(2)
        ->and($query->items[0]->serviceId)->toBe(1)
        ->and($query->items[0]->staffId)->toBe(5)
        ->and($query->items[1]->staffId)->toBeNull()
        ->and($query->from)->toBe('2026-04-06')
        ->and($query->to)->toBe('2026-04-13')
        ->and($query->sameStaff)->toBeFalse()
        ->and((new AvailabilityQuery([choice()], '2026-04-06', '2026-04-07', true))->sameStaff)->toBeTrue();
});

it('answers every day from from up to but excluding to, in order, and how many there are', function () {
    $week = new AvailabilityQuery([choice()], '2026-04-06', '2026-04-13');
    $one = new AvailabilityQuery([choice()], '2026-12-31', '2027-01-01');

    expect($week->days())->toBe(['2026-04-06', '2026-04-07', '2026-04-08', '2026-04-09', '2026-04-10', '2026-04-11', '2026-04-12'])
        ->and($week->dayCount())->toBe(7)
        ->and($one->days())->toBe(['2026-12-31'])
        ->and($one->dayCount())->toBe(1);
});

it('names the services asked for, each once, in first-seen order', function () {
    expect((new AvailabilityQuery([choice(3, 1), choice(1), choice(3, 2), choice(2)], '2026-04-06', '2026-04-07'))->serviceIds())->toBe([3, 1, 2]);
});

it('refuses a chain of no items or of more than the cap', function () {
    expect(fn () => new AvailabilityQuery([], '2026-04-06', '2026-04-07'))
        ->toThrow(InvalidArgumentException::class, 'A query names at least one item.')
        ->and(fn () => new AvailabilityQuery(array_fill(0, Chain::MAX_SEGMENTS + 1, choice()), '2026-04-06', '2026-04-07'))
        ->toThrow(InvalidArgumentException::class, 'A query names at most 5 items, not 6.');
});

it('refuses a to at or before from, a misspelled date, and a span past 62 days, and allows exactly 62', function () {
    expect(fn () => new AvailabilityQuery([choice()], '2026-04-06', '2026-04-06'))
        ->toThrow(InvalidArgumentException::class, 'A query ends after it starts, not from 2026-04-06 to 2026-04-06.')
        ->and(fn () => new AvailabilityQuery([choice()], '2026-04-07', '2026-04-06'))
        ->toThrow(InvalidArgumentException::class, 'A query ends after it starts, not from 2026-04-07 to 2026-04-06.')
        ->and(fn () => new AvailabilityQuery([choice()], '2026-4-6', '2026-04-07'))
        ->toThrow(InvalidArgumentException::class, 'A day is spelled YYYY-MM-DD, not "2026-4-6".')
        ->and(fn () => new AvailabilityQuery([choice()], '2026-04-06', '2026-06-08'))
        ->toThrow(InvalidArgumentException::class, 'A query spans at most 62 days, not 63.')
        ->and((new AvailabilityQuery([choice()], '2026-04-06', '2026-06-07'))->dayCount())->toBe(AvailabilityQuery::MAX_DAYS)
        ->and(AvailabilityQuery::MAX_DAYS)->toBe(62);
});

it('refuses a service id or a staff id below one on a choice, and takes null as no preference', function () {
    expect(fn () => choice(0))->toThrow(InvalidArgumentException::class, 'A service id is a positive integer, not 0.')
        ->and(fn () => choice(1, 0))->toThrow(InvalidArgumentException::class, 'A staff id is a positive integer, not 0.')
        ->and(choice(1, null)->staffId)->toBeNull();
});
