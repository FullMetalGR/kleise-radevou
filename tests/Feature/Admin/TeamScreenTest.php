<?php

use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Requests\TeamRoleRequest;
use App\Models\User;
use App\Platform\PasswordLinks;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LevelledPlatformMessages;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Team screen: the people who sign in to this business's admin, the owner's alone. GET
 * /admin/team lists every user of the tenant in the order they joined, each with whether they are
 * the viewer, whether their invitation is still out (their address never proven by a used link),
 * the name of the staff row linked to them and their role; POST invites someone, creating the
 * users row with a secret nobody knows and mailing the set-password link the console mails; PUT
 * changes a role; DELETE removes a user, unlinking their staff row; POST .../link mails the link
 * again. The last owner is never demoted or removed and nobody removes themself. The platform's
 * seam is a recorder, so each mail is read as it was handed over.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    $this->seam = app(LevelledPlatformMessages::class);
    app()->instance(PlatformMessenger::class, $this->seam);
});

function teamOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

function teamUser(string $email): User
{
    return User::query()->where('email', $email)->sole();
}

/**
 * A write of the Team screen as the user given sends it from the screen.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function teamWrite(TestCase $test, User $user, string $method, string $path, array $body = []): TestResponse
{
    return $test->actingAs($user)->from(tenantUrl('luna', '/admin/team'))->json($method, tenantUrl('luna', '/admin/team'.$path), $body, ['X-Inertia' => 'true']);
}

/**
 * The clear token a set-password message's link carries, the empty string where it carries none.
 */
function teamTokenIn(string $body): string
{
    return preg_match('~/admin/set-password/([0-9a-f]{64})~', $body, $match) === 1 ? $match[1] : '';
}

