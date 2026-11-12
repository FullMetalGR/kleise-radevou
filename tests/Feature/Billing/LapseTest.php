<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Models\User;
use App\Platform\ClosingWindow;
use App\Platform\Suspension;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;

pest()->use(RefreshDatabase::class);

/*
 * Grace running out: a scheduled command lapses every tenant in grace whose end has passed,
 * writing past_due, so the gate keeps reading one column and never compares a date. The last test
 * walks it end to end through the subscription gate: a configuration write admitted inside grace
 * and refused once the lapse has run.
 *
 * The trial's end: the same command writes canceled on every trial whose trial_ends_at is at or
 * before the clock's now, beside the grace lapse and in a statement of its own, grace_ends_at
 * untouched, and the tenant is then frozen as any canceled one.
 *
 * The trial's close: in a third statement the command closes every active tenant canceled with
 * its trial's end still set and thirty days or more behind the clock, closing_at written as now
 * and the status closing, and the owners are emailed once through the platform's seam, which is
 * the recorder here. A trial the console suspends still lapses, and is closed only on the first
 * run after the console resumes it, since the close reads active rows alone.
 *
 * The closing email says the data is deleted after the window and promises nothing more of what
 * or when.
 *
 * The closing email is a branded mail in the row's language: its words are mail.php's in
 * tenants.locale in both parts, and the Greek case lays Greek lines over the loaded
 * lang/el/mail.php, as TrialNoticeTest does, to tell the test's lines from the file's whatever
 * the file holds.
 */

/**
 * The closing email's subject as lang/{language}/mail.php holds it, read through the translator.
 */
function closingSubject(SiteLanguage $language = SiteLanguage::English): string
{
    return mailChoice('mail.closing.subject', ClosingWindow::days(), $language);
}

beforeEach(fn () => app()->instance(Clock::class, FixedClock::at('2026-10-01 09:00')));

it('lapses a tenant whose grace has run out and leaves one whose end is ahead', function () {
    foreach (['luna', 'pier', 'oak'] as $slug) {
        billingTenant($slug);
    }
    syncWith()->chargeFailed('cus_luna');
    app()->instance(Clock::class, FixedClock::at('2026-10-05 09:00'));
    syncWith()->chargeFailed('cus_pier');
    app()->instance(Clock::class, FixedClock::at('2026-10-08 09:00'));

    expect(Artisan::call('billing:lapse'))->toBe(0)
        ->and(Artisan::output())->toContain('Lapsed 1 tenant.')
        ->and(billingColumns('luna'))->toMatchArray(['subscription_state' => 'past_due', 'grace_ends_at' => null])
        ->and(billingColumns('pier'))->toMatchArray(['subscription_state' => 'grace', 'grace_ends_at' => '2026-10-12 09:00'])
        ->and(billingColumns('oak'))->toMatchArray(['subscription_state' => 'active', 'grace_ends_at' => null]);
});

/**
 * A billing row in trial ending at the instant given, as a trial's build leaves it.
 */
function trialTenant(string $slug, string $endsAt): Tenant
{
    $tenant = schemaBehind(billingTenant($slug));
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => $endsAt], $slug);

    return $tenant;
}

it('ends a trial whose end has passed by the clock, canceled with its end kept, and leaves one still ahead', function () {
    trialTenant('luna', '2026-10-08 09:00:00+00');
    trialTenant('pier', '2026-10-08 09:00:01+00');
    trialTenant('oak', '2026-10-07 21:00:00+00');
    app()->instance(Clock::class, FixedClock::at('2026-10-08 12:00', 'Europe/Athens'));

    expect(Artisan::call('billing:lapse'))->toBe(0)
        ->and(Artisan::output())->toContain('Lapsed 0 tenants.')->toContain('Ended 2 trials.')
        ->and(billingColumns('luna'))->toMatchArray(['subscription_state' => 'canceled', 'entitlements' => '{staff}', 'grace_ends_at' => null])
        ->and(billingColumns('oak'))->toMatchArray(['subscription_state' => 'canceled'])
        ->and(billingColumns('pier'))->toMatchArray(['subscription_state' => 'trial', 'entitlements' => '{staff}'])
        ->and(DB::table('tenants')->where('slug', 'luna')->value('trial_ends_at'))->not->toBeNull();
});

