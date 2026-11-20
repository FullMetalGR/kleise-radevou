<?php

use App\Platform\PasswordLinks;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;

/*
 * The Team screen in a real browser: the owner opens Team from the rail, finds themself marked
 * You with Remove held, invites someone as a manager, and finds the new row marked Invitation
 * out with its role pressed; then tries to step down as the only owner and reads the refusal's
 * sentence, the role kept. The guards themselves are proven by TeamScreenTest; the busy and
 * failing states by the component tests.
 *
 * Then the screen's layout, in English and in Greek, whose words are longer: on a desktop the
 * invitation's four controls lie on one line and the members are a grid that fits its card, the
 * grid still fitting in the narrowest window that keeps it; narrower, the invitation's row wraps
 * with no field squeezed, and beside the sidebar at 1024px the members read as rows, every
 * control of theirs inside the card and nothing scrolling sideways; on a phone the invitation
 * stacks, each control across the card, and each member is a block of labelled lines whose role
 * control lies across its row under its name, where a role is changed as anywhere else, and the
 * two links of a row each keep a touch area of their own. The page never scrolls sideways.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

const TEAM_WALK_ENTRY = '.admin-rail a[href="/admin/team"]';

/**
 * Whether Name, Email, the role and Send lie on one line: every box shares a band of the page's
 * height with every other, and each starts where the one before it ends.
 */
const TEAM_WALK_INVITE_ON_ONE_LINE = <<<'JS'
    document.fonts.ready.then(() => {
        const boxes = ['#team-invite-name', '#team-invite-email', '.admin-team-invite__role', '.admin-team-invite__send'].map((selector) => document.querySelector(selector)?.getBoundingClientRect());

        return boxes.every((box) => box !== undefined && box.width > 0)
            && Math.max(...boxes.map((box) => box.top)) < Math.min(...boxes.map((box) => box.bottom))
            && boxes.every((box, at) => at === 0 || box.left >= boxes[at - 1].right);
    })
    JS;

/**
 * Whether Name, Email, the role and Send are stacked: each below the one before it and each as
 * wide as the invitation's row, the role's segments reaching across the group on every line they
 * take.
 */
const TEAM_WALK_INVITE_STACKED = <<<'JS'
    document.fonts.ready.then(() => {
        const row = document.querySelector('.admin-team-invite__row')?.getBoundingClientRect();
        const boxes = ['#team-invite-name', '#team-invite-email', '.admin-team-invite__role', '.admin-team-invite__send'].map((selector) => document.querySelector(selector)?.getBoundingClientRect());

        if (row === undefined || boxes.some((box) => box === undefined)) {
            return false;
        }

        const ends = new Map();

        for (const option of document.querySelectorAll('.admin-team-invite__role button')) {
            const box = option.getBoundingClientRect();

            ends.set(Math.round(box.top), Math.max(ends.get(Math.round(box.top)) ?? 0, box.right));
        }

        return boxes.every((box, at) => at === 0 || box.top >= boxes[at - 1].bottom - 1)
            && boxes.every((box) => Math.abs(box.left - row.left) <= 1 && Math.abs(box.right - row.right) <= 1)
            && [...ends.values()].every((end) => end >= boxes[2].right - 6);
    })
    JS;

/**
 * Whether the invitation's two fields keep their room wherever the row puts them: each at least
 * 12rem wide, or as wide as the row where the row is narrower, and nothing in the row past its
 * end.
 */
const TEAM_WALK_INVITE_ROOMY = <<<'JS'
    document.fonts.ready.then(() => {
        const row = document.querySelector('.admin-team-invite__row')?.getBoundingClientRect();
        const fields = ['#team-invite-name', '#team-invite-email'].map((selector) => document.querySelector(selector)?.getBoundingClientRect());

        return row !== undefined
            && fields.every((field) => field !== undefined && field.width >= Math.min(192, row.width) - 1)
            && [...document.querySelectorAll('.admin-team-invite__row > *')].every((part) => part.getBoundingClientRect().right <= row.right + 1);
    })
    JS;

