<?php

use App\Billing\BillingNotConfigured;
use App\Billing\BillingProviderFailed;
use App\Billing\NoticeKind;
use App\Billing\PlanCatalogue;
use App\Billing\SignatureRefused;
use App\Billing\SnapshotItem;
use App\Billing\StripeBillingProvider;
use App\Billing\TaxId;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\FixedClock;

/*
 * The billing port over Stripe, driven over the HTTP client's fake transport with Stripe's
 * documented request and object shapes: no test here opens a connection, stray requests are
 * refused, and every notification is a fixture signed with a made-up secret.
 */

beforeEach(function () {
    Http::preventStrayRequests();
});

function stripeAdapter(#[SensitiveParameter] mixed $secretKey = 'sk_test_made_up_key', #[SensitiveParameter] mixed $webhookSecret = 'whsec_made_up_test_secret', mixed $automaticTax = false): StripeBillingProvider
{
    $http = Http::getFacadeRoot();
    assert($http instanceof Factory);

    return new StripeBillingProvider($http, new FixedClock(new DateTimeImmutable('@1790683200', new DateTimeZone('UTC'))), $secretKey, $webhookSecret, $automaticTax);
}

/**
 * Every request sent, its form read back from the body as it went on the wire.
 *
 * @return list<array{0: string, 1: string, 2: array<array-key, mixed>}>
 */
function stripeAsked(): array
{
    return array_values(Http::recorded()->map(function (array $pair): array {
        parse_str($pair[0]->body(), $form);

        return [$pair[0]->method(), $pair[0]->url(), $form];
    })->all());
}

/**
 * A subscription object as Stripe renders it from 2025-03-31.basil, the period end on its item.
 *
 * @return array<string, mixed>
 */
function stripeSubscription(string $status = 'active', string $price = 'price_floor_test'): array
{
    return [
        'id' => 'sub_1', 'object' => 'subscription', 'customer' => 'cus_1', 'status' => $status,
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_1', 'object' => 'subscription_item', 'current_period_end' => 1793264400,
            'price' => ['id' => $price, 'object' => 'price'], 'quantity' => 1, 'subscription' => 'sub_1',
        ]]],
    ];
}

/**
 * @param  array<array-key, mixed>  $object
 */
function stripeEvent(string $type, array $object): string
{
    return json_encode(['id' => 'evt_1', 'object' => 'event', 'created' => 1790683200, 'data' => ['object' => $object], 'type' => $type], JSON_THROW_ON_ERROR);
}

function stripeSigned(string $payload, int $at = 1790683200, #[SensitiveParameter] string $secret = 'whsec_made_up_test_secret'): string
{
    return 't='.$at.',v1='.hash_hmac('sha256', $at.'.'.$payload, $secret);
}

it('starts a checkout in subscription mode for the customer with the plan\'s price once and both addresses, collecting a tax id with the name saved on the customer and no automatic tax while the switch is off, answering the session\'s address', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);

    $checkout = stripeAdapter()->startCheckout(app(PlanCatalogue::class)->plan('staff'), 'cus_1', 'owner@luna.example', 'https://luna.test/ok', 'https://luna.test/back');

    expect([$checkout->customerId, $checkout->sessionId, $checkout->url])->toBe(['cus_1', 'cs_test_1', 'https://checkout.stripe.com/c/pay/cs_test_1'])
        ->and(stripeAsked())->toBe([['POST', 'https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'subscription',
            'customer' => 'cus_1',
            'line_items' => [['price' => 'price_staff_test', 'quantity' => '1']],
            'success_url' => 'https://luna.test/ok',
            'cancel_url' => 'https://luna.test/back',
            'tax_id_collection' => ['enabled' => 'true'],
            'customer_update' => ['name' => 'auto'],
        ]]]);
});

