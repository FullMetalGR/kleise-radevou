<?php

use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Http\Controllers\Admin\SetPasswordController;
use App\Http\Controllers\Console\TicketsController;
use App\Models\User;
use App\Notifications\HelpMessageReceived;
use App\Platform\PasswordLinks;
use App\Site\Brand;
use App\Site\SiteLanguage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Console;
use Tests\Browser\Support\Help;
use Tests\Support\RecordingPlatformMessages;

/*
 * The help bubble's tickets in a real browser, from the business's admin to the console and back.
 * An owner searches the answers in English and opens a ticket from the search, the form holding
 * what was searched; the platform is mailed; an operator finds the ticket awaiting a reply in the
 * console and replies; the owner is mailed, and the admin shows the reply with a dot on the
 * bubble, on the Tickets tab and on the row until the thread is read. Around that: a second
 * business sees none of the first one's tickets; the console closes a ticket, the admin says so,
 * a reply of the business opens it again and the console reopens it; a staff member sees the
 * business's tickets as the owner does; the demo account has the answers and no tickets; an
 * operator's support session has no bubble; the link of a reply's mail, followed signed out,
 * lands through the sign-in on the admin with the panel open on its thread; a session begun on a
 * set-password link sends a ticket and opens a row from a screen that is not the landing one; and
 * a message of line breaks, link-like text and markup is drawn as text with its breaks on both
 * sides.
 *
 * Every mail goes through the platform's seam, a recorder here. The help tables are central, so
 * the truncation between tests empties them with the rest.
 */

const HELP_WALK_QUERY = 'opening hours';

const HELP_WALK_SUBJECT = 'Hours for the summer';

const HELP_WALK_REPLY = 'Set them under Settings, then Opening hours.';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
});

afterEach(fn () => dropDemoSchemas());

