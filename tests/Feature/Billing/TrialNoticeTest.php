<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Site\SiteLanguage;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;

pest()->use(RefreshDatabase::class);

/*
 * The trial's two notices: the hourly trial:notify sends "three days left" once when 72 hours or
 * less remain and "ends today" once when 24 hours or less remain, to every owner user of a tenant
 * in trial, through the platform's own seam, each stamped on the tenant row in the statement that
 * decides it. Every case pins the clock first and moves it as LapseTest does; the seam is the
 * recorder, so nothing is sent anywhere, but for the one case that reads the mail the adapter
 * writes.
 *
 * A trial whose end has passed before the hourly lapse moved it is still sent "ends today", once,
 * its body worded for an end already past, and never "three days left" (P9F-51a); a row the lapse
 * has moved, a closing one and a suspended one are sent nothing.
 */

beforeEach(fn () => app()->instance(Clock::class, FixedClock::at('2026-10-01 09:00')));

function noticeSeam(): RecordingPlatformMessages
{
    $seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $seam);

    return $seam;
}

/**
 * A notice's subject as lang/{language}/mail.php holds it, read through the translator.
 */
function noticeSubject(string $notice, SiteLanguage $language = SiteLanguage::English): string
{
    return siteSentence('mail.trial.'.$notice.'.subject', $language);
}

/**
 * A tenant in trial ending at the instant given, with the owners and the other people named, as
 * a trial's build leaves the row, its schema behind it for the language its settings hold.
 *
 * @param  array<string, string>  $people  address => role
 */