it('sends the tax fields by the switch for a customer given and for one created first, nothing else new', function (?string $given, mixed $automaticTax, array $form) {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_new', 'object' => 'customer']),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
    ]);

    stripeAdapter(automaticTax: $automaticTax)->startCheckout(app(PlanCatalogue::class)->plan('floor'), $given, 'owner@luna.example', 'https://luna.test/ok', 'https://luna.test/back');
    $asked = stripeAsked();

    expect(end($asked))->toBe(['POST', 'https://api.stripe.com/v1/checkout/sessions', $form])
        ->and(count($asked))->toBe($given === null ? 2 : 1);
})->with([
    'created first, the switch off' => [null, false, [
        'mode' => 'subscription',
        'customer' => 'cus_new',
        'line_items' => [['price' => 'price_floor_test', 'quantity' => '1']],
        'success_url' => 'https://luna.test/ok',
        'cancel_url' => 'https://luna.test/back',
        'tax_id_collection' => ['enabled' => 'true'],
        'customer_update' => ['name' => 'auto'],
    ]],
    'given, the switch on' => ['cus_1', true, [
        'mode' => 'subscription',
        'customer' => 'cus_1',
        'line_items' => [['price' => 'price_floor_test', 'quantity' => '1']],
        'success_url' => 'https://luna.test/ok',
        'cancel_url' => 'https://luna.test/back',
        'tax_id_collection' => ['enabled' => 'true'],
        'customer_update' => ['name' => 'auto', 'address' => 'auto'],
        'automatic_tax' => ['enabled' => 'true'],
    ]],
    'created first, the switch on' => [null, true, [
        'mode' => 'subscription',
        'customer' => 'cus_new',
        'line_items' => [['price' => 'price_floor_test', 'quantity' => '1']],
        'success_url' => 'https://luna.test/ok',
        'cancel_url' => 'https://luna.test/back',
        'tax_id_collection' => ['enabled' => 'true'],
        'customer_update' => ['name' => 'auto', 'address' => 'auto'],
        'automatic_tax' => ['enabled' => 'true'],
    ]],
    'given, the switch the string true, which is not the boolean' => ['cus_1', 'true', [
        'mode' => 'subscription',
        'customer' => 'cus_1',
        'line_items' => [['price' => 'price_floor_test', 'quantity' => '1']],
        'success_url' => 'https://luna.test/ok',
        'cancel_url' => 'https://luna.test/back',
        'tax_id_collection' => ['enabled' => 'true'],
        'customer_update' => ['name' => 'auto'],
    ]],
    'given, the switch unset' => ['cus_1', null, [
        'mode' => 'subscription',
        'customer' => 'cus_1',
        'line_items' => [['price' => 'price_floor_test', 'quantity' => '1']],
        'success_url' => 'https://luna.test/ok',
        'cancel_url' => 'https://luna.test/back',
        'tax_id_collection' => ['enabled' => 'true'],
        'customer_update' => ['name' => 'auto'],
    ]],
]);

it('reads the tax ids the provider holds for a customer as values of ours, in the provider\'s order', function () {
    Http::fake(['api.stripe.com/v1/customers/cus_1/tax_ids' => Http::response(['object' => 'list', 'has_more' => false, 'url' => '/v1/customers/cus_1/tax_ids', 'data' => [
        ['id' => 'txi_1', 'object' => 'tax_id', 'country' => 'GR', 'customer' => 'cus_1', 'type' => 'eu_vat', 'value' => 'EL123456789', 'verification' => ['status' => 'pending']],
        ['id' => 'txi_2', 'object' => 'tax_id', 'country' => 'DE', 'customer' => 'cus_1', 'type' => 'eu_vat', 'value' => 'DE123456789', 'verification' => ['status' => 'verified']],
    ]])]);

    expect(stripeAdapter()->taxIds('cus_1'))->toEqual([new TaxId('eu_vat', 'EL123456789'), new TaxId('eu_vat', 'DE123456789')])
        ->and(stripeAsked())->toBe([['GET', 'https://api.stripe.com/v1/customers/cus_1/tax_ids', []]]);
});

it('reads a customer holding no tax id as the empty list', function () {
    Http::fake(['api.stripe.com/v1/customers/cus_1/tax_ids' => Http::response(['object' => 'list', 'has_more' => false, 'url' => '/v1/customers/cus_1/tax_ids', 'data' => []])]);

    expect(stripeAdapter()->taxIds('cus_1'))->toBe([]);
});