it('opens a ticket from an English search, mails the platform, and shows the console\'s reply under the platform\'s name with a dot until the thread is read', function () {
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);
    addOperator();
    $owner = User::query()->where('email', Admin::owner('luna'))->sole();
    $found = Help::matching(SiteLanguage::English, HELP_WALK_QUERY);

    expect($found)->not->toBe([]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertVisible(Help::BUBBLE)
        ->assertMissing(Help::DOT)
        ->assertAttribute(Help::BUBBLE, 'aria-label', adminLine('admin/shell.help.name'))
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->assertScript(Admin::focusIsOn(Help::SEARCH), true)
        ->type(Help::SEARCH, HELP_WALK_QUERY)
        ->assertSeeIn('.admin-help-count', adminChoice('admin/help.search.found', count($found)))
        ->assertSeeIn('.admin-help-matches', $found[0])
        ->click(Help::FOOT_OPEN)
        ->assertVisible(Help::SUBJECT)
        ->assertValue(Help::MESSAGE, HELP_WALK_QUERY)
        ->assertScript(Admin::focusIsOn(Help::SUBJECT), true)
        ->type(Help::SUBJECT, HELP_WALK_SUBJECT)
        ->click(Help::SEND)
        ->assertSeeIn(Help::THREAD_SUBJECT, HELP_WALK_SUBJECT)
        ->assertSeeIn(Help::BUSINESS_MESSAGE, HELP_WALK_QUERY)
        ->assertSeeIn(Help::BUSINESS_MESSAGE, $owner->name)
        ->assertSeeIn(Help::THREAD_STATUS, adminLine('admin/help.statuses.open'))
        ->assertNoJavaScriptErrors();

    $uuid = Help::onlyTicket();
    $id = Help::id($uuid);
    $toUs = Help::sent($seam, PlatformMessageKind::HelpMessage);

    expect($toUs)->toHaveCount(1)
        ->and($toUs[0]->toAddress)->toBe(config('site.contact_address'))
        ->and($toUs[0]->subject)->toBe(sprintf(HelpMessageReceived::OPENED, 'Luna Hair Studio'))
        ->and($toUs[0]->body)->toContain('From: '.$owner->name.', Owner')
        ->and($toUs[0]->body)->toContain(HELP_WALK_SUBJECT)
        ->and($toUs[0]->body)->toContain(HELP_WALK_QUERY)
        ->and($toUs[0]->body)->toContain(Console::url('/tickets/'.$id))
        ->and(Help::sent($seam, PlatformMessageKind::HelpReply))->toBe([]);

    Help::consoleSignIn($page)
        ->navigate(Console::url('/tickets'))
        ->assertSeeIn('.admin-tickets__row[data-awaiting="true"]', HELP_WALK_SUBJECT)
        ->assertSeeIn('.admin-tickets__row .admin-tickets__awaiting', 'Awaiting reply')
        ->assertSeeIn('.admin-tickets__row .admin-tickets__tenant', 'Luna Hair Studio')
        ->click('.admin-tickets__subject')
        ->assertPathIs('/tickets/'.$id)
        ->assertSeeIn('.admin-ticket__subject', HELP_WALK_SUBJECT)
        ->assertSeeIn('.admin-ticket-message[data-side="business"]', HELP_WALK_QUERY)
        ->type('#ticket-reply', HELP_WALK_REPLY)
        ->click('.admin-ticket-reply__send')
        ->assertSee(TicketsController::REPLIED)
        ->assertSeeIn('.admin-ticket-message[data-side="platform"]', HELP_WALK_REPLY)
        ->assertMissing('.admin-ticket__awaiting')
        ->assertNoJavaScriptErrors();

    $toWriter = Help::sent($seam, PlatformMessageKind::HelpReply);

    expect($toWriter)->toHaveCount(1)
        ->and($toWriter[0]->toAddress)->toBe(Admin::owner('luna'))
        ->and($toWriter[0]->toName)->toBe($owner->name)
        ->and($toWriter[0]->subject)->toBe(__('mail.help_reply.subject', ['subject' => HELP_WALK_SUBJECT], 'en'))
        ->and($toWriter[0]->body)->toContain(HELP_WALK_REPLY)
        ->and($toWriter[0]->body)->toContain(Admin::url('luna', '/help/'.$uuid));

    $page->navigate(Admin::url('luna'))
        ->assertVisible(Help::DOT)
        ->assertAttribute(Help::BUBBLE, 'aria-label', adminLine('admin/shell.help.unread'))
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->assertVisible(Help::TAB_DOT)
        ->assertAttribute(Help::TICKETS_TAB, 'aria-label', adminLine('admin/help.tabs.tickets_unread'))
        ->click(Help::TICKETS_TAB)
        ->assertSeeIn(Help::ROW, HELP_WALK_SUBJECT)
        ->assertSeeIn(Help::ROW.' .admin-help-unread', adminLine('admin/help.list.unread'))
        ->click(Help::ROW)
        ->assertSeeIn(Help::PLATFORM_MESSAGE, HELP_WALK_REPLY)
        ->assertSeeIn(Help::PLATFORM_MESSAGE, adminLine('admin/help.platform', ['brand' => app(Brand::class)->name]))
        ->assertMissing(Help::DOT)
        ->assertMissing(Help::TAB_DOT)
        ->assertAttribute(Help::BUBBLE, 'aria-label', adminLine('admin/shell.help.name'))
        ->assertNoJavaScriptErrors();

    $page->navigate(Admin::url('luna'))
        ->assertVisible(Help::BUBBLE)
        ->assertMissing(Help::DOT)
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->click(Help::TICKETS_TAB)
        ->assertSeeIn(Help::ROW, HELP_WALK_SUBJECT)
        ->assertMissing(Help::ROW.' .admin-help-unread')
        ->assertNoJavaScriptErrors();
});

it('shows a second business none of the first one\'s tickets: an empty list and no dot', function () {
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    $uuid = Help::ticket(Admin::owner('luna'), 'A question of luna alone', 'Only luna asks this.');
    Help::answer($uuid, addOperator(), 'Only luna is answered.');

    Admin::signedIn($this, 'pier', Admin::owner('pier'))
        ->assertVisible(Help::BUBBLE)
        ->assertMissing(Help::DOT)
        ->assertAttribute(Help::BUBBLE, 'aria-label', adminLine('admin/shell.help.name'))
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->assertMissing(Help::TAB_DOT)
        ->click(Help::TICKETS_TAB)
        ->assertSeeIn('.admin-help-empty', adminLine('admin/help.list.empty'))
        ->assertCount(Help::ROW, 0)
        ->assertDontSee('A question of luna alone')
        ->assertNoJavaScriptErrors();
});

