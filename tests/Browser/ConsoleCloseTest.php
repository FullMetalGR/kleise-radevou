<?php

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessenger;
use App\Http\Controllers\Console\ClosingController;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Console;
use Tests\Support\RecordingPlatformMessages;

/*
 * The console's close and reopen in a real browser: an operator signs in, opens luna's detail,
 * closes it from the Close modal with a reason, and the detail reads the closing sentence with
 * Reopen offered, luna being free; luna's booking page still answers through the window; the
 * operator reopens it through its confirm, Keep it first leaving it closing, and the detail is
 * active again with Suspend and Close. The owner is mailed at each step through the platform's
 * seam, a recorder here, and each write lands its row in the platform audit log. The walk is on the
 * system clock, so the sentence's instant and day are read by their shape.
 */

const BROWSER_CLOSE_REASON = 'The owner asked us by telephone to close the account.';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
});

afterEach(fn () => dropDemoSchemas());

it('closes luna from its detail with a reason, reads the closing sentence with Reopen offered, keeps its booking page open, reopens it, and reads it active again', function () {
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);
    addOperator();

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('2 of 2');

    $page->navigate(Console::url('/tenants/luna'))
        ->waitForText('Plan and subscription')
        ->assertSeeIn('.admin-tenant__status', 'Active')
        ->assertMissing('.admin-tenant-reopen')
        ->assertMissing('.admin-tenant-closing')
        ->click('.admin-tenant-close')
        ->waitForText('Close Luna Hair Studio')
        ->assertSee('The account closes in thirty days')
        ->type('#close-reason', BROWSER_CLOSE_REASON)
        ->click('.admin-close-prompt__confirm')
        ->waitForText(ClosingController::CLOSED)
        ->assertMissing('.admin-close-prompt')
        ->assertSeeIn('.admin-tenant__status', 'Closing')
        ->assertSeeIn('.admin-tenant-closing__sentence', 'Closing since ')
        ->assertSeeIn('.admin-tenant-closing__sentence', ', deleted on ')
        ->assertMissing('.admin-tenant-closing__way-back')
        ->assertPresent('.admin-tenant-reopen')
        ->assertMissing('.admin-tenant-close')
        ->assertMissing('.admin-tenant-suspend')
        ->assertNoJavaScriptErrors();

    $page->navigate(hostedPage('luna'))
        ->assertTitle('Luna Hair Studio')
        ->assertSeeIn('.hosted-header', 'Luna Hair Studio');

    $page->navigate(Console::url('/tenants/luna'))
        ->waitForText('Plan and subscription')
        ->click('.admin-tenant-reopen')
        ->waitForText('Reopen Luna Hair Studio')
        ->assertSee('The account stays open')
        ->click('.admin-reopen-prompt__keep')
        ->assertMissing('.admin-reopen-prompt')
        ->assertSeeIn('.admin-tenant__status', 'Closing')
        ->click('.admin-tenant-reopen')
        ->waitForText('Reopen Luna Hair Studio')
        ->click('.admin-reopen-prompt__confirm')
        ->waitForText(ClosingController::REOPENED)
        ->assertMissing('.admin-reopen-prompt')
        ->assertSeeIn('.admin-tenant__status', 'Active')
        ->assertMissing('.admin-tenant-closing')
        ->assertPresent('.admin-tenant-suspend')
        ->assertPresent('.admin-tenant-close')
        ->assertNoJavaScriptErrors();

    expect(array_map(fn (PlatformMessage $message): string => $message->toAddress.' '.$message->kind->value, $seam->sent))->toBe([
        'pat@inbox.example operator_sign_in',
        'owner@luna.example closing',
        'owner@luna.example reopened',
    ])
        ->and($seam->sent[1]->body)->toContain(BROWSER_CLOSE_REASON)
        ->and(DB::table('platform_audit_log')->orderBy('id')->pluck('action')->all())->toBe(['sign_in', 'tenant_closed', 'tenant_reopened'])
        ->and(DB::table('tenants')->where('slug', 'luna')->first(['status', 'closing_at']))->toEqual((object) ['status' => 'active', 'closing_at' => null]);
});
