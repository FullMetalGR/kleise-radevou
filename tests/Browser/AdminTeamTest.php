<?php

use App\Platform\PasswordLinks;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;

/*
 * The Team screen in a real browser: the owner opens Team from the rail, finds themself marked
 * You with Remove held, invites someone as a manager, and finds the new row marked Invitation
 * out with its role pressed; then tries to step down as the only owner and reads the refusal's
 * sentence, the role kept. The guards themselves are proven by TeamScreenTest; the busy and
 * failing states by the component tests.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

const TEAM_WALK_ENTRY = '.admin-rail a[href="/admin/team"]';

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