it('closes a ticket from the console, says so in the admin\'s thread, opens it again by the business\'s reply, and reopens it from the console', function () {
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);
    addOperator();
    $uuid = Help::ticket(Admin::owner('luna'), 'Closing on a holiday', 'Can the page close for a holiday?');
    $id = Help::id($uuid);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));

    Help::consoleSignIn($page)
        ->navigate(Console::url('/tickets/'.$id))
        ->assertSeeIn('.admin-ticket__subject', 'Closing on a holiday')
        ->assertVisible('.admin-ticket__awaiting')
        ->click('.admin-ticket-close')
        ->assertSee(TicketsController::CLOSED)
        ->assertSeeIn('.admin-ticket__status', 'Closed')
        ->assertVisible('.admin-ticket-reopen')
        ->assertMissing('.admin-ticket__awaiting')
        ->assertNoJavaScriptErrors();

    $page->navigate(Admin::url('luna'))
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->click(Help::TICKETS_TAB)
        ->assertSeeIn(Help::ROW, adminLine('admin/help.statuses.closed'))
        ->click(Help::ROW)
        ->assertSeeIn('.admin-help-closed', adminLine('admin/help.thread.closed'))
        ->assertSeeIn(Help::THREAD_STATUS, adminLine('admin/help.statuses.closed'))
        ->type(Help::REPLY, 'One more thing about the holiday.')
        ->click(Help::SEND_REPLY)
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'One more thing about the holiday.')
        ->assertMissing('.admin-help-closed')
        ->assertSeeIn(Help::THREAD_STATUS, adminLine('admin/help.statuses.open'))
        ->assertNoJavaScriptErrors();

    $toUs = Help::sent($seam, PlatformMessageKind::HelpMessage);

    expect($toUs)->toHaveCount(2)
        ->and($toUs[1]->subject)->toBe(sprintf(HelpMessageReceived::ADDED, 'Luna Hair Studio'))
        ->and($toUs[1]->body)->toContain('One more thing about the holiday.');

    $page->navigate(Console::url('/tickets/'.$id))
        ->assertSeeIn('.admin-ticket__status', 'Open')
        ->assertVisible('.admin-ticket__awaiting')
        ->click('.admin-ticket-close')
        ->assertSee(TicketsController::CLOSED)
        ->click('.admin-ticket-reopen')
        ->assertSee(TicketsController::REOPENED)
        ->assertVisible('.admin-ticket-close')
        ->assertSeeIn('.admin-ticket__status', 'Open')
        ->assertNoJavaScriptErrors();

    $page->navigate(Admin::url('luna'))
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->click(Help::TICKETS_TAB)
        ->assertSeeIn(Help::ROW, 'Closing on a holiday')
        ->click(Help::ROW)
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'One more thing about the holiday.')
        ->assertSeeIn(Help::THREAD_STATUS, adminLine('admin/help.statuses.open'))
        ->assertMissing('.admin-help-closed')
        ->assertNoJavaScriptErrors();
});

it('gives a staff member the bubble and the business\'s tickets, the owner\'s among them, and mails the platform their reply', function () {
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);
    expect(Artisan::call('tenant:add-user', ['slug' => 'luna', '--name' => 'Sam Staff', '--email' => 'sam@luna.example', '--password' => 'staff password', '--role' => 'staff']))->toBe(0);
    $uuid = Help::ticket(Admin::owner('luna'), 'Who sees the calendar', 'Can my staff see every booking?');
    Help::answer($uuid, addOperator(), 'Staff see their own calendar.');

    Admin::signIn($this, 'luna', 'sam@luna.example', 'staff password')
        ->assertVisible(Admin::RAIL)
        ->assertPathIs('/admin/calendar')
        ->assertVisible(Help::DOT)
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->click(Help::TICKETS_TAB)
        ->assertSeeIn(Help::ROW, 'Who sees the calendar')
        ->assertVisible(Help::ROW.' .admin-help-unread')
        ->click(Help::ROW)
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'Can my staff see every booking?')
        ->assertSeeIn(Help::PLATFORM_MESSAGE, 'Staff see their own calendar.')
        ->assertMissing(Help::DOT)
        ->type(Help::REPLY, 'Thank you, that answers it.')
        ->click(Help::SEND_REPLY)
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'Thank you, that answers it.')
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'Sam Staff')
        ->assertNoJavaScriptErrors();

    $toUs = Help::sent($seam, PlatformMessageKind::HelpMessage);

    expect($toUs)->toHaveCount(2)
        ->and($toUs[1]->subject)->toBe(sprintf(HelpMessageReceived::ADDED, 'Luna Hair Studio'))
        ->and($toUs[1]->body)->toContain('From: Sam Staff, Staff')
        ->and($toUs[1]->body)->toContain('Thank you, that answers it.');
});

