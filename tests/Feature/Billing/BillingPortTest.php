<?php

use App\Billing\BillingNotConfigured;
use App\Billing\BillingProvider;
use App\Billing\BillingProviderFailed;
use App\Billing\NoticeKind;
use App\Billing\PlanCatalogue;
use App\Billing\SignatureRefused;
use App\Billing\SnapshotItem;
use App\Billing\StripeBillingProvider;
use App\Billing\TaxId;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Tests\Support\BillingPortDecorator;
use Tests\Support\FakeBillingProvider;

/*
 * The seam between the platform's billing provider and the tenant row: the port's questions, the
 * fake every test meets in its place, and the container's own binding, the Stripe adapter, which
 * boots without billing keys and refuses at the first question with one sentence naming the one
 * missing. The plan catalogue as configuration and the environment hold it closes the file.
 */

it('hands every test the fake the test environment names in place of the billing provider', function () {
    expect(config('billing.provider.class'))->toBe(FakeBillingProvider::class)
        ->and(billingPort())->toBeInstanceOf(FakeBillingProvider::class)
        ->and(billingPort())->toBe(billingFake());
});

it('keeps the provider\'s secrets out of every test run', function () {
    expect(config('billing.provider.secret_key'))->toBe('')
        ->and(config('billing.provider.webhook_secret'))->toBe('');
});

it('binds the Stripe adapter when nothing is named, refusing its first question with one sentence naming the missing key', function (Closure $ask, string $sentence) {
    Http::preventStrayRequests();
    config(['billing.provider.class' => null]);
    app()->forgetInstance(BillingProvider::class);
    $port = billingPort();

    expect($port)->toBeInstanceOf(StripeBillingProvider::class)
        ->and(fn () => $ask($port))->toThrow(new BillingNotConfigured($sentence));
})->with([
    'start a checkout' => [fn (BillingProvider $port) => $port->startCheckout(app(PlanCatalogue::class)->plan('staff'), null, 'owner@luna.example', 'https://luna.test/ok', 'https://luna.test/back'), BillingNotConfigured::NO_SECRET_KEY],
    'open the portal' => [fn (BillingProvider $port) => $port->openPortal('cus_1', 'https://luna.test/admin/billing'), BillingNotConfigured::NO_SECRET_KEY],
    'read a subscription' => [fn (BillingProvider $port) => $port->subscription('sub_1'), BillingNotConfigured::NO_SECRET_KEY],
    'change a plan' => [fn (BillingProvider $port) => $port->changePlan('sub_1', app(PlanCatalogue::class)->plan('floor')), BillingNotConfigured::NO_SECRET_KEY],
    'cancel a subscription' => [fn (BillingProvider $port) => $port->cancel('sub_1'), BillingNotConfigured::NO_SECRET_KEY],
    'read the tax ids' => [fn (BillingProvider $port) => $port->taxIds('cus_1'), BillingNotConfigured::NO_SECRET_KEY],
    'verify a notification' => [fn (BillingProvider $port) => $port->verify('{}', 't=1,v1=unsigned'), BillingNotConfigured::NO_WEBHOOK_SECRET],
]);

it('boots and serves with the Stripe adapter bound and no billing key set', function () {
    Http::preventStrayRequests();
    config(['billing.provider.class' => null]);
    app()->forgetInstance(BillingProvider::class);

    $this->get(centralUrl('/up'))->assertOk();

    expect(billingPort()->signatureHeader())->toBe('Stripe-Signature');
});

it('keeps automatic tax off by default and in every test run', function () {
    // phpunit.xml forces the variable to (false), the framework's boolean false (PHPUnit reads a
    // bare false as the empty string), so a machine whose .env turns it on changes nothing.
    expect(Env::get('BILLING_AUTOMATIC_TAX'))->toBeFalse()
        ->and(config('billing.provider.automatic_tax'))->toBeFalse();
});