it('encodes the customer\'s identifier in the tax ids\' path', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['object' => 'list', 'data' => []])]);

    stripeAdapter()->taxIds('cus/1 x');

    expect(stripeAsked()[0][1] ?? null)->toBe('https://api.stripe.com/v1/customers/cus%2F1%20x/tax_ids');
});

it('fails a tax ids read the provider refuses with a sentence naming the operation', function () {
    Http::fake(['api.stripe.com/v1/customers/cus_9/tax_ids' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No such customer: 'cus_9'", 'param' => 'customer']], 404)]);

    expect(fn () => stripeAdapter()->taxIds('cus_9'))
        ->toThrow(BillingProviderFailed::class, "The billing provider refused to read the tax ids with status 404, code resource_missing: No such customer: 'cus_9'");
});

it('fails a tax ids answer it cannot read, naming what is missing', function (array $body, string $sentence) {
    Http::fake(['api.stripe.com/v1/customers/cus_1/tax_ids' => Http::response($body)]);

    expect(fn () => stripeAdapter()->taxIds('cus_1'))->toThrow(BillingProviderFailed::class, $sentence);
})->with([
    'no list' => [['object' => 'list'], 'The billing provider answered a request to read the tax ids without a readable data.'],
    'an entry without its value' => [['object' => 'list', 'data' => [['id' => 'txi_1', 'object' => 'tax_id', 'type' => 'eu_vat']]], 'The billing provider answered a request to read the tax ids without a readable value.'],
    'an entry without its type' => [['object' => 'list', 'data' => [['id' => 'txi_1', 'object' => 'tax_id', 'value' => 'EL123456789']]], 'The billing provider answered a request to read the tax ids without a readable type.'],
    'an entry that is no object' => [['object' => 'list', 'data' => ['txi_1']], 'The billing provider answered a request to read the tax ids without a readable tax id.'],
]);

it('asks every question over Basic auth with the secret key as the username and no password', function () {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_new', 'object' => 'customer']),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
        'api.stripe.com/v1/billing_portal/sessions' => Http::response(['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_1']),
        'api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeSubscription()),
        'api.stripe.com/v1/customers/cus_1/tax_ids' => Http::response(['object' => 'list', 'data' => []]),
    ]);
    $port = stripeAdapter();

    $port->startCheckout(app(PlanCatalogue::class)->plan('staff'), null, 'owner@luna.example', 'https://luna.test/ok', 'https://luna.test/back');
    $port->openPortal('cus_1', 'https://luna.test/admin/billing');
    $port->subscription('sub_1');
    $port->changePlan('sub_1', app(PlanCatalogue::class)->plan('floor'));
    $port->taxIds('cus_1');

    expect(Http::recorded()->map(fn (array $pair): array => $pair[0]->header('Authorization'))->values()->all())
        ->toBe(array_fill(0, 7, ['Basic '.base64_encode('sk_test_made_up_key:')]));
});

it('creates the customer from the email first when handed none, and names it in the checkout', function () {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_new', 'object' => 'customer']),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
    ]);

    $checkout = stripeAdapter()->startCheckout(app(PlanCatalogue::class)->plan('floor'), null, 'owner@luna.example', 'https://luna.test/ok', 'https://luna.test/back');
    $asked = stripeAsked();

    expect($checkout->customerId)->toBe('cus_new')
        ->and($asked[0] ?? null)->toBe(['POST', 'https://api.stripe.com/v1/customers', ['email' => 'owner@luna.example']])
        ->and($asked[1][2]['customer'] ?? null)->toBe('cus_new')
        ->and(count($asked))->toBe(2);
});

it('opens the portal for the customer with the return address, answering the session\'s address', function () {
    Http::fake(['api.stripe.com/v1/billing_portal/sessions' => Http::response(['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_1'])]);

    expect(stripeAdapter()->openPortal('cus_1', 'https://luna.test/admin/billing'))->toBe('https://billing.stripe.com/p/session/test_1')
        ->and(stripeAsked())->toBe([['POST', 'https://api.stripe.com/v1/billing_portal/sessions', ['customer' => 'cus_1', 'return_url' => 'https://luna.test/admin/billing']]]);
});