/**
 * Whether the members draw as a grid: the table, its head and every member's row display as a
 * table's own parts, the head row has its height on screen, no cell shows its column name, and
 * the table's box has nothing to scroll sideways.
 */
const TEAM_WALK_TABLE_IS_GRID = <<<'JS'
    document.fonts.ready.then(() => {
        const box = document.querySelector('.admin-team-members .admin-table-scroll');
        const table = box?.querySelector('table');
        const rows = [...document.querySelectorAll('.admin-team-member')];
        const shown = (element) => getComputedStyle(element).display;

        return table instanceof HTMLTableElement
            && shown(table) === 'table'
            && table.tHead.getBoundingClientRect().height > 1
            && rows.length > 0
            && rows.every((row) => shown(row) === 'table-row')
            && [...table.querySelectorAll('.admin-table__label')].every((label) => shown(label) === 'none')
            && box.scrollWidth <= box.clientWidth + 1;
    })
    JS;

/**
 * Whether every member's role control, sign-in link and Remove lie inside the members' card as
 * the page stands, the table's box scrolled nowhere: each has its width on screen and neither
 * side of it is past the card's.
 */
const TEAM_WALK_CONTROLS_IN_CARD = <<<'JS'
    document.fonts.ready.then(() => {
        const card = document.querySelector('.admin-team-members')?.getBoundingClientRect();
        const box = document.querySelector('.admin-team-members .admin-table-scroll');
        const members = [...document.querySelectorAll('.admin-team-member')];

        return card !== undefined
            && box !== null
            && box.scrollLeft === 0
            && members.length > 0
            && members.every((member) => ['.admin-team-role', '.admin-team-link', '.admin-team-remove'].every((selector) => {
                const at = member.querySelector(selector)?.getBoundingClientRect();

                return at !== undefined && at.width > 0 && at.left >= card.left - 1 && at.right <= card.right + 1;
            }));
    })
    JS;

/**
 * Whether the invitation's row has wrapped: Name and Email side by side on its first line, and
 * Send on a line under them.
 */
const TEAM_WALK_INVITE_WRAPPED = <<<'JS'
    document.fonts.ready.then(() => {
        const [name, email, send] = ['#team-invite-name', '#team-invite-email', '.admin-team-invite__send'].map((selector) => document.querySelector(selector)?.getBoundingClientRect());

        return name !== undefined
            && email !== undefined
            && send !== undefined
            && Math.max(name.top, email.top) < Math.min(name.bottom, email.bottom)
            && email.left >= name.right
            && send.top >= Math.max(name.bottom, email.bottom) - 1;
    })
    JS;

/**
 * Whether Send sign-in link and Remove each keep a touch area of their own on every member's row:
 * a touch 20 pixels above the middle of a control's words, one on the middle and one 20 pixels
 * below it each land on that control and on no other. Each control is brought to the middle of
 * the screen first, clear of the bars at its head and foot.
 */
const TEAM_WALK_ACTIONS_TAKE_A_TOUCH = <<<'JS'
    document.fonts.ready.then(() => {
        const actions = [...document.querySelectorAll('.admin-team-member .admin-team-link, .admin-team-member .admin-team-remove')];

        return actions.length > 0 && actions.every((action) => {
            action.scrollIntoView({ block: 'center', behavior: 'instant' });

            const words = document.createRange();
            words.selectNodeContents(action);

            const at = words.getBoundingClientRect();
            const across = at.left + at.width / 2;
            const middle = at.top + at.height / 2;

            return at.width > 0
                && [middle - 20, middle, middle + 20].every((down) => document.elementFromPoint(across, down)?.closest('button, a, input, select, textarea, [role="button"]') === action);
        });
    })
    JS;

