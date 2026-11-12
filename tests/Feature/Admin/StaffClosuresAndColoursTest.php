<?php

use App\Application\Clock;
use App\Billing\EntitlementResolver;
use App\Billing\Entitlements;
use App\Http\Controllers\Admin\BlackoutController;
use App\Http\Controllers\Admin\StaffColourController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Requests\BlackoutRequest;
use App\Http\Requests\StaffColourRequest;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\StaffColour;
use App\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Staff screen's closures and calendar colours: a closure with Until written as one row per
 * date in one write and refused past 62 days or before its Date; the screen's closures today
 * onward, soonest first, a run of consecutive dates with the same who, hours and reason folded
 * into one line that one request removes whole; a member's own colour on its route, held to the
 * palette's colour words, read back on the Staff rows and by the Calendar and the Shifts screen;
 * and Hours this week as the sentence's parts. The clock stands on Thursday 2026-12-10 in Athens
 * unless a test moves it.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-12-10 12:00:00', 'Europe/Athens'));
});

function closuresOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

function closuresElena(): int
{
    return LunaFixture::id('staff', 'Elena Papadaki');
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function closuresWrite(TestCase $test, string $method, string $path, array $body = [], ?User $user = null): TestResponse
{
    return $test->actingAs($user ?? closuresOwner())->from(tenantUrl('luna', '/admin/staff'))->json($method, tenantUrl('luna', '/admin'.$path), $body, ['X-Inertia' => 'true']);
}

/**
 * The dates of the closures of one scope with the reason given, in date order.
 *
 * @return list<string>
 */
function closureDates(?int $staffId, string $reason): array
{
    $query = DB::table('tenant_luna.blackouts')->where('reason', $reason)->orderBy('date');
    $query = $staffId === null ? $query->whereNull('staff_id') : $query->where('staff_id', $staffId);

    return array_values(array_map(fn (mixed $date): string => is_string($date) ? $date : '', $query->pluck('date')->all()));
}

it('writes a closure with Until as one row for each date from Date to Until, for the business and for one member', function () {
    closuresWrite($this, 'POST', '/blackouts', ['date' => '2027-01-04', 'until' => '2027-01-06', 'reason' => 'Stocktake'])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(BlackoutController::ADDED)])
        ->and(closureDates(null, 'Stocktake'))->toBe(['2027-01-04', '2027-01-05', '2027-01-06']);

    closuresWrite($this, 'POST', '/staff/'.closuresElena().'/blackouts', ['date' => '2027-01-11', 'until' => '2027-01-12', 'start_time' => '09:00', 'end_time' => '11:00', 'reason' => 'Course'])->assertRedirect();
    expect(closureDates(closuresElena(), 'Course'))->toBe(['2027-01-11', '2027-01-12'])
        ->and(DB::table('tenant_luna.blackouts')->where('reason', 'Course')->pluck('start_time')->all())->toBe(['09:00:00', '09:00:00']);

    closuresWrite($this, 'POST', '/blackouts', ['date' => '2027-02-01', 'until' => '2027-02-01', 'reason' => 'One day'])->assertRedirect();
    expect(closureDates(null, 'One day'))->toBe(['2027-02-01']);

    closuresWrite($this, 'POST', '/blackouts', ['date' => '2027-03-01', 'until' => '2027-05-01', 'reason' => 'Sixty-two'])->assertRedirect();
    expect(closureDates(null, 'Sixty-two'))->toHaveCount(62);
});

it('refuses an Until before its Date, an Until past 62 days and an Until not a date, each in its own sentence in the user\'s language, and writes nothing', function (SiteLanguage $language) {
    User::query()->where('email', 'owner@luna.example')->update(['locale' => $language->value]);
    $before = DB::table('tenant_luna.blackouts')->count();

    closuresWrite($this, 'POST', '/blackouts', ['date' => '2027-01-06', 'until' => '2027-01-04'])->assertStatus(400)->assertJsonPath('data.detail', adminLine(BlackoutRequest::UNTIL_ORDER, [], $language));
    closuresWrite($this, 'POST', '/blackouts', ['date' => '2027-03-01', 'until' => '2027-05-02'])->assertStatus(400)->assertJsonPath('data.detail', adminLine(BlackoutRequest::UNTIL_SPAN, ['n' => (string) BlackoutRequest::MOST_DAYS], $language));
    closuresWrite($this, 'POST', '/staff/'.closuresElena().'/blackouts', ['date' => '2027-03-01', 'until' => '2027-12-31'])->assertStatus(400)->assertJsonPath('data.detail', adminLine(BlackoutRequest::UNTIL_SPAN, ['n' => (string) BlackoutRequest::MOST_DAYS], $language));
    closuresWrite($this, 'POST', '/blackouts', ['date' => '2027-01-06', 'until' => 'Sunday'])->assertStatus(400)->assertJsonPath('data.detail', adminLine(BlackoutRequest::UNTIL_DATE, [], $language));

    expect(DB::table('tenant_luna.blackouts')->count())->toBe($before);
})->with(['a user at en' => [SiteLanguage::English], 'a user at el' => [SiteLanguage::Greek]]);

