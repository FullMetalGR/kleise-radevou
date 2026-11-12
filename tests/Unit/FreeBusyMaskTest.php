<?php

use App\Domain\Availability\FreeBusyMask;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A mask of the width with exactly the given indices set.
 *
 * @param  list<int>  $indices
 */
function maskOf(int $width, array $indices): FreeBusyMask
{
    $mask = FreeBusyMask::allClear($width);

    foreach ($indices as $index) {
        $mask = $mask->set($index, $index + 1);
    }

    return $mask;
}

/**
 * The three widths every operation is held to: one day at five minutes, one day at one
 * minute, and a sixty-day window at five minutes. None is a multiple of 64 except by
 * accident, so the top word is partial in each.
 */
dataset('widths', ['one day at five minutes' => [288], 'one day at one minute' => [1440], 'sixty days at five minutes' => [17280]]);

it('is all clear or all set at a width, and knows its width', function (int $width) {
    $clear = FreeBusyMask::allClear($width);
    $set = FreeBusyMask::allSet($width);

    expect($clear->width)->toBe($width)
        ->and($clear->setIndices())->toBe([])
        ->and($set->width)->toBe($width)
        ->and($set->setIndices())->toBe(range(0, $width - 1))
        ->and($set->isSet(0))->toBeTrue()
        ->and($set->isSet($width - 1))->toBeTrue()
        ->and($clear->isSet(0))->toBeFalse()
        ->and($clear->isSet($width - 1))->toBeFalse();
})->with('widths');

it('refuses a width below one', function (int $width) {
    expect(fn () => FreeBusyMask::allClear($width))->toThrow(InvalidArgumentException::class, sprintf('A mask is at least one slot wide, not %d.', $width));
    expect(fn () => FreeBusyMask::allSet($width))->toThrow(InvalidArgumentException::class, sprintf('A mask is at least one slot wide, not %d.', $width));
})->with(['zero' => [0], 'negative' => [-288]]);

it('sets a half-open range across a word boundary and leaves the rest clear', function (int $width) {
    $mask = FreeBusyMask::allClear($width)->set(60, 70);

    expect($mask->setIndices())->toBe(range(60, 69))
        ->and($mask->isSet(59))->toBeFalse()
        ->and($mask->isSet(60))->toBeTrue()
        ->and($mask->isSet(63))->toBeTrue()
        ->and($mask->isSet(64))->toBeTrue()
        ->and($mask->isSet(69))->toBeTrue()
        ->and($mask->isSet(70))->toBeFalse();
})->with('widths');

it('sets ranges that end on, start on and span whole word boundaries', function () {
    expect(FreeBusyMask::allClear(288)->set(0, 64)->setIndices())->toBe(range(0, 63))
        ->and(FreeBusyMask::allClear(288)->set(64, 128)->setIndices())->toBe(range(64, 127))
        ->and(FreeBusyMask::allClear(288)->set(63, 65)->setIndices())->toBe([63, 64])
        ->and(FreeBusyMask::allClear(288)->set(1, 200)->setIndices())->toBe(range(1, 199))
        ->and(FreeBusyMask::allClear(288)->set(0, 288)->setIndices())->toBe(range(0, 287))
        ->and(FreeBusyMask::allClear(288)->set(287, 288)->setIndices())->toBe([287])
        ->and(FreeBusyMask::allClear(17280)->set(17000, 17280)->setIndices())->toBe(range(17000, 17279))
        ->and(FreeBusyMask::allClear(1)->set(0, 1)->setIndices())->toBe([0]);
});

it('clears a half-open range across a word boundary and leaves the rest set', function (int $width) {
    $mask = FreeBusyMask::allSet($width)->clear(60, 70);

    expect($mask->setIndices())->toBe([...range(0, 59), ...range(70, $width - 1)])
        ->and($mask->isSet(63))->toBeFalse()
        ->and($mask->isSet(64))->toBeFalse();
})->with('widths');

it('treats an empty range as a no-op and leaves the mask as it was', function () {
    $mask = maskOf(288, [5, 100]);

    expect($mask->set(50, 50)->equals($mask))->toBeTrue()
        ->and($mask->clear(5, 5)->equals($mask))->toBeTrue()
        ->and($mask->set(288, 288)->equals($mask))->toBeTrue();
});

