<?php

use App\Billing\NoticeKind;
use App\Billing\PlanChangeRefused;
use App\Billing\TaxId;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\StaffController;
use App\Site\SiteLanguage;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Support\FakeBillingProvider;

/*
 * The Billing screen in a real browser: pier's owner, on the floor with the implicit member alone
 * and subscribed at the fake provider, opens Billing from the rail, reads the active sentence,
 * adds staff and sees the rail's Closures entry relabelled Staff, adds a second member on Staff,
 * is refused the removal with the count, deactivates the member and removes the feature, after
 * which the entry reads Closures again, no banner and no record beside the plan name it, the
 * owner having asked, and the Staff screen, opened from that entry, carries its own read-only
 * notice instead. A trial's owner chooses a plan through the checkout and comes back to Billing
 * reading active on it, as a canceled owner subscribes again. The provider is the fake the test
 * environment binds, answering in this process. Each success toast is dismissed as seen, so no
 * assertion rides on the auto-clear timer.
 *
 * The cards are measured too. On a wide screen the plan the business is on sits beside the change
 * it offers, a trial's plans sit side by side, and a free account's one banner, no wider than
 * 760px, holds its 60px tick beside the sentence. On a phone, in each language, each of them
 * stacks and the page is never wider than the screen.
 *
 * Greek's longer labels are measured against the cards that hold them. The change's button keeps
 * to one line, so the plan row takes two cards only where that line has room, read at the widths
 * around the step. A plan's button of the choice shows a longer line while its press is sent, and
 * wraps inside its card, as the one button of a way back that posts its plan wraps inside the
 * notice on a phone: the write is held in the page so the line stays to be read.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);

    $tenant = DB::table('tenants')->where('slug', 'pier')->value('id');
    DB::table('subscriptions')->insert([
        'tenant_id' => $tenant,
        'provider_customer_id' => 'cus_pier',
        'provider_subscription_id' => 'sub_pier',
        'status' => 'active',
        'items' => json_encode([['price' => 'price_floor_test', 'quantity' => 1]], JSON_THROW_ON_ERROR),
    ]);
    DB::table('tenants')->where('slug', 'pier')->update(['plan_ref' => 'price_floor_test']);
    billingFake()->script(billingSnapshot('sub_pier', 'cus_pier', 'active', ['price_floor_test']));
});

afterEach(fn () => dropDemoSchemas());

// The rail's own link, reached by its target: the page's text holds "Billing" elsewhere, in the
// plan card and in the narrowing banner, as it holds "Staff" (Admin::STAFF_ENTRY).
const BILLING_ENTRY = '.admin-rail a[href="/admin/billing"]';

/**
 * The port's questions less the tax id reads: each Billing page with a row loads its tax id line
 * after the render, in its own request, so how many have landed when a walk asserts is the
 * browser's timing, not the screen's behaviour.
 *
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function billingPortWrites(): array
{
    return array_values(array_filter(billingPortReads(), fn (array $question): bool => $question[0] !== 'taxIds'));
}

it('adds staff from Billing, is refused the removal while two members are active, and removes it with one', function () {
    // A made-up tax id: the Payment card's number line proves the router loads the deferred prop
    // with no Deferred around it, and that the server's name for the prop is the client's.
    billingFake()->scriptTaxIds('cus_pier', [new TaxId('eu_vat', 'EL0TEST0ID')]);
    $page = Admin::signedIn($this, 'pier', Admin::owner('pier'));

    $page->assertCount('.admin-rail-entry', 10)
        ->assertSeeIn(Admin::STAFF_ENTRY, navLabel('closures'))
        ->click(BILLING_ENTRY)
        ->waitForText(adminLine(BillingController::ACTIVE))
        ->assertPathIs('/admin/billing')
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/billing')
        ->assertSeeIn('.admin-billing__plan-name', 'Solo')
        ->waitForText(adminLine(BillingController::TAX_ID_NUMBER, ['tax_id' => 'EL0TEST0ID']))
        ->assertSeeIn('.admin-billing__tax-id', adminLine(BillingController::TAX_ID_NUMBER, ['tax_id' => 'EL0TEST0ID']))
        ->assertSeeIn('.admin-billing__vat', adminLine(BillingController::VAT_LINE))
        ->assertCount('.admin-billing__paused', 0)
        ->click('.admin-billing__change-button')
        ->waitForText(adminLine(BillingController::STAFF_ADDED))
        ->click('.admin-toast-dismiss')
        ->assertSeeIn(Admin::STAFF_ENTRY, navLabel('staff'))
        ->assertCount('.admin-rail-entry', 11)
        ->assertSeeIn('.admin-billing__change-button', adminLine('admin/billing.change.remove'));

    $page->click(Admin::STAFF_ENTRY)
        ->waitForText(adminLine('admin/staff.table.hours'))
        ->click('.admin-staff-new')
        ->waitForText(adminLine('admin/staff.form.needs_name'))
        ->fill('#staff-name', 'Maria Ioannou')
        ->click('.admin-staff-save')
        ->waitForText(adminLine(StaffController::CREATED))
        ->click('.admin-toast-dismiss');

    $created = DB::table('tenant_pier.staff')->where('name', 'Maria Ioannou')->value('id');
    assert(is_int($created));
    $refusal = adminChoice(PlanChangeRefused::DOWNGRADE_SENTENCE, 2);

    $page->click(BILLING_ENTRY)
        ->waitForText($refusal)
        ->assertSeeIn('.admin-billing__staff-note', $refusal)
        ->assertAttribute('.admin-billing__staff-link', 'href', '/admin/staff')
        ->click('.admin-billing__change-button')
        ->waitForText(adminLine('admin/shell.toaster.dismiss'))
        ->assertSeeIn('.admin-toast', $refusal)
        ->click('.admin-toast-dismiss');

    expect(DB::table('tenants')->where('slug', 'pier')->value('entitlements'))->toBe('{staff}');

    $page->click('.admin-billing__staff-link')
        ->waitForText(adminLine('admin/staff.table.hours'))
        ->assertPathIs('/admin/staff');
    $page->click('Maria Ioannou')
        ->waitForText(adminLine('admin/staff.form.user'))
        ->assertPathIs('/admin/staff/'.$created)
        ->select('#staff-status', 'inactive')
        ->click('.admin-staff-save')
        ->waitForText(adminLine(StaffController::SAVED))
        ->click('.admin-toast-dismiss');

    $page->click(BILLING_ENTRY)
        ->waitForText(adminLine(BillingController::ACTIVE))
        ->assertCount('.admin-billing__staff-note', 0)
        ->click('.admin-billing__change-button')
        ->waitForText(adminLine(BillingController::STAFF_REMOVED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-rail-entry', 10)
        ->assertSeeIn(Admin::STAFF_ENTRY, navLabel('closures'))
        ->assertSeeIn('.admin-billing__plan-name', 'Solo')
        ->assertSeeIn('.admin-billing__change-button', adminLine('admin/billing.change.add'))
        ->assertMissing('.admin-billing__narrowed')
        ->assertMissing('.admin-narrowed')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenants')->where('slug', 'pier')->value('entitlements'))->toBe('{}')
        ->and(DB::table('tenants')->where('slug', 'pier')->value('narrowed'))->toBe('{}');

    // The owner asked, so nothing records the removal: the Staff screen, reached by the rail's
    // entry now labelled Closures, shows the read-only roster under its own info notice and no
    // banner above it.
    $page->click(Admin::STAFF_ENTRY)
        ->waitForText(adminLine('admin/staff.table.hours'))
        ->assertPathIs('/admin/staff')
        ->assertSeeIn('.admin-staff-readonly', adminLine('admin/shell.banners.features.staff.gone', ['billing' => adminLine('admin/shell.banners.billing')]))
        ->assertPresent('.admin-staff-readonly a.admin-staff-readonly__billing')
        ->assertMissing('.admin-narrowed')
        ->assertMissing('.admin-staff-new')
        ->assertNoJavaScriptErrors();
});

it('subscribes again from canceled through the checkout and its completion, reading active with Staff on the rail', function () {
    // pier's subscription ended on the staff plan: the row still names it, the rail carrying
    // Closures where Staff stood since a frozen state resolves to the floor.
    DB::table('subscriptions')->update(['status' => 'canceled', 'items' => json_encode([['price' => 'price_staff_test', 'quantity' => 1]], JSON_THROW_ON_ERROR)]);
    DB::table('tenants')->where('slug', 'pier')->update(['plan_ref' => 'price_staff_test', 'entitlements' => '{staff}', 'subscription_state' => 'canceled']);

    $page = Admin::signedIn($this, 'pier', Admin::owner('pier'));

    $page->assertCount('.admin-rail-entry', 10)
        ->assertSeeIn(Admin::STAFF_ENTRY, navLabel('closures'))
        ->click(BILLING_ENTRY)
        ->waitForText(adminLine(BillingController::CANCELED))
        ->assertSeeIn('.admin-billing__resubscribe', adminLine(BillingController::SUBSCRIBE_AGAIN))
        ->assertCount('.admin-billing__pending', 0)
        ->click('.admin-billing__resubscribe')
        ->waitForText(adminLine(BillingController::ON_ITS_WAY))
        ->assertPathIs('/admin/billing')
        ->assertSeeIn('.admin-billing__sentence', adminLine(BillingController::CANCELED))
        ->assertSeeIn('.admin-billing__resubscribe', adminLine(BillingController::CONTINUE_CHECKOUT))
        ->assertNoJavaScriptErrors();

    expect(billingPortWrites())->toBe([['startCheckout', [
        'plan' => 'staff',
        'price' => 'price_staff_test',
        'customer' => 'cus_pier',
        'email' => Admin::owner('pier'),
        'success' => Admin::url('pier', '/billing'),
        'cancel' => Admin::url('pier', '/billing'),
    ]]]);

    billingFake()->script(billingSnapshot('sub_back', 'cus_pier', 'active', ['price_staff_test']));
    deliverBillingNotice($this, FakeBillingProvider::notice('evt_back', NoticeKind::CheckoutCompleted, 'cus_pier', 'sub_back'), url: app(TenantUrls::class)->central('/webhooks/billing'))->assertOk();

    $page->click(BILLING_ENTRY)
        ->waitForText(adminLine(BillingController::ACTIVE))
        ->assertCount('.admin-billing__resubscribe', 0)
        ->assertCount('.admin-billing__pending', 0)
        ->assertSeeIn(Admin::STAFF_ENTRY, navLabel('staff'))
        ->assertCount('.admin-rail-entry', 11)
        ->assertNoJavaScriptErrors();
});

it('chooses a plan in a trial through the checkout and its completion, reading active on the plan chosen', function () {
    // pier in a trial with no subscriptions row, on the system clock, its end five days on at an
    // hour of the tenant's day, spelled in the tenant's zone and the default formats on the
    // screen.
    $ends = (new DateTimeImmutable('now', new DateTimeZone('Europe/London')))->add(new DateInterval('P5D'))->setTime(9, 30);
    DB::table('subscriptions')->delete();
    DB::table('tenants')->where('slug', 'pier')->update([
        'subscription_state' => 'trial',
        'entitlements' => '{staff}',
        'plan_ref' => null,
        'trial_ends_at' => $ends->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
    ]);

    $page = Admin::signedIn($this, 'pier', Admin::owner('pier'));

    $page->click(BILLING_ENTRY)
        ->waitForText(adminLine(BillingController::TRIAL))
        ->assertSeeIn('.admin-billing__trial', adminLine('admin/billing.trial_end', ['end' => $ends->format('d/m/Y H:i')]))
        ->assertSeeIn('.admin-billing__plan-name', adminLine(BillingController::TRIAL_PLAN))
        ->assertCount('.admin-billing__paused', 0)
        ->assertCount('.admin-billing__portal', 0)
        ->assertSeeIn('.admin-billing__resubscribe', adminLine(BillingController::CHOOSE_PLAN))
        ->click('.admin-billing__resubscribe')
        ->assertSeeIn('[data-plan="floor"] .admin-billing__option-name', 'Solo')
        ->assertSeeIn('[data-plan="staff"] .admin-billing__option-name', 'Team')
        ->click('[data-plan="staff"] .admin-billing__option-button')
        ->waitForText(adminLine(BillingController::ON_ITS_WAY))
        ->assertPathIs('/admin/billing')
        ->assertQueryStringHas('fake_checkout', 'cus_fake_1')
        ->assertQueryStringHas('plan', 'staff')
        ->assertSeeIn('.admin-billing__resubscribe', adminLine(BillingController::CONTINUE_CHECKOUT))
        ->assertNoJavaScriptErrors();

    expect(billingPortWrites())->toBe([['startCheckout', [
        'plan' => 'staff',
        'price' => 'price_staff_test',
        'customer' => null,
        'email' => Admin::owner('pier'),
        'success' => Admin::url('pier', '/billing'),
        'cancel' => Admin::url('pier', '/billing'),
    ]]]);

    billingFake()->script(billingSnapshot('sub_chosen', 'cus_fake_1', 'active', ['price_staff_test']));
    deliverBillingNotice($this, FakeBillingProvider::notice('evt_chosen', NoticeKind::CheckoutCompleted, 'cus_fake_1', 'sub_chosen'), url: app(TenantUrls::class)->central('/webhooks/billing'))->assertOk();

    $page->click(BILLING_ENTRY)
        ->waitForText(adminLine(BillingController::ACTIVE))
        ->assertSeeIn('.admin-billing__plan-name', 'Team')
        ->assertCount('.admin-billing__resubscribe', 0)
        ->assertCount('.admin-billing__pending', 0)
        ->assertSeeIn(Admin::STAFF_ENTRY, navLabel('staff'))
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenants')->where('slug', 'pier')->value('trial_ends_at'))->toBeNull();
});

// The page is no wider than the window: the document's scroll width against its client width,
// which leaves the scrollbar out.
const BILLING_NO_SIDEWAYS = 'document.fonts.ready.then(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)';

/**
 * A script answering whether the boxes the selector finds, two or more in the page's order, stand
 * one under another on one left edge, or side by side on one top edge.
 */
