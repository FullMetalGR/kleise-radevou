<?php

use App\Application\Clock;
use App\Billing\EntitlementResolver;
use App\Billing\Entitlements;
use App\Http\Controllers\Admin\ShiftsController;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Requests\ShiftDayRequest;
use App\Http\Requests\ShiftPatternRequest;
use App\Http\Requests\WeeklyWindows;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Shifts screen and its writes under /admin: the page's props for the week asked, each
 * member's day through the shift resolver with its source and closures, the patterns and the
 * totals with the closures subtracted; the pattern write and the day's override and its
 * removal, each replace-all, a weekly pattern starting on the one start whatever the body names
 * and a longer cadence on the start entered, each stamping the hours marker where it holds a
 * window and flashing its key; not-found without the staff feature, for an unknown member and
 * for a date that is none.
 *
 * luna's week of 2026-11-30, a Monday, its week start: Elena works Monday to Friday 09:00-13:00
 * and 14:00-18:00, Nikos Tuesday to Saturday 10:00-19:00 with the dentist on Friday 2026-12-04
 * 14:00-16:00. None of these writes enters the transaction runner, so the suite's transaction
 * wraps each test.
 */

beforeEach(function () {
    $this->withoutVite();
    app()->instance(Clock::class, FixedClock::at('2026-12-02 12:00:00', 'Europe/Athens'));
    seedDemo();
});

function shiftsOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

function shiftsElena(): int
{
    return LunaFixture::id('staff', 'Elena Papadaki');
}

function shiftsNikos(): int
{
    return LunaFixture::id('staff', 'Nikos Georgiou');
}

/**
 * @param  array<mixed>  $body
 * @return TestResponse<Response>
 */
function shiftsWrite(TestCase $test, string $method, string $path, array $body = [], ?User $user = null): TestResponse
{
    return $test->actingAs($user ?? shiftsOwner())->from(tenantUrl('luna', '/admin/shifts'))->json($method, tenantUrl('luna', '/admin/shifts'.$path), $body, ['X-Inertia' => 'true']);
}

/**
 * One member's day of the page's days prop.
 *
 * @param  list<array<string, mixed>>  $days
 * @return array<string, mixed>
 */
function shiftsDay(array $days, int $staffId, string $date): array
{
    foreach ($days as $day) {
        if ($day['staff_id'] === $staffId && $day['date'] === $date) {
            return $day;
        }
    }

    throw new LogicException(sprintf('No day %s of member %d.', $date, $staffId));
}

/**
 * The page's props for the query given, as the owner reads them.
 *
 * @return array{members: list<array<string, mixed>>, week: array<string, string>, days: list<array<string, mixed>>, patterns: list<array<string, mixed>>, totals: array{days: list<array<string, mixed>>, members: list<array<string, mixed>>}, editable: bool}
 */
function shiftsProps(TestCase $test, string $query = ''): array
{
    $page = $test->actingAs(shiftsOwner())->get(tenantUrl('luna', '/admin/shifts'.$query))->assertOk()->viewData('page');
    assert(is_array($page) && is_array($page['props'] ?? null));
    /** @var array{members: list<array<string, mixed>>, week: array<string, string>, days: list<array<string, mixed>>, patterns: list<array<string, mixed>>, totals: array{days: list<array<string, mixed>>, members: list<array<string, mixed>>}, editable: bool} $props */
    $props = $page['props'];

    return $props;
}

/*
 * The owner at the language given, so that a user at el reads Greek; the English colleague's case
 * is the same owner at en.
 */
dataset('shifts languages', ['a user at en' => [SiteLanguage::English], 'a user at el' => [SiteLanguage::Greek]]);

function shiftsSpeaking(SiteLanguage $language): void
{
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);
}

/**
 * A server sentence of the screen in the language given through the translator, a Greek one held
 * unlike its English so that a Greek line left English cannot pass.
 */
function shiftsLine(string $key, SiteLanguage $language): string
{
    $line = adminLine($key, [], $language);

    if ($language === SiteLanguage::Greek) {
        expect($line)->not->toBe(adminLine($key));
    }

    return $line;
}

function shiftsWithoutStaff(): void
{
    app()->instance(EntitlementResolver::class, new class implements EntitlementResolver
    {
        public function resolve(Tenant $tenant): Entitlements
        {
            return Entitlements::none();
        }
    });
}

