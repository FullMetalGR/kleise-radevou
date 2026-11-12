<?php

declare(strict_types=1);

use App\Application\Payment\PlatformFee;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;

/*
 * The platform's fee: a percentage of the booking's total, a fixed amount in the total's own
 * currency and minor units, or both, read from configuration and never from a tenant row. Unset,
 * or set to anything it cannot read, it is no fee at all, and platform mode is then not offered.
 * It comes out of the tenant's side and is never more than the total.
 */

function feeOn(?PlatformFee $fee, int $minor, string $currency): int
{
    assert($fee !== null);

    return $fee->on(new Money($minor, Currency::of($currency)))->minor;
}

it('reads nothing configured, and anything it cannot read, as no fee', function (mixed $configured) {
    expect(PlatformFee::of($configured))->toBeNull();
})->with([
    'nothing' => [null],
    'both unset' => [['percent' => null, 'fixed' => null]],
    'both empty' => [['percent' => '', 'fixed' => '']],
    'a bare string' => ['1.5'],
    'a percentage over a hundred' => [['percent' => '100.01']],
    'a negative percentage' => [['percent' => '-1']],
    'three decimal places' => [['percent' => '1.555']],
    'a percentage with its sign' => [['percent' => '1.5%']],
    'a float' => [['percent' => 1.5]],
    'a fixed amount with no currency' => [['fixed' => '25']],
    'a fixed amount in a lower-case code' => [['fixed' => 'eur:25']],
    'a fixed amount with a decimal point' => [['fixed' => 'EUR:0.25']],
    'a currency named twice' => [['fixed' => 'EUR:25,EUR:30']],
    'a readable percentage beside an unreadable fixed amount' => [['percent' => '1.5', 'fixed' => 'EUR:x']],
]);

it('takes a percentage of the total, rounded half up to the total\'s own minor unit', function () {
    $fee = PlatformFee::of(['percent' => '1.5', 'fixed' => null]);

    expect(feeOn($fee, 5000, 'EUR'))->toBe(75)
        ->and(feeOn($fee, 4999, 'EUR'))->toBe(75)
        ->and(feeOn($fee, 4966, 'EUR'))->toBe(74)
        ->and(feeOn($fee, 1000, 'JPY'))->toBe(15)
        ->and(feeOn($fee, 33, 'JPY'))->toBe(0)
        ->and($fee?->percent())->toBe('1.5')
        ->and($fee?->fixedIn('EUR'))->toBeNull();
});

it('takes a fixed amount in the total\'s own currency, and none in a currency it does not name', function () {
    $fee = PlatformFee::of(['percent' => null, 'fixed' => 'EUR:25, JPY:30']);

    expect(feeOn($fee, 5000, 'EUR'))->toBe(25)
        ->and(feeOn($fee, 5000, 'JPY'))->toBe(30)
        ->and(feeOn($fee, 5000, 'GBP'))->toBe(0)
        ->and($fee?->percent())->toBeNull()
        ->and($fee?->fixedIn('JPY'))->toBe(30)
        ->and($fee?->fixedIn('GBP'))->toBeNull();
});

it('adds the two together, and is never more than the total, a currency with no minor unit included', function () {
    $fee = PlatformFee::of(['percent' => '2.25', 'fixed' => 'EUR:25,JPY:30']);

    expect(feeOn($fee, 10000, 'EUR'))->toBe(250)
        ->and(feeOn($fee, 20, 'EUR'))->toBe(20)
        ->and(feeOn($fee, 0, 'EUR'))->toBe(0)
        ->and(feeOn($fee, 1000, 'JPY'))->toBe(53)
        ->and(feeOn($fee, 31, 'JPY'))->toBe(31)
        ->and(feeOn(PlatformFee::of(['percent' => '100']), 4999, 'JPY'))->toBe(4999)
        ->and(feeOn(PlatformFee::of(['percent' => '100', 'fixed' => 'JPY:500']), 4999, 'JPY'))->toBe(4999);
});

it('reads a fee of zero as a fee, so the mode is offered and takes nothing', function () {
    $fee = PlatformFee::of(['percent' => '0']);

    expect($fee)->not->toBeNull()
        ->and(feeOn($fee, 5000, 'EUR'))->toBe(0)
        ->and($fee?->percent())->toBe('0')
        ->and(PlatformFee::of(['percent' => '12.50'])?->percent())->toBe('12.5')
        ->and(PlatformFee::of(['percent' => 3])?->percent())->toBe('3');
});