function billingBoxes(string $selector, bool $stacked): string
{
    $placed = $stacked
        ? 'box.top >= boxes[index - 1].bottom - 1 && Math.abs(box.left - boxes[0].left) <= 1'
        : 'box.left >= boxes[index - 1].right - 1 && Math.abs(box.top - boxes[0].top) <= 1';

    return "document.fonts.ready.then(() => { const boxes = [...document.querySelectorAll('{$selector}')].map((node) => node.getBoundingClientRect()); return boxes.length > 1 && boxes.every((box, index) => index === 0 || ({$placed})); })";
}

/**
 * A script answering whether the free account's banner is at most 760px wide and inside the
 * window, its tick a 60px square, and the sentence beside the tick or under it.
 */
function billingBanner(bool $beside): string
{
    $placed = $beside ? 'sentence.left >= tick.right && sentence.top < tick.bottom' : 'sentence.top >= tick.bottom';

    return "document.fonts.ready.then(() => { const card = document.querySelector('.admin-billing__free'); const mark = card?.querySelector('.admin-billing__tick'); const words = card?.querySelector('.admin-billing__sentence'); if (!card || !mark || !words) { return false; } const box = card.getBoundingClientRect(); const tick = mark.getBoundingClientRect(); const sentence = words.getBoundingClientRect(); return box.width <= 760.5 && box.right <= document.documentElement.clientWidth + 1 && Math.abs(tick.width - 60) < 0.5 && Math.abs(tick.height - 60) < 0.5 && ({$placed}); })";
}