it('renders the asked week\'s members, days, sources, closures, patterns and totals through the shifts section, the implicit member left out', function () {
    $elena = shiftsElena();
    $nikos = shiftsNikos();

    $this->actingAs(shiftsOwner())->get(tenantUrl('luna', '/admin/shifts?week=2026-12-03'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(ShiftsController::PAGE)
            ->where('members', [['id' => $elena, 'name' => 'Elena Papadaki', 'is_implicit' => false], ['id' => $nikos, 'name' => 'Nikos Georgiou', 'is_implicit' => false]])
            ->where('week', ['from' => '2026-11-30', 'to' => '2026-12-07'])
            ->has('days', 14)
            ->where('editable', true)
            ->where('strings.shifts', __('admin/shifts', [], 'en'))
            ->where('strings.shell', __('admin/shell', [], 'en')));

    $props = shiftsProps($this, '?week=2026-12-03');
    $days = $props['days'];

    expect(shiftsDay($days, $elena, '2026-11-30'))->toBe(['staff_id' => $elena, 'date' => '2026-11-30', 'windows' => [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:00']], 'source' => 'pattern', 'closures' => [], 'pattern' => [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:00']]])
        ->and(shiftsDay($days, $nikos, '2026-11-30'))->toBe(['staff_id' => $nikos, 'date' => '2026-11-30', 'windows' => [], 'source' => 'none', 'closures' => [], 'pattern' => []])
        ->and(shiftsDay($days, $nikos, '2026-12-04')['closures'])->toBe([['start' => '14:00', 'end' => '16:00', 'reason' => 'Dentist', 'business' => false]])
        ->and($props['patterns'])->toBe([
            ['staff_id' => $elena, 'cadence_weeks' => 1, 'starts_on' => '1999-12-27', 'ends_on' => null, 'windows' => array_merge(...array_map(fn (int $weekday): array => [['weekday' => $weekday, 'start' => '09:00', 'end' => '13:00'], ['weekday' => $weekday, 'start' => '14:00', 'end' => '18:00']], [1, 2, 3, 4, 5])), 'minutes' => 2400],
            ['staff_id' => $nikos, 'cadence_weeks' => 1, 'starts_on' => '1999-12-27', 'ends_on' => null, 'windows' => array_map(fn (int $weekday): array => ['weekday' => $weekday, 'start' => '10:00', 'end' => '19:00'], [2, 3, 4, 5, 6]), 'minutes' => 2700],
        ])
        ->and($props['totals'])->toBe([
            'days' => [
                ['date' => '2026-11-30', 'minutes' => 480], ['date' => '2026-12-01', 'minutes' => 1020], ['date' => '2026-12-02', 'minutes' => 1020],
                ['date' => '2026-12-03', 'minutes' => 1020], ['date' => '2026-12-04', 'minutes' => 900], ['date' => '2026-12-05', 'minutes' => 540],
                ['date' => '2026-12-06', 'minutes' => 0],
            ],
            'members' => [['staff_id' => $elena, 'minutes' => 2400], ['staff_id' => $nikos, 'minutes' => 2580]],
        ]);
});

it('opens on this week in the tenant\'s week start, and on it again for a week that is no date', function (string $query) {
    expect(shiftsProps($this, $query)['week'])->toBe(['from' => '2026-11-30', 'to' => '2026-12-07']);
})->with(['no query' => [''], 'no date' => ['?week=next'], 'not a date' => ['?week=2026-02-30'], 'blank' => ['?week=']]);

it('subtracts the tenant-wide closure from every member\'s day and shows it on each', function () {
    $props = shiftsProps($this, '?week=2026-12-25');
    $friday = shiftsDay($props['days'], shiftsElena(), '2026-12-25');

    expect($friday['closures'])->toBe([['start' => null, 'end' => null, 'reason' => 'Christmas Day', 'business' => true]])
        ->and($friday['windows'])->toHaveCount(2)
        ->and($props['totals']['days'][4])->toBe(['date' => '2026-12-25', 'minutes' => 0]);
});

it('lists the implicit member under the tenant\'s name, marked implicit, where no named member is active', function () {
    DB::table('tenant_luna.staff')->where('is_implicit', false)->update(['status' => 'inactive']);
    $implicit = implicitStaffId('tenant_luna');

    expect(shiftsProps($this)['members'])->toBe([['id' => $implicit, 'name' => LunaFixture::tenant()->name, 'is_implicit' => true]]);
});

