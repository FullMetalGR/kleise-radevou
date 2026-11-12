<?php

use App\Application\Clock;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\SettingsController;
use App\Platform\ClosingWindow;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;

/*
 * Closing the account in a real browser: luna's owner, subscribed at the fake provider on the
 * staff plan, opens Settings, reads the Danger zone's close under the export, opens its dialog
 * and reads the window, the day in the tenant's zone with the zone named, what keeps running,
 * what pauses, that billing stops and the way back, confirms, lands on Billing with the flash
 * naming the day and the closing notice at the top with Subscribe again inside it, then finds the
 * Danger zone's control replaced by the same sentence and a settings save refused with the
 * freeze's sentence. The walk stands on the system clock, as every browser test does, and spells
 * the day from it as the server does; the provider is the fake the test environment binds,
 * answering in this process. The flash's toast is dismissed as seen, so no assertion rides on the
 * auto-clear timer.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);

    DB::table('subscriptions')->insert([
        'tenant_id' => DB::table('tenants')->where('slug', 'luna')->value('id'),
        'provider_customer_id' => 'cus_luna',
        'provider_subscription_id' => 'sub_luna',
        'status' => 'active',
        'items' => json_encode([['price' => 'price_staff_test', 'quantity' => 1]], JSON_THROW_ON_ERROR),
    ]);
    DB::table('tenants')->where('slug', 'luna')->update(['plan_ref' => 'price_staff_test']);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));
});

afterEach(fn () => dropDemoSchemas());

it('closes the account from the Danger zone through its dialog, lands on Billing\'s closing notice, and is refused a settings save with the freeze\'s sentence', function () {
    $day = ClosingWindow::dayOf(app(Clock::class)->now(), new DateTimeZone('Europe/Athens'), SiteLanguage::English);
    $notice = adminLine(ClosingWindow::CLOSES, ['day' => $day]).' '.adminLine(ClosingWindow::WAY_BACK);
    // The window's days as the server spells them in the owner's language, English here.
    $days = (string) (new NumberFormatter('en', NumberFormatter::SPELLOUT))->format(ClosingWindow::days());
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));

    $page->click(navLabel('settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'))
        ->assertSeeIn('.admin-settings-danger', adminLine('admin/billing.danger.export.button'))
        ->assertSeeIn('.admin-settings-close__what', adminChoice(SettingsController::CLOSE_WHAT, ClosingWindow::days(), ['count' => $days]))
        ->assertCount('.admin-close-prompt', 0)
        ->click('.admin-settings-close__button')
        ->waitForText(adminLine('admin/billing.danger.close.title'))
        ->assertSeeIn('.admin-close-prompt__when', adminChoice('admin/billing.danger.close.when', ClosingWindow::days(), ['count' => $days, 'day' => $day, 'zone' => 'Europe/Athens']))
        ->assertSeeIn('.admin-close-prompt__keeps', adminLine('admin/billing.danger.close.keeps'))
        ->assertSeeIn('.admin-close-prompt__pauses', adminLine('admin/billing.danger.close.pauses'))
        ->assertSeeIn('.admin-close-prompt__billing', adminLine('admin/billing.danger.close.billing'))
        ->assertSeeIn('.admin-close-prompt__way-back', adminLine(ClosingWindow::WAY_BACK))
        ->click('.admin-close-prompt__confirm')
        ->waitForText(adminLine(ClosingWindow::CLOSED, ['day' => $day]))
        ->assertPathIs('/admin/billing')
        ->click('.admin-toast-dismiss')
        ->assertSeeIn('.admin-billing__notice .admin-billing__sentence', $notice)
        ->assertSeeIn('.admin-billing__notice .admin-billing__paused', adminLine(BillingController::PAUSED))
        ->assertSeeIn('.admin-billing__notice .admin-billing__resubscribe', adminLine(BillingController::SUBSCRIBE_AGAIN))
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenants')->where('slug', 'luna')->value('status'))->toBe('closing')
        ->and(DB::table('tenants')->where('slug', 'luna')->value('subscription_state'))->toBe('canceled')
        ->and(array_values(array_filter(billingFake()->asked, fn (array $question): bool => $question[0] === 'cancel')))->toBe([['cancel', ['subscription' => 'sub_luna']]]);

    $page->click(navLabel('settings'))
        ->waitForText(adminLine('admin/settings.booking_page.snippet'))
        ->assertSeeIn('.admin-settings-close__closing', $notice)
        ->assertCount('.admin-settings-close__button', 0)
        ->click('.admin-settings-theme[data-value="blue"]')
        ->click('.admin-settings-save')
        ->waitForText(adminLine(ClosingWindow::FROZEN, ['day' => $day]).' '.adminLine(ClosingWindow::WAY_BACK))
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.settings')->value('theme'))->toBe('violet');
});