/** pier in a trial with no subscriptions row, its end five days on, as the trial's walk sets it. */
function billingTrial(): void
{
    DB::table('subscriptions')->delete();
    DB::table('tenants')->where('slug', 'pier')->update([
        'subscription_state' => 'trial',
        'entitlements' => '{staff}',
        'plan_ref' => null,
        'trial_ends_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('P5D'))->format('Y-m-d H:i:sP'),
    ]);
}

/** pier in the free shape: active, with no subscriptions row and no trial end. */
function billingFree(): void
{
    DB::table('subscriptions')->delete();
    DB::table('tenants')->where('slug', 'pier')->update(['subscription_state' => 'active', 'entitlements' => '{}', 'plan_ref' => null, 'trial_ends_at' => null]);
}

it('lays the plan cards and a trial\'s plans side by side on a wide screen, and a free account\'s banner with its tick beside the sentence, stacked on a narrow one', function () {
    $page = Admin::signedIn($this, 'pier', Admin::owner('pier'));

    $page->resize(1440, 900)
        ->navigate(Admin::url('pier', '/billing'))
        ->waitForText(adminLine(BillingController::ACTIVE))
        ->assertScript(billingBoxes('.admin-billing__current, .admin-billing__change', false), true)
        ->assertScript(BILLING_NO_SIDEWAYS, true);

    billingTrial();

    $page->navigate(Admin::url('pier', '/billing'))
        ->waitForText(adminLine(BillingController::TRIAL))
        ->click('.admin-billing__resubscribe')
        ->assertVisible('[data-plan="staff"] .admin-billing__option-button')
        ->assertScript(billingBoxes('.admin-billing__option', false), true)
        ->assertScript(BILLING_NO_SIDEWAYS, true);

    billingFree();

    $page->navigate(Admin::url('pier', '/billing'))
        ->waitForText(adminLine(BillingController::FREE))
        ->assertMissing('.admin-billing__plan')
        ->assertMissing('.admin-billing__portal')
        ->assertScript(billingBanner(true), true)
        ->assertScript(BILLING_NO_SIDEWAYS, true)
        ->resize(390, 844)
        ->assertScript(billingBanner(false), true)
        ->assertScript(BILLING_NO_SIDEWAYS, true)
        ->assertNoJavaScriptErrors();
});