it('lists the closures today onward, soonest first, each naming its member or none, a run of the same who, hours and reason folded into one line', function () {
    closuresWrite($this, 'POST', '/blackouts', ['date' => '2027-01-04', 'until' => '2027-01-06', 'reason' => 'Stocktake'])->assertRedirect();
    closuresWrite($this, 'POST', '/staff/'.closuresElena().'/blackouts', ['date' => '2027-01-04', 'reason' => 'Course'])->assertRedirect();
    closuresWrite($this, 'POST', '/staff/'.closuresElena().'/blackouts', ['date' => '2027-01-06', 'reason' => 'Course'])->assertRedirect();
    closuresWrite($this, 'POST', '/staff/'.closuresElena().'/blackouts', ['date' => '2027-01-07', 'reason' => 'Other'])->assertRedirect();
    closuresWrite($this, 'POST', '/blackouts', ['date' => '2026-12-09', 'reason' => 'Yesterday'])->assertRedirect();
    $elena = closuresElena();

    $this->actingAs(closuresOwner())->get(tenantUrl('luna', '/admin/staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(StaffController::PAGE)
            ->missing('blackouts')
            ->where('closures', [
                ['staff_id' => null, 'date' => '2026-12-25', 'until' => null, 'start' => null, 'end' => null, 'reason' => 'Christmas Day'],
                ['staff_id' => null, 'date' => '2027-01-04', 'until' => '2027-01-06', 'start' => null, 'end' => null, 'reason' => 'Stocktake'],
                ['staff_id' => $elena, 'date' => '2027-01-04', 'until' => null, 'start' => null, 'end' => null, 'reason' => 'Course'],
                ['staff_id' => $elena, 'date' => '2027-01-06', 'until' => null, 'start' => null, 'end' => null, 'reason' => 'Course'],
                ['staff_id' => $elena, 'date' => '2027-01-07', 'until' => null, 'start' => null, 'end' => null, 'reason' => 'Other'],
            ])
            ->where('staff.1.id', $elena)
            ->where('staff.1.blackouts', [
                ['date' => '2027-01-04', 'until' => null, 'start' => null, 'end' => null, 'reason' => 'Course'],
                ['date' => '2027-01-06', 'until' => null, 'start' => null, 'end' => null, 'reason' => 'Course'],
                ['date' => '2027-01-07', 'until' => null, 'start' => null, 'end' => null, 'reason' => 'Other'],
            ])
            ->where('staff.2.blackouts', []));
});

it('removes a folded run whole in one request, and answers not-found for a run that matches nothing', function () {
    closuresWrite($this, 'POST', '/blackouts', ['date' => '2027-01-04', 'until' => '2027-01-08', 'start_time' => '14:00', 'end_time' => '16:00', 'reason' => 'Works'])->assertRedirect();
    closuresWrite($this, 'POST', '/blackouts', ['date' => '2027-01-05', 'reason' => 'All day'])->assertRedirect();

    closuresWrite($this, 'DELETE', '/blackouts', ['date' => '2027-01-04', 'until' => '2027-01-08', 'start_time' => '14:00', 'end_time' => '16:00'])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(BlackoutController::REMOVED)])
        ->and(closureDates(null, 'Works'))->toBe([])
        ->and(closureDates(null, 'All day'))->toBe(['2027-01-05']);

    closuresWrite($this, 'DELETE', '/blackouts', ['date' => '2027-01-04', 'until' => '2027-01-08', 'start_time' => '14:00', 'end_time' => '16:00'])->assertNotFound();

    closuresWrite($this, 'POST', '/staff/'.closuresElena().'/blackouts', ['date' => '2027-02-01', 'until' => '2027-04-15', 'reason' => 'Leave'])->assertStatus(400);
    closuresWrite($this, 'POST', '/staff/'.closuresElena().'/blackouts', ['date' => '2027-02-01', 'until' => '2027-02-28', 'reason' => 'Leave'])->assertRedirect();
    closuresWrite($this, 'POST', '/staff/'.closuresElena().'/blackouts', ['date' => '2027-03-01', 'until' => '2027-04-15', 'reason' => 'Leave'])->assertRedirect();
    // Two adds joined into one run longer than 62 days still leave in one request.
    closuresWrite($this, 'DELETE', '/staff/'.closuresElena().'/blackouts', ['date' => '2027-02-01', 'until' => '2027-04-15'])->assertRedirect();
    expect(closureDates(closuresElena(), 'Leave'))->toBe([]);
});

