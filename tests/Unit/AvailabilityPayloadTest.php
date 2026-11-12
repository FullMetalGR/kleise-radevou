<?php

use App\Application\Dto\AvailableStarts;
use App\Http\AvailabilityPayload;

function payloadInstant(string $text, string $zone = 'Europe/Athens'): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone($zone));
}

it('spells every start as an RFC 3339 instant in UTC under its tenant-local day, an empty day as an empty list, in the order given', function () {
    $starts = new AvailableStarts([
        '2026-04-06' => [payloadInstant('2026-04-06 09:00'), payloadInstant('2026-04-06 23:55')],
        '2026-04-07' => [],
        '2026-04-08' => [payloadInstant('2026-04-08 00:00')],
    ]);

    expect(AvailabilityPayload::of($starts))->toBe([
        '2026-04-06' => ['2026-04-06T06:00:00+00:00', '2026-04-06T20:55:00+00:00'],
        '2026-04-07' => [],
        '2026-04-08' => ['2026-04-07T21:00:00+00:00'],
    ]);
});

it('spells a start the same whatever zone the instant arrived in, and keeps the repeated hour distinct', function () {
    $starts = new AvailableStarts([
        '2026-10-25' => [payloadInstant('2026-10-25 03:30:00+03:00'), payloadInstant('2026-10-25 03:30:00+02:00'), payloadInstant('2026-10-25 01:30', 'UTC')],
    ]);

    expect(AvailabilityPayload::of($starts))->toBe([
        '2026-10-25' => ['2026-10-25T00:30:00+00:00', '2026-10-25T01:30:00+00:00', '2026-10-25T01:30:00+00:00'],
    ]);
});

it('encodes as a JSON object keyed by day whose values are lists, so a client reads the keys as the strip and one list as the grid', function () {
    $json = json_encode(AvailabilityPayload::of(new AvailableStarts(['2026-04-06' => [], '2026-04-07' => [payloadInstant('2026-04-07 09:00')]])));

    expect($json)->toBe('{"2026-04-06":[],"2026-04-07":["2026-04-07T06:00:00+00:00"]}');
});

it('spells an empty window as its days and then next_available_from, a tenant-local date or null, the one key no date is spelled like', function () {
    $empty = new AvailableStarts(['2026-04-06' => [], '2026-04-07' => []]);

    expect(json_encode(AvailabilityPayload::emptyWindow($empty, '2026-04-13')))->toBe('{"2026-04-06":[],"2026-04-07":[],"next_available_from":"2026-04-13"}')
        ->and(json_encode(AvailabilityPayload::emptyWindow($empty, null)))->toBe('{"2026-04-06":[],"2026-04-07":[],"next_available_from":null}')
        ->and(AvailabilityPayload::NEXT_AVAILABLE_FROM)->toBe('next_available_from');
});