it('reads a subscription by its identifier into a snapshot, the status verbatim and the period end an instant in UTC', function () {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeSubscription('past_due'))]);

    $snapshot = stripeAdapter()->subscription('sub_1');

    expect(stripeAsked())->toBe([['GET', 'https://api.stripe.com/v1/subscriptions/sub_1', []]])
        ->and([$snapshot->subscriptionId, $snapshot->customerId, $snapshot->status])->toBe(['sub_1', 'cus_1', 'past_due'])
        ->and($snapshot->periodEnd->format(DATE_ATOM))->toBe('2026-10-29T09:00:00+00:00')
        ->and($snapshot->periodEnd->getTimezone()->getName())->toBe('UTC')
        ->and($snapshot->items)->toEqual([new SnapshotItem('price_floor_test', 1)]);
});

it('reads the period end from the subscription itself as API versions before basil render it', function () {
    $older = stripeSubscription();
    $older['current_period_end'] = 1793264400;
    $older['items'] = ['object' => 'list', 'data' => [['id' => 'si_1', 'price' => ['id' => 'price_floor_test'], 'quantity' => 1]]];
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response($older)]);

    expect(stripeAdapter()->subscription('sub_1')->periodEnd->format(DATE_ATOM))->toBe('2026-10-29T09:00:00+00:00');
});

it('changes a plan by sending the new price against the subscription\'s existing item, answering the changed subscription', function () {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::sequence()->push(stripeSubscription())->push(stripeSubscription('active', 'price_staff_test'))]);

    $snapshot = stripeAdapter()->changePlan('sub_1', app(PlanCatalogue::class)->plan('staff'));

    expect(stripeAsked())->toBe([
        ['GET', 'https://api.stripe.com/v1/subscriptions/sub_1', []],
        ['POST', 'https://api.stripe.com/v1/subscriptions/sub_1', ['items' => [['id' => 'si_1', 'price' => 'price_staff_test']]]],
    ])->and($snapshot->items)->toEqual([new SnapshotItem('price_staff_test', 1)])
        ->and($snapshot->periodEnd->getTimezone()->getName())->toBe('UTC');
});

it('cancels a subscription at once with a DELETE carrying no body, so no final invoice and no proration, answering the subscription ended', function () {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeSubscription('canceled', 'price_staff_test'))]);

    $snapshot = stripeAdapter()->cancel('sub_1');

    expect(stripeAsked())->toBe([['DELETE', 'https://api.stripe.com/v1/subscriptions/sub_1', []]])
        ->and([$snapshot->subscriptionId, $snapshot->customerId, $snapshot->status])->toBe(['sub_1', 'cus_1', 'canceled'])
        ->and($snapshot->items)->toEqual([new SnapshotItem('price_staff_test', 1)])
        ->and($snapshot->periodEnd->getTimezone()->getName())->toBe('UTC');
});

it('fails a cancel the provider refuses with a sentence naming the operation and the provider\'s code and message', function () {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No such subscription: 'sub_1'", 'param' => 'id']], 404)]);

    expect(fn () => stripeAdapter()->cancel('sub_1'))
        ->toThrow(BillingProviderFailed::class, "The billing provider refused to cancel a subscription with status 404, code resource_missing: No such subscription: 'sub_1'");
});

it('refuses to change a plan on a subscription carrying other than one item, sending no change', function (array $items) {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response([...stripeSubscription(), 'items' => ['object' => 'list', 'data' => $items]])]);

    expect(fn () => stripeAdapter()->changePlan('sub_1', app(PlanCatalogue::class)->plan('staff')))
        ->toThrow(BillingProviderFailed::class, 'The billing provider answered a request to change a plan without a readable single subscription item.')
        ->and(stripeAsked())->toBe([['GET', 'https://api.stripe.com/v1/subscriptions/sub_1', []]]);
})->with([
    'no item' => [[]],
    'two items' => [[
        ['id' => 'si_1', 'price' => ['id' => 'price_floor_test'], 'quantity' => 1, 'current_period_end' => 1793264400],
        ['id' => 'si_2', 'price' => ['id' => 'price_staff_test'], 'quantity' => 1, 'current_period_end' => 1793264400],
    ]],
]);

