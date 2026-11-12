<?php

use App\Application\Dto\Customer;
use App\Application\Dto\HoldItem;
use App\Application\Dto\HoldPlan;
use App\Application\Dto\Intake;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatch;
use App\Domain\Money\Money;

/*
 * The plan the protocol takes: at least one item, none of them contending with another of the
 * same plan, the blocks in segment order, the total re-summed from the prices in one currency,
 * and the copied columns as given.
 */

function planItem(int $staffId, string $start, string $end, ?string $blockStart = null, ?string $blockEnd = null, int $price = 2500, string $currency = 'EUR'): HoldItem
{
    $utc = new DateTimeZone('UTC');

    return new HoldItem(
        $staffId,
        1,
        new DateTimeImmutable($start, $utc),
        new DateTimeImmutable($end, $utc),
        new DateTimeImmutable($blockStart ?? $start, $utc),
        new DateTimeImmutable($blockEnd ?? $end, $utc),
        null,
        new Money($price, Currency::of($currency)),
    );
}

function planCustomer(): Customer
{
    return new Customer('Casey Customer', 'casey@example.com');
}

/**
 * @param  list<HoldItem>  $items
 */
function planOf(array $items, BookingStatus $status = BookingStatus::Pending, HoldClass $class = HoldClass::Checkout, int $ttl = 15): HoldPlan
{
    return new HoldPlan(planCustomer(), $items, $status, $class, $ttl, PaymentMode::Onsite, false, ApprovalTimeout::Expire, new Intake(BookingSource::Embed));
}

it('needs at least one item', function () {
    expect(fn () => planOf([]))->toThrow(InvalidArgumentException::class, 'at least one item');
});

it('refuses two segments occupying one staff member at the same time, naming both', function () {
    expect(fn () => planOf([
        planItem(1, '2026-10-01 09:00', '2026-10-01 10:00'),
        planItem(2, '2026-10-01 10:00', '2026-10-01 11:00'),
        planItem(1, '2026-10-01 09:30', '2026-10-01 10:30'),
    ]))->toThrow(InvalidArgumentException::class, 'Segments 0 and 2 occupy staff member 1');
});

it('judges self-contention on the block ranges, buffers included', function () {
    expect(fn () => planOf([
        planItem(1, '2026-10-01 09:00', '2026-10-01 10:00', blockEnd: '2026-10-01 10:15'),
        planItem(1, '2026-10-01 10:10', '2026-10-01 11:00'),
    ]))->toThrow(InvalidArgumentException::class, 'Segments 0 and 1');
});

it('accepts adjacent segments on one staff member, since the ranges are half-open, and the same range on different members', function () {
    expect(planOf([
        planItem(1, '2026-10-01 09:00', '2026-10-01 10:00'),
        planItem(1, '2026-10-01 10:00', '2026-10-01 11:00'),
    ])->items)->toHaveCount(2)
        ->and(planOf([
            planItem(1, '2026-10-01 09:00', '2026-10-01 10:00'),
            planItem(2, '2026-10-01 09:00', '2026-10-01 10:00'),
        ])->items)->toHaveCount(2);
});

it('hands the blocks back in segment order, so a segment index reads both lists', function () {
    $plan = planOf([
        planItem(2, '2026-10-01 12:00', '2026-10-01 13:00', blockStart: '2026-10-01 11:50'),
        planItem(1, '2026-10-01 09:00', '2026-10-01 10:00', blockEnd: '2026-10-01 10:05'),
    ]);

    expect(array_map(fn (StaffBlock $block): string => $block->staffId.' '.$block->start->format('H:i').'-'.$block->end->format('H:i'), $plan->blocks()))
        ->toBe(['2 11:50-13:00', '1 09:00-10:05']);
});

it('re-sums the total from the item prices in their one currency', function () {
    $plan = planOf([
        planItem(1, '2026-10-01 09:00', '2026-10-01 10:00', price: 2500),
        planItem(2, '2026-10-01 09:00', '2026-10-01 10:00', price: 1500),
        planItem(3, '2026-10-01 09:00', '2026-10-01 10:00', price: 0),
    ]);

    expect($plan->total->minor)->toBe(4000)
        ->and($plan->total->currency->code)->toBe('EUR');
});

it('refuses a chain mixing two currencies as it is built', function () {
    expect(fn () => planOf([
        planItem(1, '2026-10-01 09:00', '2026-10-01 10:00', currency: 'EUR'),
        planItem(2, '2026-10-01 09:00', '2026-10-01 10:00', currency: 'GBP'),
    ]))->toThrow(CurrencyMismatch::class, 'Money in EUR cannot be combined with money in GBP.');
});

it('refuses a lifetime below one minute and a status that is not a held one', function () {
    expect(fn () => planOf([planItem(1, '2026-10-01 09:00', '2026-10-01 10:00')], ttl: 0))->toThrow(InvalidArgumentException::class, 'at least one minute')
        ->and(fn () => planOf([planItem(1, '2026-10-01 09:00', '2026-10-01 10:00')], status: BookingStatus::Confirmed))->toThrow(InvalidArgumentException::class, 'held status');
});

it('carries the copied columns as given', function () {
    $plan = new HoldPlan(planCustomer(), [planItem(1, '2026-10-01 09:00', '2026-10-01 10:00')], BookingStatus::AwaitingApproval, HoldClass::Approval, 360, PaymentMode::Online, true, ApprovalTimeout::AutoApprove, new Intake(BookingSource::Embed));

    expect($plan->status)->toBe(BookingStatus::AwaitingApproval)
        ->and($plan->holdClass)->toBe(HoldClass::Approval)
        ->and($plan->ttlMinutes)->toBe(360)
        ->and($plan->paymentMode)->toBe(PaymentMode::Online)
        ->and($plan->requiresApproval)->toBeTrue()
        ->and($plan->onApprovalTimeout)->toBe(ApprovalTimeout::AutoApprove);
});

it('needs a customer with a name and an email address', function () {
    expect(fn () => new Customer('', 'casey@example.com'))->toThrow(InvalidArgumentException::class, 'name')
        ->and(fn () => new Customer('Casey Customer', ' '))->toThrow(InvalidArgumentException::class, 'email')
        ->and((new Customer('Casey Customer', 'casey@example.com'))->phone)->toBeNull();
});

it('proves its chain once and hands that same chain on, so the write reads the object the rules were established against', function () {
    $plan = planOf([planItem(1, '09:00', '09:30'), planItem(2, '10:00', '10:30')]);

    expect($plan->chain->items)->toBe($plan->items)
        ->and($plan->chain->total())->toEqual($plan->total)
        ->and($plan->chain->blocks())->toEqual($plan->blocks())
        // The same object, not an equal one: a second chain over the same items would re-run
        // the self-contention check and the currency sum that building the plan already ran.
        ->and($plan->chain)->toBe($plan->chain);
});