it('replaces a pattern whole, stamps the marker and flashes, and the next read resolves the cadence from the start entered', function () {
    $elena = shiftsElena();
    settingsOf('luna')->update(['hours_confirmed_at' => null]);

    shiftsWrite($this, 'PUT', '/'.$elena.'/pattern', [
        'cadence_weeks' => 2, 'starts_on' => '2026-11-30', 'ends_on' => null,
        'windows' => [['weekday' => 2, 'start' => '10:00', 'end' => '16:00'], ['weekday' => 1, 'start' => '10:00', 'end' => '19:00']],
    ])->assertRedirect(tenantUrl('luna', '/admin/shifts'));

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => __(ShiftsController::PATTERN_SAVED)])
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->value('windows'))->toBe('[{"end": "19:00", "start": "10:00", "weekday": 1}, {"end": "16:00", "start": "10:00", "weekday": 2}]')
        ->and(settingsOf('luna')->value('hours_confirmed_at'))->not->toBeNull()
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->count())->toBe(1);

    $week = shiftsProps($this, '?week=2026-11-30');
    $next = shiftsProps($this, '?week=2026-12-07');
    $after = shiftsProps($this, '?week=2026-12-14');

    expect(shiftsDay($week['days'], $elena, '2026-11-30')['windows'])->toBe([['start' => '10:00', 'end' => '19:00']])
        ->and(shiftsDay($next['days'], $elena, '2026-12-07'))->toMatchArray(['windows' => [], 'source' => 'none'])
        ->and(shiftsDay($after['days'], $elena, '2026-12-15')['windows'])->toBe([['start' => '10:00', 'end' => '16:00']])
        ->and(shiftsDay($week['days'], $elena, '2026-11-30')['source'])->toBe('pattern')
        ->and($week['patterns'][0])->toMatchArray(['staff_id' => $elena, 'cadence_weeks' => 2, 'starts_on' => '2026-11-30', 'ends_on' => null, 'minutes' => 900]);
});

it('leaves a member saved with no window no pattern, stamping nothing, and ends a pattern on its end date', function () {
    $elena = shiftsElena();
    settingsOf('luna')->update(['hours_confirmed_at' => null]);

    shiftsWrite($this, 'PUT', '/'.$elena.'/pattern', ['cadence_weeks' => 2, 'starts_on' => '2026-11-30', 'ends_on' => '2026-12-31', 'windows' => []])->assertRedirect();

    expect(settingsOf('luna')->value('hours_confirmed_at'))->toBeNull()
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->exists())->toBeFalse();

    shiftsWrite($this, 'PUT', '/'.$elena.'/pattern', ['cadence_weeks' => 1, 'starts_on' => '2026-11-30', 'ends_on' => '2026-12-01', 'windows' => [['weekday' => 1, 'start' => '09:00', 'end' => '17:00'], ['weekday' => 2, 'start' => '09:00', 'end' => '17:00']]])->assertRedirect();
    $days = shiftsProps($this, '?week=2026-11-30')['days'];

    expect(shiftsDay($days, $elena, '2026-12-01')['windows'])->toBe([['start' => '09:00', 'end' => '17:00']])
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->value('ends_on'))->toBe('2026-12-01')
        ->and(shiftsProps($this, '?week=2026-12-07')['totals']['members'][0])->toBe(['staff_id' => $elena, 'minutes' => 0]);
});

it('starts a weekly pattern on the week holding 2000-01-01 whatever start the body names, and a longer cadence on the start entered', function (int $startOfWeek, string $weekly) {
    $elena = shiftsElena();
    settingsOf('luna')->update(['start_of_week' => $startOfWeek]);
    $windows = [['weekday' => 3, 'start' => '10:00', 'end' => '14:00']];

    shiftsWrite($this, 'PUT', '/'.$elena.'/pattern', ['cadence_weeks' => 1, 'starts_on' => '2026-12-02', 'ends_on' => null, 'windows' => $windows])->assertRedirect();
    $stored = fn (): array => (array) DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->first(['starts_on', 'cadence_weeks', 'ends_on']);

    expect($stored())->toBe(['starts_on' => $weekly, 'cadence_weeks' => 1, 'ends_on' => null]);

    shiftsWrite($this, 'PUT', '/'.$elena.'/pattern', ['cadence_weeks' => 3, 'starts_on' => '2026-12-02', 'ends_on' => '2027-03-31', 'windows' => $windows])->assertRedirect();

    expect($stored())->toBe(['starts_on' => '2026-12-02', 'cadence_weeks' => 3, 'ends_on' => '2027-03-31']);
})->with([
    'a Monday start' => [1, '1999-12-27'],
    'a Sunday start' => [7, '1999-12-26'],
]);

