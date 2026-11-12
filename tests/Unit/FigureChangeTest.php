<?php

use App\Application\Dto\FigureChange;

/*
 * The comparison every figure on Home carries: the relative change against the period
 * before, one rule for every tile, delivered as facts and never as a sentence.
 */

it('reads a figure against the period before: {0}', function (string $case, int|float|null $now, int|float|null $before, array $read) {
    expect(FigureChange::between($now, $before)->toArray())->toBe($read);
})->with([
    'up' => ['up', 23, 20, ['kind' => 'up', 'percent' => 15]],
    'down' => ['down', 2, 3, ['kind' => 'down', 'percent' => -33]],
    'down to nothing' => ['down to nothing', 0, 4, ['kind' => 'down', 'percent' => -100]],
    'more than double' => ['more than double', 9, 4, ['kind' => 'up', 'percent' => 125]],
    'a half rounded away from zero, up' => ['a half up', 3, 2, ['kind' => 'up', 'percent' => 50]],
    'a half rounded away from zero, down' => ['a half down', 1, 8, ['kind' => 'down', 'percent' => -88]],
    'no change' => ['no change', 6, 6, ['kind' => 'same', 'percent' => 0]],
    'up from zero' => ['up from zero', 1, 0, ['kind' => 'from_zero', 'percent' => null]],
    'both zero' => ['both zero', 0, 0, ['kind' => 'none', 'percent' => null]],
    'a rate up' => ['a rate up', 0.583, 0.561, ['kind' => 'up', 'percent' => 4]],
    'a rate up from zero' => ['a rate up from zero', 0.25, 0.0, ['kind' => 'from_zero', 'percent' => null]],
    'a change under half a percent' => ['under half a percent', 1001, 1000, ['kind' => 'up', 'percent' => 0]],
    'no rate now' => ['no rate now', null, 0.5, ['kind' => 'none', 'percent' => null]],
    'no rate before' => ['no rate before', 0.5, null, ['kind' => 'none', 'percent' => null]],
]);