it('stacks the plan cards, a trial\'s plans and a free account\'s banner on a phone, the page never wider than the screen', function (SiteLanguage $language) {
    // A longer line in one language widens a card in that language alone, so the owner reads the
    // screen in each, chosen before the sign-in.
    DB::table('users')->where('email', Admin::owner('pier'))->update(['locale' => $language->value]);
    $page = Admin::signInOnPhone($this, 'pier', Admin::owner('pier'))->assertVisible(Admin::HOME);

    $page->navigate(Admin::url('pier', '/billing'))
        ->waitForText(adminLine(BillingController::ACTIVE, [], $language))
        ->assertScript(billingBoxes('.admin-billing__current, .admin-billing__change', true), true)
        ->assertScript(BILLING_NO_SIDEWAYS, true);

    billingTrial();

    $page->navigate(Admin::url('pier', '/billing'))
        ->waitForText(adminLine(BillingController::TRIAL, [], $language))
        ->click('.admin-billing__resubscribe')
        ->assertVisible('[data-plan="staff"] .admin-billing__option-button')
        ->assertScript(billingBoxes('.admin-billing__option', true), true)
        ->assertScript(BILLING_NO_SIDEWAYS, true);

    billingFree();

    $page->navigate(Admin::url('pier', '/billing'))
        ->waitForText(adminLine(BillingController::FREE, [], $language))
        ->assertMissing('.admin-billing__plan')
        ->assertScript(billingBanner(false), true)
        ->assertScript(BILLING_NO_SIDEWAYS, true)
        ->assertNoJavaScriptErrors();
})->with([SiteLanguage::English, SiteLanguage::Greek]);