it('is a value: an operation returns a new mask and leaves the receiver as it was', function () {
    $original = FreeBusyMask::allClear(288);
    $changed = $original->set(0, 10);

    expect($original->setIndices())->toBe([])
        ->and($changed->setIndices())->toBe(range(0, 9))
        ->and($original->equals($changed))->toBeFalse()
        ->and($changed->equals(maskOf(288, range(0, 9))))->toBeTrue()
        ->and($changed->equals(FreeBusyMask::allClear(289)->set(0, 10)))->toBeFalse();
});

it('refuses a range that is inverted or leaves the mask: {from} to {to}', function (int $from, int $to) {
    $message = sprintf('A slot range on a mask of width 288 is half-open between 0 and 288, not %d to %d.', $from, $to);

    expect(fn () => FreeBusyMask::allClear(288)->set($from, $to))->toThrow(InvalidArgumentException::class, $message);
    expect(fn () => FreeBusyMask::allSet(288)->clear($from, $to))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'inverted' => [10, 5],
    'below zero' => [-1, 5],
    'past the width' => [280, 289],
    'starting at the width' => [288, 289],
]);

it('refuses to read an index outside the mask', function () {
    expect(fn () => FreeBusyMask::allSet(288)->isSet(288))->toThrow(InvalidArgumentException::class, 'A slot index on a mask of width 288 lies between 0 and 287, not 288.');
    expect(fn () => FreeBusyMask::allSet(288)->isSet(-1))->toThrow(InvalidArgumentException::class, 'A slot index on a mask of width 288 lies between 0 and 287, not -1.');
});

it('ands, ors and nots at width {0}', function (int $width) {
    $left = FreeBusyMask::allClear($width)->set(60, 70)->set($width - 3, $width);
    $right = FreeBusyMask::allClear($width)->set(65, 130)->set(0, 1);

    expect($left->and($right)->setIndices())->toBe(range(65, 69))
        ->and($left->or($right)->setIndices())->toBe([0, ...range(60, 129), ...range($width - 3, $width - 1)])
        ->and($left->not()->setIndices())->toBe([...range(0, 59), ...range(70, $width - 4)])
        ->and($left->not()->not()->equals($left))->toBeTrue()
        ->and($left->and($left->not())->setIndices())->toBe([])
        ->and($left->or($left->not())->equals(FreeBusyMask::allSet($width)))->toBeTrue();
})->with('widths');

it('keeps the bits past the width clear after a not, so nothing above the width can leak in', function () {
    $mask = FreeBusyMask::allClear(70)->not();

    expect($mask->setIndices())->toBe(range(0, 69))
        ->and($mask->equals(FreeBusyMask::allSet(70)))->toBeTrue()
        ->and($mask->not()->setIndices())->toBe([])
        ->and(FreeBusyMask::allClear(64)->not()->equals(FreeBusyMask::allSet(64)))->toBeTrue()
        ->and(FreeBusyMask::allClear(65)->not()->equals(FreeBusyMask::allSet(65)))->toBeTrue()
        ->and(FreeBusyMask::allClear(1)->not()->equals(FreeBusyMask::allSet(1)))->toBeTrue();
});

it('refuses to and or or two masks of different widths', function () {
    $message = 'Masks of widths 288 and 276 cannot be combined.';

    expect(fn () => FreeBusyMask::allSet(288)->and(FreeBusyMask::allSet(276)))->toThrow(InvalidArgumentException::class, $message);
    expect(fn () => FreeBusyMask::allSet(288)->or(FreeBusyMask::allSet(276)))->toThrow(InvalidArgumentException::class, $message);
});

it('shifts toward higher indices by {1} with zero fill at the bottom and bits falling off the top, at width {0}', function (int $width, int $by, array $expected) {
    $mask = maskOf($width, [0, 62, 63, 64, 100, $width - 1]);

    expect($mask->shiftToHigher($by)->setIndices())->toBe($by === 0 ? [...$expected, $width - 1] : $expected);
})->with('widths')->with([
    'zero' => [0, [0, 62, 63, 64, 100]],
    'three, across the first word boundary' => [3, [3, 65, 66, 67, 103]],
    'a whole word' => [64, [64, 126, 127, 128, 164]],
    'a whole word and one' => [65, [65, 127, 128, 129, 165]],
    'two words and three' => [131, [131, 193, 194, 195, 231]],
]);