it('gives the demo account the bubble and the answers with no Tickets tab, no form, no list and no dot', function () {
    // The demo business holds a ticket with an answer it has not read: in any other business
    // that is a dot on the bubble, a Tickets tab and a row in its list.
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    $uuid = Help::ticket(Admin::owner('pier'), 'Asked before the demo', 'A ticket the demo must not show.');
    Help::answer($uuid, addOperator(), 'An answer the demo must not flag.');
    config(['site.demo_slug' => 'pier']);

    Admin::signedIn($this, 'pier', Admin::owner('pier'))
        ->assertVisible(Help::BUBBLE)
        ->assertMissing(Help::DOT)
        ->assertAttribute(Help::BUBBLE, 'aria-label', adminLine('admin/shell.help.name'))
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->assertVisible('.admin-help-topics')
        ->assertCount('.admin-help-tab', 0)
        ->assertCount('.admin-help-panel [role="tablist"]', 0)
        ->assertCount('.admin-help-still', 0)
        ->type(Help::SEARCH, 'zzqqxx')
        ->assertSeeIn('.admin-help-count', adminLine('admin/help.search.none'))
        ->assertCount('.admin-help-matches button', 0)
        ->assertCount('.admin-help-list', 0)
        ->assertCount(Help::ROW, 0)
        ->assertCount('.admin-help-form', 0)
        ->assertDontSee('Asked before the demo')
        ->assertNoJavaScriptErrors();
});

it('shows no bubble in an operator\'s support session inside a business', function () {
    addOperator();

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('2 of 2');

    $page->navigate(Console::url('/tenants/luna'))
        ->waitForText('Support sessions')
        ->click('.admin-tenant-support__open')
        ->waitForText(trim(Str::before(adminLine('admin/shell.banners.support.line', ['operator' => 'Pat Operator']), ':end')))
        ->assertUrlIs(Admin::url('luna'))
        ->assertVisible('.admin-support-banner')
        ->assertVisible(Admin::RAIL)
        ->assertCount(Help::BUBBLE, 0)
        ->assertCount(Help::PANEL, 0)
        ->assertNoJavaScriptErrors();
});

it('follows a reply mail\'s link signed out, through the sign-in, to the admin with the panel open on its thread', function () {
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);
    $uuid = Help::ticket(Admin::owner('luna'), 'Where the reports are', 'Where do I find the reports?');
    Help::answer($uuid, addOperator(), 'Reports are on the rail, under the business.');
    $mail = Help::sent($seam, PlatformMessageKind::HelpReply)[0] ?? null;
    $link = preg_match('~\S+/admin/help/[0-9a-f-]{36}~', $mail->body ?? '', $match) === 1 ? $match[0] : '';

    expect($link)->toBe(Admin::url('luna', '/help/'.$uuid));

    // The sign-in page is drawn, its button there to press, before it is read for a bubble.
    $this->visit($link)
        ->assertPathIs('/admin/login')
        ->assertVisible('.admin-login-submit')
        ->assertMissing(Help::BUBBLE)
        ->fill('email', Admin::owner('luna'))
        ->fill('password', Admin::DEMO_PASSWORD)
        ->click('.admin-login-submit')
        ->assertVisible(Help::PANEL)
        ->assertSeeIn(Help::THREAD_SUBJECT, 'Where the reports are')
        ->assertSeeIn(Help::PLATFORM_MESSAGE, 'Reports are on the rail, under the business.')
        ->assertScript(Help::SHOWN_TAB, 'tickets')
        ->assertPathIs('/admin')
        ->assertVisible(Admin::HOME)
        ->assertMissing(Help::DOT)
        ->assertNoJavaScriptErrors();
});

