<?php

use App\Billing\NoticeKind;
use App\Billing\PlanChangeRefused;
use App\Billing\TaxId;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\StaffController;
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