it('shifts toward lower indices by {1} with zero fill at the top and bits falling off the bottom, at width {0}', function (int $width, int $by, array $expected) {
    $mask = maskOf($width, [0, 62, 63, 64, 100, $width - 1]);

    expect($mask->shiftToLower($by)->setIndices())->toBe([...$expected, $width - 1 - $by]);
})->with('widths')->with([
    'zero' => [0, [0, 62, 63, 64, 100]],
    'three, across the first word boundary' => [3, [59, 60, 61, 97]],
    'a whole word' => [64, [0, 36]],
    'a whole word and one' => [65, [35]],
    'two words and three' => [131, []],
]);

it('shifts by the width less one, by the width and past the width, at width {0}', function (int $width) {
    $mask = maskOf($width, [0, 62, 63, 64, 100, $width - 1]);

    expect($mask->shiftToHigher($width - 1)->setIndices())->toBe([$width - 1])
        ->and($mask->shiftToHigher($width)->setIndices())->toBe([])
        ->and($mask->shiftToHigher($width + 3)->setIndices())->toBe([])
        ->and($mask->shiftToLower($width - 1)->setIndices())->toBe([0])
        ->and($mask->shiftToLower($width)->setIndices())->toBe([])
        ->and($mask->shiftToLower($width + 3)->setIndices())->toBe([]);
})->with('widths');

it('never wraps a bit from one end to the other, a top bit of a word included', function () {
    expect(FreeBusyMask::allSet(288)->shiftToHigher(1)->setIndices())->toBe(range(1, 287))
        ->and(FreeBusyMask::allSet(288)->shiftToLower(1)->setIndices())->toBe(range(0, 286))
        ->and(FreeBusyMask::allSet(64)->shiftToLower(1)->setIndices())->toBe(range(0, 62))
        ->and(FreeBusyMask::allSet(64)->shiftToLower(63)->setIndices())->toBe([0])
        ->and(FreeBusyMask::allSet(64)->shiftToHigher(1)->setIndices())->toBe(range(1, 63))
        ->and(FreeBusyMask::allSet(64)->shiftToHigher(63)->setIndices())->toBe([63])
        ->and(maskOf(128, [63])->shiftToLower(1)->setIndices())->toBe([62])
        ->and(maskOf(128, [63])->shiftToHigher(1)->setIndices())->toBe([64])
        ->and(maskOf(128, [127])->shiftToLower(64)->setIndices())->toBe([63])
        ->and(FreeBusyMask::allSet(1)->shiftToLower(1)->setIndices())->toBe([])
        ->and(FreeBusyMask::allSet(1)->shiftToHigher(1)->setIndices())->toBe([]);
});

it('refuses a negative shift', function () {
    expect(fn () => FreeBusyMask::allSet(288)->shiftToLower(-1))->toThrow(InvalidArgumentException::class, 'A shift is a number of slots at or above zero, not -1.');
    expect(fn () => FreeBusyMask::allSet(288)->shiftToHigher(-1))->toThrow(InvalidArgumentException::class, 'A shift is a number of slots at or above zero, not -1.');
});

it('concatenates two masks so that slot k of the second sits at the width of the first plus k', function () {
    $first = maskOf(288, [5, 287]);
    $second = maskOf(276, [0, 3, 275]);

    $window = $first->concat($second);

    expect($window->width)->toBe(564)
        ->and($window->setIndices())->toBe([5, 287, 288, 291, 563])
        ->and($window->not()->setIndices())->toHaveCount(564 - 5)
        ->and($second->concat($first)->setIndices())->toBe([0, 3, 275, 281, 563]);
});

