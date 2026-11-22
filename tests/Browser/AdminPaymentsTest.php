<?php

use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Requests\PaymentCredentialsRequest;
use App\Infrastructure\Payment\PaymentRoutes;
use App\Site\SiteLanguage;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;
use Tests\Support\FakePaymentAdapter;

/*
 * The Payments section of Settings in a real browser: luna's owner, with nothing connected, reads
 * the degrade line and the fake provider's two modes, opens the own-mode form and fills it. With
 * a settings field edited and unsaved, Enter in a credential input runs the section's own save
 * and never the settings form's implicit submission, which jsdom cannot show: the settings row
 * stands and the edit stays on screen. The connected state follows with its masks and its webhook
 * URL; Replace opens every field empty and re-sends the whole set, the URL kept; Change URL,
 * behind its confirmation, is kept once and then mints a new token, the old one unknown to the
 * routing table; Disconnect, behind its confirmation, removes both rows and brings the degrade
 * line back. The provider is the fake the test environment registers beside Stripe, answering in
 * this process. Each success toast is dismissed as seen, so no assertion rides on the auto-clear
 * timer. Every control is reached by class, since several labels change with the state.
 */

const PAYMENTS_WALK_PAYDESK = '.admin-payments-provider[data-provider="paydesk"]';

/**
 * A line of the payments section in English, read through the translator, the provider the
 * fake's name.
 *
 * @param  array<string, string>  $values
 */
function paymentsWalkLine(string $key, array $values = []): string
{
    return adminLine('admin/payments.'.$key, ['provider' => 'Paydesk', ...$values]);
}

/** Whether any input inside the Payments section holds a value. */
const PAYMENTS_WALK_FILLED = "[...document.querySelectorAll('.admin-section--payments input')].some((input) => input.value !== '')";

/**
 * Every payment card on the page against the Payments card around it: how many are drawn, how
 * many scroll sideways inside themselves, reach past the Payments card's edges or hold a part
 * that reaches past their own, and whether the page scrolls sideways.
 */
const PAYMENTS_CARDS_INSIDE = <<<'JS'
    document.fonts.ready.then(() => {
        const section = document.querySelector('.admin-section--payments').getBoundingClientRect();
        const cards = [...document.querySelectorAll('.admin-payments-provider > section, .admin-payments-credentials, .admin-payments-connection')];
        const past = (box, edges) => box.left < edges.left - 1 || box.right > edges.right + 1;
        return {
            drawn: cards.length,
            sideways: cards.filter((card) => card.scrollWidth > card.clientWidth + 1).length,
            outside: cards.filter((card) => past(card.getBoundingClientRect(), section)).length,
            spill: cards.filter((card) => [...card.querySelectorAll('*')].some((part) => part.getClientRects().length > 0 && past(part.getBoundingClientRect(), card.getBoundingClientRect()))).length,
            page: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        };
    })
    JS;

/**
 * The webhook address against its connection card: whether it stays inside the card with nothing
 * of it scrolled out of sight, and how many lines it is broken across.
 */
const PAYMENTS_WEBHOOK_WRAPS = <<<'JS'
    document.fonts.ready.then(() => {
        const card = document.querySelector('.admin-payments-connection').getBoundingClientRect();
        const address = document.querySelector('.admin-payments-webhook__url');
        const box = address.getBoundingClientRect();
        const style = getComputedStyle(address);
        const text = box.height - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom);
        return {
            inside: box.left >= card.left - 1 && box.right <= card.right + 1 && address.scrollWidth <= address.clientWidth + 1,
            lines: Math.round(text / parseFloat(style.lineHeight)),
        };
    })
    JS;

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

