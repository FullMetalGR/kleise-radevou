<?php

use App\Application\Notification\PlatformMessenger;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Help;
use Tests\Browser\Support\PhoneScripts;
use Tests\Support\RecordingPlatformMessages;

/*
 * The help panel as a layer of the admin in a real browser. At the desktop width: its two tabs,
 * only the chosen one drawn; each way it closes, a press on the page, the bubble, Close, Escape,
 * Tab past its last control, the browser's Back, and where focus is after each; Shift+Tab from its
 * first control to the bubble leaving it open; what was typed in it surviving every close; a
 * thread longer than the panel opening with its reply box in view; and the toasts standing beside
 * it. Over it and under it: a dialog of the screen closes it and covers the bubble, and the setup
 * wizard covers the bubble from the start. At a phone's width it fills the screen as a dialog with
 * the tabs and the way from a thread back to the list, and at both widths the bubble stands over
 * no control at the foot of any screen. In Greek the search finds an answer by a word with its
 * accents or without them, in capitals or small letters, and with either sigma at its end, and a
 * search finding nothing offers a ticket beside the line saying so.
 *
 * The help tables are central, so the truncation between tests empties them with the rest; the
 * business made here for the setup wizard is dropped as the demo ones are.
 */

const HELP_PANEL_FRESH = 'reed';

const HELP_PANEL_FRESH_PASSWORD = 'a fresh owner password';

/** The screens at the foot of which the bubble must stand clear of every control, each with what shows it is drawn. */
const HELP_PANEL_SCREENS = [
    '' => '.admin-home-screen',
    '/calendar' => '.admin-calendar-screen',
    '/bookings' => '.admin-bookings-screen',
    '/customers' => '.admin-customers-screen',
    '/services' => '.admin-services-screen',
    '/services/new' => '.admin-save-bar',
    '/staff' => '.admin-staff-screen',
    '/team' => '.admin-team-screen',
    '/settings' => '.admin-save-bar',
];

/**
 * True when a thread longer than the panel shows its reply box whole in the panel's view and its
 * last message whole just above the box.
 */
const HELP_PANEL_THREAD_IN_VIEW = <<<'JS'
    (() => {
        const view = document.querySelector('.admin-help-panel [role="tabpanel"][id$="-content-tickets"]');
        const reply = document.querySelector('.admin-help-reply');
        const messages = document.querySelectorAll('.admin-help-message');
        if (view === null || reply === null || messages.length === 0) {
            return false;
        }
        const v = view.getBoundingClientRect();
        const r = reply.getBoundingClientRect();
        const last = messages[messages.length - 1].getBoundingClientRect();
        return view.scrollHeight > view.clientHeight + 1
            && r.top >= v.top - 1 && r.bottom <= v.bottom + 1
            && last.top >= v.top - 1 && last.bottom <= r.top + 1;
    })()
    JS;

/** True when the tickets' view stands at its top. */
const HELP_PANEL_AT_TOP = "document.querySelector('.admin-help-panel [role=\"tabpanel\"][id$=\"-content-tickets\"]')?.scrollTop === 0";

/**
 * True when the toaster, and the toast in it where one shows, stands inside the window to the
 * open panel's left with nothing of the two overlapping, at the panel's bottom.
 */
const HELP_PANEL_TOASTS_BESIDE = <<<'JS'
    (() => {
        const box = (selector) => document.querySelector(selector)?.getBoundingClientRect() ?? null;
        const panel = box('.admin-help-panel');
        const toaster = box('.admin-toaster');
        const toast = box('.admin-toast');
        if (panel === null || toaster === null) {
            return false;
        }
        const beside = (b) => b.left >= 0 && b.right <= panel.left && Math.abs(b.bottom - panel.bottom) <= 1;
        return beside(toaster) && (toast === null || beside(toast));
    })()
    JS;

beforeEach(function () {
    dropDemoSchemas();
    DB::statement(sprintf('drop schema if exists tenant_%s cascade', HELP_PANEL_FRESH));
    seedDemo();
});

afterEach(function () {
    dropDemoSchemas();
    DB::statement(sprintf('drop schema if exists tenant_%s cascade', HELP_PANEL_FRESH));
});