it('concatenates across every kind of word alignment', function () {
    expect(maskOf(64, [63])->concat(maskOf(64, [0]))->setIndices())->toBe([63, 64])
        ->and(maskOf(64, [63])->concat(maskOf(64, [0]))->width)->toBe(128)
        ->and(maskOf(1, [0])->concat(maskOf(1, [0]))->setIndices())->toBe([0, 1])
        ->and(maskOf(1, [0])->concat(maskOf(1, [0]))->width)->toBe(2)
        ->and(maskOf(63, [62])->concat(maskOf(65, [0, 64]))->setIndices())->toBe([62, 63, 127])
        ->and(FreeBusyMask::allSet(100)->concat(FreeBusyMask::allSet(100))->equals(FreeBusyMask::allSet(200)))->toBeTrue()
        ->and(FreeBusyMask::allClear(100)->concat(FreeBusyMask::allSet(100))->setIndices())->toBe(range(100, 199))
        ->and(FreeBusyMask::allSet(288)->concat(FreeBusyMask::allSet(276))->concat(FreeBusyMask::allSet(300))->equals(FreeBusyMask::allSet(864)))->toBeTrue();
});

it('builds a sixty-day window by concatenating days, and the window shifts as one', function () {
    $window = FreeBusyMask::allClear(288);

    for ($day = 1; $day < 60; $day++) {
        $window = $window->concat(maskOf(288, [$day]));
    }

    $expected = [];

    for ($day = 1; $day < 60; $day++) {
        $expected[] = $day * 288 + $day;
    }

    expect($window->width)->toBe(17280)
        ->and($window->setIndices())->toBe($expected)
        ->and($window->shiftToLower(288)->setIndices())->toBe(array_map(fn (int $index): int => $index - 288, $expected));
});

/**
 * The definition runs() is held to: bit i is set when the n bits from i on are all set and
 * all inside the mask. Walked slot by slot, stopping at the first clear bit.
 *
 * @return list<int>
 */
function runsByWalking(FreeBusyMask $mask, int $n): array
{
    $starts = [];

    for ($start = 0; $start + $n <= $mask->width; $start++) {
        $length = 0;

        while ($length < $n && $mask->isSet($start + $length)) {
            $length++;
        }

        if ($length === $n) {
            $starts[] = $start;
        }
    }

    return $starts;
}

it('marks the start of every run of at least n free slots, and bit i reports a run beginning at i', function () {
    $mask = maskOf(20, [0, 1, 2, 3, 6, 7, 10, 11, 12, 13, 14, 15]);

    expect($mask->runs(1)->equals($mask))->toBeTrue()
        ->and($mask->runs(2)->setIndices())->toBe([0, 1, 2, 6, 10, 11, 12, 13, 14])
        ->and($mask->runs(3)->setIndices())->toBe([0, 1, 10, 11, 12, 13])
        ->and($mask->runs(4)->setIndices())->toBe([0, 10, 11, 12])
        ->and($mask->runs(5)->setIndices())->toBe([10, 11])
        ->and($mask->runs(6)->setIndices())->toBe([10])
        ->and($mask->runs(7)->setIndices())->toBe([])
        ->and($mask->runs(20)->setIndices())->toBe([]);
});

it('finds a run that crosses word boundaries', function () {
    $mask = maskOf(288, range(60, 131));

    expect($mask->runs(72)->setIndices())->toBe([60])
        ->and($mask->runs(64)->setIndices())->toBe(range(60, 68))
        ->and($mask->runs(73)->setIndices())->toBe([]);
});

it('refuses n below one', function (int $n) {
    expect(fn () => FreeBusyMask::allSet(288)->runs($n))->toThrow(InvalidArgumentException::class, sprintf('A run is at least one slot long, not %d.', $n));
})->with(['zero' => [0], 'negative' => [-5]]);

it('answers n equal to the width with the first bit alone, and n above the width with an empty mask', function () {
    expect(FreeBusyMask::allSet(288)->runs(288)->setIndices())->toBe([0])
        ->and(FreeBusyMask::allSet(288)->clear(287, 288)->runs(288)->setIndices())->toBe([])
        ->and(FreeBusyMask::allSet(288)->runs(289)->setIndices())->toBe([])
        ->and(FreeBusyMask::allSet(288)->runs(289)->width)->toBe(288)
        ->and(FreeBusyMask::allSet(288)->runs(100000)->setIndices())->toBe([])
        ->and(FreeBusyMask::allSet(1)->runs(1)->setIndices())->toBe([0])
        ->and(FreeBusyMask::allSet(1)->runs(2)->setIndices())->toBe([])
        ->and(FreeBusyMask::allSet(17280)->runs(17280)->setIndices())->toBe([0])
        ->and(FreeBusyMask::allSet(17280)->runs(17281)->setIndices())->toBe([]);
});

