<?php

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessenger;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\SetPasswordController;
use App\Http\Controllers\Console\NewTenantController;
use App\Http\Controllers\Console\TenantFeaturesController;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Console;
use Tests\Support\RecordingPlatformMessages;

/*
 * The free tenant in a real browser: an operator signs in, opens New tenant from the list, fills
 * the form with Staff checked and creates the tenant, landing on its detail with Change features
 * offered; takes Staff away there and saves it; and the owner, who was never told a password, gets
 * in through the emailed link alone: sets a password on acme's own host, signs in with it, and
 * reads the Billing screen's free sentence.
 */

const BROWSER_FREE_PASSWORD = 'the owner chose this one';

beforeEach(fn () => DB::statement('drop schema if exists tenant_acme cascade'));

afterEach(fn () => DB::statement('drop schema if exists tenant_acme cascade'));

it('creates a free tenant from the console\'s form, changes its features on the detail, and lets its owner in through the emailed link alone', function () {
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);
    addOperator();

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('New tenant')
        ->click('.admin-tenants-new')
        ->waitForText('Create tenant')
        ->assertPathIs('/tenants/new')
        ->assertValue('#new-tenant-timezone', 'Europe/Athens')
        ->assertValue('#new-tenant-currency', 'EUR')
        ->type('#new-tenant-slug', 'acme')
        ->type('#new-tenant-name', 'Acme Barbers')
        ->select('#new-tenant-category', 'barber')
        ->type('#new-tenant-owner_name', 'Alex Owner')
        ->type('#new-tenant-owner_email', 'alex@acme.example')
        ->check('#new-tenant-feature-staff')
        ->click('.admin-new-tenant-submit')
        ->waitForText(sprintf(NewTenantController::CREATED, 'alex@acme.example'))
        ->assertPathIs('/tenants/acme')
        ->assertSeeIn('.admin-tenant__name', 'Acme Barbers')
        ->assertSeeIn('.admin-tenant__plan', 'Free')
        ->assertChecked('#tenant-feature-staff')
        ->uncheck('#tenant-feature-staff')
        ->click('.admin-tenant-features__save')
        ->waitForText(TenantFeaturesController::SAVED)
        ->assertNotChecked('#tenant-feature-staff')
        ->assertNoJavaScriptErrors();

    $link = array_values(array_filter($seam->sent, fn (PlatformMessage $message): bool => $message->toAddress === 'alex@acme.example'))[0] ?? null;
    $token = preg_match('~/admin/set-password/([0-9a-f]{64})~', $link->body ?? '', $match) === 1 ? $match[1] : '';

    expect($token)->not->toBe('');

    $page->navigate(Admin::url('acme', '/set-password/'.$token))
        ->waitForText('Acme Barbers')
        ->type('#set-password', BROWSER_FREE_PASSWORD)
        ->click('.admin-set-password-submit')
        ->waitForText(adminLine(SetPasswordController::SET))
        ->assertPathIs('/admin/login')
        ->fill('email', 'alex@acme.example')
        ->fill('password', BROWSER_FREE_PASSWORD)
        ->click('.admin-login-submit')
        ->waitForText(navLabel('billing'));

    $page->navigate(Admin::url('acme', '/billing'))
        ->waitForText(adminLine(BillingController::FREE))
        ->assertMissing('.admin-billing__plan')
        ->assertMissing('.admin-billing__portal')
        ->assertNoJavaScriptErrors();

    expect(DB::table('platform_audit_log')->orderBy('id')->pluck('action')->all())->toBe(['sign_in', 'tenant_created', 'features_changed'])
        ->and(DB::table('tenants')->where('slug', 'acme')->value('entitlements'))->toBe('{}')
        ->and(DB::table('subscriptions')->count())->toBe(0);
});