it('lists every user of the tenant in the order they joined, the viewer marked, an invitation still out marked, the staff row linked to each by name, and the page\'s words named', function () {
    $elena = addTenantUser('elena@luna.example', 'manager', 'Elena Papadaki');
    $invited = addTenantUser('ioanna@luna.example', 'staff');
    DB::table('users')->where('id', '<>', $invited)->update(['email_verified_at' => now()->utc()->format('Y-m-d H:i:sP')]);
    DB::table('users')->where('id', $invited)->update(['email_verified_at' => null, 'password_link_hash' => 'out', 'password_link_expires_at' => now()->addDay()->utc()->format('Y-m-d H:i:sP')]);
    // Unproven but sent no link, as a seeded owner is: no invitation is out.
    DB::table('users')->where('id', $elena)->update(['email_verified_at' => null]);
    addTenantUser('elsewhere@pier.example', 'manager');
    DB::table('users')->where('email', 'elsewhere@pier.example')->update(['tenant_id' => DB::table('tenants')->where('slug', 'pier')->value('id')]);

    $this->actingAs(teamOwner())->get(tenantUrl('luna', '/admin/team'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Team')
            ->has('strings.team')
            ->where('strings.team.page.title', adminLine('admin/team.page.title'))
            ->where('members', [
                ['id' => teamOwner()->id, 'name' => teamOwner()->name, 'email' => 'owner@luna.example', 'role' => 'owner', 'you' => true, 'invited' => false, 'staff' => null],
                ['id' => $elena, 'name' => 'elena@luna.example', 'email' => 'elena@luna.example', 'role' => 'manager', 'you' => false, 'invited' => false, 'staff' => 'Elena Papadaki'],
                ['id' => $invited, 'name' => 'ioanna@luna.example', 'email' => 'ioanna@luna.example', 'role' => 'staff', 'you' => false, 'invited' => true, 'staff' => null],
            ]));
});

it('lists the signed-in owner first, You, the rest in the order they joined', function () {
    $ada = addTenantUser('ada@luna.example', 'staff');
    $bea = addTenantUser('bea@luna.example', 'owner');

    $this->actingAs(teamUser('bea@luna.example'))->get(tenantUrl('luna', '/admin/team'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('members', fn (Collection $members): bool => $members->pluck('id')->all() === [$bea, teamOwner()->id, $ada]
                && $members->pluck('you')->all() === [true, false, false]));
});

it('puts Team on an owner\'s rail between Reports and Settings, lit on its own page, and on no manager\'s or staff user\'s', function () {
    addTenantUser('manager@luna.example', 'manager');
    $rail = fn (string $email) => $this->actingAs(teamUser($email))->get(tenantUrl('luna', '/admin/reports'))->assertOk();

    $rail('owner@luna.example')->assertInertia(fn (Assert $page) => $page->where('nav', fn (Collection $nav): bool => array_slice($nav->pluck('label')->all(), 7, 3) === [navLabel('reports'), navLabel('team'), navLabel('settings')]));
    $rail('manager@luna.example')->assertInertia(fn (Assert $page) => $page->where('nav', fn (Collection $nav): bool => ! in_array(navLabel('team'), $nav->pluck('label')->all(), true)));

    $this->actingAs(teamOwner())->get(tenantUrl('luna', '/admin/team'))
        ->assertInertia(fn (Assert $page) => $page->where('nav.8', ['label' => navLabel('team'), 'url' => '/admin/team', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => true]));
});

it('refuses every route of the screen to a {role} with a 403, writing nothing and mailing nothing', function (string $role) {
    $actor = User::query()->findOrFail(addTenantUser($role.'@luna.example', $role));
    $owner = teamOwner();
    $before = DB::table('users')->orderBy('id')->get()->toArray();

    $this->actingAs($actor)->get(tenantUrl('luna', '/admin/team'))->assertForbidden();
    teamWrite($this, $actor, 'POST', '', ['name' => 'Maria', 'email' => 'maria@luna.example', 'role' => 'owner'])->assertForbidden();
    teamWrite($this, $actor, 'PUT', '/'.$owner->id, ['role' => 'staff'])->assertForbidden();
    teamWrite($this, $actor, 'DELETE', '/'.$owner->id)->assertForbidden();
    teamWrite($this, $actor, 'POST', '/'.$owner->id.'/link')->assertForbidden();

    expect(DB::table('users')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and($this->seam->sent)->toBe([]);
})->with(['manager', 'staff']);

it('names the link\'s lifetime in the two flashes from PasswordLinks::LIFETIME, the file spelling no figure, and names no role in the role\'s refusal', function () {
    foreach ([TeamController::INVITED, TeamController::LINK_SENT] as $key) {
        // A choice's conditions, {1} and [2,*], are the syntax and no figure.
        expect(preg_replace('/[{\[][-\d,*]*[}\]]/', '', (string) __($key, [], 'en')))->toBeString()->toContain(':count')->not->toMatch('/\\d/');
        expect(adminChoice($key, PasswordLinks::hours(), ['email' => 'a@b.example']))->toContain(sprintf(' %d ', PasswordLinks::hours()));
    }

    expect(adminLine(TeamRoleRequest::REFUSED))->not->toContain(adminLine('admin/team.roles.owner'))->not->toContain(adminLine('admin/team.roles.manager'));
});

it('invites someone as a {role}: the users row at this tenant with that role, no proven address and a secret nobody knows, and the set-password link mailed to them for a first password', function (string $role) {
    teamWrite($this, teamOwner(), 'POST', '', ['name' => '  Maria Ioannou ', 'email' => 'maria@luna.example', 'role' => $role])
        ->assertRedirect(tenantUrl('luna', '/admin/team'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminChoice(TeamController::INVITED, PasswordLinks::hours(), ['email' => 'maria@luna.example'])]);

    $user = teamUser('maria@luna.example');
    $token = teamTokenIn($this->seam->bodyTo('maria@luna.example'));

    expect($user->tenant_id)->toBe(DB::table('tenants')->where('slug', 'luna')->value('id'))
        ->and($user->name)->toBe('Maria Ioannou')
        ->and($user->role->value)->toBe($role)
        ->and($user->email_verified_at)->toBeNull()
        ->and(DB::table('users')->where('id', $user->id)->value('password_link_hash'))->toBe(hash('sha256', $token))
        ->and(array_map(fn (array $sent): string => $sent['message']->toAddress.' '.$sent['message']->kind->value, $this->seam->sent))->toBe(['maria@luna.example '.PlatformMessageKind::SetPassword->value])
        ->and($this->seam->bodyTo('maria@luna.example'))->toContain(tenantUrl('luna', '/admin/set-password/'.$token));
})->with(['owner', 'manager', 'staff']);

it('admits an address another tenant\'s user signs in with, since an address is unique per tenant and not across them', function () {
    teamWrite($this, teamOwner(), 'POST', '', ['name' => 'Pier Owner', 'email' => 'owner@pier.example', 'role' => 'staff'])->assertRedirect();

    expect(DB::table('users')->where('email', 'owner@pier.example')->count())->toBe(2);
});

it('refuses an invitation in a sentence, writing nothing and mailing nothing, where {case}', function (string $name, ?string $email, string $role, string $key) {
    $before = DB::table('users')->count();

    teamWrite($this, teamOwner(), 'POST', '', array_filter(['name' => $name, 'email' => $email, 'role' => $role], fn (?string $value): bool => $value !== null))
        ->assertStatus(400)
        ->assertJsonPath('data.detail', adminLine($key));

    expect(DB::table('users')->count())->toBe($before)
        ->and($this->seam->sent)->toBe([]);
})->with([
    'the address is a user\'s of this tenant already, whatever its case' => ['Again', 'OWNER@luna.example', 'staff', 'admin/team.validation.email_taken'],
    'the address is not one' => ['Maria', 'not an address', 'staff', 'admin/team.validation.email'],
    'there is no address' => ['Maria', null, 'staff', 'admin/team.validation.email'],
    'the name is blank' => ['   ', 'maria@luna.example', 'staff', 'admin/team.validation.name'],
    'the role is none of the three' => ['Maria', 'maria@luna.example', 'support', 'admin/team.validation.role'],
]);

it('changes a member\'s role at once, and a second owner may be made and then step down', function () {
    $elena = addTenantUser('elena@luna.example', 'manager');

    teamWrite($this, teamOwner(), 'PUT', '/'.$elena, ['role' => 'staff'])
        ->assertRedirect(tenantUrl('luna', '/admin/team'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(TeamController::ROLE_SAVED, ['name' => 'elena@luna.example'])]);
    expect(teamUser('elena@luna.example')->role->value)->toBe('staff');

    teamWrite($this, teamOwner(), 'PUT', '/'.$elena, ['role' => 'owner'])->assertRedirect();
    teamWrite($this, teamOwner(), 'PUT', '/'.teamOwner()->id, ['role' => 'manager'])->assertRedirect(tenantUrl('luna', '/admin'));

    expect(teamUser('elena@luna.example')->role->value)->toBe('owner')
        ->and(teamOwner()->role->value)->toBe('manager');
});

it('refuses to demote the last owner, themself included, in a sentence, the role kept', function () {
    teamWrite($this, teamOwner(), 'PUT', '/'.teamOwner()->id, ['role' => 'manager'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'last_owner')
        ->assertJsonPath('data.detail', adminLine('admin/team.refusals.last_owner'));

    expect(teamOwner()->role->value)->toBe('owner');
});

it('refuses a role that is none of the three in a sentence', function () {
    $elena = addTenantUser('elena@luna.example', 'manager');

    teamWrite($this, teamOwner(), 'PUT', '/'.$elena, ['role' => 'admin'])
        ->assertStatus(400)
        ->assertJsonPath('data.detail', adminLine('admin/team.validation.role'));
});

it('removes a member: the users row gone, their sessions rows gone, and the staff row linked to them kept and unlinked', function () {
    $elena = addTenantUser('elena@luna.example', 'staff', 'Elena Papadaki');
    DB::table('sessions')->insert([
        ['id' => 'team-removal-elena', 'user_id' => $elena, 'payload' => '', 'last_activity' => 0],
        ['id' => 'team-removal-owner', 'user_id' => teamOwner()->id, 'payload' => '', 'last_activity' => 0],
    ]);

    teamWrite($this, teamOwner(), 'DELETE', '/'.$elena)
        ->assertRedirect(tenantUrl('luna', '/admin/team'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(TeamController::REMOVED, ['name' => 'elena@luna.example'])]);

    expect(DB::table('users')->where('id', $elena)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $elena)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'team-removal-owner')->exists())->toBeTrue()
        ->and(DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('user_id'))->toBeNull()
        ->and(DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->exists())->toBeTrue();
});

it('refuses anybody\'s removal of themself in a sentence, an owner beside another owner included', function () {
    addTenantUser('second@luna.example', 'owner');

    teamWrite($this, teamOwner(), 'DELETE', '/'.teamOwner()->id)
        ->assertStatus(409)
        ->assertJsonPath('code', 'self')
        ->assertJsonPath('data.detail', adminLine('admin/team.refusals.self'));

    expect(DB::table('users')->where('email', 'owner@luna.example')->exists())->toBeTrue();
});

it('answers not-found for a user of another tenant or of none, on every write naming one', function () {
    $pier = DB::table('users')->where('email', 'owner@pier.example')->value('id');
    assert(is_int($pier));

    foreach ([$pier, 999999] as $id) {
        teamWrite($this, teamOwner(), 'PUT', '/'.$id, ['role' => 'staff'])->assertNotFound();
        teamWrite($this, teamOwner(), 'DELETE', '/'.$id)->assertNotFound();
        teamWrite($this, teamOwner(), 'POST', '/'.$id.'/link')->assertNotFound();
    }

    expect(DB::table('users')->where('email', 'owner@pier.example')->value('role'))->toBe('owner')
        ->and($this->seam->sent)->toBe([]);
});

it('mails a member the set-password link again, a new link replacing the old, for a first password while their invitation is out and for a reset once it is not', function (bool $invited) {
    $elena = addTenantUser('elena@luna.example', 'staff');
    DB::table('users')->where('id', $elena)->update(['email_verified_at' => $invited ? null : now()->utc()->format('Y-m-d H:i:sP'), 'password_link_hash' => 'old', 'password_link_expires_at' => now()->addDay()->utc()->format('Y-m-d H:i:sP')]);

    teamWrite($this, teamOwner(), 'POST', '/'.$elena.'/link')
        ->assertRedirect(tenantUrl('luna', '/admin/team'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminChoice(TeamController::LINK_SENT, PasswordLinks::hours(), ['email' => 'elena@luna.example'])]);

    $body = $this->seam->bodyTo('elena@luna.example');

    expect(DB::table('users')->where('id', $elena)->value('password_link_hash'))->toBe(hash('sha256', teamTokenIn($body)))
        ->and($body)->toContain(mailChoice('mail.set_password.window_first', PasswordLinks::hours()))
        ->and(str_contains($body, mailChoice('mail.set_password.window', PasswordLinks::hours())))->toBe(! $invited);
})->with([
    'an invitation out' => [true],
    'a member with a password' => [false],
]);

it('answers each of the screen\'s ten server sentences in the user\'s language, Greek for an owner at el and English for an owner at en at the same tenant, each Greek unlike its English', function (SiteLanguage $language) {
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => $language->value]);
    $owner = teamOwner();
    $hours = PasswordLinks::hours();
    $member = addTenantUser('elena@luna.example', 'manager');
    // A typed name, so the two sentences that name the member read a person's name.
    DB::table('users')->where('id', $member)->update(['name' => 'Elena Vasileiou']);
    $said = [];
    $refused = function (TestResponse $response, int $status, string $key, array $values = []) use ($language, &$said): void {
        /** @var array<string, string> $values */
        $response->assertStatus($status)->assertJsonPath('data.detail', adminLine($key, $values, $language));
        $said[] = $key;
    };
    $flashed = function (TestResponse $response, string $key, array $values = [], ?int $count = null) use ($language, &$said): void {
        /** @var array<string, string> $values */
        $line = $count === null ? adminLine($key, $values, $language) : adminChoice($key, $count, $values, $language);
        $response->assertSessionHas('flash', ['kind' => 'success', 'message' => $line]);
        $said[] = $key;
    };

    $refused(teamWrite($this, $owner, 'POST', '', ['name' => '   ', 'email' => 'maria@luna.example', 'role' => 'staff']), 400, 'admin/team.validation.name');
    $refused(teamWrite($this, $owner, 'POST', '', ['name' => 'Maria', 'email' => 'not an address', 'role' => 'staff']), 400, 'admin/team.validation.email');
    $refused(teamWrite($this, $owner, 'POST', '', ['name' => 'Maria', 'email' => 'owner@luna.example', 'role' => 'staff']), 400, 'admin/team.validation.email_taken');
    $refused(teamWrite($this, $owner, 'PUT', '/'.$member, ['role' => 'admin']), 400, 'admin/team.validation.role');
    $refused(teamWrite($this, $owner, 'PUT', '/'.$owner->id, ['role' => 'manager']), 409, 'admin/team.refusals.last_owner');
    $refused(teamWrite($this, $owner, 'DELETE', '/'.$owner->id), 409, 'admin/team.refusals.self');
    $flashed(teamWrite($this, $owner, 'POST', '', ['name' => 'Maria', 'email' => 'maria@luna.example', 'role' => 'staff']), TeamController::INVITED, ['email' => 'maria@luna.example'], $hours);
    $flashed(teamWrite($this, $owner, 'PUT', '/'.$member, ['role' => 'staff']), TeamController::ROLE_SAVED, ['name' => 'Elena Vasileiou']);
    $flashed(teamWrite($this, $owner, 'POST', '/'.$member.'/link'), TeamController::LINK_SENT, ['email' => 'elena@luna.example'], $hours);
    $flashed(teamWrite($this, $owner, 'DELETE', '/'.$member), TeamController::REMOVED, ['name' => 'Elena Vasileiou']);

    // The ten are every sentence of the file's three server groups, each said once.
    $groups = array_merge(...array_map(function (string $group): array {
        $lines = __('admin/team.'.$group, [], 'en');
        assert(is_array($lines));

        return array_map(fn (int|string $key): string => 'admin/team.'.$group.'.'.$key, array_keys($lines));
    }, ['validation', 'refusals', 'flashes']));
    expect($said)->toHaveCount(10)->and(collect($said)->sort()->values()->all())->toBe(collect($groups)->sort()->values()->all());

    foreach ($said as $key) {
        $values = ['email' => 'maria@luna.example', 'name' => 'Elena Vasileiou'];
        expect(adminChoice($key, $hours, $values, SiteLanguage::Greek))->not->toBe(adminChoice($key, $hours, $values, SiteLanguage::English), $key);
    }
})->with(['a user at el' => [SiteLanguage::Greek], 'a user at en' => [SiteLanguage::English]]);

it('reaches each case of the screen\'s three counted lines in Greek at its bounds, one and two, and the labels read in Greek differ from the English', function () {
    $values = ['email' => 'maria@luna.example'];

    foreach (['admin/team.page.count', TeamController::INVITED, TeamController::LINK_SENT] as $key) {
        $forms = explode('|', (string) preg_replace('/(^|\|)\s*[{\[][-\d,*]*[}\]]\s*/', '$1', adminLine($key, [], SiteLanguage::Greek)));

        expect($forms)->toHaveCount(2, $key)
            ->and(adminChoice($key, 1, $values, SiteLanguage::Greek))->toBe(strtr($forms[0], [':count' => '1', ':email' => 'maria@luna.example']), $key)
            ->and(adminChoice($key, 2, $values, SiteLanguage::Greek))->toBe(strtr($forms[1], [':count' => '2', ':email' => 'maria@luna.example']), $key);
    }

    foreach (['admin/team.page.title', 'admin/team.roles.owner', 'admin/team.roles.manager', 'admin/team.roles.staff', 'admin/team.members.staff_row', 'admin/team.members.you'] as $label) {
        expect(adminLine($label, [], SiteLanguage::Greek))->not->toBe(adminLine($label), $label);
    }
});
