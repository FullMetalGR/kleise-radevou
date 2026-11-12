<?php

use App\Application\Dto\HoldItem;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;

/*
 * One planned item of a hold: the customer-visible range, the stored contended range, the
 * processing end when a gap follows, the price as money, and the shapes the constructor
 * refuses.
 */

function itemInstant(string $text, string $zone = 'UTC'): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone($zone));
}

function itemAt(string $start, string $end, ?string $blockStart = null, ?string $blockEnd = null, ?string $processingEnd = null, int $staffId = 1, int $serviceId = 1, int $price = 2500): HoldItem
{
    return new HoldItem(
        $staffId,
        $serviceId,
        itemInstant($start),
        itemInstant($end),
        itemInstant($blockStart ?? $start),
        itemInstant($blockEnd ?? $end),
        $processingEnd === null ? null : itemInstant($processingEnd),
        new Money($price, Currency::of('EUR')),
    );
}

it('keeps the customer range and the contended range as given, and answers the block on the member', function () {
    $item = itemAt('2026-10-01 09:00', '2026-10-01 10:00', '2026-10-01 08:50', '2026-10-01 10:05', '2026-10-01 10:30');
    $block = $item->block();

    expect($item->start->format('Y-m-d H:i'))->toBe('2026-10-01 09:00')
        ->and($item->end->format('Y-m-d H:i'))->toBe('2026-10-01 10:00')
        ->and($item->processingEndsAt?->format('Y-m-d H:i'))->toBe('2026-10-01 10:30')
        ->and($item->price->minor)->toBe(2500)
        ->and($block->staffId)->toBe(1)
        ->and($block->start->format('Y-m-d H:i'))->toBe('2026-10-01 08:50')
        ->and($block->end->format('Y-m-d H:i'))->toBe('2026-10-01 10:05')
        ->and(itemAt('2026-10-01 09:00', '2026-10-01 10:00')->processingEndsAt)->toBeNull();
});

it('lets the block range equal the customer range', function () {
    $block = itemAt('2026-10-01 09:00', '2026-10-01 10:00')->block();

    expect($block->start->getTimestamp())->toBe(itemInstant('2026-10-01 09:00')->getTimestamp())
        ->and($block->end->getTimestamp())->toBe(itemInstant('2026-10-01 10:00')->getTimestamp());
});

it('refuses {shape}', function (Closure $build, string $message) {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a staff id below one' => [fn () => itemAt('2026-10-01 09:00', '2026-10-01 10:00', staffId: 0), 'staff id'],
    'a service id below one' => [fn () => itemAt('2026-10-01 09:00', '2026-10-01 10:00', serviceId: 0), 'service id'],
    'an end at the start' => [fn () => itemAt('2026-10-01 09:00', '2026-10-01 09:00'), 'ends after it starts'],
    'an end before the start' => [fn () => itemAt('2026-10-01 10:00', '2026-10-01 09:00'), 'ends after it starts'],
    'a block starting after the start' => [fn () => itemAt('2026-10-01 09:00', '2026-10-01 10:00', blockStart: '2026-10-01 09:05'), 'contains its customer range'],
    'a block ending before the end' => [fn () => itemAt('2026-10-01 09:00', '2026-10-01 10:00', blockEnd: '2026-10-01 09:55'), 'contains its customer range'],
    'a processing end at the end' => [fn () => itemAt('2026-10-01 09:00', '2026-10-01 10:00', processingEnd: '2026-10-01 10:00'), 'processing gap ends after'],
    'a negative price' => [fn () => itemAt('2026-10-01 09:00', '2026-10-01 10:00', price: -1), 'zero or more minor units'],
]);