it('shows only the chosen tab, and closes at the desktop width on a press on the page, the bubble, Close, Escape, Tab past its end and Back, keeping what was typed', function () {
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    Help::ticket(Admin::owner('luna'), 'A ticket in the list', 'So the list has a row.');

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', '/customers'))
        ->assertVisible('#customers-search');

    // The tabs: the one chosen drawn, the other hidden, after each change.
    $page->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->assertScript(Admin::focusIsOn(Help::SEARCH), true)
        ->assertScript(Help::SHOWN_TAB, 'answers')
        ->type(Help::SEARCH, 'hours')
        ->assertVisible('.admin-help-count')
        ->click(Help::TICKETS_TAB)
        ->assertScript(Help::SHOWN_TAB, 'tickets')
        ->assertVisible(Help::ROW)
        ->assertMissing(Help::SEARCH)
        ->click(Help::ANSWERS_TAB)
        ->assertScript(Help::SHOWN_TAB, 'answers')
        ->assertMissing(Help::ROW)
        ->assertValue(Help::SEARCH, 'hours');

    // A press on the page closes the panel and leaves focus where the press put it.
    $page->click('#customers-search')
        ->assertMissing(Help::PANEL)
        ->assertScript(Admin::focusIsOn('#customers-search'), true)
        ->click(Help::BUBBLE)
        ->assertValue(Help::SEARCH, 'hours')
        ->assertScript(Help::SHOWN_TAB, 'answers');

    // A press on the bubble while open closes it for good, focus on the bubble: from the press
    // to focus settling the panel left the page once and was never put back into it.
    $page->script(Help::PANEL_WATCH);
    $page->click(Help::BUBBLE)
        ->assertMissing(Help::PANEL)
        ->assertScript(Admin::focusIsOn(Help::BUBBLE), true);
    expect($page->script(Help::PANEL_SINCE))->toBe(['opened' => 0, 'closed' => 1, 'open' => false]);

    // The next press opens it, which the same count sees, with the search as it was left. Close
    // then hands focus back to the bubble; the form keeps what was typed.
    $page->click(Help::BUBBLE)
        ->assertValue(Help::SEARCH, 'hours');
    expect($page->script(Help::PANEL_SINCE))->toBe(['opened' => 1, 'closed' => 1, 'open' => true]);

    $page->click(Help::TICKETS_TAB)
        ->click(Help::LIST_OPEN)
        ->type(Help::SUBJECT, 'A kept subject')
        ->type(Help::MESSAGE, 'A kept message')
        ->click(Help::CLOSE)
        ->assertMissing(Help::PANEL)
        ->assertScript(Admin::focusIsOn(Help::BUBBLE), true)
        ->click(Help::BUBBLE)
        ->assertValue(Help::SUBJECT, 'A kept subject')
        ->assertValue(Help::MESSAGE, 'A kept message')
        ->assertScript(Help::SHOWN_TAB, 'tickets');

    // Escape closes the panel alone, the screen staying, and hands focus to the bubble.
    $page->assertScript(Admin::focusIsOn(Help::SUBJECT), true)
        ->keys(Help::MESSAGE, 'Escape')
        ->assertMissing(Help::PANEL)
        ->assertScript(Admin::focusIsOn(Help::BUBBLE), true)
        ->assertPathIs('/admin/customers')
        ->assertVisible('#customers-search');

    // Shift+Tab from the first control goes to the bubble and leaves the panel open: from the
    // key to focus settling the panel never left the page.
    $page->click(Help::BUBBLE)
        ->assertValue(Help::MESSAGE, 'A kept message');
    $page->script(Help::PANEL_WATCH);
    $page->keys(Help::CLOSE, 'Shift+Tab')
        ->assertScript(Admin::focusIsOn(Help::BUBBLE), true);
    expect($page->script(Help::PANEL_SINCE))->toBe(['opened' => 0, 'closed' => 0, 'open' => true]);

    // Tab past the last control leaves the panel: it closes and focus is on the page.
    $page->keys(Help::SEND, 'Tab')
        ->assertMissing(Help::PANEL)
        ->assertScript(Help::FOCUS, 'body')
        ->click(Help::BUBBLE)
        ->assertValue(Help::SUBJECT, 'A kept subject');

    // The browser's Back changes the path under the open panel: closed, focus on the bubble.
    $page->click(Help::CLOSE)
        ->assertMissing(Help::PANEL)
        ->click('.admin-rail a[href="/admin/bookings"]')
        ->assertVisible('.admin-bookings-screen')
        ->click(Help::BUBBLE)
        ->assertValue(Help::SUBJECT, 'A kept subject')
        ->back()
        ->assertVisible('#customers-search')
        ->assertPathIs('/admin/customers')
        ->assertMissing(Help::PANEL)
        ->assertScript(Admin::focusIsOn(Help::BUBBLE), true);

    // A reply's draft survives a press on the page.
    $page->click(Help::BUBBLE)
        ->assertValue(Help::MESSAGE, 'A kept message')
        ->click('.admin-help-form > button:first-child')
        ->click(Help::ROW)
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'So the list has a row.')
        ->type(Help::REPLY, 'A draft reply')
        ->click('#customers-search')
        ->assertMissing(Help::PANEL)
        ->assertScript(Admin::focusIsOn('#customers-search'), true)
        ->click(Help::BUBBLE)
        ->assertValue(Help::REPLY, 'A draft reply')
        ->assertNoJavaScriptErrors();
});