it('builds the bound Stripe adapter with the automatic tax switch configuration holds', function (bool $on) {
    Http::preventStrayRequests();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
    config(['billing.provider.class' => null, 'billing.provider.secret_key' => 'sk_test_made_up_key', 'billing.provider.automatic_tax' => $on]);
    app()->forgetInstance(BillingProvider::class);

    billingPort()->startCheckout(app(PlanCatalogue::class)->plan('staff'), 'cus_1', 'owner@luna.example', 'https://luna.test/ok', 'https://luna.test/back');
    $form = [];
    parse_str(Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->implode(''), $form);

    expect(array_key_exists('automatic_tax', $form))->toBe($on);
})->with(['on' => [true], 'off' => [false]]);

it('answers a checkout with a customer it created and where to send the person, and records the question', function () {
    $checkout = billingFake()->startCheckout(app(PlanCatalogue::class)->plan('staff'), null, 'owner@luna.example', 'https://luna.test/ok', 'https://luna.test/back');

    expect($checkout->customerId)->toBe('cus_fake_1')
        ->and($checkout->sessionId)->toBe('cs_fake_1')
        ->and($checkout->url)->toBe('https://luna.test/ok?fake_checkout=cus_fake_1&plan=staff')
        ->and(billingFake()->asked)->toBe([['startCheckout', ['plan' => 'staff', 'price' => 'price_staff_test', 'customer' => null, 'email' => 'owner@luna.example', 'success' => 'https://luna.test/ok', 'cancel' => 'https://luna.test/back']]]);
});

it('refuses at first use a port class that is not a billing provider, naming it', function () {
    config(['billing.provider.class' => stdClass::class]);
    app()->forgetInstance(BillingProvider::class);

    expect(fn () => billingPort())->toThrow(BillingNotConfigured::class, 'BILLING_PROVIDER names stdClass, which is not a billing provider.');
});

it('answers a plan change with the plan\'s price in place of the scripted items', function () {
    billingFake()->script(billingSnapshot('sub_1', 'cus_1', 'active', ['price_floor_test']));

    $changed = billingFake()->changePlan('sub_1', app(PlanCatalogue::class)->plan('staff'));

    expect($changed->items)->toEqual([new SnapshotItem('price_staff_test', 1)])
        ->and(billingFake()->asked)->toBe([['changePlan', ['subscription' => 'sub_1', 'plan' => 'staff', 'price' => 'price_staff_test']]]);
});

it('answers a read of a changed subscription with the snapshot the change answered', function () {
    billingFake()->script(billingSnapshot('sub_1', 'cus_1', 'active', ['price_floor_test']));
    $changed = billingFake()->changePlan('sub_1', app(PlanCatalogue::class)->plan('staff'));

    expect(billingFake()->subscription('sub_1'))->toBe($changed);
});

it('answers a cancel with the scripted subscription ended, its items and period kept, reads it ended from then on, and records the question', function () {
    billingFake()->script(billingSnapshot('sub_1', 'cus_1', 'active', ['price_staff_test']));

    $ended = billingFake()->cancel('sub_1');

    expect([$ended->subscriptionId, $ended->customerId, $ended->status])->toBe(['sub_1', 'cus_1', 'canceled'])
        ->and($ended->items)->toEqual([new SnapshotItem('price_staff_test', 1)])
        ->and($ended->periodEnd)->toEqual(billingSnapshot('sub_1', 'cus_1', 'active', ['price_staff_test'])->periodEnd)
        ->and(billingFake()->subscription('sub_1'))->toBe($ended)
        ->and(billingFake()->asked)->toBe([['cancel', ['subscription' => 'sub_1']], ['subscription', ['subscription' => 'sub_1']]]);
});

