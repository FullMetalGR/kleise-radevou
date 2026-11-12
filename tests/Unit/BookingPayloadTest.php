<?php

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\Customer;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Http\BookingFacts;
use App\Http\BookingPayload;
use App\Notifications\CustomerFacts;
use App\Notifications\TenantClock;
use App\Site\SiteLanguage;
use Tests\Support\LangLines;

/*
 * The payload whitelist on a snapshot: which columns a guest holding a valid manage token is
 * shown, how each is spelled on the wire, and what the hold response adds. The live tables are
 * read in the Feature suite, where a schema exists; here the class is asked what it does with
 * a booking. The facts a page or a message of the business names read their lines of
 * decision.php through a translator over the lang directory.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

function payloadItem(int $sort, string $serviceName, string $staffName, bool $implicit = false): BookingItemSnapshot
{
    $athens = new DateTimeZone('Europe/Athens');

    return new BookingItemSnapshot(
        $sort,
        7 + $sort,
        $serviceName,
        3,
        $staffName,
        $implicit,
        new DateTimeImmutable('2026-11-03 09:00', $athens),
        new DateTimeImmutable('2026-11-03 09:45', $athens),
        new Money(3500, Currency::of('EUR')),
    );
}

function payloadSnapshot(BookingItemSnapshot ...$items): BookingSnapshot
{
    return new BookingSnapshot(
        '7b7f0e5a-0000-4000-8000-000000000001',
        BookingStatus::Pending,
        HoldClass::Checkout,
        new DateTimeImmutable('2026-11-03 06:15', new DateTimeZone('UTC')),
        new Customer('Casey Customer', 'casey@example.com', '+30 210 0000000'),
        new Money(3500, Currency::of('EUR')),
        array_values($items),
    );
}

it('shows a guest the booking as its columns spell it, the instants RFC 3339 in UTC and the prices minor units beside the currency', function () {
    expect(BookingPayload::guest(payloadSnapshot(payloadItem(0, 'Haircut', 'Elena Papadaki'))))->toBe([
        'uuid' => '7b7f0e5a-0000-4000-8000-000000000001',
        'status' => 'pending',
        'hold_class' => 'checkout',
        'hold_expires_at' => '2026-11-03T06:15:00+00:00',
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'total_minor' => 3500,
        'currency' => 'EUR',
        'items' => [[
            'sort' => 0,
            'service_id' => 7,
            'service_name' => 'Haircut',
            'staff_id' => 3,
            'staff_name' => 'Elena Papadaki',
            'start' => '2026-11-03T07:00:00+00:00',
            'end' => '2026-11-03T07:45:00+00:00',
            'price_minor' => 3500,
        ]],
    ]);
});

it('names no staff member for a booking on the tenant\'s implicit one, while still naming which member holds the range', function () {
    $items = BookingPayload::guest(payloadSnapshot(payloadItem(0, 'Initial assessment', 'Pier Physiotherapy', implicit: true)))['items'];

    expect($items[0]['staff_id'] ?? null)->toBe(3)
        ->and($items[0]['staff_name'])->toBeNull();
});

it('sends a null hold class and a null expiry through as nulls, for a booking that is no longer held', function () {
    $snapshot = new BookingSnapshot(
        '7b7f0e5a-0000-4000-8000-000000000002',
        BookingStatus::Confirmed,
        null,
        null,
        new Customer('Casey Customer', 'casey@example.com'),
        new Money(3500, Currency::of('EUR')),
        [payloadItem(0, 'Haircut', 'Elena Papadaki')],
    );

    expect(BookingPayload::guest($snapshot))->toMatchArray([
        'status' => 'confirmed',
        'hold_class' => null,
        'hold_expires_at' => null,
        'customer_phone' => null,
    ]);
});

it('sends the three customer columns through as nulls for a booking whose customer was erased, and names nobody where a message or a page of the business would', function () {
    $snapshot = new BookingSnapshot(
        '7b7f0e5a-0000-4000-8000-000000000003',
        BookingStatus::Completed,
        null,
        null,
        null,
        new Money(3500, Currency::of('EUR')),
        [payloadItem(0, 'Haircut', 'Elena Papadaki')],
    );

    expect(BookingPayload::guest($snapshot))->toMatchArray([
        'uuid' => '7b7f0e5a-0000-4000-8000-000000000003',
        'customer_name' => null,
        'customer_email' => null,
        'customer_phone' => null,
        'total_minor' => 3500,
    ])
        ->and(CustomerFacts::of($snapshot->customer, true, SiteLanguage::English))->toBe([CustomerFacts::CUSTOMER => LangLines::line(CustomerFacts::ERASED)])
        ->and(CustomerFacts::of($snapshot->customer, false, SiteLanguage::English))->toBe([CustomerFacts::CUSTOMER => LangLines::line(CustomerFacts::ERASED)])
        ->and(BookingFacts::of($snapshot, new TenantClock(new DateTimeZone('Europe/Athens')), true, SiteLanguage::English)['customer'])->toBe([['label' => LangLines::line('decision.facts.customer'), 'value' => LangLines::line(CustomerFacts::ERASED)]])
        ->and(CustomerFacts::of(new Customer('Casey Customer', 'casey@example.com', '+30 210 0000000'), true, SiteLanguage::English))->toBe([CustomerFacts::CUSTOMER => 'Casey Customer', CustomerFacts::EMAIL => 'casey@example.com', CustomerFacts::PHONE => '+30 210 0000000'])
        ->and(fn () => $snapshot->person())->toThrow(LogicException::class, 'The booking 7b7f0e5a-0000-4000-8000-000000000003 names no customer: its customer was erased.')
        ->and(payloadSnapshot(payloadItem(0, 'Haircut', 'Elena Papadaki'))->person()->email)->toBe('casey@example.com');
});

it('adds the manage token to the hold response, in plaintext and exactly once, and no checkout_url key at all', function () {
    $response = BookingPayload::hold(payloadSnapshot(payloadItem(0, 'Haircut', 'Elena Papadaki')), 'abc123');

    expect($response['manage_token'] ?? null)->toBe('abc123')
        ->and(array_key_exists('checkout_url', $response))->toBeFalse()
        ->and(array_keys($response))->toBe([...array_keys(BookingPayload::guest(payloadSnapshot(payloadItem(0, 'Haircut', 'Elena Papadaki')))), 'manage_token']);
});

it('classifies each column of bookings and booking_items once, withholds the serial ids and the token hash from everybody, and the rejection reason from the guest alone', function () {
    $booking = [...BookingPayload::GUEST, ...BookingPayload::VIEWER, ...BookingPayload::MANAGE_ONLY, ...BookingPayload::WITHHELD];
    $item = [...BookingPayload::ITEM_GUEST, ...BookingPayload::ITEM_MANAGE_ONLY, ...BookingPayload::ITEM_WITHHELD];

    expect(array_unique($booking))->toBe($booking)
        ->and(array_unique($item))->toBe($item)
        ->and(BookingPayload::WITHHELD)->toContain('id', 'manage_token_hash')
        ->and(BookingPayload::ITEM_WITHHELD)->toContain('id', 'booking_id')
        ->and(BookingPayload::GUEST)->toContain('customer_email', 'customer_phone')
        ->and(BookingPayload::VIEWER)->toContain('rejection_reason');
});