/** Whether the invitation's role control keeps its segments on one line. */
const TEAM_WALK_INVITE_ROLE_ON_ONE_LINE = <<<'JS'
    document.fonts.ready.then(() => {
        const options = [...document.querySelectorAll('.admin-team-invite__role button')];

        return options.length === 3 && new Set(options.map((option) => Math.round(option.getBoundingClientRect().top))).size === 1;
    })
    JS;

/**
 * The most lines any member's role control takes, its segments reaching across the control on
 * every one of them; 0 where a line stops short of the control's end.
 */
const TEAM_WALK_MEMBER_ROLE_LINES = <<<'JS'
    document.fonts.ready.then(() => {
        const groups = [...document.querySelectorAll('.admin-team-member .admin-team-role')];
        const lines = groups.map((group) => {
            const ends = new Map();
            const end = group.getBoundingClientRect().right;

            for (const option of group.querySelectorAll('button')) {
                const at = option.getBoundingClientRect();

                ends.set(Math.round(at.top), Math.max(ends.get(Math.round(at.top)) ?? 0, at.right));
            }

            return [...ends.values()].every((reach) => reach >= end - 6) ? ends.size : 0;
        });

        return groups.length === 0 || lines.includes(0) ? 0 : Math.max(...lines);
    })
    JS;

/**
 * Whether each member's role control lies under its column name and across its row: the name
 * shown and above the control, the control as wide as its cell, and its segments reaching across
 * the control on every line they take.
 */
const TEAM_WALK_ROLES_UNDER_LABEL = <<<'JS'
    document.fonts.ready.then(() => {
        const groups = [...document.querySelectorAll('.admin-team-member .admin-team-role')];

        return groups.length > 0 && groups.every((group) => {
            const cell = group.closest('td');
            const label = cell?.querySelector('.admin-table__label');
            const box = group.getBoundingClientRect();
            const ends = new Map();

            if (cell === null || label === null || label === undefined || getComputedStyle(label).display === 'none') {
                return false;
            }

            for (const option of group.querySelectorAll('button')) {
                const at = option.getBoundingClientRect();

                ends.set(Math.round(at.top), Math.max(ends.get(Math.round(at.top)) ?? 0, at.right));
            }

            const room = cell.getBoundingClientRect();

            return label.getBoundingClientRect().bottom <= box.top + 1
                && Math.abs(box.left - room.left) <= 1
                && Math.abs(box.right - room.right) <= 1
                && [...ends.values()].every((end) => end >= box.right - 6);
        });
    })
    JS;

it('invites someone from the Team screen, the new row marked Invitation out, and refuses the only owner\'s stepping down in a sentence', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(TEAM_WALK_ENTRY)
        ->waitForText(adminLine('admin/team.invite.title'))
        ->assertPathIs('/admin/team')
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/team')
        ->assertSeeIn('.admin-team-count', adminChoice('admin/team.page.count', 1))
        ->assertSeeIn('.admin-team-member:first-child .admin-chip--past', adminLine('admin/team.members.you'))
        ->assertDisabled('.admin-team-member:first-child .admin-team-remove');

    $page->type('#team-invite-name', 'Maria Ioannou')
        ->type('#team-invite-email', 'maria@luna.example')
        ->click('.admin-team-invite__role button:nth-child(2)')
        ->click('.admin-team-invite__send')
        ->waitForText(adminChoice('admin/team.flashes.invited', PasswordLinks::hours(), ['email' => 'maria@luna.example']))
        ->assertSeeIn('.admin-team-count', adminChoice('admin/team.page.count', 2))
        ->assertSeeIn('.admin-team-member:nth-child(2)', 'Maria Ioannou')
        ->assertSeeIn('.admin-team-member:nth-child(2)', adminLine('admin/team.members.invited'))
        ->assertAttribute('.admin-team-member:nth-child(2) .admin-team-role button:nth-child(2)', 'aria-pressed', 'true');

    expect(DB::table('users')->where('email', 'maria@luna.example')->value('role'))->toBe('manager');

    $page->click('.admin-team-member:first-child .admin-team-role button:nth-child(2)')
        ->waitForText(adminLine('admin/team.refusals.last_owner'))
        ->assertAttribute('.admin-team-member:first-child .admin-team-role button:nth-child(1)', 'aria-pressed', 'true')
        ->assertNoJavaScriptErrors();

    expect(DB::table('users')->where('email', Admin::owner('luna'))->value('role'))->toBe('owner');
});

