<?php

use App\Application\Clock;
use App\Tenancy\SignupVerification;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;

pest()->use(RefreshDatabase::class);

/*
 * Abandoned signups swept: a provisioning row older than 24 hours whose owner is unverified
 * goes with its users, which frees the slug; a verified owner's row stays.
 */

/**
 * A signup reserved through reserve() itself while the application's clock reads the given UTC
 * time.
 */
function sweptSignup(string $slug, string $reservedAt): Tenant
{
    app()->instance(Clock::class, FixedClock::at($reservedAt));

    return app(TenantProvisioner::class)->reserve(signupFor($slug));
}

/**
 * Runs the sweep while the application's clock reads the given UTC time, and answers what it
 * printed.
 */
function sweptAt(string $now): string
{
    app()->instance(Clock::class, FixedClock::at($now));
    Artisan::call('signup:sweep');

    return trim(Artisan::output());
}

/**
 * @return array<string, int>
 */
function sweptCounts(Tenant $tenant): array
{
    return [
        'tenants' => DB::table('tenants')->where('id', $tenant->id)->count(),
        'users' => DB::table('users')->where('tenant_id', $tenant->id)->count(),
        'subscriptions' => DB::table('subscriptions')->where('tenant_id', $tenant->id)->count(),
    ];
}

it('measures the day from the instant reserve() wrote, to the microsecond, against the application clock, whatever the session zone, keeping a row at 24 hours and sweeping it a microsecond later', function (string $zone) {
    DB::statement(sprintf("set local time zone '%s'", $zone));
    $tenant = sweptSignup('luna', '2026-09-28 18:00:00.750000');

    expect(DB::scalar("select to_char(created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') || '/' || to_char(updated_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') from tenants where id = ?", [$tenant->id]))->toBe('2026-09-28 18:00:00.750000/2026-09-28 18:00:00.750000')
        ->and(sweptAt('2026-09-29 17:59:59.750000'))->toBe('Swept 0 abandoned signups.')
        ->and(sweptAt('2026-09-29 18:00:00.750000'))->toBe('Swept 0 abandoned signups.')
        ->and(sweptCounts($tenant))->toBe(['tenants' => 1, 'users' => 1, 'subscriptions' => 0])
        ->and(sweptAt('2026-09-29 18:00:00.750001'))->toBe('Swept 1 abandoned signup.')
        ->and(sweptCounts($tenant))->toBe(['tenants' => 0, 'users' => 0, 'subscriptions' => 0]);
})->with(['UTC', 'Europe/Athens', 'America/New_York']);

it('deletes a signup row older than a day with its owner, and the slug can be taken again', function () {
    $abandoned = sweptSignup('luna', '2026-09-28 18:00:00');

    expect(sweptAt('2026-09-29 19:00:00'))->toBe('Swept 1 abandoned signup.')
        ->and(sweptCounts($abandoned))->toBe(['tenants' => 0, 'users' => 0, 'subscriptions' => 0])
        ->and(app(TenantProvisioner::class)->reserve(signupFor('luna'))->slug)->toBe('luna');
});

it('keeps a row it must not sweep', function (string $reservedAt, ?string $status) {
    $kept = sweptSignup('luna', $reservedAt);

    if ($status !== null) {
        DB::table('tenants')->where('id', $kept->id)->update(['status' => $status]);
    }

    expect(sweptAt('2026-09-29 19:00:00'))->toBe('Swept 0 abandoned signups.')
        ->and(sweptCounts($kept))->toBe(['tenants' => 1, 'users' => 1, 'subscriptions' => 0]);
})->with([
    'a signup row younger than a day' => ['2026-09-28 20:00:00', null],
    'an active tenant a day old' => ['2026-09-28 18:00:00', 'active'],
]);

it('sweeps a signup whose link was never clicked a day after its reservation, with its owner, and frees the slug', function () {
    app()->instance(Clock::class, FixedClock::at('2026-09-28 18:00:00'));
    ['tenant' => $tenant] = app(SignupVerification::class)->reserve(signupFor('luna'));

    expect(sweptAt('2026-09-29 18:00:01'))->toBe('Swept 1 abandoned signup.')
        ->and(sweptCounts($tenant))->toBe(['tenants' => 0, 'users' => 0, 'subscriptions' => 0])
        ->and(app(SignupVerification::class)->reserve(signupFor('luna'))['tenant']->slug)->toBe('luna');
});

it('never sweeps a verified owner\'s row, {case}', function (bool $built) {
    app()->instance(Clock::class, FixedClock::at('2026-09-28 18:00:00'));
    ['tenant' => $tenant, 'token' => $token] = app(SignupVerification::class)->reserve(signupFor('luna'));

    if ($built) {
        expect(app(SignupVerification::class)->verify($token)?->built)->toBeTrue();
    } else {
        DB::table('users')->where('tenant_id', $tenant->id)->update(['email_verified_at' => '2026-09-28 18:00:00+00']);
    }

    expect(sweptAt('2026-10-05 18:00:00'))->toBe('Swept 0 abandoned signups.')
        ->and(sweptCounts($tenant))->toBe(['tenants' => 1, 'users' => 1, 'subscriptions' => 0]);
})->with(['built by its click' => true, 'even one left provisioning' => false]);

it('gives back only a row still provisioning, answering whether it deleted', function (bool $released, string $status, array $left) {
    $tenant = sweptSignup('luna', '2026-09-29 18:00:00');
    DB::table('tenants')->where('id', $tenant->id)->update(['status' => $status]);

    expect(app(TenantProvisioner::class)->release($tenant))->toBe($released)
        ->and(sweptCounts($tenant))->toBe($left);
})->with([
    'a row still provisioning, with its owner' => [true, 'provisioning', ['tenants' => 0, 'users' => 0, 'subscriptions' => 0]],
    'nothing of an active row' => [false, 'active', ['tenants' => 1, 'users' => 1, 'subscriptions' => 0]],
]);

it('runs every hour in the console schedule, after the lapse and before the trial\'s notices, which run every hour too', function () {
    $commands = array_values(array_filter(array_map(
        fn (Event $event): array => [$event->command ?? '', $event->expression],
        app(Schedule::class)->events(),
    ), fn (array $event): bool => str_contains($event[0], 'billing:lapse') || str_contains($event[0], 'signup:sweep') || str_contains($event[0], 'trial:notify')));

    expect($commands)->toHaveCount(3)
        ->and($commands[0][0])->toContain('billing:lapse')
        ->and($commands[1][0])->toContain('signup:sweep')
        ->and($commands[2][0])->toContain('trial:notify')
        ->and(array_column($commands, 1))->toBe(['0 * * * *', '0 * * * *', '0 * * * *']);
});
