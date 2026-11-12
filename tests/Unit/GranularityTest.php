<?php

use App\Domain\Availability\Granularity;
use App\Domain\Availability\InvalidGranularity;

it('defaults to five minutes', function () {
    expect(Granularity::default()->minutes)->toBe(5);
});

it('accepts every divisor of sixty and nothing else between one and sixty', function () {
    $accepted = [];

    foreach (range(1, 60) as $minutes) {
        try {
            $accepted[] = Granularity::minutes($minutes)->minutes;
        } catch (InvalidGranularity) {
        }
    }

    expect($accepted)->toBe([1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60]);
});

it('refuses {minutes} minutes with a typed error naming the input', function (int $minutes) {
    try {
        Granularity::minutes($minutes);
    } catch (InvalidGranularity $e) {
        expect($e->input)->toBe($minutes)
            ->and($e->getMessage())->toBe(sprintf('A granularity is a number of minutes dividing sixty, not %d.', $minutes));

        return;
    }

    $this->fail('No InvalidGranularity was thrown.');
})->with([
    'zero' => [0],
    'negative' => [-5],
    'seven' => [7],
    'ninety' => [90],
    'a hundred and twenty, a multiple rather than a divisor' => [120],
]);

it('rounds {minutes} minutes at {granularity} up to {expected} whole slots', function (int $minutes, int $granularity, int $expected) {
    expect(Granularity::minutes($granularity)->slotsFor($minutes))->toBe($expected);
})->with([
    'fifty at fifteen' => [50, 15, 4],
    'five at five' => [5, 5, 1],
    'zero' => [0, 5, 0],
    'one at five' => [1, 5, 1],
    'sixty at fifteen, exact' => [60, 15, 4],
    'sixty-one at fifteen' => [61, 15, 5],
    'one at sixty' => [1, 60, 1],
    'a full day at one' => [1440, 1, 1440],
    'a full day at five' => [1440, 5, 288],
]);

it('refuses a negative duration', function () {
    expect(fn () => Granularity::default()->slotsFor(-1))
        ->toThrow(InvalidArgumentException::class, 'A duration is a number of minutes at or above zero, not -1.');
});

it('is a value: two of the same minutes are equal, two of different minutes are not', function () {
    expect(Granularity::minutes(5)->equals(Granularity::default()))->toBeTrue()
        ->and(Granularity::minutes(5)->equals(Granularity::minutes(15)))->toBeFalse();
});
