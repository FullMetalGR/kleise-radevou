<?php

use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatch;
use App\Domain\Money\Money;

function eur(int $minor): Money
{
    return new Money($minor, Currency::of('EUR'));
}

function usd(int $minor): Money
{
    return new Money($minor, Currency::of('USD'));
}

it('holds an integer of minor units beside its currency', function () {
    $money = eur(5000);

    expect($money->minor)->toBe(5000)
        ->and($money->currency->code)->toBe('EUR');
});

it('takes its amount as an int and nothing wider, so a float or a decimal string is refused by type', function () {
    $types = [];

    foreach ((new ReflectionMethod(Money::class, '__construct'))->getParameters() as $parameter) {
        $type = $parameter->getType();
        $types[$parameter->getName()] = $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    expect($types)->toBe(['minor' => 'int', 'currency' => Currency::class]);
});

it('has a zero for a currency', function () {
    $zero = Money::zero(Currency::of('JPY'));

    expect($zero->minor)->toBe(0)
        ->and($zero->currency->code)->toBe('JPY');
});

it('adds two amounts of one currency into a new amount and leaves both as they were', function () {
    $left = eur(5000);
    $right = eur(2500);

    $total = $left->add($right);

    expect($total->minor)->toBe(7500)
        ->and($total->currency->code)->toBe('EUR')
        ->and($left->minor)->toBe(5000)
        ->and($right->minor)->toBe(2500);
});

it('adds a negative amount', function () {
    expect(eur(5000)->add(eur(-7000))->minor)->toBe(-2000);
});

it('refuses to add across currencies with a typed error naming both codes', function () {
    try {
        eur(5000)->add(usd(5000));
    } catch (CurrencyMismatch $e) {
        expect($e->left->code)->toBe('EUR')
            ->and($e->right->code)->toBe('USD')
            ->and($e->getMessage())->toBe('Money in EUR cannot be combined with money in USD.');

        return;
    }

    $this->fail('No CurrencyMismatch was thrown.');
});

it('sums a chain to exactly the sum of its item prices in minor units, with no rounding step', function () {
    $prices = [eur(1999), eur(2999), eur(3999), eur(1)];

    $total = Money::sum(Currency::of('EUR'), $prices);

    expect($total->minor)->toBe(8998)
        ->and($total->minor)->toBe(array_sum(array_map(fn (Money $price): int => $price->minor, $prices)))
        ->and($total->currency->code)->toBe('EUR');
});

it('sums an empty list to zero in the currency asked for', function () {
    $total = Money::sum(Currency::of('KWD'), []);

    expect($total->minor)->toBe(0)
        ->and($total->currency->code)->toBe('KWD');
});

it('sums any iterable, a generator included', function () {
    $prices = (function (): Generator {
        yield eur(100);
        yield eur(200);
    })();

    expect(Money::sum(Currency::of('EUR'), $prices)->minor)->toBe(300);
});

it('refuses to sum an amount of another currency, naming the currency asked for and the stray one', function () {
    try {
        Money::sum(Currency::of('EUR'), [eur(100), usd(100)]);
    } catch (CurrencyMismatch $e) {
        expect($e->left->code)->toBe('EUR')
            ->and($e->right->code)->toBe('USD');

        return;
    }

    $this->fail('No CurrencyMismatch was thrown.');
});

it('compares two amounts of one currency as {expected}', function (int $left, int $right, int $expected) {
    expect(eur($left)->compare(eur($right)))->toBe($expected);
})->with([
    'less' => [100, 200, -1],
    'equal' => [200, 200, 0],
    'greater' => [300, 200, 1],
    'negative against zero' => [-1, 0, -1],
]);

it('refuses to compare across currencies', function () {
    expect(fn () => eur(100)->compare(usd(100)))
        ->toThrow(CurrencyMismatch::class, 'Money in EUR cannot be combined with money in USD.');
});

it('equals an amount of the same minor units and currency, and not another amount', function () {
    expect(eur(100)->equals(eur(100)))->toBeTrue()
        ->and(eur(100)->equals(eur(101)))->toBeFalse()
        ->and(eur(0)->equals(Money::zero(Currency::of('EUR'))))->toBeTrue();
});

it('refuses to test equality across currencies rather than answering false', function () {
    expect(fn () => eur(100)->equals(usd(100)))
        ->toThrow(CurrencyMismatch::class, 'Money in EUR cannot be combined with money in USD.');
});

it('renders {minor} {code} as {expected}: a plain decimal with exactly the currency digits', function (int $minor, string $code, string $expected) {
    expect((new Money($minor, Currency::of($code)))->decimal())->toBe($expected);
})->with([
    'whole units' => [5000, 'JPY', '5000'],
    'hundredths' => [5000, 'EUR', '50.00'],
    'thousandths' => [5000, 'KWD', '5.000'],
    'ten-thousandths' => [12345, 'CLF', '1.2345'],
    'an unlisted code, hundredths' => [100, 'XYZ', '1.00'],
    'fewer minor units than digits' => [5, 'EUR', '0.05'],
    'exactly one unit' => [100, 'EUR', '1.00'],
    'zero, hundredths' => [0, 'EUR', '0.00'],
    'zero, whole units' => [0, 'JPY', '0'],
    'zero, thousandths' => [0, 'BHD', '0.000'],
    'negative' => [-5000, 'EUR', '-50.00'],
    'negative, fewer minor units than digits' => [-5, 'EUR', '-0.05'],
    'negative, thousandths' => [-5000, 'KWD', '-5.000'],
    'negative, whole units' => [-7, 'KRW', '-7'],
    'the largest amount' => [PHP_INT_MAX, 'EUR', '92233720368547758.07'],
    'the smallest amount' => [PHP_INT_MIN, 'EUR', '-92233720368547758.08'],
]);

it('refuses an overflowing sum rather than widening it to a float', function () {
    expect(fn () => eur(PHP_INT_MAX)->add(eur(1)))->toThrow(TypeError::class);
});