it('runs the grace lapse beside the trial lapse, naming each count', function () {
    billingTenant('luna');
    syncWith()->chargeFailed('cus_luna');
    trialTenant('pier', '2026-10-05 09:00:00+00');
    trialTenant('oak', '2026-10-20 09:00:00+00');
    app()->instance(Clock::class, FixedClock::at('2026-10-08 09:00'));

    expect(Artisan::call('billing:lapse'))->toBe(0)
        ->and(Artisan::output())->toContain('Lapsed 1 tenant.')->toContain('Ended 1 trial.')
        ->and(billingColumns('luna'))->toMatchArray(['subscription_state' => 'past_due', 'grace_ends_at' => null])
        ->and(billingColumns('pier'))->toMatchArray(['subscription_state' => 'canceled'])
        ->and(billingColumns('oak'))->toMatchArray(['subscription_state' => 'trial']);
});

it('leaves a trial alone that the first subscription already ended, its end cleared', function () {
    trialTenant('luna', '2026-10-05 09:00:00+00');
    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));
    app()->instance(Clock::class, FixedClock::at('2026-10-08 09:00'));

    Artisan::call('billing:lapse');

    expect(Artisan::output())->toContain('Ended 0 trials.')
        ->and(billingColumns('luna'))->toMatchArray(['subscription_state' => 'active']);
});

it('runs every hour in the console schedule, beside the holds sweeper', function () {
    $scheduled = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (object $event): bool => is_string($event->command ?? null) && str_contains($event->command, 'billing:lapse'),
    ));

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]->expression)->toBe('0 * * * *');
});

it('admits a configuration write inside grace and refuses it once the lapse has run, through the gate as built', function () {
    provisionTenant('luna', ['ownerEmail' => 'owner@luna.example']);
    syncWith()->attachCustomer(Tenant::query()->where('slug', 'luna')->sole(), openedCheckout('cus_luna'));
    $owner = User::query()->where('email', 'owner@luna.example')->sole();
    syncWith()->chargeFailed('cus_luna');

    $this->actingAs($owner)->from(tenantUrl('luna', '/admin/staff'))->post(tenantUrl('luna', '/admin/blackouts'), ['date' => '2026-12-25'])
        ->assertRedirect(tenantUrl('luna', '/admin/staff'));

    app()->instance(Clock::class, FixedClock::at('2026-10-08 09:00'));
    Artisan::call('billing:lapse');

    $this->actingAs($owner)->from(tenantUrl('luna', '/admin/staff'))->postJson(tenantUrl('luna', '/admin/blackouts'), ['date' => '2026-12-26'])
        ->assertForbidden()
        ->assertJsonPath('code', 'subscription_required')
        ->assertJsonPath('data.state', 'past_due')
        ->assertJsonPath('data.detail', adminLine(RequiresActiveSubscription::DETAIL));

    expect(DB::table('tenant_luna.blackouts')->pluck('date')->all())->toBe(['2026-12-25']);
});

function lapseSeam(): RecordingPlatformMessages
{
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);

    return $seam;
}

/**
 * A tenant whose trial ended unpaid at the instant given, as the lapse leaves it (canceled with
 * the end kept), with the owners and the other people named.
 *
 * @param  array<string, string>  $people  address => role
 */
function endedTrialTenant(string $slug, string $endedAt, array $people = []): int
{
    $tenant = schemaBehind(billingTenant($slug));
    billingSet(['subscription_state' => 'canceled', 'entitlements' => '{staff}', 'trial_ends_at' => $endedAt], $slug);

    foreach ($people === [] ? ["owner@{$slug}.example" => 'owner'] : $people as $address => $role) {
        DB::table('users')->insert(['tenant_id' => $tenant->id, 'name' => ucfirst(strtok($address, '@') ?: $address).' Person', 'email' => $address, 'password' => bcrypt('correct horse battery staple'), 'role' => $role]);
    }

    return $tenant->id;
}

/**
 * The status of a tenant row and its closing, spelled in UTC to the minute.
 *
 * @return array<mixed>
 */
function closingColumns(string $slug): array
{
    return (array) DB::selectOne(
        "select status, to_char(closing_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as closing_at from tenants where slug = ?",
        [$slug],
    );
}

