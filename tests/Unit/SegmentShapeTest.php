<?php

use App\Domain\Availability\SegmentShape;

it('is a duration and two buffers in minutes, the buffers zero unless given', function () {
    $shape = new SegmentShape(durationMin: 50, bufferBeforeMin: 10, bufferAfterMin: 15);
    $bare = new SegmentShape(30);

    expect($shape->durationMin)->toBe(50)
        ->and($shape->bufferBeforeMin)->toBe(10)
        ->and($shape->bufferAfterMin)->toBe(15)
        ->and($bare->durationMin)->toBe(30)
        ->and($bare->bufferBeforeMin)->toBe(0)
        ->and($bare->bufferAfterMin)->toBe(0);
});

it('refuses a duration below one minute', function (int $minutes) {
    expect(fn () => new SegmentShape($minutes))->toThrow(InvalidArgumentException::class, sprintf('A segment lasts at least one minute, not %d.', $minutes));
})->with(['zero' => [0], 'negative' => [-30]]);

it('refuses a negative buffer on either side', function () {
    expect(fn () => new SegmentShape(30, -5, 0))->toThrow(InvalidArgumentException::class, 'A buffer is a number of minutes at or above zero, not -5.');
    expect(fn () => new SegmentShape(30, 0, -1))->toThrow(InvalidArgumentException::class, 'A buffer is a number of minutes at or above zero, not -1.');
});

it('carries no processing gap: the gap belongs to the chain and a segment booked alone blocks nothing after its end', function () {
    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(SegmentShape::class, '__construct'))->getParameters(),
    );

    expect($parameters)->toBe(['durationMin', 'bufferBeforeMin', 'bufferAfterMin']);
});
