<?php

use App\Application\Dto\HoldTtls;

it('answers the approval window in minutes from the service hours when named, else the tenant default', function () {
    $ttls = new HoldTtls(checkoutMinutes: 15, approvalHours: 48);

    expect($ttls->approvalMinutes(null))->toBe(2880)
        ->and($ttls->approvalMinutes(6))->toBe(360)
        ->and($ttls->checkoutMinutes)->toBe(15);
});

it('refuses a lifetime below one, and a service window below one hour', function () {
    expect(fn () => new HoldTtls(0, 48))->toThrow(InvalidArgumentException::class, 'checkout TTL')
        ->and(fn () => new HoldTtls(15, 0))->toThrow(InvalidArgumentException::class, 'approval TTL')
        ->and(fn () => (new HoldTtls(15, 48))->approvalMinutes(0))->toThrow(InvalidArgumentException::class, 'service approval window');
});
