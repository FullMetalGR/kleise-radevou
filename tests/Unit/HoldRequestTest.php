<?php

use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Domain\Chain\Chain;
use App\Domain\Enum\BookingSource;

/*
 * What a customer asks for: the chain as choices, a start, the preference, within the bounds
 * the HTTP boundary enforces.
 */

function requestStart(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-03 09:00', new DateTimeZone('Europe/Athens'));
}

it('carries the customer, the choices in order, the start and the preference', function () {
    $request = new HoldRequest(new Customer('Casey Customer', 'casey@example.com'), [new SegmentChoice(3, null), new SegmentChoice(1, 2), new SegmentChoice(3, 2)], requestStart(), new Intake(BookingSource::Embed), true);

    expect($request->items)->toHaveCount(3)
        ->and($request->items[1]->staffId)->toBe(2)
        ->and($request->serviceIds())->toBe([3, 1])
        ->and($request->start->getTimestamp())->toBe(requestStart()->getTimestamp())
        ->and($request->sameStaff)->toBeTrue()
        ->and((new HoldRequest(new Customer('Casey Customer', 'casey@example.com'), [new SegmentChoice(3, null)], requestStart(), new Intake(BookingSource::Embed)))->sameStaff)->toBeFalse();
});

it('needs at least one item and at most the chain cap', function () {
    $tooMany = array_fill(0, Chain::MAX_SEGMENTS + 1, new SegmentChoice(1, null));

    expect(fn () => new HoldRequest(new Customer('Casey Customer', 'casey@example.com'), [], requestStart(), new Intake(BookingSource::Embed)))->toThrow(InvalidArgumentException::class, 'at least one item')
        ->and(fn () => new HoldRequest(new Customer('Casey Customer', 'casey@example.com'), $tooMany, requestStart(), new Intake(BookingSource::Embed)))->toThrow(InvalidArgumentException::class, 'at most 5 items, not 6');
});