it('writes a member\'s own colour on its route, Automatic as none, and reads it back on the Staff rows, the Calendar and the Shifts screen', function () {
    $elena = closuresElena();

    closuresWrite($this, 'PUT', '/staff/'.$elena.'/colour', ['colour' => 'purple'])->assertRedirect(tenantUrl('luna', '/admin/staff'));
    expect(session('flash'))->toBe(['kind' => 'success', 'message' => adminLine(StaffColourController::SAVED)])
        ->and(DB::table('tenant_luna.staff')->where('id', $elena)->value('colour'))->toBe('purple');

    $this->actingAs(closuresOwner())->get(tenantUrl('luna', '/admin/staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('staff.1.id', $elena)->where('staff.1.colour', 'purple')->where('staff.2.colour', null));
    $this->actingAs(closuresOwner())->get(tenantUrl('luna', '/admin/calendar'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('colours', [['id' => $elena, 'colour' => 'purple']]));
    $this->actingAs(closuresOwner())->get(tenantUrl('luna', '/admin/shifts'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('colours', [['id' => $elena, 'colour' => 'purple']]));

    closuresWrite($this, 'PUT', '/staff/'.$elena.'/colour', ['colour' => null])->assertRedirect();
    expect(DB::table('tenant_luna.staff')->where('id', $elena)->value('colour'))->toBeNull();
    $this->actingAs(closuresOwner())->get(tenantUrl('luna', '/admin/calendar'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('colours', []));
});

it('refuses a colour outside the palette in a sentence, a member nobody has as not-found, and holds the column to the seven words', function () {
    $elena = closuresElena();

    // The palette's size is StaffColour's cases spelled by ICU, never a word in the file.
    $palette = adminLine(StaffColourRequest::REFUSED, [
        'automatic' => adminLine(StaffColourRequest::AUTOMATIC),
        'count' => (string) (new NumberFormatter('en', NumberFormatter::SPELLOUT))->format(count(StaffColour::cases())),
    ]);
    closuresWrite($this, 'PUT', '/staff/'.$elena.'/colour', ['colour' => 'pink'])->assertStatus(400)->assertJsonPath('data.detail', $palette);
    closuresWrite($this, 'PUT', '/staff/'.$elena.'/colour', [])->assertStatus(400)->assertJsonPath('data.detail', $palette);
    expect(__(StaffColourRequest::REFUSED, [], 'en'))->toContain(':count')->toContain(':automatic');
    closuresWrite($this, 'PUT', '/staff/999999/colour', ['colour' => 'blue'])->assertNotFound();

    expect(StaffColour::words())->toBe(['orange', 'sky_blue', 'green', 'yellow', 'blue', 'vermilion', 'purple'])
        ->and(fn () => DB::transaction(fn (): int => DB::table('tenant_luna.staff')->where('id', $elena)->update(['colour' => 'pink'])))->toThrow(QueryException::class, 'staff_colour_check');
});

it('gates the colour write as the staff writes are: not-found without the feature, forbidden to a staff user, refused on a lapsed subscription', function () {
    $elena = closuresElena();

    closuresWrite($this, 'PUT', '/staff/'.$elena.'/colour', ['colour' => 'blue'], User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff')))->assertForbidden();

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'past_due']);
    closuresWrite($this, 'PUT', '/staff/'.$elena.'/colour', ['colour' => 'blue'])->assertForbidden()->assertJsonPath('code', 'subscription_required')->assertJsonPath('data.detail', adminLine(RequiresActiveSubscription::DETAIL));
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'active']);

    app()->instance(EntitlementResolver::class, new class implements EntitlementResolver
    {
        public function resolve(Tenant $tenant): Entitlements
        {
            return Entitlements::none();
        }
    });
    closuresWrite($this, 'PUT', '/staff/'.$elena.'/colour', ['colour' => 'blue'])->assertNotFound();
    expect(DB::table('tenant_luna.staff')->where('id', $elena)->value('colour'))->toBeNull();
});

it('hands each member Hours this week as the sentence\'s parts: the pattern\'s week and every week where this week is the pattern\'s, and the week\'s closures reaching them', function () {
    // The week of Monday 2026-12-07: a closure of the business on the Wednesday afternoon, one of
    // Elena's own on the Friday, all day, and a past week's left out.
    closuresWrite($this, 'POST', '/blackouts', ['date' => '2026-12-09', 'start_time' => '14:00', 'end_time' => '16:00'])->assertRedirect();
    closuresWrite($this, 'POST', '/staff/'.closuresElena().'/blackouts', ['date' => '2026-12-11'])->assertRedirect();
    closuresWrite($this, 'POST', '/blackouts', ['date' => '2026-12-01'])->assertRedirect();

    $this->actingAs(closuresOwner())->get(tenantUrl('luna', '/admin/staff'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('staff.1.id', closuresElena())
            ->where('staff.1.hours', ['minutes' => 2400, 'weekly' => true, 'closures' => [['date' => '2026-12-09', 'start' => '14:00', 'end' => '16:00'], ['date' => '2026-12-11', 'start' => null, 'end' => null]]])
            ->where('staff.2.hours', ['minutes' => 2700, 'weekly' => true, 'closures' => [['date' => '2026-12-09', 'start' => '14:00', 'end' => '16:00']]]));
});