it('connects the fake provider in own mode, keeps Enter off the settings save, replaces the set, changes the URL through its confirmation and disconnects', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('settings'))
        ->waitForText(paymentsWalkLine('degrade'))
        ->assertPathIs('/admin/settings')
        ->assertSeeIn(PAYMENTS_WALK_PAYDESK.' .admin-payments-provider__name', 'Paydesk')
        ->assertCount(PAYMENTS_WALK_PAYDESK.' .admin-payments-mode', 2)
        ->assertSeeIn(PAYMENTS_WALK_PAYDESK.' .admin-payments-mode--platform', paymentsWalkLine('not_offered'))
        ->assertCount(PAYMENTS_WALK_PAYDESK.' .admin-payments-mode--own .admin-payments-own', 1)
        ->assertCount('#payments-paydesk-api_token', 0)
        ->click(PAYMENTS_WALK_PAYDESK.' .admin-payments-own')
        ->assertAttribute('#payments-paydesk-merchant_code', 'type', 'text')
        ->assertAttribute('#payments-paydesk-api_token', 'type', 'password')
        ->assertAttribute('#payments-paydesk-signing_phrase', 'type', 'password');
    $credentials = paymentFakeCredentials();
    $page->fill('#payments-paydesk-merchant_code', $credentials['merchant_code'])
        ->fill('#payments-paydesk-api_token', $credentials['api_token']);

    // An unsaved edit in another section, then Enter in a credential input. The settings form
    // has an enabled submit button, so without the section's guard the browser would submit it.
    // The first Enter meets the section's own check, which sends nothing and holds nothing, so
    // only the guard stands between the key and the settings save; the second runs the
    // section's save, and a settings save sent by the first would have landed before it.
    $before = (array) settingsOf('luna')->first();
    expect($before['theme'])->toBe('violet');
    $page->click('.admin-settings-theme[data-value="blue"]')
        ->keys('#payments-paydesk-api_token', 'Enter')
        ->assertSeeIn(PAYMENTS_WALK_PAYDESK.' .admin-payments-credentials', adminLine(PaymentCredentialsRequest::MISSING, ['field' => 'Signing phrase']))
        ->assertScript('document.activeElement.id', 'payments-paydesk-signing_phrase')
        ->fill('#payments-paydesk-signing_phrase', $credentials['signing_phrase'])
        ->keys('#payments-paydesk-signing_phrase', 'Enter')
        ->waitForText(adminLine(PaymentsController::SAVED))
        ->waitForText(paymentsWalkLine('states.active'))
        ->assertDontSee(adminLine(SettingsController::SAVED))
        ->assertAttribute('.admin-settings-theme[data-value="blue"]', 'aria-checked', 'true')
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0);

    expect((array) settingsOf('luna')->first())->toBe($before);

    $token = paymentConnections()->webhookToken();
    expect($token)->toBeString();
    assert(is_string($token));
    $url = app(TenantUrls::class)->central('/webhooks/'.FakePaymentAdapter::SLUG.'/'.$token);

    // The last test's instant is the tenant's clock's; the line's words after it are the passed
    // outcome's own.
    [, $passed] = explode(':when', paymentsWalkLine('checked.passed'));
    $page->assertSeeIn('.admin-payments-state__sentence', paymentsWalkLine('states.active'))
        ->assertSeeIn('.admin-payments-connection__name', 'Paydesk')
        ->assertSeeIn('.admin-payments-checked', $passed)
        ->assertCount('.admin-payments-secret__mask', 2)
        ->assertSeeIn('.admin-payments-secrets', 'API token')
        ->assertSeeIn('.admin-payments-secrets', 'Signing phrase')
        ->assertCount('#payments-paydesk-api_token', 0)
        ->assertScript(PAYMENTS_WALK_FILLED, false)
        ->assertSeeIn('.admin-payments-webhook__url', $url);
    foreach (paymentFakeCredentials() as $value) {
        $page->assertDontSee($value);
    }

    // Replace re-enters the whole set: every declared field opens empty, the plain one too.
    $page->click('.admin-payments-replace')
        ->assertValue('#payments-paydesk-merchant_code', '')
        ->assertValue('#payments-paydesk-api_token', '')
        ->assertValue('#payments-paydesk-signing_phrase', '');
    foreach (paymentFakeCredentials() as $field => $value) {
        $page->fill('#payments-paydesk-'.$field, $value);
    }
    $page->click('.admin-payments-save')
        ->waitForText(adminLine(PaymentsController::SAVED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertCount('#payments-paydesk-api_token', 0)
        ->assertSeeIn('.admin-payments-state__sentence', paymentsWalkLine('states.active'))
        ->assertSeeIn('.admin-payments-webhook__url', $url);

    expect(paymentConnections()->webhookToken())->toBe($token);

    // Change URL, behind a confirmation naming that the provider must be told the new address:
    // Keep leaves the token alone; the confirmed change mints a new one over the row and the
    // routing row together.
    $page->click('.admin-payments-rotate')
        ->waitForText(paymentsWalkLine('webhook.confirm_title'))
        ->assertSeeIn('.admin-payments-rotate-confirm__body', paymentsWalkLine('webhook.confirm_body'))
        ->click('.admin-payments-rotate-confirm__keep')
        ->assertCount('.admin-payments-rotate-confirm', 0)
        ->assertSeeIn('.admin-payments-webhook__url', $url);

    expect(paymentConnections()->webhookToken())->toBe($token);

    $page->click('.admin-payments-rotate')
        ->waitForText(paymentsWalkLine('webhook.confirm_title'))
        ->click('.admin-payments-rotate-confirm__change')
        ->waitForText(adminLine(PaymentsController::ROTATED))
        ->assertCount('.admin-payments-rotate-confirm', 0)
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0);

    $rotated = paymentConnections()->webhookToken();
    expect($rotated)->toBeString()->not->toBe($token)
        ->and(app(PaymentRoutes::class)->byToken(FakePaymentAdapter::SLUG, $token))->toBeNull()
        ->and(app(PaymentRoutes::class)->byToken(FakePaymentAdapter::SLUG, (string) $rotated))->toBe(DB::table('tenants')->where('slug', 'luna')->value('id'));
    $page->assertSeeIn('.admin-payments-webhook__url', app(TenantUrls::class)->central('/webhooks/'.FakePaymentAdapter::SLUG.'/'.$rotated))
        ->assertDontSee($url);

    // Disconnect, behind a confirmation naming what stops.
    $page->click('.admin-payments-disconnect')
        ->waitForText(paymentsWalkLine('disconnect.title'))
        ->assertSeeIn('.admin-payments-confirm__body', paymentsWalkLine('disconnect.body'))
        ->click('.admin-payments-confirm__disconnect')
        ->waitForText(adminLine(PaymentsController::DISCONNECTED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->waitForText(paymentsWalkLine('degrade'))
        ->assertCount('.admin-payments-connection', 0)
        ->assertCount(PAYMENTS_WALK_PAYDESK.' .admin-payments-own', 1)
        ->assertAttribute('.admin-settings-theme[data-value="blue"]', 'aria-checked', 'true')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.payment_connections')->count())->toBe(0)
        ->and(DB::table('payment_routes')->count())->toBe(0)
        ->and((array) settingsOf('luna')->first())->toBe($before);
});

it('keeps every payment card inside the Payments card on a phone in Greek and at 1440px, none scrolling sideways, with nothing connected, the credentials open, connected and restricted, and breaks a long webhook address across lines inside its card', function () {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
    $held = ['sideways' => 0, 'outside' => 0, 'spill' => 0, 'page' => false];

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/payments.degrade', [], SiteLanguage::Greek))
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390);
    $none = $page->script(PAYMENTS_CARDS_INSIDE);
    assert(is_array($none) && is_int($none['drawn']));
    expect($none)->toMatchArray($held)
        ->and($none['drawn'])->toBeGreaterThanOrEqual(2);
    Look::shot($page, 'settings-payments-none-390-el');

    $page->click(PAYMENTS_WALK_PAYDESK.' .admin-payments-own')
        ->assertVisible('#payments-paydesk-api_token')
        ->assertAttribute('#payments-paydesk-api_token', 'type', 'password');
    expect($page->script(PAYMENTS_CARDS_INSIDE))->toBe(['drawn' => $none['drawn'] + 1, ...$held]);
    Look::shot($page, 'settings-payments-credentials-390-el');

    // Connected in own mode under a token far longer than a phone's line, so the address can only
    // stay inside its card by breaking.
    connectPaymentFake();
    DB::table('tenant_luna.payment_connections')->update(['webhook_token' => str_repeat('k7Qm2xWv', 8)]);
    $page->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/payments.states.active', ['provider' => 'Paydesk'], SiteLanguage::Greek));
    expect($page->script(PAYMENTS_CARDS_INSIDE))->toBe(['drawn' => 1, ...$held]);
    $wrapped = $page->script(PAYMENTS_WEBHOOK_WRAPS);
    assert(is_array($wrapped));
    expect($wrapped['inside'])->toBeTrue()
        ->and($wrapped['lines'])->toBeGreaterThanOrEqual(2);
    Look::shot($page, 'settings-payments-connected-390-el');

    $page->resize(1440, 900);
    expect($page->script(PAYMENTS_CARDS_INSIDE))->toBe(['drawn' => 1, ...$held]);
    $rewrapped = $page->script(PAYMENTS_WEBHOOK_WRAPS);
    assert(is_array($rewrapped));
    expect($rewrapped['inside'])->toBeTrue();
    Look::shot($page, 'settings-payments-connected-1440');

    // Restricted, the provider's reason quoted verbatim inside the state's notice.
    DB::table('tenant_luna.payment_connections')->update(['state' => 'restricted', 'requirements' => json_encode(['reason' => 'The provider holds payouts until the business address is confirmed.'], JSON_THROW_ON_ERROR)]);
    $page->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/payments.states.restricted', ['provider' => 'Paydesk'], SiteLanguage::Greek))
        ->assertPresent('.admin-payments-state.admin-notice--warning .admin-notice__icon svg');
    expect($page->script(PAYMENTS_CARDS_INSIDE))->toBe(['drawn' => 1, ...$held]);
    Look::shot($page, 'settings-payments-restricted-1440');

    $page->resize(390, 844);
    expect($page->script(PAYMENTS_CARDS_INSIDE))->toBe(['drawn' => 1, ...$held])
        ->and($page->script(PAYMENTS_WEBHOOK_WRAPS))->toMatchArray(['inside' => true]);
    Look::shot($page, 'settings-payments-restricted-390-el');
    $page->assertNoJavaScriptErrors();
});
