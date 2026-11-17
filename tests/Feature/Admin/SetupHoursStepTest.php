<?php

use App\Application\Clock;
use App\Http\AdminSession;
use App\Http\Requests\WeeklyWindows;
use App\Infrastructure\Db\BlackoutRecords;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\WeeklyPattern;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixtureDay;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Opening hours step of setup: what its panel is handed, the business's week or, with the
 * staff feature, one week for every member the Shifts screen lists, and its one write. The panel
 * is told nobody holds hours only where no member the week is for holds a weekly pattern or a
 * dated day, a day past or a day off among them. The write
 * is judged by Settings' own rules and refuses a week with no window besides, gives every listed
 * member the week in one transaction that a failure on any one member undoes whole, leaves dated
 * overrides, closures and members off the roster as they are, and is refused to anyone the wizard
 * is not offered to. It never records setup as finished itself: where every step is closed by the
 * time it runs it still lands, and the Home answer after it is the one that says so.
 *
 * luna holds the staff feature and named members whose weeks differ; pier holds no feature and
 * its built-in member alone, on the default working week provisioning gave it.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FixtureDay::pinClock();
});

/** Opens setup again on a seeded business, every setup fact unset. */
function hoursStepOpen(string $slug = 'luna'): void
{
    DB::table(sprintf('tenant_%s.settings', $slug))->where('id', 1)->update([
        'setup_opened_at' => null, 'setup_finished_at' => null, 'setup_profile_saved_at' => null,
        'setup_team_skipped_at' => null, 'setup_payments_skipped_at' => null, 'setup_link_finished_at' => null,
        'hours_confirmed_at' => null, 'link_copied_at' => null,
    ]);
}

function hoursStepOwner(string $slug = 'luna'): User
{
    return User::query()->where('email', sprintf('owner@%s.example', $slug))->sole();
}

/**
 * The step's data on Home for the business's owner.
 *
 * @return array<mixed>
 */