function noticeTenant(string $slug, string $name, string $endsAt, array $people = []): int
{
    $tenant = schemaBehind(billingTenant($slug));
    billingSet(['name' => $name, 'subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => $endsAt], $slug);

    foreach ($people === [] ? ["owner@{$slug}.example" => 'owner'] : $people as $address => $role) {
        DB::table('users')->insert(['tenant_id' => $tenant->id, 'name' => ucfirst(strtok($address, '@') ?: $address).' Person', 'email' => $address, 'password' => bcrypt('correct horse battery staple'), 'role' => $role]);
    }

    return $tenant->id;
}

/**
 * Runs trial:notify while the application's clock reads the given UTC time, and answers what it
 * printed.
 */
function notifiedAt(string $now): string
{
    app()->instance(Clock::class, FixedClock::at($now));
    expect(Artisan::call('trial:notify'))->toBe(0);

    return Artisan::output();
}

/**
 * Each message as who it went to and which notice it was, in the order the seam was handed them.
 *
 * @return list<string>
 */
function noticesSent(RecordingPlatformMessages $seam): array
{
    return array_map(fn (PlatformMessage $message): string => $message->toAddress.' '.$message->subject, $seam->sent);
}

/**
 * The two stamps of a tenant row, spelled in UTC to the minute, null where the notice is unsent.
 *
 * @return array<mixed>
 */
function noticeStamps(string $slug): array
{
    return (array) DB::selectOne(
        "select to_char(trial_three_days_sent_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as three_days,
                to_char(trial_ends_today_sent_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as ends_today
         from tenants where slug = ?",
        [$slug],
    );
}

it('sends the three days notice once to every owner when 72 hours or less remain, stamping it, and nothing to anybody else', function () {
    $seam = noticeSeam();
    noticeTenant('luna', 'Luna Hair Studio', '2026-10-08 09:00:00+00', ['ada@luna.example' => 'owner', 'bea@luna.example' => 'owner', 'cy@luna.example' => 'manager', 'di@luna.example' => 'staff']);

    expect(notifiedAt('2026-10-05 08:59'))->toContain('Sent the three days left notice to 0 tenants.')
        ->and($seam->sent)->toBe([])
        ->and(noticeStamps('luna'))->toBe(['three_days' => null, 'ends_today' => null]);

    expect(notifiedAt('2026-10-05 09:00'))->toContain('Sent the three days left notice to 1 tenant.')->toContain('Sent the ends today notice to 0 tenants.')
        ->and(noticesSent($seam))->toBe(['ada@luna.example '.noticeSubject('three_days'), 'bea@luna.example '.noticeSubject('three_days')])
        ->and(array_map(fn (PlatformMessage $message): PlatformMessageKind => $message->kind, $seam->sent))->each->toBe(PlatformMessageKind::TrialNotice)
        ->and(noticeStamps('luna'))->toBe(['three_days' => '2026-10-05 09:00', 'ends_today' => null]);

    notifiedAt('2026-10-05 10:00');
    notifiedAt('2026-10-07 08:59');

    expect($seam->sent)->toHaveCount(2)
        ->and(noticeStamps('luna'))->toBe(['three_days' => '2026-10-05 09:00', 'ends_today' => null]);
});

it('sends the ends today notice once when 24 hours or less remain, stamping it, after the three days one', function () {
    $seam = noticeSeam();
    noticeTenant('luna', 'Luna Hair Studio', '2026-10-08 09:00:00+00');

    notifiedAt('2026-10-05 12:00');
    notifiedAt('2026-10-07 08:59');

    expect($seam->sent)->toHaveCount(1)
        ->and(notifiedAt('2026-10-07 09:00'))->toContain('Sent the three days left notice to 0 tenants.')->toContain('Sent the ends today notice to 1 tenant.')
        ->and(noticesSent($seam))->toBe(['owner@luna.example '.noticeSubject('three_days'), 'owner@luna.example '.noticeSubject('ends_today')])
        ->and(noticeStamps('luna'))->toBe(['three_days' => '2026-10-05 12:00', 'ends_today' => '2026-10-07 09:00']);

    notifiedAt('2026-10-07 11:00');
    notifiedAt('2026-10-08 08:59');

    expect($seam->sent)->toHaveCount(2);
});

it('sends both notices, in order, to a trial already shorter than three days when the first run sees it', function () {
    $seam = noticeSeam();
    noticeTenant('luna', 'Luna Hair Studio', '2026-10-08 09:00:00+00', ['ada@luna.example' => 'owner', 'bea@luna.example' => 'owner']);
    noticeTenant('pier', 'Pier Barbers', '2026-10-09 09:00:00+00');

    expect(notifiedAt('2026-10-07 21:00'))->toContain('Sent the three days left notice to 2 tenants.')->toContain('Sent the ends today notice to 1 tenant.')
        ->and(noticesSent($seam))->toBe([
            'ada@luna.example '.noticeSubject('three_days'),
            'bea@luna.example '.noticeSubject('three_days'),
            'owner@pier.example '.noticeSubject('three_days'),
            'ada@luna.example '.noticeSubject('ends_today'),
            'bea@luna.example '.noticeSubject('ends_today'),
        ])
        ->and(noticeStamps('luna'))->toBe(['three_days' => '2026-10-07 21:00', 'ends_today' => '2026-10-07 21:00'])
        ->and(noticeStamps('pier'))->toBe(['three_days' => '2026-10-07 21:00', 'ends_today' => null]);

    notifiedAt('2026-10-07 22:00');

    expect($seam->sent)->toHaveCount(5);
});

it('names the business, the end in the tenant\'s clock with the zone named, what pauses and what keeps running, and the Billing screen\'s address, today\'s English character for character, with the same words as HTML in the layout', function (string $zone, string $end) {
    $seam = noticeSeam();
    $id = noticeTenant('luna', 'Luna Hair Studio', '2026-10-08 09:00:00+00', ['ada@luna.example' => 'owner']);
    billingSet(['timezone' => $zone]);

    notifiedAt('2026-10-07 10:00');

    [$three, $today] = $seam->sent;
    $whatEnds = siteSentence('mail.trial.what_ends', SiteLanguage::English);
    $zoneLine = siteSentence('mail.trial.zone', SiteLanguage::English, ['zone' => $zone]);
    $greeting = siteSentence('mail.greeting', SiteLanguage::English, ['owner' => 'Ada Person']);
    $billing = siteSentence('mail.trial.billing', SiteLanguage::English);

    foreach ([$three, $today] as $message) {
        expect($message->tenantId)->toBe($id)
            ->and($message->toAddress)->toBe('ada@luna.example')
            ->and($message->toName)->toBe('Ada Person')
            ->and($message->replyTo)->toBeNull()
            ->and($message->html)->toContain('<html lang="en">')
            ->and($message->html)->toContain('<title>'.$message->subject.'</title>')
            ->and($message->html)->toContain('<a href="'.tenantUrl('luna', '/admin/billing').'"')
            ->and($message->html)->toContain('Luna Hair Studio')
            ->and($message->html)->toContain($end)
            ->and($message->html)->toContain($zoneLine)
            ->and($message->html)->toContain(siteSentence('mail.trial.reason', SiteLanguage::English, ['business' => 'Luna Hair Studio']))
            ->and($message->html)->toContain(siteSentence('mail.layout.sent_by', SiteLanguage::English))
            ->and($message->html)->not->toContain('<img');
    }

    expect($three->subject)->toBe(noticeSubject('three_days'))
        ->and($three->body)->toBe(implode("\n", [
            $greeting,
            '',
            siteSentence('mail.trial.three_days.ends', SiteLanguage::English, ['business' => 'Luna Hair Studio', 'end' => $end]),
            '',
            $whatEnds,
            '',
            $billing,
            '',
            tenantUrl('luna', '/admin/billing'),
            '',
            $zoneLine,
        ]))
        ->and($today->subject)->toBe(noticeSubject('ends_today'))
        ->and($today->body)->toBe(implode("\n", [
            $greeting,
            '',
            siteSentence('mail.trial.ends_today.ends', SiteLanguage::English, ['business' => 'Luna Hair Studio', 'end' => $end]),
            '',
            $whatEnds,
            '',
            $billing,
            '',
            tenantUrl('luna', '/admin/billing'),
            '',
            $zoneLine,
        ]));
})->with([
    'Athens, three hours ahead of UTC' => ['Europe/Athens', 'Thursday 8 October 2026 at 12:00'],
    'New York, four hours behind' => ['America/New_York', 'Thursday 8 October 2026 at 05:00'],
]);

it('tells a Greek row in Greek: both notices\' words from lang/el through the translator in both parts, the end spelled by the formatter in Greek in the tenant\'s clock with the zone named, the document in Greek', function () {
    $seam = noticeSeam();
    noticeTenant('luna', 'Luna Hair Studio', '2026-10-08 09:00:00+00', ['ada@luna.example' => 'owner']);
    billingSet(['timezone' => 'America/New_York', 'locale' => 'el']);
    // Greek lines are laid over the loaded lang/el/mail.php to tell the test's lines from the
    // file's whatever the file holds; the date itself is the formatter's, joined to the time by
    // the file's own words, and never spelled.
    __('mail.greeting', [], 'el');
    app('translator')->addLines([
        'mail.trial.three_days.ends' => 'GREEK :business ENDS :end',
        'mail.trial.ends_today.subject' => 'GREEK LAST DAY',
        'mail.trial.zone' => 'GREEK ZONE :zone',
    ], 'el');
    $end = new DateTimeImmutable('2026-10-08 09:00:00', new DateTimeZone('UTC'));
    $formatter = new IntlDateFormatter('el', IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'America/New_York', IntlDateFormatter::GREGORIAN, 'EEEE d MMMM y');
    $spelled = siteSentence('mail.time.instant', SiteLanguage::Greek, ['date' => (string) $formatter->format($end), 'time' => '05:00']);

    notifiedAt('2026-10-07 10:00');

    [$three, $today] = $seam->sent;

    expect($three->subject)->toBe(noticeSubject('three_days', SiteLanguage::Greek))
        ->and($three->body)->toContain("\n\nGREEK Luna Hair Studio ENDS ".$spelled."\n\n")
        ->and($three->body)->toEndWith("\n\nGREEK ZONE America/New_York")
        ->and($three->body)->not->toContain('Thursday')
        ->and(preg_match('/[^\x00-\x7F]/', $three->body))->toBe(1)
        ->and($three->html)->toContain('<html lang="el">')
        ->and($three->html)->toContain('GREEK Luna Hair Studio ENDS '.$spelled)
        ->and($three->html)->toContain('GREEK ZONE America/New_York')
        ->and($three->html)->toContain(siteSentence('mail.trial.reason', SiteLanguage::Greek, ['business' => 'Luna Hair Studio']))
        ->and($today->subject)->toBe('GREEK LAST DAY')
        ->and($today->body)->toContain(siteSentence('mail.trial.ends_today.ends', SiteLanguage::Greek, ['business' => 'Luna Hair Studio', 'end' => $spelled]))
        ->and($today->html)->toContain('<title>GREEK LAST DAY</title>');
});

it('goes from the platform\'s address and name with no reply-to, through the seam as bound', function () {
    config(['mail.from.address' => 'hello@kleiseradevou.test', 'mail.from.name' => siteName()]);
    seamTransport()->flush();
    noticeTenant('luna', 'Luna Hair Studio', '2026-10-08 09:00:00+00', ['ada@luna.example' => 'owner']);

    notifiedAt('2026-10-05 09:00');

    $message = seamOnly();
    // The line up to the end it names, which the tenant's clock spells.
    [$endsBefore] = explode('{end}', siteSentence('mail.trial.three_days.ends', SiteLanguage::English, [
        'business' => 'Luna Hair Studio', 'end' => '{end}',
    ]), 2);

    expect(array_map(fn ($address): string => $address->toString(), $message->getFrom()))->toBe(['"'.siteName().'" <hello@kleiseradevou.test>'])
        ->and($message->getReplyTo())->toBe([])
        ->and(array_map(fn ($address): string => $address->toString(), $message->getTo()))->toBe(['"Ada Person" <ada@luna.example>'])
        ->and($message->getSubject())->toBe(noticeSubject('three_days'))
        ->and($message->getTextBody())->toStartWith(siteSentence('mail.greeting', SiteLanguage::English, ['owner' => 'Ada Person'])."\n\n".$endsBefore)
        ->and($message->getHtmlBody())->toContain('<html lang="en">')
        ->and($message->getBody()->getMediaSubtype())->toBe('alternative');
});

it('sends the ends today notice once to a trial whose end passed before the lapse ran, its body true of an end already past, the end in the tenant\'s clock with the zone named, and never the three days one (P9F-51a)', function (string $endsAt, string $ended) {
    $seam = noticeSeam();
    noticeTenant('luna', 'Luna Hair Studio', $endsAt, ['ada@luna.example' => 'owner']);
    $endedLine = siteSentence('mail.trial.ends_today.ended', SiteLanguage::English, ['business' => 'Luna Hair Studio', 'end' => $ended]);
    $body = implode("\n", [
        siteSentence('mail.greeting', SiteLanguage::English, ['owner' => 'Ada Person']),
        '',
        $endedLine,
        '',
        siteSentence('mail.trial.what_ended', SiteLanguage::English),
        '',
        siteSentence('mail.trial.billing', SiteLanguage::English),
        '',
        tenantUrl('luna', '/admin/billing'),
        '',
        siteSentence('mail.trial.zone', SiteLanguage::English, ['zone' => 'Europe/Athens']),
    ]);

    expect(notifiedAt('2026-10-07 10:00'))->toContain('Sent the three days left notice to 0 tenants.')->toContain('Sent the ends today notice to 1 tenant.')
        ->and(noticesSent($seam))->toBe(['ada@luna.example '.siteSentence('mail.trial.ends_today.ended_subject', SiteLanguage::English)])
        ->and(siteSentence('mail.trial.ends_today.ended_subject', SiteLanguage::English))->not->toBe(noticeSubject('ends_today'))
        ->and($seam->sent[0]->kind)->toBe(PlatformMessageKind::TrialNotice)
        ->and($seam->sent[0]->html)->toContain('<title>'.e(siteSentence('mail.trial.ends_today.ended_subject', SiteLanguage::English)).'</title>')
        ->and($seam->sent[0]->body)->toBe($body)
        ->and($seam->sent[0]->body)->not->toContain(siteSentence('mail.trial.ends_today.ends', SiteLanguage::English, ['business' => 'Luna Hair Studio', 'end' => $ended]))
        ->and($seam->sent[0]->html)->toContain($endedLine)
        ->and($seam->sent[0]->html)->toContain(siteSentence('mail.trial.what_ended', SiteLanguage::English))
        ->and($seam->sent[0]->html)->not->toContain(siteSentence('mail.trial.what_ends', SiteLanguage::English))
        ->and(noticeStamps('luna'))->toBe(['three_days' => null, 'ends_today' => '2026-10-07 10:00']);

    notifiedAt('2026-10-07 10:30');

    expect($seam->sent)->toHaveCount(1);

    app()->instance(Clock::class, FixedClock::at('2026-10-07 11:00'));
    expect(Artisan::call('billing:lapse'))->toBe(0);
    notifiedAt('2026-10-07 11:00');

    expect($seam->sent)->toHaveCount(1)
        ->and(DB::table('tenants')->where('slug', 'luna')->value('subscription_state'))->toBe('canceled')
        ->and(noticeStamps('luna'))->toBe(['three_days' => null, 'ends_today' => '2026-10-07 10:00']);
})->with([
    'an end at the run\'s own instant, which the lapse counts as passed' => ['2026-10-07 10:00:00+00', 'Wednesday 7 October 2026 at 13:00'],
    'an end half an hour behind the run' => ['2026-10-07 09:30:00+00', 'Wednesday 7 October 2026 at 12:30'],
    'an end two days behind, whose lapse never ran' => ['2026-10-05 10:00:00+00', 'Monday 5 October 2026 at 13:00'],
]);

it('tells a Greek row whose trial end has passed in Greek, the past end\'s three lines, its subject among them, read from lang/el through the translator, the end spelled by the formatter in the tenant\'s clock (P9F-51a)', function () {
    $seam = noticeSeam();
    noticeTenant('luna', 'Luna Hair Studio', '2026-10-07 09:00:00+00', ['ada@luna.example' => 'owner']);
    billingSet(['timezone' => 'America/New_York', 'locale' => 'el']);
    $formatter = new IntlDateFormatter('el', IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'America/New_York', IntlDateFormatter::GREGORIAN, 'EEEE d MMMM y');
    $spelled = siteSentence('mail.time.instant', SiteLanguage::Greek, ['date' => (string) $formatter->format(new DateTimeImmutable('2026-10-07 09:00:00', new DateTimeZone('UTC'))), 'time' => '05:00']);
    $subject = siteSentence('mail.trial.ends_today.ended_subject', SiteLanguage::Greek);
    $ended = siteSentence('mail.trial.ends_today.ended', SiteLanguage::Greek, ['business' => 'Luna Hair Studio', 'end' => $spelled]);
    $whatEnded = siteSentence('mail.trial.what_ended', SiteLanguage::Greek);

    notifiedAt('2026-10-07 10:00');

    expect($seam->sent)->toHaveCount(1)
        ->and($seam->sent[0]->subject)->toBe($subject)
        ->and($seam->sent[0]->html)->toContain('<title>'.e($subject).'</title>')
        ->and($seam->sent[0]->body)->toContain("\n\n".$ended."\n\n".$whatEnded."\n\n")
        ->and($seam->sent[0]->body)->not->toContain(siteSentence('mail.trial.what_ends', SiteLanguage::Greek))
        ->and($seam->sent[0]->html)->toContain('<html lang="el">')
        ->and($seam->sent[0]->html)->toContain(e($ended));
});

it('sends nothing to a tenant subscribed, canceled, closing or suspended, whatever its trial end reads', function (string $status, string $state, ?string $endsAt) {
    $seam = noticeSeam();
    noticeTenant('luna', 'Luna Hair Studio', '2026-10-08 09:00:00+00');
    billingSet(['status' => $status, 'subscription_state' => $state, 'trial_ends_at' => $endsAt]);

    notifiedAt('2026-10-07 10:00');

    expect($seam->sent)->toBe([])
        ->and(noticeStamps('luna'))->toBe(['three_days' => null, 'ends_today' => null]);
})->with([
    'subscribed, its trial end cleared' => ['active', 'active', null],
    'canceled after its trial' => ['active', 'canceled', '2026-10-07 09:00:00+00'],
    'a trial on a suspended row' => ['suspended', 'trial', '2026-10-08 09:00:00+00'],
    'a trial on a suspended row, its end passed' => ['suspended', 'trial', '2026-10-07 09:00:00+00'],
    'a closing row, its trial ended unpaid' => ['closing', 'canceled', '2026-09-06 09:00:00+00'],
]);

it('runs every hour in the console schedule, beside the lapse and the signup sweep', function () {
    $commands = array_values(array_filter(array_map(
        fn (Event $event): array => [$event->command ?? '', $event->expression],
        app(Schedule::class)->events(),
    ), fn (array $event): bool => str_contains($event[0], 'trial:notify')));

    expect($commands)->toHaveCount(1)
        ->and($commands[0][1])->toBe('0 * * * *');
});