it('fails a refused request with a sentence naming the operation and the provider\'s code and message, and no key', function () {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_9' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No such subscription: 'sub_9'", 'param' => 'id']], 404)]);

    expect(fn () => stripeAdapter()->subscription('sub_9'))
        ->toThrow(BillingProviderFailed::class, "The billing provider refused to read a subscription with status 404, code resource_missing: No such subscription: 'sub_9'");
});

it('fails a request the provider cannot be reached for, naming the operation', function () {
    Http::fake(['api.stripe.com/*' => Http::failedConnection('Could not resolve host')]);

    expect(fn () => stripeAdapter()->openPortal('cus_1', 'https://luna.test/admin/billing'))
        ->toThrow(BillingProviderFailed::class, 'The billing provider could not be reached to open the portal: Could not resolve host');
});

it('fails a checkout answered without the session\'s identifier, naming it, since the signup records it beside the customer', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);

    expect(fn () => stripeAdapter()->startCheckout(app(PlanCatalogue::class)->plan('staff'), 'cus_1', 'owner@luna.example', 'https://luna.test/ok', 'https://luna.test/back'))
        ->toThrow(BillingProviderFailed::class, 'The billing provider answered a request to start a checkout without a readable id.');
});

it('fails an answer the snapshot cannot be read from, naming what is missing', function () {
    $unreadable = stripeSubscription();
    unset($unreadable['items']);
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response($unreadable)]);

    expect(fn () => stripeAdapter()->subscription('sub_1'))
        ->toThrow(BillingProviderFailed::class, 'The billing provider answered a request to read a subscription without a readable items.');
});

it('never puts a key or the Basic auth header in a failure sentence', function (Closure $fail) {
    $message = null;

    try {
        $fail();
    } catch (BillingProviderFailed|BillingNotConfigured $failed) {
        $message = $failed->getMessage();
    }

    expect($message)->toBeString()
        ->not->toContain('sk_test_made_up_key')
        ->not->toContain('whsec_made_up_test_secret')
        ->not->toContain(base64_encode('sk_test_made_up_key:'))
        ->not->toContain('Basic ');
})->with([
    'a refused key' => [function () {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'api_key_expired', 'message' => 'Expired API Key provided: sk_test_***********_key']], 401)]);
        stripeAdapter()->openPortal('cus_1', 'https://luna.test/admin/billing');
    }],
    'a refused request' => [function () {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No such customer: 'cus_9'", 'param' => 'customer']], 400)]);
        stripeAdapter()->startCheckout(app(PlanCatalogue::class)->plan('staff'), 'cus_9', 'owner@luna.example', 'https://luna.test/ok', 'https://luna.test/back');
    }],
    'an unreachable provider' => [function () {
        Http::fake(['api.stripe.com/*' => Http::failedConnection('Could not resolve host')]);
        stripeAdapter()->changePlan('sub_1', app(PlanCatalogue::class)->plan('floor'));
    }],
    'an unreadable answer' => [function () {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'bps_1', 'object' => 'billing_portal.session'])]);
        stripeAdapter()->openPortal('cus_1', 'https://luna.test/admin/billing');
    }],
    'an answer that is not JSON' => [function () {
        Http::fake(['api.stripe.com/*' => Http::response('<html>bad gateway</html>')]);
        stripeAdapter()->subscription('sub_1');
    }],
    'no secret key, the signing secret set' => [function () {
        Http::fake();
        stripeAdapter('', 'whsec_made_up_test_secret')->subscription('sub_1');
    }],
    'no signing secret, the secret key set' => [function () {
        stripeAdapter('sk_test_made_up_key', '')->verify('{}', stripeSigned('{}'));
    }],
]);