it('writes the implicit member\'s pattern through its own row on a roster whose named members are all inactive, where the business\'s hours are edited, and the next read lists it alone with that pattern', function () {
    DB::table('tenant_luna.staff')->where('is_implicit', false)->update(['status' => 'inactive']);
    $implicit = implicitStaffId('tenant_luna');

    expect(array_column(shiftsProps($this, '?week=2026-11-30')['members'], 'id'))->toBe([$implicit]);

    shiftsWrite($this, 'PUT', '/'.$implicit.'/pattern', ['cadence_weeks' => 1, 'starts_on' => '2026-11-30', 'ends_on' => null, 'windows' => [['weekday' => 6, 'start' => '10:00', 'end' => '14:00']]])
        ->assertRedirect();

    $props = shiftsProps($this, '?week=2026-11-30');

    expect(DB::table('tenant_luna.shift_patterns')->where('staff_id', $implicit)->value('windows'))->toBe('[{"end": "14:00", "start": "10:00", "weekday": 6}]')
        ->and($props['patterns'])->toHaveCount(1)
        ->and($props['patterns'][0])->toMatchArray(['staff_id' => $implicit, 'cadence_weeks' => 1, 'starts_on' => '1999-12-27', 'ends_on' => null, 'minutes' => 240])
        ->and(shiftsDay($props['days'], $implicit, '2026-12-05')['windows'])->toBe([['start' => '10:00', 'end' => '14:00']]);
});

it('judges a weekly pattern\'s end against the one start it stores, not the shown week\'s first day the editor sends with Starts on hidden', function () {
    $elena = shiftsElena();

    // The editor on the week of 2026-11-30 sends that Monday as starts_on; the end is the Friday
    // before it.
    shiftsWrite($this, 'PUT', '/'.$elena.'/pattern', ['cadence_weeks' => 1, 'starts_on' => '2026-11-30', 'ends_on' => '2026-11-27', 'windows' => [['weekday' => 1, 'start' => '09:00', 'end' => '17:00']]])
        ->assertRedirect();

    expect((array) DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->first(['starts_on', 'cadence_weeks', 'ends_on']))
        ->toBe(['starts_on' => '1999-12-27', 'cadence_weeks' => 1, 'ends_on' => '2026-11-27']);
});