/**
 * A script answering where the control leaves the box it stands in, the card around it unless
 * another box is named: by how many pixels its right edge passes the room inside the box's own
 * border and padding, and by how many the box passes the one around it, with the window's width.
 * It answers an empty string where both are held, so a failure reads as its measure. A box that
 * clips what it holds keeps a control past its edge out of the page's scroll width, so the edges
 * themselves are compared. A window resized across the width at which the rail comes or goes is
 * laid out again a moment later, so the measure is taken once the box around has kept its place
 * for a few frames.
 */
function billingHeld(string $control, string $around, string $card = 'section'): string
{
    $atRest = "new Promise((resolve) => { let last = null; let calm = 0; const look = () => { const box = document.querySelector('{$around}')?.getBoundingClientRect(); const now = box ? box.left + ':' + box.width : ''; calm = now === last ? calm + 1 : 0; last = now; if (calm >= 5) { resolve(); } else { requestAnimationFrame(look); } }; look(); })";

    return "document.fonts.ready.then(() => {$atRest}).then(() => { const control = document.querySelector('{$control}'); const card = control?.closest('{$card}'); const around = card?.parentElement?.closest('{$around}'); if (!control || !card || !around) { return 'not on the page'; } const edge = card.getBoundingClientRect().right; const style = getComputedStyle(card); const past = control.getBoundingClientRect().right - (edge - parseFloat(style.paddingRight) - parseFloat(style.borderRightWidth)); const out = edge - around.getBoundingClientRect().right; const found = [past > 0.5 ? 'the control ' + past.toFixed(1) + 'px past the room in its card' : '', out > 0.5 ? 'the card ' + out.toFixed(1) + 'px past the box around it' : ''].filter(Boolean); return found.length === 0 ? '' : 'at ' + document.documentElement.clientWidth + 'px: ' + found.join(', '); })";
}