it('closes for a dialog of the screen, which covers the bubble and keeps focus, and keeps the search for after it', function () {
    $page = Admin::onCalendar($this, 'luna', Admin::owner('luna'))
        ->assertVisible('.admin-new-booking')
        ->click(Help::BUBBLE)
        ->type(Help::SEARCH, 'closure')
        ->assertVisible('.admin-help-count')
        ->click('.admin-new-booking')
        ->waitForText(adminLine('admin/customers.wizard.add_segment'))
        ->assertMissing(Help::PANEL)
        ->assertScript(Help::FOCUS_IN_DIALOG, true);

    expect($page->script(Help::OVER_BUBBLE))->toMatchArray(['bubble' => true, 'covered' => true, 'hidden' => true]);

    // The dialog hands focus back to the control that opened it a moment after it has gone. The
    // bubble is pressed only once that has landed: landing later, with the panel open, it would
    // be focus moving out of the panel, which closes it.
    $page->keys('.admin-modal__frame', 'Escape')
        ->assertCount('.admin-modal__frame', 0)
        ->assertScript(Admin::focusIsOn('.admin-new-booking'), true)
        ->click(Help::BUBBLE)
        ->assertValue(Help::SEARCH, 'closure')
        ->assertNoJavaScriptErrors();
});

it('keeps the bubble out of reach under the setup wizard', function () {
    expect(Artisan::call('tenant:provision', [
        'slug' => HELP_PANEL_FRESH,
        '--name' => 'Reed Rooms',
        '--timezone' => 'Europe/Athens',
        '--currency' => 'EUR',
        '--owner-name' => 'Ria Owner',
        '--owner-email' => Admin::owner(HELP_PANEL_FRESH),
        '--owner-password' => HELP_PANEL_FRESH_PASSWORD,
        '--features' => 'staff',
    ]))->toBe(0);

    $page = Admin::signIn($this, HELP_PANEL_FRESH, Admin::owner(HELP_PANEL_FRESH), HELP_PANEL_FRESH_PASSWORD)
        ->assertSeeIn('.admin-setup .admin-modal__title', adminLine('admin/setup.title'))
        ->assertScript(Help::FOCUS_IN_DIALOG, true);

    expect($page->script(Help::OVER_BUBBLE))->toMatchArray(['bubble' => true, 'covered' => true, 'hidden' => true])
        ->and($page->script("document.querySelector('.admin-help-panel') === null"))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('fills a phone\'s screen with the panel, its tabs and the way back from a thread to the list', function () {
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);

    Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))
        ->assertVisible(Admin::HOME)
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->assertScript(Help::PANEL_FILLS_SCREEN, true)
        ->assertAttribute(Help::PANEL, 'aria-modal', 'true')
        ->assertPresent('.admin-help-backdrop')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(Help::SHOWN_TAB, 'answers')
        ->click(Help::TICKETS_TAB)
        ->assertScript(Help::SHOWN_TAB, 'tickets')
        ->assertSeeIn('.admin-help-empty', adminLine('admin/help.list.empty'))
        ->click(Help::LIST_OPEN)
        ->type(Help::SUBJECT, 'Asked from a phone')
        ->type(Help::MESSAGE, 'Written on a small screen.')
        ->click(Help::SEND)
        ->assertSeeIn(Help::THREAD_SUBJECT, 'Asked from a phone')
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'Written on a small screen.')
        ->assertScript(Help::PANEL_FILLS_SCREEN, true)
        ->click(Help::BACK_FROM_THREAD)
        ->assertSeeIn(Help::ROW, 'Asked from a phone')
        ->assertScript(Admin::focusIsOn(Help::LIST_OPEN), true)
        ->click(Help::ANSWERS_TAB)
        ->assertScript(Help::SHOWN_TAB, 'answers')
        ->click(Help::CLOSE)
        ->assertMissing(Help::PANEL)
        ->assertScript(Admin::focusIsOn(Help::BUBBLE), true)
        ->assertNoJavaScriptErrors();
});