/**
 * Runs billing:lapse while the application's clock reads the given UTC time, and answers what
 * it printed.
 */
function lapsedAt(string $now): string
{
    app()->instance(Clock::class, FixedClock::at($now));
    expect(Artisan::call('billing:lapse'))->toBe(0);

    return Artisan::output();
}

it('closes an active tenant thirty days past a trial it never paid for, closing_at now and the status closing, and emails every owner once', function () {
    $seam = lapseSeam();
    endedTrialTenant('luna', '2026-10-08 09:00:00+00', ['ada@luna.example' => 'owner', 'bea@luna.example' => 'owner', 'cy@luna.example' => 'manager', 'di@luna.example' => 'staff']);

    expect(lapsedAt('2026-11-07 08:59'))->toContain('Closed 0 accounts.')
        ->and(closingColumns('luna'))->toBe(['status' => 'active', 'closing_at' => null])
        ->and($seam->sent)->toBe([]);

    expect(lapsedAt('2026-11-07 09:00'))->toContain('Lapsed 0 tenants.')->toContain('Ended 0 trials.')->toContain('Closed 1 account.')
        ->and(closingColumns('luna'))->toBe(['status' => 'closing', 'closing_at' => '2026-11-07 09:00'])
        ->and(billingColumns('luna'))->toMatchArray(['subscription_state' => 'canceled', 'entitlements' => '{staff}'])
        ->and(array_map(fn (PlatformMessage $message): string => $message->toAddress.' '.$message->kind->value.' '.$message->subject, $seam->sent))->toBe([
            'ada@luna.example closing '.closingSubject(),
            'bea@luna.example closing '.closingSubject(),
        ]);

    expect(lapsedAt('2026-11-07 10:00'))->toContain('Closed 0 accounts.')
        ->and(lapsedAt('2026-12-01 09:00'))->toContain('Closed 0 accounts.')
        ->and(closingColumns('luna'))->toBe(['status' => 'closing', 'closing_at' => '2026-11-07 09:00'])
        ->and($seam->sent)->toHaveCount(2);
});

it('lapses and closes in one run a trial whose end is thirty days behind and whose lapse never ran', function () {
    $seam = lapseSeam();
    trialTenant('luna', '2026-10-08 09:00:00+00');
    DB::table('users')->insert(['tenant_id' => DB::table('tenants')->where('slug', 'luna')->value('id'), 'name' => 'Ada Person', 'email' => 'ada@luna.example', 'password' => bcrypt('correct horse battery staple'), 'role' => 'owner']);

    expect(lapsedAt('2026-11-07 09:00'))->toContain('Ended 1 trial.')->toContain('Closed 1 account.')
        ->and(closingColumns('luna'))->toBe(['status' => 'closing', 'closing_at' => '2026-11-07 09:00'])
        ->and(billingColumns('luna'))->toMatchArray(['subscription_state' => 'canceled'])
        ->and($seam->sent)->toHaveCount(1);
});

it('leaves alone a canceled tenant that once subscribed, one inside the thirty days, a suspended one and one already closing', function () {
    $seam = lapseSeam();
    endedTrialTenant('luna', '2026-10-08 09:00:00+00');
    endedTrialTenant('pier', '2026-10-08 09:00:00+00');
    billingSet(['trial_ends_at' => null], 'pier');
    endedTrialTenant('oak', '2026-10-08 09:01:00+00');
    endedTrialTenant('elm', '2026-10-08 09:00:00+00');
    billingSet(['status' => 'suspended'], 'elm');
    endedTrialTenant('fir', '2026-10-08 09:00:00+00');
    billingSet(['status' => 'closing', 'closing_at' => '2026-10-20 09:00:00+00'], 'fir');

    expect(lapsedAt('2026-11-07 09:00'))->toContain('Closed 1 account.')
        ->and(closingColumns('luna'))->toBe(['status' => 'closing', 'closing_at' => '2026-11-07 09:00'])
        ->and(closingColumns('pier'))->toBe(['status' => 'active', 'closing_at' => null])
        ->and(closingColumns('oak'))->toBe(['status' => 'active', 'closing_at' => null])
        ->and(closingColumns('elm'))->toBe(['status' => 'suspended', 'closing_at' => null])
        ->and(closingColumns('fir'))->toBe(['status' => 'closing', 'closing_at' => '2026-10-20 09:00'])
        ->and(array_map(fn (PlatformMessage $message): string => $message->toAddress, $seam->sent))->toBe(['owner@luna.example']);
});

