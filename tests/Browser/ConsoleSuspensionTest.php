<?php

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessenger;
use App\Http\Controllers\Console\SuspensionController;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Console;
use Tests\Support\RecordingPlatformMessages;

/*
 * Suspend and resume in a real browser: an operator signs in, opens luna's detail, suspends it
 * from the Suspend modal with a reason, and the detail shows the suspension; luna's booking page
 * then answers the suspended page; the operator resumes it from the detail through its confirm,
 * Keep it first leaving it suspended, and the booking page answers again. A tenant with no owner
 * user, pier with its owner removed, is suspended and resumed with the two flashes that promise no
 * mail. The owner is mailed at each step through the platform's seam, a recorder here, and each
 * write lands its row in the platform audit log.
 */

const BROWSER_SUSPENSION_REASON = 'Chargebacks under review with the card network.';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
});

afterEach(fn () => dropDemoSchemas());

it('suspends luna from its detail with a reason, reads the paused page on luna\'s host, resumes it, and reads the booking page again', function () {
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);
    addOperator();

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('2 of 2');

    $page->navigate(Console::url('/tenants/luna'))
        ->waitForText('Plan and subscription')
        ->assertSeeIn('.admin-tenant__status', 'Active')
        ->assertMissing('.admin-tenant-resume')
        ->click('.admin-tenant-suspend')
        ->waitForText('Suspend Luna Hair Studio')
        ->type('#suspend-reason', BROWSER_SUSPENSION_REASON)
        ->click('.admin-suspend-prompt__confirm')
        ->waitForText(SuspensionController::SUSPENDED)
        ->assertMissing('.admin-suspend-prompt')
        ->assertSeeIn('.admin-tenant__status', 'Suspended')
        ->assertSeeIn('.admin-tenant__suspension-reason', BROWSER_SUSPENSION_REASON)
        ->assertPresent('.admin-tenant__suspended')
        ->assertPresent('.admin-tenant-resume')
        ->assertMissing('.admin-tenant-suspend')
        ->assertNoJavaScriptErrors();

    $page->navigate(hostedPage('luna'))
        ->assertTitle(pageSentence('pages.suspended.title'))
        ->assertSee(pageSentence('pages.suspended.heading'))
        ->assertDontSee('Luna Hair Studio');

    $page->navigate(Console::url('/tenants/luna'))
        ->waitForText('Plan and subscription')
        ->click('.admin-tenant-resume')
        ->waitForText('Resume Luna Hair Studio')
        ->assertSee('the booking page and the admin included, opens again')
        ->click('.admin-resume-prompt__keep')
        ->assertMissing('.admin-resume-prompt')
        ->assertSeeIn('.admin-tenant__status', 'Suspended')
        ->click('.admin-tenant-resume')
        ->waitForText('Resume Luna Hair Studio')
        ->click('.admin-resume-prompt__confirm')
        ->waitForText(SuspensionController::RESUMED)
        ->assertMissing('.admin-resume-prompt')
        ->assertSeeIn('.admin-tenant__status', 'Active')
        ->assertMissing('.admin-tenant__suspended')
        ->assertPresent('.admin-tenant-suspend')
        ->assertNoJavaScriptErrors();

    $page->navigate(hostedPage('luna'))
        ->assertTitle('Luna Hair Studio')
        ->assertSeeIn('.hosted-header', 'Luna Hair Studio')
        ->assertDontSee(pageSentence('pages.suspended.heading'));

    expect(array_map(fn (PlatformMessage $message): string => $message->toAddress.' '.$message->kind->value, $seam->sent))->toBe([
        'pat@inbox.example operator_sign_in',
        'owner@luna.example suspension',
        'owner@luna.example resumption',
    ])
        ->and($seam->sent[1]->body)->toContain(BROWSER_SUSPENSION_REASON)
        ->and(DB::table('platform_audit_log')->orderBy('id')->pluck('action')->all())->toBe(['sign_in', 'tenant_suspended', 'tenant_resumed'])
        ->and(DB::table('tenants')->where('slug', 'luna')->first(['status', 'suspended_at', 'suspension_reason']))->toEqual((object) ['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null]);
});

it('flashes the two sentences that promise no mail when the suspended and resumed tenant has no owner, and mails nobody', function () {
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);
    addOperator();
    DB::table('users')->where('tenant_id', DB::table('tenants')->where('slug', 'pier')->value('id'))->delete();

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('2 of 2');

    $page->navigate(Console::url('/tenants/pier'))
        ->waitForText('Plan and subscription')
        ->click('.admin-tenant-suspend')
        ->waitForText('Suspend Pier Physiotherapy')
        ->type('#suspend-reason', BROWSER_SUSPENSION_REASON)
        ->click('.admin-suspend-prompt__confirm')
        ->waitForText(SuspensionController::SUSPENDED_NO_OWNER)
        ->assertSeeIn('.admin-tenant__status', 'Suspended')
        ->click('.admin-toast-dismiss')
        ->click('.admin-tenant-resume')
        ->waitForText('Resume Pier Physiotherapy')
        ->click('.admin-resume-prompt__confirm')
        ->waitForText(SuspensionController::RESUMED_NO_OWNER)
        ->assertSeeIn('.admin-tenant__status', 'Active')
        ->assertNoJavaScriptErrors();

    expect(array_map(fn (PlatformMessage $message): string => $message->toAddress.' '.$message->kind->value, $seam->sent))->toBe([
        'pat@inbox.example operator_sign_in',
    ])
        ->and(DB::table('platform_audit_log')->orderBy('id')->pluck('action')->all())->toBe(['sign_in', 'tenant_suspended', 'tenant_resumed']);
});
