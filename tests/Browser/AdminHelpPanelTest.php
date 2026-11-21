<?php

use App\Application\Notification\PlatformMessenger;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Help;
use Tests\Browser\Support\Look;
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
 * search finding nothing offers a ticket beside the line saying so. Its look: a rounded card on a
 * desktop and a square-cornered screen on a phone, the bubble, Close and each question at their
 * sizes, a thread's messages on their sides, and every view of it with nothing scrolling sideways.
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

/** A ticket's first message long enough to wrap in a thread at any width the panel takes. */
const HELP_PANEL_LONG_MESSAGE = 'Where are the opening hours for the summer set, and can each member of the team keep hours of their own for the season?';

/** A writer's name of sixty letters with no place to break it, wider than a message can be. */
const HELP_PANEL_LONG_NAME = 'WilhelminaWilhelminaWilhelminaWilhelminaWilhelminaWilhelmina';

/**
 * Whether the view the open panel shows scrolls no way but down. The panel is held to the window
 * and its view is a scroll box of its own, so a line too wide for it widens that box and never
 * the document: the box itself is asked. Both tabs' views stay in the page, so the one drawn is
 * the one read.
 */
const HELP_PANEL_NO_SIDEWAYS = <<<'JS'
    document.fonts.ready.then(() => {
        const body = [...document.querySelectorAll('.admin-help-panel .admin-help-body')].find((view) => view.getClientRects().length > 0);
        return body !== undefined && body.scrollWidth <= body.clientWidth + 1;
    })
    JS;

/** The open panel's top left corner radius, as the browser computes it. */
const HELP_PANEL_RADIUS = "getComputedStyle(document.querySelector('.admin-help-panel')).borderTopLeftRadius";

/**
 * The sizes a touch needs, read off the page in whole pixels: the bubble's box, the panel's
 * Close's box, and the height of the lowest question shown.
 */
const HELP_PANEL_SIZES = <<<'JS'
    document.fonts.ready.then(() => {
        const box = (selector) => {
            const element = document.querySelector(selector);
            if (element === null) {
                return null;
            }
            const rect = element.getBoundingClientRect();
            return [Math.round(rect.width), Math.round(rect.height)];
        };
        const questions = [...document.querySelectorAll('.admin-help-question')]
            .filter((question) => question.getClientRects().length > 0)
            .map((question) => question.getBoundingClientRect().height);
        return {
            bubble: box('.admin-help-bubble'),
            close: box('.admin-help-close'),
            question: questions.length === 0 ? 0 : Math.floor(Math.min(...questions)),
        };
    })
    JS;

/**
 * Whether every message of the thread shown stands on its side of the list, the business's
 * against its end and the platform's against its start, and the widest message's share of the
 * list's width in whole percent.
 */
const HELP_PANEL_MESSAGE_SIDES = <<<'JS'
    document.fonts.ready.then(() => {
        const list = document.querySelector('.admin-help-messages');
        const messages = [...document.querySelectorAll('.admin-help-message')];
        if (list === null || messages.length === 0) {
            return null;
        }
        const l = list.getBoundingClientRect();
        const near = (a, b) => Math.abs(a - b) <= 1;
        const sides = messages.every((message) => {
            const m = message.getBoundingClientRect();
            return message.classList.contains('admin-help-message--business') ? near(m.right, l.right) : near(m.left, l.left);
        });
        const widest = Math.max(...messages.map((message) => message.getBoundingClientRect().width));
        return { sides, widest: Math.round((widest / l.width) * 100) };
    })
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
        ->assertScript(PhoneScripts::BAR_HIDDEN, true)
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
        ->assertScript(PhoneScripts::BAR_HIDDEN, false)
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

it('keeps the bar along the foot, the save bar, the bubble, a toast and the open panel apart below the wide breakpoint', function () {
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    $uuid = Help::ticket(Admin::owner('luna'), 'A ticket to reply on', 'Its first message.');

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(800, 900);
    $page->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.sections.booking_rules'))
        ->assertVisible('.admin-save-bar')
        ->assertVisible('.admin-bottom-bar')
        ->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->click(Help::TICKETS_TAB)
        ->click(Help::ROW)
        ->assertSeeIn(Help::BUSINESS_MESSAGE, 'Its first message.');

    // The reply is refused, as the ticket has moved to another business, so an error toast shows
    // and stays while the panel is still open.
    DB::table('help_tickets')->where('uuid', $uuid)->update(['tenant_id' => User::query()->where('email', Admin::owner('pier'))->value('tenant_id')]);

    $page->type(Help::REPLY, 'A reply that will not land.')
        ->click(Help::SEND_REPLY)
        ->assertVisible('.admin-toast')
        ->assertVisible(Help::PANEL);

    expect($page->script(PhoneScripts::FLOATERS_APART))->toBe(['drawn' => ['bar', 'save', 'bubble', 'toast', 'panel'], 'overlaps' => []]);
    $page->assertNoJavaScriptErrors();
});

