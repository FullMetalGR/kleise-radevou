<?php

use App\Application\Notification\MessageKey;
use App\Tenancy\Settings;

it('carries no accent, the theme the one colour a tenant chooses', function () {
    $settings = new ReflectionClass(Settings::class);

    expect($settings->hasProperty('brandAccent'))->toBeFalse()
        ->and($settings->hasConstant('ACCENT_PROPERTY'))->toBeFalse()
        ->and($settings->hasMethod('accentDeclaration'))->toBeFalse();
});

it('carries the two hold TTLs and refuses either below one', function () {
    $settings = new Settings(5, 20, 72, 24);

    expect($settings->checkoutTtlMin)->toBe(20)
        ->and($settings->approvalTtlHours)->toBe(72)
        ->and(fn () => new Settings(5, 0, 48, 24))->toThrow(InvalidArgumentException::class, 'checkout TTL')
        ->and(fn () => new Settings(5, 15, 0, 24))->toThrow(InvalidArgumentException::class, 'approval TTL');
});

it('carries the reminder lead, which zero switches off, and refuses one below zero', function () {
    // Zero is a value and not a fault: zero switches reminders off, and the lead has its own
    // rule for exactly that reason, where the three holds must be positive. So the lead alone is
    // admitted at zero and refused only below it.
    expect((new Settings(5, 15, 48, 24))->reminderLeadHours)->toBe(24)
        ->and((new Settings(5, 15, 48, 0))->reminderLeadHours)->toBe(0)
        ->and(fn () => new Settings(5, 15, 48, -1))->toThrow(InvalidArgumentException::class, 'reminder lead');
});

it('carries the payment TTL and the logo path the settings screen edits, refusing a payment TTL below one', function () {
    $settings = new Settings(5, 15, 48, 24, [], 1, 'd/m/Y', 'H:i', 36, 'tenants/luna/logo-0123456789abcdef.png');

    expect($settings->paymentTtlHours)->toBe(36)
        ->and($settings->brandLogoPath)->toBe('tenants/luna/logo-0123456789abcdef.png')
        ->and((new Settings(5, 15, 48, 24))->paymentTtlHours)->toBe(24)
        ->and((new Settings(5, 15, 48, 24))->brandLogoPath)->toBeNull()
        ->and(fn () => new Settings(5, 15, 48, 24, [], 1, 'd/m/Y', 'H:i', 0))->toThrow(InvalidArgumentException::class, 'payment TTL');
});

it('carries the start interval, 15 unless the row says otherwise', function () {
    expect((new Settings(5, 15, 48, 24))->startIntervalMin)->toBe(15)
        ->and((new Settings(5, 15, 48, 24, [], 1, 'd/m/Y', 'H:i', 24, null, 30))->startIntervalMin)->toBe(30)
        ->and((new Settings(10, 15, 48, 24, [], 1, 'd/m/Y', 'H:i', 24, null, 20))->startIntervalMin)->toBe(20);
});

it('refuses a start interval {what}', function (int $interval) {
    expect(fn () => new Settings(5, 15, 48, 24, [], 1, 'd/m/Y', 'H:i', 24, null, $interval))
        ->toThrow(InvalidArgumentException::class, sprintf('A start interval is a number of minutes dividing sixty, not %d.', $interval));
})->with([
    'of 7, which does not divide the hour' => [7],
    'of 25, which does not divide the hour' => [25],
    'of 0, which is no interval' => [0],
    'of -15, a negative divisor of the hour' => [-15],
    'of 120, longer than the hour' => [120],
]);

it('refuses a start interval off the granularity, 15 over a grid of 10', function () {
    expect(fn () => new Settings(10, 15, 48, 24, [], 1, 'd/m/Y', 'H:i', 24, null, 15))
        ->toThrow(InvalidArgumentException::class, 'A start interval is a multiple of the 10-minute granularity, not 15.');
});

it('sends a message whose key is off the list and holds back one whose key is on it, the one reading of emails_off every reader asks', function () {
    $off = new Settings(5, 15, 48, 24, [MessageKey::MyBookingsLink->value, 'a_key_this_build_never_heard_of']);

    expect($off->sends(MessageKey::MyBookingsLink))->toBeFalse()
        ->and($off->sends(MessageKey::Reminder))->toBeTrue()
        ->and(array_values(array_filter(MessageKey::cases(), fn (MessageKey $key): bool => ! $off->sends($key))))->toBe([MessageKey::MyBookingsLink])
        ->and(array_filter(MessageKey::cases(), fn (MessageKey $key): bool => ! (new Settings(5, 15, 48, 24))->sends($key)))->toBe([]);
});

it('sends the approved message whatever emails_off holds while a working payment connection exists, locks no other key, and locks nothing without one', function () {
    $everyKey = array_map(fn (MessageKey $key): string => $key->value, MessageKey::cases());
    $paying = new Settings(5, 15, 48, 24, $everyKey, takesPayments: true);
    $unpaid = new Settings(5, 15, 48, 24, $everyKey);

    expect(array_values(array_filter(MessageKey::cases(), fn (MessageKey $key): bool => $paying->sends($key))))->toBe([MessageKey::Approved])
        ->and(array_values(array_filter(MessageKey::cases(), fn (MessageKey $key): bool => $paying->locked($key))))->toBe([MessageKey::Approved])
        ->and(array_filter(MessageKey::cases(), fn (MessageKey $key): bool => $unpaid->sends($key) || $unpaid->locked($key)))->toBe([])
        ->and((new Settings(5, 15, 48, 24))->takesPayments)->toBeFalse();
});

it('stores each key a save lists once, in its order, a key it does not know among them, and no key held on', function () {
    $given = ['reminder', MessageKey::Approved->value, 'a_key_this_build_never_heard_of', 'reminder'];

    expect((new Settings(5, 15, 48, 24, takesPayments: true))->storable($given))->toBe(['reminder', 'a_key_this_build_never_heard_of'])
        ->and((new Settings(5, 15, 48, 24))->storable($given))->toBe(['reminder', MessageKey::Approved->value, 'a_key_this_build_never_heard_of']);
});

it('colours the calendar by staff unless told, carries category and status, and refuses another word', function () {
    expect((new Settings(5, 15, 48, 24))->colourBy)->toBe('staff')
        ->and(Settings::COLOUR_BY)->toBe(['staff', 'category', 'status'])
        ->and((new Settings(5, 15, 48, 24, colourBy: 'category'))->colourBy)->toBe('category')
        ->and((new Settings(5, 15, 48, 24, colourBy: 'status'))->colourBy)->toBe('status')
        ->and(fn () => new Settings(5, 15, 48, 24, colourBy: 'service'))->toThrow(InvalidArgumentException::class, 'Colour by');
});