it('refuses a cancel with what a test set, after recording the question, the scripted subscription left as it was', function () {
    billingFake()->script(billingSnapshot('sub_1', 'cus_1', 'active', ['price_staff_test']));
    billingFake()->cancelFails = BillingProviderFailed::unreachable('cancel a subscription', 'Could not resolve host');

    expect(fn () => billingFake()->cancel('sub_1'))->toThrow(BillingProviderFailed::class, 'The billing provider could not be reached to cancel a subscription: Could not resolve host')
        ->and(billingFake()->asked)->toBe([['cancel', ['subscription' => 'sub_1']]])
        ->and(billingFake()->subscription('sub_1')->status)->toBe('active');
});

it('answers the portal with the provider\'s address for the customer, and records the question', function () {
    expect(billingFake()->openPortal('cus_1', 'https://luna.test/admin/billing'))->toBe('https://billing.test/portal/cus_1')
        ->and(billingFake()->asked)->toBe([['openPortal', ['customer' => 'cus_1', 'return' => 'https://luna.test/admin/billing']]]);
});

it('answers the tax ids scripted for each customer, none for one never scripted, and records each question', function () {
    $held = [new TaxId('eu_vat', 'EL123456789')];
    billingFake()->scriptTaxIds('cus_1', $held);

    expect(billingFake()->taxIds('cus_1'))->toBe($held)
        ->and(billingFake()->taxIds('cus_2'))->toBe([])
        ->and(billingFake()->asked)->toBe([['taxIds', ['customer' => 'cus_1']], ['taxIds', ['customer' => 'cus_2']]]);
});

it('throws the failure scripted for a customer\'s tax ids after recording the question', function () {
    billingFake()->scriptTaxIds('cus_1', BillingProviderFailed::unreachable('read the tax ids', 'Could not resolve host'));

    expect(fn () => billingFake()->taxIds('cus_1'))->toThrow(BillingProviderFailed::class, 'The billing provider could not be reached to read the tax ids: Could not resolve host')
        ->and(billingFake()->asked)->toBe([['taxIds', ['customer' => 'cus_1']]]);
});

it('passes the tax ids question through the decorator to the fake', function () {
    $held = [new TaxId('eu_vat', 'EL123456789')];
    $fake = billingFake()->scriptTaxIds('cus_1', $held);
    BillingPortDecorator::around($fake);

    expect(billingPort())->toBeInstanceOf(BillingPortDecorator::class)
        ->and(billingPort()->taxIds('cus_1'))->toBe($held)
        ->and($fake->asked)->toBe([['taxIds', ['customer' => 'cus_1']]]);
});

it('verifies a notification signed with the secret a test knows and reads it as a notice', function () {
    $payload = FakeBillingProvider::notice('evt_1', NoticeKind::ChargeFailed, 'cus_1', 'sub_1');

    $notice = billingFake()->verify($payload, FakeBillingProvider::sign($payload));

    expect([$notice->eventId, $notice->type, $notice->kind, $notice->customerId, $notice->subscriptionId])
        ->toBe(['evt_1', 'fake.charge_failed', NoticeKind::ChargeFailed, 'cus_1', 'sub_1']);
});

it('refuses a notification whose signature it cannot verify', function (string $signature) {
    $payload = FakeBillingProvider::notice('evt_1', NoticeKind::ChargeFailed, 'cus_1');

    expect(fn () => billingFake()->verify($payload, $signature))->toThrow(SignatureRefused::class);
})->with([
    'no signature' => [''],
    'another payload\'s signature' => [FakeBillingProvider::sign('{"id":"evt_2"}')],
]);

it('reads the plans\' price identifiers from the environment', function () {
    expect(array_map(fn ($plan): array => [$plan->key, $plan->priceId, $plan->features->toSlugs()], app(PlanCatalogue::class)->plans()))
        ->toBe([['floor', 'price_floor_test', []], ['staff', 'price_staff_test', ['staff']]]);
});

it('reads the grace length from configuration', function () {
    expect(app(PlanCatalogue::class)->graceDays())->toBe(7);
});