function hoursStepData(TestCase $test, string $slug = 'luna'): array
{
    $data = data_get($test->actingAs(hoursStepOwner($slug))->get(tenantUrl($slug, '/admin'))->assertOk()->viewData('page'), 'props.setup.data.hours');
    assert(is_array($data));

    return $data;
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function hoursStepSave(TestCase $test, User $user, array $body, string $slug = 'luna'): TestResponse
{
    return $test->actingAs($user)->from(tenantUrl($slug, '/admin'))->json('PUT', tenantUrl($slug, '/admin/setup/hours'), $body, ['X-Inertia' => 'true']);
}

/**
 * The active named members of luna in the roster's order, by id.
 *
 * @return array<int, string>
 */
function hoursStepMembers(): array
{
    return DB::table('tenant_luna.staff')->where('status', 'active')->where('is_implicit', false)->orderBy('sort')->orderBy('id')->get(['id', 'name'])
        ->mapWithKeys(fn (stdClass $row): array => [BlackoutRecords::int($row->id) => BlackoutRecords::string($row->name)])->all();
}

/** The id of luna's first or last active named member in the roster's order. */
function hoursStepMember(bool $last): int
{
    $ids = array_keys(hoursStepMembers());
    $id = $last ? end($ids) : reset($ids);

    return is_int($id) ? $id : throw new LogicException('luna lists no named member.');
}

/**
 * Every row of one of the schema's tables, as stored, in the order given.
 *
 * @return list<array<mixed>>
 */
function hoursStepTable(string $slug, string $table, string $order): array
{
    return array_values(DB::table(sprintf('tenant_%s.%s', $slug, $table))->orderBy($order)->get()->map(fn (stdClass $row): array => (array) $row)->all());
}

/**
 * Every row of the schema's pattern, override and closure tables, as stored.
 *
 * @return array{patterns: list<array<mixed>>, overrides: list<array<mixed>>, closures: list<array<mixed>>}
 */
function hoursStepRows(string $slug = 'luna'): array
{
    return ['patterns' => hoursStepTable($slug, 'shift_patterns', 'staff_id'), 'overrides' => hoursStepTable($slug, 'shift_overrides', 'staff_id'), 'closures' => hoursStepTable($slug, 'blackouts', 'id')];
}

/**
 * One date's hours given to one member on the Shifts screen, the day off for no window.
 *
 * @param  list<array{start: string, end: string}>  $windows
 * @return TestResponse<Response>
 */
function hoursStepDay(TestCase $test, int $staffId, string $date, array $windows, string $slug = 'luna'): TestResponse
{
    return $test->actingAs(hoursStepOwner($slug))->from(tenantUrl($slug, '/admin/shifts'))
        ->json('PUT', tenantUrl($slug, sprintf('/admin/shifts/%d/days/%s', $staffId, $date)), ['windows' => $windows], ['X-Inertia' => 'true']);
}

function hoursStepConfirmed(string $slug = 'luna'): bool
{
    return DB::table(sprintf('tenant_%s.settings', $slug))->where('id', 1)->value('hours_confirmed_at') !== null;
}

/**
 * The default working week as the editor spells it.
 *
 * @return list<array{weekday: int, start: string, end: string}>
 */
function hoursStepDefault(): array
{
    return array_map(fn (int $weekday): array => ['weekday' => $weekday, 'start' => '09:00', 'end' => '17:00'], [1, 2, 3, 4, 5]);
}

const HOURS_STEP_WEEK = [
    ['weekday' => 2, 'start' => '10:00', 'end' => '14:00'],
    ['weekday' => 2, 'start' => '15:00', 'end' => '19:00'],
    ['weekday' => 6, 'start' => '09:30', 'end' => '13:30'],
];

it('hands luna\'s owner one week for every listed member, the default working week while their weeks differ, saying so', function () {
    hoursStepOpen();

    expect(hoursStepMembers())->toHaveCount(2)
        ->and(hoursStepData($this))->toBe(['mode' => 'members', 'week' => hoursStepDefault(), 'members' => array_values(hoursStepMembers()), 'differs' => true, 'hourless' => false]);
});

it('opens luna\'s step on the week every member shares as a plain weekly pattern, and on the default where none holds one, saying nobody holds hours rather than that they differ', function () {
    hoursStepOpen();

    foreach (array_keys(hoursStepMembers()) as $staffId) {
        WeeklyHours::week('tenant_luna', $staffId, [[6, '09:30', '13:30'], [2, '10:00', '14:00'], [2, '15:00', '19:00']]);
    }

    expect(hoursStepData($this))->toMatchArray(['week' => HOURS_STEP_WEEK, 'differs' => false, 'hourless' => false]);

    // A member whose pattern repeats every second week does not share the plain week.
    WeeklyHours::pattern('tenant_luna', hoursStepMember(true), '2026-11-02', 2, null, [[6, '09:30', '13:30'], [2, '10:00', '14:00'], [2, '15:00', '19:00']]);
    expect(hoursStepData($this))->toMatchArray(['week' => hoursStepDefault(), 'differs' => true, 'hourless' => false]);

    WeeklyHours::clear('tenant_luna');
    expect(hoursStepData($this))->toMatchArray(['week' => hoursStepDefault(), 'differs' => false, 'hourless' => true]);

    // One member holding a week beside one holding none differ: a save would replace a week set by hand.
    WeeklyHours::week('tenant_luna', hoursStepMember(false), [[2, '10:00', '14:00']]);
    expect(hoursStepData($this))->toMatchArray(['week' => hoursStepDefault(), 'differs' => true, 'hourless' => false]);
});

it('hands pier\'s owner the built-in member\'s week, Settings\' Opening hours, or the default where it holds none, and the roster with nothing to edit once more than one member is active', function () {
    hoursStepOpen('pier');
    $implicit = DB::table('tenant_pier.staff')->where('is_implicit', true)->value('id');
    assert(is_int($implicit));

    WeeklyHours::week('tenant_pier', $implicit, [[2, '10:00', '14:00'], [2, '15:00', '19:00'], [6, '09:30', '13:30']]);
    expect(hoursStepData($this, 'pier'))->toBe(['mode' => 'week', 'week' => HOURS_STEP_WEEK, 'members' => [], 'differs' => false, 'hourless' => false]);

    WeeklyHours::clear('tenant_pier');
    expect(hoursStepData($this, 'pier'))->toBe(['mode' => 'week', 'week' => hoursStepDefault(), 'members' => [], 'differs' => false, 'hourless' => true]);

    DB::table('tenant_pier.staff')->insert(['name' => 'Second Member', 'status' => 'active']);
    expect(hoursStepData($this, 'pier'))->toBe(['mode' => 'roster', 'week' => [], 'members' => [], 'differs' => false, 'hourless' => false]);
});

it('does not say nobody holds hours where a listed member of luna works by a dated day alone, no weekly pattern anywhere', function (string $date, array $windows) {
    /** @var list<array{start: string, end: string}> $windows */
    hoursStepOpen();
    WeeklyHours::clear('tenant_luna');
    $member = hoursStepMember(true);

    expect(hoursStepData($this))->toMatchArray(['differs' => false, 'hourless' => true]);

    hoursStepDay($this, $member, $date, $windows)->assertRedirect(tenantUrl('luna', '/admin/shifts'));

    expect(DB::table('tenant_luna.shift_patterns')->count())->toBe(0)
        ->and(hoursStepData($this))->toMatchArray(['week' => hoursStepDefault(), 'differs' => false, 'hourless' => false]);

    // The day handed back to the pattern, nobody holds hours again.
    $this->actingAs(hoursStepOwner())->from(tenantUrl('luna', '/admin/shifts'))
        ->json('DELETE', tenantUrl('luna', sprintf('/admin/shifts/%d/days/%s', $member, $date)), [], ['X-Inertia' => 'true'])
        ->assertRedirect(tenantUrl('luna', '/admin/shifts'));

    expect(hoursStepData($this))->toMatchArray(['differs' => false, 'hourless' => true]);
})->with([
    'a day to come' => ['2026-12-01', [['start' => '12:00', 'end' => '14:00']]],
    'a day already past' => ['2026-10-06', [['start' => '12:00', 'end' => '14:00']]],
    'a day off' => ['2026-12-01', []],
]);

it('does not say nobody holds hours where pier\'s built-in member works by a dated day alone, while the staff feature is held and once it is gone', function () {
    hoursStepOpen('pier');
    WeeklyHours::clear('tenant_pier');
    $implicit = DB::table('tenant_pier.staff')->where('is_implicit', true)->value('id');
    assert(is_int($implicit));
    $plain = DB::table('tenants')->where('slug', 'pier')->value('entitlements');

    // The Shifts screen takes a day only while the staff feature is held, the built-in member then the one it lists.
    DB::table('tenants')->where('slug', 'pier')->update(['entitlements' => DB::table('tenants')->where('slug', 'luna')->value('entitlements')]);
    expect(hoursStepData($this, 'pier'))->toMatchArray(['mode' => 'week', 'hourless' => true]);

    hoursStepDay($this, $implicit, '2026-12-01', [['start' => '12:00', 'end' => '14:00']], 'pier')->assertRedirect(tenantUrl('pier', '/admin/shifts'));
    expect(hoursStepData($this, 'pier'))->toMatchArray(['mode' => 'week', 'week' => hoursStepDefault(), 'hourless' => false]);

    DB::table('tenants')->where('slug', 'pier')->update(['entitlements' => $plain]);
    expect(DB::table('tenant_pier.shift_patterns')->count())->toBe(0)
        ->and(hoursStepData($this, 'pier'))->toBe(['mode' => 'week', 'week' => hoursStepDefault(), 'members' => [], 'differs' => false, 'hourless' => false]);
});

it('gives every listed member of luna the week saved, from the one start every week with no end, confirms the hours, and leaves the unlisted built-in member, overrides and closures as they are', function () {
    hoursStepOpen();
    WeeklyPattern::override(DB::connection(), 'tenant_luna.', hoursStepMember(false), '2026-12-08', [['start' => '11:00', 'end' => '12:00']]);
    $before = hoursStepRows();

    hoursStepSave($this, hoursStepOwner(), ['rules' => HOURS_STEP_WEEK])->assertRedirect(tenantUrl('luna', '/admin'));

    $stored = WeeklyPattern::stored(DB::connection(), 'tenant_luna.', array_keys(hoursStepMembers()));
    $after = hoursStepRows();
    $implicit = DB::table('tenant_luna.staff')->where('is_implicit', true)->value('id');
    $builtIn = fn (array $patterns): array => array_values(array_filter($patterns, fn (mixed $row): bool => is_array($row) && $row['staff_id'] === $implicit));
    $shared = reset($stored);
    assert($shared !== false);

    expect(array_keys($stored))->toBe(array_keys(hoursStepMembers()))
        ->and($builtIn($after['patterns']))->toBe($builtIn($before['patterns']))->not->toBe([])
        ->and(array_unique(array_map(fn (array $pattern): string => json_encode([$pattern['cadence_weeks'], $pattern['starts_on'], $pattern['ends_on'], WeeklyPattern::windows($pattern['windows'])], JSON_THROW_ON_ERROR), $stored)))->toHaveCount(1)
        ->and($shared)->toMatchArray(['cadence_weeks' => 1, 'ends_on' => null])
        ->and(WeeklyPattern::windows($shared['windows']))->toBe(HOURS_STEP_WEEK)
        ->and(hoursStepConfirmed())->toBeTrue()
        ->and($after['overrides'])->toBe($before['overrides'])->not->toBe([])
        ->and($after['closures'])->toBe($before['closures'])->not->toBe([])
        ->and(hoursStepData($this))->toMatchArray(['week' => HOURS_STEP_WEEK, 'differs' => false, 'hourless' => false]);
});

it('leaves a member off the roster the pattern they hold, the week given to listed members alone', function () {
    hoursStepOpen();
    $former = DB::table('tenant_luna.staff')->insertGetId(['name' => 'Former Member', 'status' => 'inactive']);
    WeeklyHours::pattern('tenant_luna', $former, '2026-11-02', 2, '2026-12-27', [[3, '08:00', '12:00']]);
    $held = fn (): array => array_values(array_filter(hoursStepRows()['patterns'], fn (array $row): bool => $row['staff_id'] === $former));
    $before = $held();

    hoursStepSave($this, hoursStepOwner(), ['rules' => HOURS_STEP_WEEK])->assertRedirect(tenantUrl('luna', '/admin'));

    expect($held())->toBe($before)->toHaveCount(1)
        ->and(array_keys(WeeklyPattern::stored(DB::connection(), 'tenant_luna.', [...array_keys(hoursStepMembers()), $former])))->toHaveCount(3);
});

it('makes a listed member on a two-week cadence and one whose pattern ends plain weekly with no end', function () {
    hoursStepOpen();
    WeeklyHours::pattern('tenant_luna', hoursStepMember(false), '2026-11-02', 2, null, [[1, '09:00', '17:00']]);
    WeeklyHours::pattern('tenant_luna', hoursStepMember(true), '2026-11-02', 1, '2026-12-27', [[1, '09:00', '17:00']]);

    hoursStepSave($this, hoursStepOwner(), ['rules' => HOURS_STEP_WEEK])->assertRedirect(tenantUrl('luna', '/admin'));

    $stored = WeeklyPattern::stored(DB::connection(), 'tenant_luna.', array_keys(hoursStepMembers()));

    expect($stored)->toHaveCount(2);

    foreach ($stored as $pattern) {
        expect($pattern)->toMatchArray(['cadence_weeks' => 1, 'ends_on' => null])
            ->and(WeeklyPattern::windows($pattern['windows']))->toBe(HOURS_STEP_WEEK);
    }
});

it('writes pier\'s built-in member\'s week as Settings\' Opening hours does, confirming the hours', function () {
    hoursStepOpen('pier');

    hoursStepSave($this, hoursStepOwner('pier'), ['rules' => HOURS_STEP_WEEK], 'pier')->assertRedirect(tenantUrl('pier', '/admin'));

    $implicit = DB::table('tenant_pier.staff')->where('is_implicit', true)->value('id');
    assert(is_int($implicit));
    expect(WeeklyPattern::windows(WeeklyPattern::stored(DB::connection(), 'tenant_pier.')[$implicit]['windows'] ?? []))->toBe(HOURS_STEP_WEEK)
        ->and(hoursStepConfirmed('pier'))->toBeTrue();
});

it('refuses a window ending before it starts by Settings\' rule, naming the window, every member\'s week left as it was', function () {
    hoursStepOpen();
    $before = hoursStepRows();

    hoursStepSave($this, hoursStepOwner(), ['rules' => [...HOURS_STEP_WEEK, ['weekday' => 4, 'start' => '13:00', 'end' => '09:00']]])
        ->assertStatus(400)
        ->assertJsonPath('data.field', 'rules.3')
        ->assertJsonPath('data.detail', adminLine(WeeklyWindows::ORDER));

    expect(hoursStepRows())->toBe($before)
        ->and(hoursStepConfirmed())->toBeFalse();
});

it('refuses a week with no window by the step\'s own sentence, every listed member of luna holding the pattern they held', function () {
    hoursStepOpen();
    $held = fn (): array => WeeklyPattern::stored(DB::connection(), 'tenant_luna.', array_keys(hoursStepMembers()));
    $patterns = $held();
    $before = hoursStepRows();

    hoursStepSave($this, hoursStepOwner(), ['rules' => []])
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.field', 'rules')
        ->assertJsonPath('data.detail', adminLine('admin/setup.steps.hours.empty'));

    expect(array_keys($patterns))->toBe(array_keys(hoursStepMembers()))
        ->and($held())->toBe($patterns)
        ->and(hoursStepRows())->toBe($before)
        ->and(hoursStepConfirmed())->toBeFalse();
});

it('refuses pier a week with no window too, its built-in member\'s week left as it was', function () {
    hoursStepOpen('pier');
    $before = hoursStepRows('pier');

    hoursStepSave($this, hoursStepOwner('pier'), ['rules' => []], 'pier')
        ->assertStatus(400)
        ->assertJsonPath('data.field', 'rules')
        ->assertJsonPath('data.detail', adminLine('admin/setup.steps.hours.empty'));

    expect($before['patterns'])->not->toBe([])
        ->and(hoursStepRows('pier'))->toBe($before)
        ->and(hoursStepConfirmed('pier'))->toBeFalse();
});

it('leaves every member\'s week as it was and the hours unconfirmed where the write fails on one member, the lot one transaction', function () {
    hoursStepOpen();
    $before = hoursStepRows();

    // The last member's pattern write raises inside the transaction, after the others were written.
    DB::unprepared(<<<'SQL'
        create function tenant_luna.refuse_pattern() returns trigger language plpgsql as $$
        begin
            if new.staff_id = (select id from tenant_luna.staff where status = 'active' and not is_implicit order by sort desc, id desc limit 1) then
                raise exception 'refused by the test';
            end if;
            return new;
        end $$;
        create trigger refuse_pattern before insert or update on tenant_luna.shift_patterns for each row execute function tenant_luna.refuse_pattern();
        SQL);

    hoursStepSave($this, hoursStepOwner(), ['rules' => HOURS_STEP_WEEK])->assertStatus(500);

    expect(hoursStepRows())->toBe($before)
        ->and(hoursStepConfirmed())->toBeFalse();
});

it('refuses the write to a manager, a staff login, a support session, an owner whose setup is finished and a lapsed subscription, writing nothing', function () {
    hoursStepOpen();
    $before = hoursStepRows();

    // The support session goes first, nobody signed in beside it: one that was not live would be sent to sign in, not refused.
    $now = app(Clock::class)->now();
    $support = DB::table('support_sessions')->insertGetId([
        'operator_id' => addOperator()->id,
        'tenant_id' => Tenant::query()->where('slug', 'luna')->value('id'),
        'opened_at' => SqlText::instant($now),
        'expires_at' => SqlText::instant($now->add(new DateInterval('PT60M'))),
        'created_at' => SqlText::instant($now),
    ]);
    $this->withSession([AdminSession::SUPPORT_KEY => $support])->from(tenantUrl('luna', '/admin'))
        ->json('PUT', tenantUrl('luna', '/admin/setup/hours'), ['rules' => HOURS_STEP_WEEK], ['X-Inertia' => 'true'])
        ->assertForbidden();
    $this->flushSession();

    $manager = User::query()->findOrFail(addTenantUser('mira@luna.example', 'manager'));
    $staff = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));

    hoursStepSave($this, $manager, ['rules' => HOURS_STEP_WEEK])->assertForbidden();
    hoursStepSave($this, $staff, ['rules' => HOURS_STEP_WEEK])->assertForbidden();

    DB::table('tenant_luna.settings')->where('id', 1)->update(['setup_finished_at' => DB::raw('now()')]);
    hoursStepSave($this, hoursStepOwner(), ['rules' => HOURS_STEP_WEEK])->assertForbidden();

    hoursStepOpen();
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    hoursStepSave($this, hoursStepOwner(), ['rules' => HOURS_STEP_WEEK])->assertForbidden()->assertJsonPath('code', 'subscription_required');

    expect(hoursStepRows())->toBe($before)
        ->and(hoursStepConfirmed())->toBeFalse();
});