it('refuses every question with one sentence naming the missing key, asking nothing', function (Closure $ask, string $sentence) {
    Http::fake();

    expect(fn () => $ask(stripeAdapter('', ' ')))->toThrow(new BillingNotConfigured($sentence));

    Http::assertNothingSent();
})->with([
    'start a checkout' => [fn (StripeBillingProvider $port) => $port->startCheckout(app(PlanCatalogue::class)->plan('staff'), null, 'owner@luna.example', 'https://luna.test/ok', 'https://luna.test/back'), BillingNotConfigured::NO_SECRET_KEY],
    'open the portal' => [fn (StripeBillingProvider $port) => $port->openPortal('cus_1', 'https://luna.test/admin/billing'), BillingNotConfigured::NO_SECRET_KEY],
    'read a subscription' => [fn (StripeBillingProvider $port) => $port->subscription('sub_1'), BillingNotConfigured::NO_SECRET_KEY],
    'change a plan' => [fn (StripeBillingProvider $port) => $port->changePlan('sub_1', app(PlanCatalogue::class)->plan('floor')), BillingNotConfigured::NO_SECRET_KEY],
    'cancel a subscription' => [fn (StripeBillingProvider $port) => $port->cancel('sub_1'), BillingNotConfigured::NO_SECRET_KEY],
    'read the tax ids' => [fn (StripeBillingProvider $port) => $port->taxIds('cus_1'), BillingNotConfigured::NO_SECRET_KEY],
    'verify a notification' => [fn (StripeBillingProvider $port) => $port->verify('{}', stripeSigned('{}')), BillingNotConfigured::NO_WEBHOOK_SECRET],
]);

it('names Stripe-Signature as the header its notifications carry', function () {
    expect(stripeAdapter(null, null)->signatureHeader())->toBe('Stripe-Signature');
});

it('verifies a notification signed with the endpoint secret and reads the event id and type', function () {
    $payload = stripeEvent('customer.subscription.updated', stripeSubscription());

    $notice = stripeAdapter()->verify($payload, stripeSigned($payload));

    expect([$notice->eventId, $notice->type, $notice->kind])->toBe(['evt_1', 'customer.subscription.updated', NoticeKind::SubscriptionChanged]);
});

it('accepts any one v1 signature made with the secret beside others, as while a secret is rolled', function () {
    $payload = stripeEvent('customer.created', ['id' => 'cus_1', 'object' => 'customer']);
    $header = 't=1790683200,v1='.hash_hmac('sha256', '1790683200.'.$payload, 'whsec_rolled_away').','.substr(stripeSigned($payload), 13).',v0=6ffbb59b2300aae6';

    expect(stripeAdapter()->verify($payload, $header)->eventId)->toBe('evt_1');
});