it('sends a ticket and opens a row from a screen that is not the landing one, in a session begun on a set-password link', function () {
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    $token = app(PasswordLinks::class)->mint(User::query()->where('email', Admin::owner('luna'))->sole());

    $page = $this->visit(Admin::url('luna', '/set-password/'.$token))
        ->waitForText('Luna Hair Studio')
        ->type('#set-password', 'a password the owner chose')
        ->click('.admin-set-password-submit')
        ->waitForText(adminLine(SetPasswordController::SET))
        ->assertPathIs('/admin/login')
        ->fill('email', Admin::owner('luna'))
        ->fill('password', 'a password the owner chose')
        ->click('.admin-login-submit')
        ->assertVisible(Admin::RAIL)
        ->click('.admin-rail a[href="/admin/bookings"]')
        ->assertVisible('.admin-bookings-screen')
        ->assertPathIs('/admin/bookings');

    $page->script('window.helpWalkDocument = true');

    $page->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->click(Help::TICKETS_TAB)
        ->assertSeeIn('.admin-help-empty', adminLine('admin/help.list.empty'))
        ->click(Help::LIST_OPEN)
        ->type(Help::SUBJECT, 'Asked from the bookings')
        ->type(Help::MESSAGE, 'Sent while the bookings were on screen.')
        ->click(Help::SEND)
        ->assertSeeIn(Help::THREAD_SUBJECT, 'Asked from the bookings')
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'Sent while the bookings were on screen.')
        ->assertPathIs('/admin/bookings')
        ->assertVisible('.admin-bookings-screen');

    // The panel still holds the thread the send brought and draws it again at once when the row
    // is pressed, before the row's read is answered. So the platform answers in between: its
    // message and the row's mark are not in what the panel holds, and each shows only once the
    // read that brings it has landed. The path, the screen and the marker are read after that.
    Help::answer(Help::onlyTicket(), addOperator(), 'Answered while the bookings were on screen.');

    $page->click(Help::BACK_FROM_THREAD)
        ->assertSeeIn(Help::ROW, 'Asked from the bookings')
        ->assertVisible(Help::ROW.' .admin-help-unread')
        ->click(Help::ROW)
        ->assertSeeIn(Help::PLATFORM_MESSAGE, 'Answered while the bookings were on screen.')
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'Sent while the bookings were on screen.')
        ->assertPathIs('/admin/bookings')
        ->assertVisible('.admin-bookings-screen')
        ->assertScript('window.helpWalkDocument === true', true)
        ->assertNoJavaScriptErrors();
});

it('draws a message of line breaks, link-like text and markup as text with its breaks, in the admin\'s thread and in the console\'s', function () {
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    addOperator();
    $body = implode("\n", [
        'First line of the question.',
        'https://example.test/help?a=1&b=2',
        '<b>not bold</b> <a href="https://example.test">not a link</a>',
        '<script>window.helpInjected = true</script>',
        '',
        'Last line.',
    ]);
    $reply = implode("\n", ['<i>Thanks</i> for asking.', 'www.example.test', '', 'A second paragraph.']);
    $markup = '.admin-help-messages a, .admin-help-messages b, .admin-help-messages i, .admin-help-messages script, .admin-ticket-messages a, .admin-ticket-messages b, .admin-ticket-messages i, .admin-ticket-messages script';
    $admin = "[...document.querySelectorAll('.admin-help-message p.whitespace-pre-line')].map((p) => p.innerText)";
    $console = "[...document.querySelectorAll('.admin-ticket-message__body')].map((p) => p.innerText)";

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->click(Help::TICKETS_TAB)
        ->click(Help::LIST_OPEN)
        ->type(Help::SUBJECT, '<em>Odd</em> text')
        ->type(Help::MESSAGE, $body)
        ->click(Help::SEND)
        ->assertSeeIn(Help::THREAD_SUBJECT, '<em>Odd</em> text')
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'Last line.')
        ->assertCount($markup, 0)
        ->assertScript('window.helpInjected === undefined', true);

    expect($page->script($admin))->toBe([$body]);

    $id = Help::id(Help::onlyTicket());

    Help::consoleSignIn($page)
        ->navigate(Console::url('/tickets/'.$id))
        ->assertSeeIn('.admin-ticket__subject', '<em>Odd</em> text')
        ->assertSeeIn('.admin-ticket-message[data-side="business"]', 'Last line.')
        ->type('#ticket-reply', $reply)
        ->click('.admin-ticket-reply__send')
        ->assertSee(TicketsController::REPLIED)
        ->assertSeeIn('.admin-ticket-message[data-side="platform"]', 'A second paragraph.')
        ->assertCount($markup, 0)
        ->assertScript('window.helpInjected === undefined', true);

    expect($page->script($console))->toBe([$body, $reply]);

    $page->navigate(Admin::url('luna'))
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->click(Help::TICKETS_TAB)
        ->click(Help::ROW)
        ->assertSeeIn(Help::PLATFORM_MESSAGE, 'A second paragraph.')
        ->assertCount($markup, 0)
        ->assertScript('window.helpInjected === undefined', true)
        ->assertNoJavaScriptErrors();

    expect($page->script($admin))->toBe([$body, $reply]);
});