it('shows a pattern saved every two weeks from the editor\'s body on alternate weeks of the Calendar\'s window, nothing before its start, and Shifts\' row the same', function () {
    $elena = shiftsElena();

    shiftsWrite($this, 'PUT', '/'.$elena.'/pattern', [
        'cadence_weeks' => 2, 'starts_on' => '2026-12-09', 'ends_on' => null,
        'windows' => [['weekday' => 1, 'start' => '10:00', 'end' => '18:00'], ['weekday' => 3, 'start' => '09:00', 'end' => '13:00'], ['weekday' => 3, 'start' => '14:00', 'end' => '17:00']],
    ])->assertRedirect();

    $window = data_get($this->actingAs(shiftsOwner())->get(tenantUrl('luna', '/admin/calendar?'.http_build_query(['from' => '2026-11-30', 'to' => '2027-01-04', 'who' => 'all'])))->assertOk()->viewData('page'), 'props.window.hours.'.$elena);
    assert(is_array($window));
    $worked = array_keys(array_filter($window, fn (mixed $day): bool => is_array($day) && $day['windows'] !== []));

    // The week holding Wednesday the ninth is week one, from Monday the seventh: its Monday is
    // before the start and draws nothing; the week of the 21st follows.
    expect($worked)->toBe(['2026-12-09', '2026-12-21', '2026-12-23'])
        ->and(data_get($window, '2026-12-09.windows'))->toBe([['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '17:00']])
        ->and(data_get($window, '2026-12-14.windows'))->toBe([])
        ->and(shiftsDay(shiftsProps($this, '?week=2026-12-21')['days'], $elena, '2026-12-21')['windows'])->toBe([['start' => '10:00', 'end' => '18:00']])
        ->and(shiftsDay(shiftsProps($this, '?week=2026-12-14')['days'], $elena, '2026-12-16'))->toMatchArray(['windows' => [], 'source' => 'none']);
});

it('edits one date alone, an empty list not working, leaves the pattern as it is, stamps the marker for a day with a window, and drops the override back to the pattern', function () {
    $elena = shiftsElena();
    $pattern = DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->value('windows');
    settingsOf('luna')->update(['hours_confirmed_at' => null]);

    shiftsWrite($this, 'PUT', '/'.$elena.'/days/2026-12-01', ['windows' => []])->assertRedirect(tenantUrl('luna', '/admin/shifts'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => __(ShiftsController::DAY_SAVED)])
        ->and(settingsOf('luna')->value('hours_confirmed_at'))->toBeNull();

    shiftsWrite($this, 'PUT', '/'.$elena.'/days/2026-12-02', ['windows' => [['start' => '15:00', 'end' => '20:00'], ['start' => '08:00', 'end' => '12:00']]])->assertRedirect();
    $days = shiftsProps($this, '?week=2026-11-30')['days'];

    expect(shiftsDay($days, $elena, '2026-12-01'))->toMatchArray(['windows' => [], 'source' => 'override'])
        ->and(shiftsDay($days, $elena, '2026-12-02'))->toMatchArray(['windows' => [['start' => '08:00', 'end' => '12:00'], ['start' => '15:00', 'end' => '20:00']], 'source' => 'override'])
        ->and(shiftsDay($days, $elena, '2026-12-03')['source'])->toBe('pattern')
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->value('windows'))->toBe($pattern)
        ->and(settingsOf('luna')->value('hours_confirmed_at'))->not->toBeNull();

    shiftsWrite($this, 'PUT', '/'.$elena.'/days/2026-12-02', ['windows' => [['start' => '09:00', 'end' => '10:00']]])->assertRedirect();
    expect(DB::table('tenant_luna.shift_overrides')->where('staff_id', $elena)->count())->toBe(2);

    shiftsWrite($this, 'DELETE', '/'.$elena.'/days/2026-12-01')->assertRedirect(tenantUrl('luna', '/admin/shifts'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => __(ShiftsController::DAY_RESET)])
        ->and(shiftsDay(shiftsProps($this, '?week=2026-11-30')['days'], $elena, '2026-12-01'))->toMatchArray(['windows' => [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:00']], 'source' => 'pattern']);

    shiftsWrite($this, 'DELETE', '/'.$elena.'/days/2026-12-01')->assertRedirect();
});

it('gives each day the windows its pattern gives that date, the override left out and the cadence the resolver\'s', function () {
    $elena = shiftsElena();

    shiftsWrite($this, 'PUT', '/'.$elena.'/pattern', ['cadence_weeks' => 2, 'starts_on' => '2026-11-30', 'ends_on' => null, 'windows' => [['weekday' => 2, 'start' => '10:00', 'end' => '19:00']]])->assertRedirect();
    shiftsWrite($this, 'PUT', '/'.$elena.'/days/2026-12-01', ['windows' => [['start' => '12:00', 'end' => '14:00']]])->assertRedirect();
    shiftsWrite($this, 'PUT', '/'.$elena.'/days/2026-12-08', ['windows' => [['start' => '12:00', 'end' => '14:00']]])->assertRedirect();

    $week = shiftsProps($this, '?week=2026-11-30')['days'];
    $off = shiftsProps($this, '?week=2026-12-07')['days'];

    expect(shiftsDay($week, $elena, '2026-12-01'))->toMatchArray(['windows' => [['start' => '12:00', 'end' => '14:00']], 'source' => 'override', 'pattern' => [['start' => '10:00', 'end' => '19:00']]])
        ->and(shiftsDay($week, $elena, '2026-12-02')['pattern'])->toBe([])
        ->and(shiftsDay($off, $elena, '2026-12-08'))->toMatchArray(['source' => 'override', 'pattern' => []]);
});

it('answers a partial reload of another week with that week\'s props alone', function () {
    $page = $this->actingAs(shiftsOwner())->get(tenantUrl('luna', '/admin/shifts'))->viewData('page');
    assert(is_array($page));
    $version = $page['version'] ?? null;

    $this->actingAs(shiftsOwner())->get(tenantUrl('luna', '/admin/shifts?week=2026-12-09'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => is_string($version) ? $version : '',
        'X-Inertia-Partial-Component' => ShiftsController::PAGE,
        'X-Inertia-Partial-Data' => 'week,days,totals',
    ])->assertOk()
        ->assertJsonPath('props.week', ['from' => '2026-12-07', 'to' => '2026-12-14'])
        ->assertJsonCount(14, 'props.days')
        ->assertJsonMissingPath('props.members')
        ->assertJsonMissingPath('props.patterns');
});

it('refuses a pattern body out of shape with the sentence of its field, in the user\'s language', function (array $body, string $key, SiteLanguage $language) {
    $base = ['cadence_weeks' => 1, 'starts_on' => '2026-11-30', 'ends_on' => null, 'windows' => [['weekday' => 1, 'start' => '09:00', 'end' => '17:00']]];
    shiftsSpeaking($language);

    shiftsWrite($this, 'PUT', '/'.shiftsElena().'/pattern', [...$base, ...$body])->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.detail', shiftsLine($key, $language));
})->with([
    'a cadence of five' => [['cadence_weeks' => 5], ShiftPatternRequest::CADENCE],
    'no cadence' => [['cadence_weeks' => null], ShiftPatternRequest::CADENCE],
    'no start' => [['starts_on' => 'soon'], ShiftPatternRequest::STARTS_ON],
    'a start in year zero, which the format takes and no calendar holds' => [['starts_on' => '0000-01-01'], ShiftPatternRequest::STARTS_ON],
    'an end in year zero, which the format takes and no calendar holds' => [['ends_on' => '0000-01-01'], ShiftPatternRequest::ENDS_ON],
    'an end before the start of a pattern every two weeks' => [['cadence_weeks' => 2, 'ends_on' => '2026-11-29'], ShiftPatternRequest::ENDS_ON],
    'a weekly end before the one start' => [['ends_on' => '1999-12-26'], ShiftPatternRequest::ENDS_ON],
    'no windows' => [['windows' => null], WeeklyWindows::LIST],
    'a window out of order' => [['windows' => [['weekday' => 1, 'start' => '17:00', 'end' => '09:00']]], WeeklyWindows::ORDER],
])->with('shifts languages');

it('refuses a day body out of shape with the sentence of its field, in the user\'s language', function (array $body, string $key, SiteLanguage $language) {
    shiftsSpeaking($language);

    shiftsWrite($this, 'PUT', '/'.shiftsElena().'/days/2026-12-01', $body)->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.detail', shiftsLine($key, $language));
})->with([
    'no list' => [[], ShiftDayRequest::LIST],
    'a time that is none' => [['windows' => [['start' => '25:00', 'end' => '26:00']]], ShiftDayRequest::TIMES],
    'a window out of order' => [['windows' => [['start' => '12:00', 'end' => '12:00']]], ShiftDayRequest::ORDER],
    'two windows overlapping' => [['windows' => [['start' => '12:00', 'end' => '16:00'], ['start' => '09:00', 'end' => '12:30']]], ShiftDayRequest::OVERLAP],
])->with('shifts languages');

it('words the two hours lines around the figure the page fills in, one spelling whatever the count, in Greek and in English', function (string $key) {
    foreach ([SiteLanguage::Greek, SiteLanguage::English] as $language) {
        $raw = __($key, [], $language->value);

        expect($raw)->toBeString()->toContain(':hours')->not->toContain('|')->not->toContain(':count')
            ->and(adminLine($key, ['hours' => '5h 30m'], $language))->toBe(strtr($raw, [':hours' => '5h 30m']));
    }

    expect(adminLine($key, ['hours' => '8h'], SiteLanguage::Greek))->not->toBe(adminLine($key, ['hours' => '8h']));
})->with(['the day editor\'s' => ['admin/shifts.editor.hours'], 'the pattern editor\'s' => ['admin/shifts.pattern.hours']]);

it('flashes the pattern saved, the day saved and the day back to the pattern in the user\'s language', function (SiteLanguage $language) {
    $elena = shiftsElena();
    shiftsSpeaking($language);

    shiftsWrite($this, 'PUT', '/'.$elena.'/pattern', ['cadence_weeks' => 1, 'starts_on' => '2026-11-30', 'ends_on' => null, 'windows' => [['weekday' => 1, 'start' => '09:00', 'end' => '17:00']]])->assertRedirect();
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => shiftsLine(ShiftsController::PATTERN_SAVED, $language)]);

    shiftsWrite($this, 'PUT', '/'.$elena.'/days/2026-12-01', ['windows' => []])->assertRedirect();
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => shiftsLine(ShiftsController::DAY_SAVED, $language)]);

    shiftsWrite($this, 'DELETE', '/'.$elena.'/days/2026-12-01')->assertRedirect();
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => shiftsLine(ShiftsController::DAY_RESET, $language)]);
})->with('shifts languages');