it('floats the panel as a rounded card on a desktop and fills a phone\'s screen square-cornered, every view of it drawn with nothing scrolling sideways, a reply and a ticket sent', function (bool $phone, SiteLanguage $language) {
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);
    $uuid = Help::ticket(Admin::owner('luna'), 'Hours for the summer', HELP_PANEL_LONG_MESSAGE);
    Help::write(Admin::owner('luna'), $uuid, 'The winter set too.', HELP_PANEL_LONG_NAME);
    Help::answer($uuid, addOperator(), 'Under Settings.');
    $width = $phone ? 390 : 1440;
    $shot = fn (string $view): string => sprintf('help-%s-%d-%s', $view, $width, $language->value);
    $first = Help::answerNames($language)[0];

    $page = $phone
        ? Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME)
        : Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);

    $page->assertScript(PhoneScripts::VIEWPORT_WIDTH, $width)
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
        ->assertVisible(Help::DOT)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, $shot('bubble'));

    $page->click(Help::BUBBLE)
        ->assertVisible(Help::SEARCH)
        ->assertScript(Help::PANEL_FILLS_SCREEN, $phone)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(HELP_PANEL_NO_SIDEWAYS, true);

    $radius = $page->script(HELP_PANEL_RADIUS);
    $phone ? expect($radius)->toBe('0px') : expect($radius)->not->toBe('0px');

    // One answer open, then the sizes a touch needs.
    $page->click('.admin-help-question[aria-controls="admin-help-answer-'.$first.'"]')
        ->assertVisible('#admin-help-answer-'.$first)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(HELP_PANEL_NO_SIDEWAYS, true);
    $sizes = $page->script(HELP_PANEL_SIZES);
    assert(is_array($sizes));
    expect($sizes['bubble'])->toBe([48, 48])
        ->and($sizes['close'])->toBe([40, 40])
        ->and($sizes['question'])->toBeGreaterThanOrEqual(44);
    Look::shot($page, $shot('answers'));

    $page->type(Help::SEARCH, 'qqzzxx')
        ->assertSeeIn('.admin-help-count', adminLine('admin/help.search.none', [], $language))
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(HELP_PANEL_NO_SIDEWAYS, true);
    Look::shot($page, $shot('no-match'));

    $page->click(Help::TICKETS_TAB)
        ->assertSeeIn(Help::ROW, 'Hours for the summer')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(HELP_PANEL_NO_SIDEWAYS, true);
    Look::shot($page, $shot('list'));

    $page->click(Help::ROW)
        ->assertSeeIn(Help::PLATFORM_MESSAGE, 'Under Settings.')
        ->type(Help::REPLY, 'Thank you, found them.')
        ->click(Help::SEND_REPLY)
        ->assertSeeIn('.admin-help-messages > li:last-child', 'Thank you, found them.')
        ->assertSeeIn('.admin-help-messages > li:nth-child(2)', HELP_PANEL_LONG_NAME)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(HELP_PANEL_NO_SIDEWAYS, true);
    expect($page->script(HELP_PANEL_MESSAGE_SIDES))->toBe(['sides' => true, 'widest' => 88]);
    Look::shot($page, $shot('thread'));

    $page->click(Help::BACK_FROM_THREAD)
        ->click(Help::LIST_OPEN)
        ->assertScript(Admin::focusIsOn(Help::SUBJECT), true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(HELP_PANEL_NO_SIDEWAYS, true);
    Look::shot($page, $shot('form'));

    $page->type(Help::SUBJECT, 'Asked at '.$width)
        ->type(Help::MESSAGE, 'Sent from the form.')
        ->click(Help::SEND)
        ->assertSeeIn(Help::THREAD_SUBJECT, 'Asked at '.$width)
        ->assertNoJavaScriptErrors();
})->with([
    'on a desktop in English' => [false, SiteLanguage::English],
    'on a phone in Greek' => [true, SiteLanguage::Greek],
]);