it('lays Team out on a desktop with the invitation on one line, the count on the lead line and the members a grid that fits its card, then wraps the invitation and reads the members as rows where their card is too narrow for the grid', function (SiteLanguage $language) {
    addTenantUser('elena@luna.example', 'manager');
    // The language is the owner's before the sign-in, so the whole walk reads it.
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);
    $page->navigate(Admin::url('luna', '/team'))
        ->assertVisible('.admin-team-member:nth-child(2)')
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
        ->assertSeeIn('.admin-page-header__lead .admin-team-count', adminChoice('admin/team.page.count', 2, [], $language))
        ->assertScript(TEAM_WALK_INVITE_ON_ONE_LINE, true)
        ->assertScript(TEAM_WALK_INVITE_ROLE_ON_ONE_LINE, true)
        ->assertScript(TEAM_WALK_TABLE_IS_GRID, true)
        // A member's role takes one line in English; in Greek the grid wraps it once, evenly.
        ->assertScript(TEAM_WALK_MEMBER_ROLE_LINES, $language === SiteLanguage::English ? 1 : 2)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'team-1440-'.$language->value);

    // Beside the sidebar at 1366px the members' card is just wide enough to be a grid again: the
    // grid fits it, nothing scrolling sideways and every control inside the card.
    $page->resize(1366, 800)
        ->assertScript(TEAM_WALK_TABLE_IS_GRID, true)
        ->assertScript(TEAM_WALK_CONTROLS_IN_CARD, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    // At 1100px the invitation's card no longer holds its four controls on one line in either
    // language: Send drops under the fields before either field is squeezed, and nothing reaches
    // past the row.
    $page->resize(1100, 800)
        ->assertScript(TEAM_WALK_INVITE_ROOMY, true)
        ->assertScript(TEAM_WALK_INVITE_WRAPPED, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    // At 1024px the members' card is too narrow for the grid in either language, so the members
    // read as rows: the table's box has nothing to scroll sideways, and every member's role
    // control, sign-in link and Remove lie inside the card.
    $page->resize(1024, 800)
        ->assertScript(PhoneScripts::TEAM_TABLE_AS_ROWS, true)
        ->assertScript(TEAM_WALK_CONTROLS_IN_CARD, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'team-1024-'.$language->value);
})->with([SiteLanguage::English, SiteLanguage::Greek]);

it('stacks the invitation on a phone, sets each member\'s role across its row under its name with a touch area for each of its two links, and changes a role there', function (SiteLanguage $language) {
    addTenantUser('elena@luna.example', 'manager');
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/team'))
        ->assertVisible('.admin-team-member:nth-child(2)')
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390)
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
        ->assertScript(TEAM_WALK_INVITE_STACKED, true)
        ->assertScript(TEAM_WALK_INVITE_ROOMY, true)
        ->assertScript(PhoneScripts::TEAM_TABLE_AS_ROWS, true)
        ->assertScript(TEAM_WALK_ROLES_UNDER_LABEL, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'team-390-'.$language->value);

    $page->assertScript(TEAM_WALK_ACTIONS_TAKE_A_TOUCH, true)
        ->click('.admin-team-member:nth-child(2) .admin-team-role button:nth-child(3)')
        ->waitForText(adminLine('admin/team.flashes.role_saved', ['name' => 'elena@luna.example'], $language))
        ->assertAttribute('.admin-team-member:nth-child(2) .admin-team-role button:nth-child(3)', 'aria-pressed', 'true')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();

    expect(DB::table('users')->where('email', 'elena@luna.example')->value('role'))->toBe('staff');
})->with([SiteLanguage::English, SiteLanguage::Greek]);