it('refuses the write to the demo account\'s owner, writing nothing', function () {
    config(['site.demo_slug' => 'luna']);
    hoursStepOpen();
    $before = hoursStepRows();

    hoursStepSave($this, hoursStepOwner(), ['rules' => HOURS_STEP_WEEK])->assertForbidden();

    expect(hoursStepRows())->toBe($before)
        ->and(hoursStepConfirmed())->toBeFalse();
});

it('lands the save for an owner whose every step is closed by the time it runs, the Home answer after it the one that says setup is finished', function () {
    hoursStepOpen();
    // Every step but Online payments is closed, this one among them; then the provider's notice
    // lands, no Home answer beside it, and the connection works, which closes the last.
    DB::table('tenant_luna.settings')->where('id', 1)->update(['about' => 'Haircuts since 1998.', 'hours_confirmed_at' => DB::raw('now()'), 'setup_link_finished_at' => DB::raw('now()')]);
    DB::table('tenant_luna.payment_connections')->insert(['provider' => 'stripe', 'mode' => 'own', 'state' => 'active', 'webhook_token' => 'hook-token-hours', 'is_primary' => true, 'last_check_ok' => true]);
    $finished = fn (): bool => DB::table('tenant_luna.settings')->where('id', 1)->value('setup_finished_at') !== null;
    $home = fn (): array => (array) data_get($this->actingAs(hoursStepOwner())->get(tenantUrl('luna', '/admin'))->assertOk()->viewData('page'), 'props');

    hoursStepSave($this, hoursStepOwner(), ['rules' => HOURS_STEP_WEEK])->assertRedirect(tenantUrl('luna', '/admin'));

    $stored = WeeklyPattern::stored(DB::connection(), 'tenant_luna.', array_keys(hoursStepMembers()));

    expect(array_map(fn (array $pattern): array => WeeklyPattern::windows($pattern['windows']), array_values($stored)))->toBe([HOURS_STEP_WEEK, HOURS_STEP_WEEK])
        ->and($finished())->toBeFalse();

    expect($home())->toHaveKey('setup_finished', true)->not->toHaveKey('setup')
        ->and($finished())->toBeTrue()
        ->and($home())->not->toHaveKey('setup_finished')->not->toHaveKey('setup');
});
