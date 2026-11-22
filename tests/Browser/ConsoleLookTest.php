<?php

use App\Application\Notification\PlatformMessenger;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Console;
use Tests\Browser\Support\Help;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;
use Tests\Support\RecordingPlatformMessages;

/*
 * Every screen of the console at a phone's width, a tablet's and a desktop's: each drawn under its
 * own hook with the one shared page header holding its one main heading, free of script errors
 * and no wider than the window. A screen is loaded afresh at each width, so its first paint is the
 * layout that width is given. Below the wide breakpoint the console keeps the bottom bar, its four
 * entries and Menu each labelled whole, and on every screen the line naming the console's zone
 * closes the first row whole, inside the window. The door is read at the same three widths first,
 * and an opened date picker last. With LOOK_SHOTS naming a run each stop is saved light, and each
 * screen dark at the phone's width and the desktop's.
 *
 * The demo seeder provisions luna and pier; luna's owner opens one ticket through the help desk
 * and one contact message is written straight to its row, so Tickets, Messages and the ticket's
 * own page have something to draw, and the operator's sign-in gives the audit its first line.
 */

/** Whether the screen draws exactly one shared page header and one main heading, inside it. */
const CONSOLE_LOOK_ONE_HEADER = "document.querySelectorAll('.admin-page-header').length === 1 && document.querySelectorAll('h1').length === 1 && document.querySelector('.admin-page-header h1') !== null";

/** Whether the zone line is drawn whole, inside the window, without pushing its row past it. */
const CONSOLE_LOOK_ZONE_WHOLE = <<<'JS'
(() => {
    const zone = document.querySelector('.admin-console-zone');
    const row = zone?.closest('.admin-page-header__row');
    const width = document.documentElement.clientWidth;
    if (!zone || !row) {
        return false;
    }
    const box = zone.getBoundingClientRect();
    return box.width > 0 && box.left >= 0 && box.right <= width
        && zone.scrollWidth <= zone.clientWidth + 1
        && row.scrollWidth <= row.clientWidth + 1
        && row.getBoundingClientRect().right <= width;
})()
JS;

/** The bottom bar as drawn: its entries, its Menu, each label cut short, and whether it sits inside the window. */
const CONSOLE_LOOK_BOTTOM_BAR = <<<'JS'
(() => {
    const bar = document.querySelector('.admin-bottom-bar');
    if (!bar) {
        return null;
    }
    const box = bar.getBoundingClientRect();
    return {
        entries: bar.querySelectorAll('.admin-bottom-bar__entry').length,
        menu: bar.querySelectorAll('.admin-bottom-bar__menu').length,
        cut: [...bar.querySelectorAll('.admin-bottom-bar__label')].filter((label) => label.scrollWidth > label.clientWidth).map((label) => label.textContent),
        inside: box.left >= 0 && box.right <= document.documentElement.clientWidth,
    };
})()
JS;

/** How far in from its own card's left edge each starts: the account's first fact and the first person's name. */
const CONSOLE_LOOK_PEOPLE_INSET = <<<'JS'
(() => {
    const left = (selector) => document.querySelector(selector).getBoundingClientRect().left;
    return {
        account: Math.round(left('.admin-tenant-account dt') - left('.admin-tenant-account')),
        person: Math.round(left('.admin-tenant-people__name') - left('.admin-tenant-people')),
    };
})()
JS;

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * One open ticket of luna's awaiting the platform's reply, opened by its owner through the help
 * desk with a first message, and one open contact message; answers the ticket's id.
 */
function consoleLookRows(): int
{
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    $uuid = Help::ticket(Admin::owner('luna'), 'The calendar shows the wrong week', 'Since this morning the calendar opens on next week instead of this one.');

    DB::table('contact_messages')->insert([
        'name' => 'Maria Papadopoulou',
        'email' => 'maria@salon.example',
        'message' => 'Do you take bookings for two chairs?',
        'locale' => 'en',
        'created_at' => DB::raw('now()'),
        'archived_at' => null,
    ]);

    return Help::id($uuid);
}

it('draws every screen of the console at a phone\'s, a tablet\'s and a desktop\'s width under its one header, with no script error and no sideways scroll', function () {
    $ticket = consoleLookRows();
    addOperator();

    $screens = [
        'tenants' => ['/', '.admin-tenants-screen'],
        'tickets' => ['/tickets', '.admin-tickets-screen'],
        'messages' => ['/messages', '.admin-messages-screen'],
        'audit' => ['/audit', '.admin-audit-screen'],
        'tenant' => ['/tenants/luna', '.admin-tenant-screen'],
        'ticket' => ['/tickets/'.$ticket, '.admin-ticket-screen'],
        'new-tenant' => ['/tenants/new', '.admin-new-tenant-screen'],
    ];

    $door = $this->visit(Console::url('/login'))->assertVisible('.admin-console-login');

    foreach ([390, 800, 1440] as $width) {
        $door->resize($width, 900)
            ->assertVisible('.admin-console-login .admin-door__card h1')
            ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
        Look::shot($door, sprintf('console-door-%d-light', $width));
    }

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')->assertVisible('.admin-tenants-screen');

    foreach ([390, 800, 1440] as $width) {
        $page->resize($width, 900);

        foreach ($screens as $screen => [$path, $hook]) {
            $page->navigate(Console::url($path))
                ->assertVisible($hook)
                ->assertScript(CONSOLE_LOOK_ONE_HEADER, true)
                ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
                ->assertScript(CONSOLE_LOOK_ZONE_WHOLE, true)
                ->assertNoJavaScriptErrors();

            if ($width < 900) {
                expect($page->script(CONSOLE_LOOK_BOTTOM_BAR))->toBe(['entries' => 4, 'menu' => 1, 'cut' => [], 'inside' => true]);
            }

            Look::shot($page, sprintf('console-%s-%d-light', $screen, $width));

            if ($width !== 800 && Look::shooting()) {
                $page->assertScript(Look::wear('neutral', true), 'neutral');
                Look::shot($page, sprintf('console-%s-%d-dark', $screen, $width));
            }
        }
    }

    $page->navigate(Console::url('/'))
        ->click('.admin-tenants-from')
        ->assertVisible('.admin-date-field__popover');
    Look::shot($page, 'console-overlay-1440-light');
});

it('lines a tenant\'s people up with its other cards at a phone\'s width', function () {
    addOperator();

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')->assertVisible('.admin-tenants-screen');
    $page->resize(390, 900);
    $page->navigate(Console::url('/tenants/luna'))->assertVisible('.admin-tenant-people__row');

    $inset = $page->script(CONSOLE_LOOK_PEOPLE_INSET);
    assert(is_array($inset) && is_int($inset['account']) && is_int($inset['person']));

    expect(abs($inset['person'] - $inset['account']))->toBeLessThanOrEqual(4);
    $page->assertNoJavaScriptErrors();
});
