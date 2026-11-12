<?php

use App\Domain\Enum\PaymentMode;

it('spells the three payment modes as the services column stores them, and nothing else', function () {
    expect(array_map(fn (PaymentMode $mode): string => $mode->value, PaymentMode::cases()))
        ->toBe(['free', 'online', 'onsite']);
});

it('is string-backed, so a row value round-trips', function () {
    expect(PaymentMode::from('online'))->toBe(PaymentMode::Online);
});