it('keeps the bubble off every control at the foot of each screen', function (bool $phone) {
    $page = $phone
        ? Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME)
        : Admin::signedIn($this, 'luna', Admin::owner('luna'));

    foreach (HELP_PANEL_SCREENS as $path => $drawn) {
        $page->navigate(Admin::url('luna', $path))
            ->assertVisible($drawn)
            ->assertVisible(Help::BUBBLE);

        expect($page->script(Help::UNDER_BUBBLE))->toBe([], 'Under the bubble on '.($path === '' ? 'Home' : $path));
    }
})->with(['at a phone\'s width' => [true], 'at the desktop width' => [false]]);

it('finds the Greek answers by a word with or without its accents, in capitals or small letters and with either sigma, and offers a ticket when nothing matches', function () {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
    $word = Help::greekWord();
    $found = Help::matching(SiteLanguage::Greek, $word);
    $line = adminChoice('admin/help.search.found', count($found), [], SiteLanguage::Greek);
    $none = adminLine('admin/help.search.none', [], SiteLanguage::Greek);
    $variants = [
        $word,
        Help::bare($word),
        mb_strtoupper($word),
        mb_strtoupper(Help::bare($word)),
        mb_substr($word, 0, -1)."\u{03C3}",
    ];

    expect($found)->not->toBe([]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH);

    foreach ($variants as $variant) {
        $page->type(Help::SEARCH, 'qqzzxx')
            ->assertSeeIn('.admin-help-count', $none)
            ->type(Help::SEARCH, $variant)
            ->assertSeeIn('.admin-help-count', $line)
            ->assertSeeIn('.admin-help-matches', $found[0]);
    }

    $page->type(Help::SEARCH, 'qqzzxx')
        ->assertSeeIn('.admin-help-count', $none)
        ->assertSeeIn('.admin-help-matches > button', adminLine('admin/help.open', [], SiteLanguage::Greek))
        ->assertCount('.admin-help-still', 0)
        ->click('.admin-help-matches > button')
        ->assertScript(Help::SHOWN_TAB, 'tickets')
        ->assertValue(Help::MESSAGE, 'qqzzxx')
        ->assertNoJavaScriptErrors();
});

it('opens a thread longer than the panel with its reply box and last message in view, and goes back to the list at its top', function () {
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    $operator = addOperator();
    $uuid = Help::ticket(Admin::owner('luna'), 'A long conversation', "The first question.\nWith a second line.");

    foreach (range(1, 7) as $turn) {
        Help::answer($uuid, $operator, sprintf("Answer number %d.\nIt runs over two lines.", $turn));
        Help::write(Admin::owner('luna'), $uuid, sprintf("Follow-up number %d.\nIt runs over two lines too.", $turn));
    }

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->click(Help::TICKETS_TAB)
        ->click(Help::ROW)
        ->assertSeeIn(Help::THREAD_SUBJECT, 'A long conversation')
        ->assertScript(HELP_PANEL_THREAD_IN_VIEW, true)
        ->assertScript(Admin::focusIsOn(Help::BACK_FROM_THREAD), true);

    $page->type(Help::REPLY, 'The newest word on it.')
        ->click(Help::SEND_REPLY)
        ->assertSeeIn('.admin-help-messages > li:last-child', 'The newest word on it.')
        ->assertScript(HELP_PANEL_THREAD_IN_VIEW, true)
        ->click(Help::BACK_FROM_THREAD)
        ->assertSeeIn(Help::ROW, 'A long conversation')
        ->assertScript(HELP_PANEL_AT_TOP, true)
        ->assertNoJavaScriptErrors();
});

it('stands the toasts beside the open panel at the narrowest desktop width and at a wide one', function () {
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    $uuid = Help::ticket(Admin::owner('luna'), 'A ticket to reply on', 'Its first message.');

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->click(Help::TICKETS_TAB)
        ->click(Help::ROW)
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'Its first message.');

    foreach ([1440, 768] as $width) {
        $page->resize($width, 900)
            ->assertScript("window.innerWidth === {$width}", true)
            ->assertVisible(Help::PANEL)
            ->assertScript(HELP_PANEL_TOASTS_BESIDE, true);
    }

    // The ticket is moved to another business under the open thread, so the reply is refused
    // and a toast shows, at the narrowest desktop width, beside the panel still open.
    DB::table('help_tickets')->where('uuid', $uuid)->update(['tenant_id' => User::query()->where('email', Admin::owner('pier'))->value('tenant_id')]);

    $page->type(Help::REPLY, 'A reply that will not land.')
        ->click(Help::SEND_REPLY)
        ->assertVisible('.admin-toast')
        ->assertVisible(Help::PANEL)
        ->assertScript(HELP_PANEL_TOASTS_BESIDE, true)
        ->assertNoJavaScriptErrors();
});