it('agrees with the slot-by-slot walk on a few thousand random masks and run lengths, the width and past it included', function () {
    $random = new Randomizer(new Mt19937(20260921));
    $trials = 0;

    $widths = [...array_fill(0, 3000, null), ...array_fill(0, 40, 1440), ...array_fill(0, 6, 17280)];

    foreach ($widths as $fixedWidth) {
        $width = $fixedWidth ?? $random->getInt(1, 320);
        $mask = FreeBusyMask::allClear($width);

        if ($random->getInt(0, 1) === 0) {
            $density = $random->getFloat(0.0, 1.0);

            for ($index = 0; $index < $width; $index++) {
                if ($random->getFloat(0.0, 1.0) < $density) {
                    $mask = $mask->set($index, $index + 1);
                }
            }
        } else {
            for ($range = $random->getInt(0, 8); $range > 0; $range--) {
                $from = $random->getInt(0, $width - 1);
                $mask = $mask->set($from, $random->getInt($from, $width));
            }
        }

        $n = match ($random->getInt(0, 9)) {
            0 => $width,
            1 => $width + $random->getInt(1, 3),
            2 => 1,
            default => $random->getInt(1, min($width, 400)),
        };

        expect($mask->runs($n)->setIndices())->toBe(runsByWalking($mask, $n), sprintf('runs(%d) on a mask of width %d with %d set bits', $n, $width, count($mask->setIndices())));
        $trials++;
    }

    expect($trials)->toBe(3046);
});

it('answers runs(300) on a sixty-day window in under five milliseconds', function () {
    $window = FreeBusyMask::allSet(17280);

    for ($index = 100; $index < 17280; $index += 700) {
        $window = $window->clear($index, $index + 1);
    }

    $fastest = PHP_INT_MAX;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $started = hrtime(true);
        $result = $window->runs(300);
        $fastest = min($fastest, hrtime(true) - $started);
    }

    expect($result->setIndices())->toBe(runsByWalking($window, 300))
        ->and($fastest)->toBeLessThan(5_000_000, sprintf('runs(300) took %.3f ms at best', $fastest / 1e6));
});

it('round-trips through its words exactly at width {0}, a word with its top bit set included', function (int $width) {
    $mask = FreeBusyMask::allSet($width)->clear(3, 7)->clear($width - 2, $width - 1);

    expect($mask->toWords())->toHaveCount(intdiv($width + 63, 64))
        ->and(FreeBusyMask::fromWords($width, $mask->toWords())->equals($mask))->toBeTrue()
        ->and(FreeBusyMask::fromWords($width, FreeBusyMask::allClear($width)->toWords())->equals(FreeBusyMask::allClear($width)))->toBeTrue()
        ->and(FreeBusyMask::allSet(64)->toWords())->toBe([-1]);
})->with([[64], [276], [288], [300], [1440]]);

it('refuses words of the wrong count for the width, and a word with a bit past the width set', function () {
    expect(fn () => FreeBusyMask::fromWords(288, [0, 0, 0, 0]))
        ->toThrow(InvalidArgumentException::class, 'A mask of width 288 has 5 words, not 4.')
        ->and(fn () => FreeBusyMask::fromWords(288, [0, 0, 0, 0, 0, 0]))
        ->toThrow(InvalidArgumentException::class, 'A mask of width 288 has 5 words, not 6.')
        ->and(fn () => FreeBusyMask::fromWords(288, [0, 0, 0, 0, 1 << 32]))
        ->toThrow(InvalidArgumentException::class, 'A mask of width 288 has no bit past its width.')
        ->and(fn () => FreeBusyMask::fromWords(288, [0, 0, 0, 0, (1 << 32) - 1]))
        ->not->toThrow(InvalidArgumentException::class);
});