it('accepts a notification signed at the edge of the tolerance on either side of now', function (int $at) {
    $payload = stripeEvent('invoice.paid', ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1']);

    expect(stripeAdapter()->verify($payload, stripeSigned($payload, $at))->eventId)->toBe('evt_1');
})->with([
    'five minutes ago' => [1790683200 - 300],
    'five minutes ahead' => [1790683200 + 300],
]);

it('refuses a notification its signature does not vouch for', function (Closure $delivery) {
    $payload = stripeEvent('invoice.paid', ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1']);
    /** @var array{0: string, 1: string} $delivered */
    $delivered = $delivery($payload);
    [$body, $header] = $delivered;

    expect(fn () => stripeAdapter()->verify($body, $header))->toThrow(SignatureRefused::class);
})->with([
    'a tampered body' => [fn (string $payload): array => [str_replace('cus_1', 'cus_2', $payload), stripeSigned($payload)]],
    'a stale timestamp' => [fn (string $payload): array => [$payload, stripeSigned($payload, 1790683200 - 301)]],
    'a timestamp beyond the tolerance ahead' => [fn (string $payload): array => [$payload, stripeSigned($payload, 1790683200 + 301)]],
    'another secret' => [fn (string $payload): array => [$payload, stripeSigned($payload, 1790683200, 'whsec_another_made_up_secret')]],
    'no signature' => [fn (string $payload): array => [$payload, '']],
    'only the test scheme' => [fn (string $payload): array => [$payload, str_replace('v1=', 'v0=', stripeSigned($payload))]],
    'a signed body that is not JSON' => [fn (string $payload): array => ['not json', stripeSigned('not json')]],
    'a signed body that is not an event' => [fn (string $payload): array => ['{"object":"list"}', stripeSigned('{"object":"list"}')]],
    'a signed event with no object' => [fn (string $payload): array => ['{"id":"evt_1","object":"event","type":"invoice.paid","data":{}}', stripeSigned('{"id":"evt_1","object":"event","type":"invoice.paid","data":{}}')]],
]);

it('names where its events carry the customer, the path the drop deletes a closed tenant\'s notifications by, which is where verify() reads it: {0}', function (string $type, array $object) {
    $payload = stripeEvent($type, $object);
    $at = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

    foreach (stripeAdapter()->customerPath() as $key) {
        $at = is_array($at) ? ($at[$key] ?? null) : null;
    }

    expect(stripeAdapter()->customerPath())->toBe(['data', 'object', 'customer'])
        ->and($at)->toBe('cus_1')
        ->and(stripeAdapter()->verify($payload, stripeSigned($payload))->customerId)->toBe($at);
})->with([
    'a completed checkout' => ['checkout.session.completed', ['id' => 'cs_1', 'object' => 'checkout.session', 'customer' => 'cus_1', 'subscription' => 'sub_1']],
    'an updated subscription' => ['customer.subscription.updated', stripeSubscription()],
    'a failed invoice payment' => ['invoice.payment_failed', ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'subscription' => 'sub_1']],
]);

it('reads each event the platform acts on as its kind, with the customer and subscription its object names', function (string $type, array $object, NoticeKind $kind, ?string $customer, ?string $subscription) {
    $payload = stripeEvent($type, $object);

    $notice = stripeAdapter()->verify($payload, stripeSigned($payload));

    expect([$notice->type, $notice->kind, $notice->customerId, $notice->subscriptionId])->toBe([$type, $kind, $customer, $subscription]);
})->with([
    'a completed checkout' => ['checkout.session.completed', ['id' => 'cs_1', 'object' => 'checkout.session', 'customer' => 'cus_1', 'subscription' => 'sub_1'], NoticeKind::CheckoutCompleted, 'cus_1', 'sub_1'],
    'a created subscription' => ['customer.subscription.created', stripeSubscription('incomplete'), NoticeKind::SubscriptionChanged, 'cus_1', 'sub_1'],
    'an updated subscription' => ['customer.subscription.updated', stripeSubscription(), NoticeKind::SubscriptionChanged, 'cus_1', 'sub_1'],
    'a deleted subscription' => ['customer.subscription.deleted', stripeSubscription('canceled'), NoticeKind::SubscriptionDeleted, 'cus_1', 'sub_1'],
    'a failed invoice payment' => ['invoice.payment_failed', ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]], NoticeKind::ChargeFailed, 'cus_1', 'sub_1'],
    'a failed invoice payment before basil' => ['invoice.payment_failed', ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'subscription' => 'sub_1'], NoticeKind::ChargeFailed, 'cus_1', 'sub_1'],
    'a paid invoice' => ['invoice.paid', ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]], NoticeKind::ChargeSucceeded, 'cus_1', 'sub_1'],
    'a paid invoice before basil' => ['invoice.paid', ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'subscription' => 'sub_1'], NoticeKind::ChargeSucceeded, 'cus_1', 'sub_1'],
    'a succeeded invoice payment' => ['invoice.payment_succeeded', ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]], NoticeKind::ChargeSucceeded, 'cus_1', 'sub_1'],
    'a succeeded invoice payment before basil' => ['invoice.payment_succeeded', ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'subscription' => 'sub_1'], NoticeKind::ChargeSucceeded, 'cus_1', 'sub_1'],
    'any other event' => ['customer.created', ['id' => 'cus_1', 'object' => 'customer'], NoticeKind::Other, null, null],
]);