it('keeps the change\'s one-line button inside its card in Greek, the plan row taking two cards only where the longer label has room', function () {
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('pier'))->update(['locale' => $el->value]);
    $page = Admin::signedIn($this, 'pier', Admin::owner('pier'));

    $page->resize(900, 900)
        ->navigate(Admin::url('pier', '/billing'))
        ->waitForText(adminLine(BillingController::ACTIVE, [], $el))
        ->assertSeeIn('.admin-billing__change-button', adminLine('admin/billing.change.add', [], $el))
        ->assertScript("getComputedStyle(document.querySelector('.admin-billing__change-button')).whiteSpace", 'nowrap');

    // The narrowest window that shows the rail, where the section is at its narrowest beside it;
    // each side of the step at which the row takes two cards, with the rail and without it; the
    // widths at which a row taking two cards a step sooner is too narrow for the label; and a
    // phone's width, where the one card under the plan's is at its narrowest.
    foreach ([900, 1084, 1083, 988, 816, 815, 640, 390] as $width) {
        $page->resize($width, 900)->assertScript(billingHeld('.admin-billing__change-button', '.admin-billing__plan'), '');
    }

    $page->assertNoJavaScriptErrors();
});

// The checkout's own write held in the page: it is opened, counted and never sent, so the button
// pressed keeps the words it shows while its write is on its way for as long as the page is read.
const BILLING_HOLD_CHECKOUT = "(() => { const open = XMLHttpRequest.prototype.open; const send = XMLHttpRequest.prototype.send; window.heldCheckouts = 0; XMLHttpRequest.prototype.open = function (method, url, ...rest) { this.heldCheckout = String(method).toUpperCase() === 'POST' && String(url).includes('/admin/billing/checkout'); return open.call(this, method, url, ...rest); }; XMLHttpRequest.prototype.send = function (...body) { if (this.heldCheckout) { window.heldCheckouts += 1; return; } return send.apply(this, body); }; return true; })()";