it('lapses a trial the console suspended to canceled and leaves it suspended, and closes it on the first run after the console resumes it, on a moved clock', function () {
    $seam = lapseSeam();
    $tenant = trialTenant('luna', '2026-10-08 09:00:00+00');
    DB::table('users')->insert(['tenant_id' => $tenant->id, 'name' => 'Ada Person', 'email' => 'ada@luna.example', 'password' => bcrypt('correct horse battery staple'), 'role' => 'owner']);
    $operator = addOperator();
    app()->instance(Clock::class, FixedClock::at('2026-10-05 09:00'));
    app(Suspension::class)->suspend($tenant, $operator, 'Chargebacks under review.');

    expect(billingColumns('luna'))->toMatchArray(['subscription_state' => 'trial', 'entitlements' => '{staff}'])
        ->and(lapsedAt('2026-10-08 10:00'))->toContain('Ended 1 trial.')->toContain('Closed 0 accounts.')
        ->and(billingColumns('luna'))->toMatchArray(['subscription_state' => 'canceled', 'entitlements' => '{staff}'])
        ->and(closingColumns('luna'))->toBe(['status' => 'suspended', 'closing_at' => null]);

    expect(lapsedAt('2026-11-07 09:00'))->toContain('Closed 0 accounts.')
        ->and(lapsedAt('2026-11-20 09:00'))->toContain('Closed 0 accounts.')
        ->and(closingColumns('luna'))->toBe(['status' => 'suspended', 'closing_at' => null]);

    app()->instance(Clock::class, FixedClock::at('2026-11-20 09:30'));
    app(Suspension::class)->resume($tenant, $operator);

    expect(closingColumns('luna'))->toBe(['status' => 'active', 'closing_at' => null])
        ->and(billingColumns('luna'))->toMatchArray(['subscription_state' => 'canceled'])
        ->and(lapsedAt('2026-11-20 10:00'))->toContain('Closed 1 account.')
        ->and(closingColumns('luna'))->toBe(['status' => 'closing', 'closing_at' => '2026-11-20 10:00'])
        ->and(array_map(fn (PlatformMessage $message): string => $message->toAddress.' '.$message->kind->value, $seam->sent))->toBe([
            'ada@luna.example suspension',
            'ada@luna.example resumption',
            'ada@luna.example closing',
        ]);
});

it('names the business, the trial\'s end and the window\'s in the tenant\'s clock with the zone named, what keeps working, and the Billing screen as the way back, today\'s English character for character, with the same words as HTML in the layout', function (string $zone, string $ended, string $window) {
    $seam = lapseSeam();
    $id = endedTrialTenant('luna', '2026-10-08 09:00:00+00', ['ada@luna.example' => 'owner']);
    billingSet(['name' => 'Luna Hair Studio', 'timezone' => $zone]);
    $whatStays = 'Until then your bookings keep running and your booking page stays open, and changes to your setup stay paused. After that, the data in your account is deleted.';

    lapsedAt('2026-11-07 09:00');

    expect($seam->sent)->toHaveCount(1);
    $message = $seam->sent[0];

    expect($message->kind)->toBe(PlatformMessageKind::Closing)
        ->and($message->tenantId)->toBe($id)
        ->and($message->toAddress)->toBe('ada@luna.example')
        ->and($message->toName)->toBe('Ada Person')
        ->and($message->replyTo)->toBeNull()
        ->and($message->subject)->toBe('Your account closes in 30 days')
        ->and($message->subject)->toBe(closingSubject())
        ->and($message->body)->toBe(implode("\n", [
            'Hello Ada Person,',
            '',
            'The free trial for Luna Hair Studio ended on '.$ended.' and no plan was chosen, so the account is now closing.',
            '',
            'We keep it for 30 days, until '.$window.'.',
            $whatStays,
            '',
            'To keep your account, choose a plan on the Billing screen before then:',
            '',
            tenantUrl('luna', '/admin/billing'),
            '',
            sprintf('Times are in %s, the clock you keep.', $zone),
        ]))
        ->and($message->html)->toContain('<html lang="en">')
        ->and($message->html)->toContain('<title>Your account closes in 30 days</title>')
        ->and($message->html)->toContain('<a href="'.tenantUrl('luna', '/admin/billing').'"')
        ->and($message->html)->toContain('ended on '.$ended.' and no plan was chosen')
        ->and($message->html)->toContain('until '.$window.'.')
        ->and($message->html)->toContain($whatStays)
        ->and($message->body)->not->toContain('everything in it')
        ->and($message->html)->toContain(sprintf('Times are in %s, the clock you keep.', $zone))
        ->and($message->html)->toContain(siteSentence('mail.closing.reason', SiteLanguage::English, ['business' => 'Luna Hair Studio']))
        ->and($message->html)->toContain(siteSentence('mail.layout.sent_by', SiteLanguage::English))
        ->and($message->html)->not->toContain('<img');
})->with([
    'Athens, three hours ahead of UTC in October and two in December' => ['Europe/Athens', 'Thursday 8 October 2026 at 12:00', 'Monday 7 December 2026 at 11:00'],
    'New York, four hours behind in October and five in December' => ['America/New_York', 'Thursday 8 October 2026 at 05:00', 'Monday 7 December 2026 at 04:00'],
]);