it('names every key of the validation and flashes groups in the cases above, so a key the file gains fails here until a case reads it', function () {
    // WeeklyWindows' two keys are the shell's, another file's, and stay out of the count.
    $keys = function (string $group): array {
        $lines = __('admin/shifts.'.$group, [], 'en');
        assert(is_array($lines));

        return collect(array_keys($lines))->map(fn (int|string $key): string => 'admin/shifts.'.$group.'.'.$key)->sort()->values()->all();
    };
    $validation = [ShiftPatternRequest::CADENCE, ShiftPatternRequest::STARTS_ON, ShiftPatternRequest::ENDS_ON, ShiftDayRequest::LIST, ShiftDayRequest::TIMES, ShiftDayRequest::ORDER, ShiftDayRequest::OVERLAP];
    $flashes = [ShiftsController::PATTERN_SAVED, ShiftsController::DAY_SAVED, ShiftsController::DAY_RESET];

    expect(collect($validation)->sort()->values()->all())->toBe($keys('validation'))
        ->and(collect($flashes)->sort()->values()->all())->toBe($keys('flashes'));
});

it('answers not-found for a member the schema does not hold and for a date that is none, writing nothing', function (string $method, string $path, array $body) {
    shiftsWrite($this, $method, $path, $body)->assertNotFound();

    expect(DB::table('tenant_luna.shift_overrides')->count())->toBe(0);
})->with([
    'pattern of nobody' => ['PUT', '/999999/pattern', ['cadence_weeks' => 1, 'starts_on' => '2026-11-30', 'ends_on' => null, 'windows' => []]],
    'day of nobody' => ['PUT', '/999999/days/2026-12-01', ['windows' => []]],
    'reset of nobody' => ['DELETE', '/999999/days/2026-12-01', []],
    'a date no calendar has' => ['PUT', '/1/days/2026-02-30', ['windows' => []]],
    'a date not spelled so' => ['PUT', '/1/days/tomorrow', ['windows' => []]],
    'a reset of a date that is none' => ['DELETE', '/1/days/2026-13-01', []],
]);