it('wraps the words a plan\'s button shows while its press is sent inside its card in Greek, beside the rail and on a phone\'s width, the page never wider than the window', function () {
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('pier'))->update(['locale' => $el->value]);
    billingTrial();

    $pressed = '[data-plan="staff"] .admin-billing__option-button';
    $page = Admin::signedIn($this, 'pier', Admin::owner('pier'));

    $page->resize(1024, 900)
        ->navigate(Admin::url('pier', '/billing'))
        ->waitForText(adminLine(BillingController::TRIAL, [], $el))
        ->click('.admin-billing__resubscribe')
        ->assertSeeIn($pressed, adminLine('admin/billing.checkout.choose', ['plan' => 'Team'], $el))
        ->assertScript(billingHeld($pressed, '.admin-billing__notice'), '')
        ->assertScript(BILLING_HOLD_CHECKOUT, true)
        ->click($pressed)
        ->waitForText(adminLine('admin/billing.checkout.busy', [], $el))
        ->assertAttribute($pressed, 'aria-busy', 'true')
        ->assertScript('window.heldCheckouts', 1);

    foreach ([[1024, 900], [900, 900], [390, 844]] as [$width, $height]) {
        $page->resize($width, $height)
            ->assertSeeIn($pressed, adminLine('admin/billing.checkout.busy', [], $el))
            ->assertScript(billingHeld($pressed, '.admin-billing__notice'), '')
            ->assertScript(BILLING_NO_SIDEWAYS, true);
    }

    $page->assertNoJavaScriptErrors();

    // The write never left the page, so the provider was asked for no checkout.
    expect(billingPortWrites())->toBe([]);
});

it('wraps the words the one checkout button of a way back shows while its press is sent inside the notice in Greek, on a phone\'s width and beside the rail, the page never wider than the window', function () {
    // pier's subscription ended on the staff plan, which the catalogue lists and its one member
    // fits, so the way back is one button posting that plan with no choice under it.
    $el = SiteLanguage::Greek;
    DB::table('users')->where('email', Admin::owner('pier'))->update(['locale' => $el->value]);
    DB::table('subscriptions')->update(['status' => 'canceled', 'items' => json_encode([['price' => 'price_staff_test', 'quantity' => 1]], JSON_THROW_ON_ERROR)]);
    DB::table('tenants')->where('slug', 'pier')->update(['plan_ref' => 'price_staff_test', 'entitlements' => '{staff}', 'subscription_state' => 'canceled']);

    $pressed = '.admin-billing__resubscribe';
    $inNotice = billingHeld($pressed, '.admin-billing', '.admin-billing__notice');
    $page = Admin::signedIn($this, 'pier', Admin::owner('pier'));

    $page->resize(390, 844)
        ->navigate(Admin::url('pier', '/billing'))
        ->waitForText(adminLine(BillingController::CANCELED, [], $el))
        ->assertSeeIn($pressed, adminLine(BillingController::SUBSCRIBE_AGAIN, [], $el))
        ->assertCount('.admin-billing__choice', 0)
        ->assertScript($inNotice, '')
        ->assertScript(BILLING_HOLD_CHECKOUT, true)
        ->click($pressed)
        ->waitForText(adminLine('admin/billing.checkout.busy', [], $el))
        ->assertAttribute($pressed, 'aria-busy', 'true')
        ->assertScript('window.heldCheckouts', 1)
        ->assertCount('.admin-billing__choice', 0);

    foreach ([[390, 844], [900, 900]] as [$width, $height]) {
        $page->resize($width, $height)
            ->assertSeeIn($pressed, adminLine('admin/billing.checkout.busy', [], $el))
            ->assertScript($inNotice, '')
            ->assertScript(BILLING_NO_SIDEWAYS, true);
    }

    $page->assertNoJavaScriptErrors();

    // The write never left the page, so the provider was asked for no checkout.
    expect(billingPortWrites())->toBe([]);
});