it('tells a Greek row\'s owner in Greek: the closing email\'s words from lang/el through the translator in both parts, the two instants spelled by the formatter in Greek in the tenant\'s clock, the document in Greek', function () {
    $seam = lapseSeam();
    endedTrialTenant('luna', '2026-10-08 09:00:00+00', ['ada@luna.example' => 'owner']);
    billingSet(['name' => 'Luna Hair Studio', 'timezone' => 'America/New_York', 'locale' => 'el']);
    // Greek lines are laid over the loaded lang/el/mail.php to tell the test's lines from the
    // file's whatever the file holds; the dates themselves are the formatter's, each joined to its
    // time by the file's own words, and never spelled.
    __('mail.greeting', [], 'el');
    app('translator')->addLines([
        'mail.closing.subject' => 'GREEK CLOSING',
        'mail.closing.ended' => 'GREEK :business ENDED :end',
        'mail.closing.kept' => 'GREEK UNTIL :until',
        'mail.closing.zone' => 'GREEK ZONE :zone',
    ], 'el');
    $formatter = new IntlDateFormatter('el', IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'America/New_York', IntlDateFormatter::GREGORIAN, 'EEEE d MMMM y');
    $ended = siteSentence('mail.time.instant', SiteLanguage::Greek, ['date' => (string) $formatter->format(new DateTimeImmutable('2026-10-08 09:00:00', new DateTimeZone('UTC'))), 'time' => '05:00']);
    $window = siteSentence('mail.time.instant', SiteLanguage::Greek, ['date' => (string) $formatter->format(new DateTimeImmutable('2026-12-07 09:00:00', new DateTimeZone('UTC'))), 'time' => '04:00']);

    lapsedAt('2026-11-07 09:00');

    expect($seam->sent)->toHaveCount(1);
    $message = $seam->sent[0];

    expect($message->subject)->toBe('GREEK CLOSING')
        ->and($message->body)->toContain("\n\nGREEK Luna Hair Studio ENDED ".$ended."\n\n")
        ->and($message->body)->toContain("\n\nGREEK UNTIL ".$window."\n")
        ->and($message->body)->toEndWith("\n\nGREEK ZONE America/New_York")
        ->and($message->body)->not->toContain('Thursday')
        ->and($message->body)->not->toContain('Monday')
        ->and(preg_match('/[^\x00-\x7F]/', $message->body))->toBe(1)
        ->and($message->html)->toContain('<html lang="el">')
        ->and($message->html)->toContain('<title>GREEK CLOSING</title>')
        ->and($message->html)->toContain('GREEK Luna Hair Studio ENDED '.$ended)
        ->and($message->html)->toContain('GREEK UNTIL '.$window)
        ->and($message->html)->toContain('GREEK ZONE America/New_York')
        ->and($message->html)->toContain(siteSentence('mail.closing.reason', SiteLanguage::Greek, ['business' => 'Luna Hair Studio']))
        ->and($message->body)->toContain("\n".siteSentence('mail.closing.what_stays', SiteLanguage::Greek)."\n\n");
});