it('answers a member id too long to be a whole number at the router, as any write to a path no route takes, never a server error, writing nothing', function (string $method, string $path, array $body) {
    shiftsWrite($this, $method, $path, $body)->assertStatus(405);

    expect(DB::table('tenant_luna.shift_overrides')->count())->toBe(0);
})->with([
    'a pattern' => ['PUT', '/99999999999999999999/pattern', ['cadence_weeks' => 1, 'starts_on' => '2026-11-30', 'ends_on' => null, 'windows' => []]],
    'a day' => ['PUT', '/99999999999999999999/days/2026-12-01', ['windows' => []]],
    'a reset' => ['DELETE', '/99999999999999999999/days/2026-12-01', []],
]);

it('reads the page without the staff feature, not editable, and answers every write not-found, a body out of shape included, writing nothing', function () {
    shiftsWithoutStaff();
    $elena = shiftsElena();

    $this->actingAs(shiftsOwner())->get(tenantUrl('luna', '/admin/shifts'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(ShiftsController::PAGE)->where('editable', false)->has('members', 2));

    foreach ([['PUT', '/'.$elena.'/pattern', ['cadence_weeks' => 1, 'starts_on' => '2026-11-30', 'ends_on' => null, 'windows' => []]], ['PUT', '/'.$elena.'/pattern', []], ['PUT', '/'.$elena.'/days/2026-12-01', ['windows' => []]], ['DELETE', '/'.$elena.'/days/2026-12-01', []]] as [$method, $path, $body]) {
        shiftsWrite($this, $method, $path, $body)->assertNotFound();
    }

    expect(DB::table('tenant_luna.shift_overrides')->count())->toBe(0)
        ->and(DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->value('cadence_weeks'))->toBe(1);
});

it('refuses the writes on a past_due tenant with subscription_required while the page still reads, and every route to a role without manageSettings', function () {
    $elena = shiftsElena();
    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff'));
    $writes = [['PUT', '/'.$elena.'/pattern', []], ['PUT', '/'.$elena.'/days/2026-12-01', ['windows' => []]], ['DELETE', '/'.$elena.'/days/2026-12-01', []]];

    $this->actingAs($staff)->get(tenantUrl('luna', '/admin/shifts'))->assertForbidden();
    foreach ($writes as [$method, $path, $body]) {
        shiftsWrite($this, $method, $path, $body, $staff)->assertForbidden();
    }

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    foreach ($writes as [$method, $path, $body]) {
        shiftsWrite($this, $method, $path, $body)->assertForbidden()->assertJsonPath('code', 'subscription_required')->assertJsonPath('data.detail', adminLine(RequiresActiveSubscription::DETAIL));
    }

    $this->actingAs(shiftsOwner())->get(tenantUrl('luna', '/admin/shifts'))->assertOk();
});

it('lights the Shifts entry after Bookings on its page for a holder of manageSettings, and shows none without the feature', function () {
    $this->actingAs(shiftsOwner())->get(tenantUrl('luna', '/admin/shifts'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('nav.2.label', navLabel('bookings'))
            ->where('nav.3', ['label' => navLabel('shifts'), 'url' => '/admin/shifts', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => true])
            ->where('nav', fn (Collection $nav): bool => $nav->where('current', true)->count() === 1));

    DB::table('tenants')->where('slug', 'luna')->update(['entitlements' => '{}']);

    $this->actingAs(shiftsOwner())->get(tenantUrl('luna', '/admin/shifts'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('editable', false)->where('nav', fn (Collection $nav): bool => ! in_array('/admin/shifts', $nav->pluck('url')->all(), true)));
});

it('hands the Staff list each member\'s Hours this week through the resolver: this week\'s, closures subtracted, where the pattern is not weekly or the week holds an override, else the pattern\'s week, every week, with the week\'s closures beside', function () {
    $elena = shiftsElena();
    shiftsWrite($this, 'PUT', '/'.$elena.'/pattern', ['cadence_weeks' => 2, 'starts_on' => '2026-11-30', 'ends_on' => null, 'windows' => [['weekday' => 1, 'start' => '10:00', 'end' => '19:00'], ['weekday' => 2, 'start' => '10:00', 'end' => '16:00']]])->assertRedirect();
    shiftsWrite($this, 'PUT', '/'.$elena.'/days/2026-12-03', ['windows' => [['start' => '09:00', 'end' => '11:00']]])->assertRedirect();
    DB::table('tenant_luna.staff')->where('id', shiftsNikos())->update(['sort' => 99]);

    $this->actingAs(shiftsOwner())->get(tenantUrl('luna', '/admin/staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('staff.1.id', $elena)
            ->where('staff.1.hours', ['minutes' => 1020, 'weekly' => false, 'closures' => []])
            ->where('staff.2.id', shiftsNikos())
            ->where('staff.2.hours', ['minutes' => 2700, 'weekly' => true, 'closures' => [['date' => '2026-12-04', 'start' => '14:00', 'end' => '16:00']]]));
});

it('gives a member with no pattern no every week and the minutes of their days edited alone', function () {
    $elena = shiftsElena();
    shiftsWrite($this, 'PUT', '/'.$elena.'/pattern', ['cadence_weeks' => 1, 'starts_on' => '2026-11-30', 'ends_on' => null, 'windows' => []])->assertRedirect();
    expect(DB::table('tenant_luna.shift_patterns')->where('staff_id', $elena)->exists())->toBeFalse();
    shiftsWrite($this, 'PUT', '/'.$elena.'/days/2026-12-01', ['windows' => [['start' => '09:00', 'end' => '12:30']]])->assertRedirect();

    $this->actingAs(shiftsOwner())->get(tenantUrl('luna', '/admin/staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('staff', fn (Collection $staff): bool => $staff->where('id', $elena)->pluck('hours')->all() === [['minutes' => 210, 'weekly' => false, 'closures' => []]]));
});
