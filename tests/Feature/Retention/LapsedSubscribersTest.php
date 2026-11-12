<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Infrastructure\Db\SqlText;
use App\Notifications\AccountClosing;
use App\Notifications\BrandedMails;
use App\Platform\AccountClose;
use App\Platform\CloseRefused;
use App\Platform\ClosingWindow;
use App\Platform\LapsedClose;
use App\Retention\CheckoutRecordsLine;
use App\Retention\LapsedSubscribersLine;
use App\Retention\RetentionLine;
use App\Retention\RetentionSweep;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\Support\RecordingPlatformMessages;
use Tests\Support\SeamLog;

pest()->use(DatabaseTruncation::class);

/*
 * The lapsed subscribers, the stranded rows' first line: an active row left canceled with no trial
 * end, a former subscriber the hourly lapse never reaches, closed by retention:sweep after the
 * tenant lines and before the drop, thirty days after its subscriptions row's current_period_end,
 * or after the tenant row's updated_at where no period was recorded, through the one writer of a
 * close: the closing shape written under the row's lock with the rule asked again of the row as it
 * stands, nothing recorded and nothing cancelled at the provider, and the closing email's lapsed
 * subscriber's opening once per owner in the tenant's language. The drop ends it thirty days later.
 *
 * The demo's luna is the subscriber: a subscriptions row naming its customer cus_luna and, where
 * a period was recorded, its subscription sub_luna canceled at the provider, its row canceled with
 * no trial end as the sync leaves a former subscriber. Its boundary is the instant thirty days
 * before 2026-11-02 06:00 in UTC, the pinned clock 26 hours before the fixture's start, so the
 * close writes closing_at there and the window ends on Wednesday 2 December 2026 at 08:00 in
 * Athens. The boundary is proven on a clock spelled in UTC and on one spelled in Athens, where
 * thirty days counted on the wall clock across the autumn change of 25 October would sit an hour
 * away from the true start, and the row's other instant sits where a line reading it would fail:
 * a period's row was last updated a month before its period ended, so a line reading updated_at
 * would close it a second inside.
 *
 * The close enters the transaction runner and the drop drops a schema, so this file truncates.
 */

/** The instant thirty days before the pinned clock's close, in UTC: the boundary of a due row. */
const LAPSED_BOUNDARY = '2026-10-03 06:00:00';

/** The pinned clock's instant, in UTC, the sweep that closes a row at the boundary. */
const LAPSED_CLOSES_AT = '2026-11-02 06:00:00';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
    $this->seam = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $this->seam);
    $this->log = new SeamLog;
    app()->instance(LoggerInterface::class, $this->log);
});

afterEach(function () {
    DB::statement('alter table tenants drop constraint if exists lapsed_test_refuses_closing');
    DB::table('subscriptions')->delete();
    dropDemoSchemas();
    dropSiteDemo();

    foreach (['platform_audit_log', 'support_sessions', 'operators', 'webhook_events', 'payment_events', 'payment_routes', 'subscriptions', 'users', 'tenants'] as $table) {
        DB::table($table)->delete();
    }
});

/**
 * Leaves the tenant of the slug as the sync leaves a former subscriber: canceled with no trial
 * end, its plan and set as the last snapshot wrote them, its subscriptions row naming the customer
 * cus_{slug} and, where a period end is given, the subscription sub_{slug} canceled at the
 * provider with that period's end; where none is, no snapshot ever landed. The tenant row's
 * updated_at is the instant given, in UTC. Answers the tenant.
 */
function lapsedSubscriber(string $slug, ?string $periodEnd, string $updatedAt): Tenant
{
    $tenant = Tenant::query()->where('slug', $slug)->sole();

    DB::table('subscriptions')->insert([
        'tenant_id' => $tenant->id,
        'provider_customer_id' => 'cus_'.$slug,
        'provider_subscription_id' => $periodEnd === null ? null : 'sub_'.$slug,
        'status' => $periodEnd === null ? null : 'canceled',
        'current_period_end' => $periodEnd === null ? null : $periodEnd.'+00',
        'items' => $periodEnd === null ? '[]' : json_encode([['price' => 'price_staff_test', 'quantity' => 1]], JSON_THROW_ON_ERROR),
    ]);
    DB::table('tenants')->where('id', $tenant->id)->update([
        'subscription_state' => 'canceled',
        'trial_ends_at' => null,
        'plan_ref' => $periodEnd === null ? null : 'price_staff_test',
        'updated_at' => $updatedAt.'+00',
    ]);

    return $tenant;
}

/**
 * Runs the sweep on a clock bound at the instant given in the zone given, and answers its exit
 * code.
 */
function lapsedSweep(string $at, string $zone = 'UTC'): int
{
    app()->instance(Clock::class, FixedClock::at($at, $zone));

    return Artisan::call('retention:sweep');
}

/**
 * The closing columns of the tenant row of the id, closing_at in UTC.
 *
 * @return array<mixed>
 */
function lapsedColumns(int $tenantId): array
{
    return (array) DB::selectOne(sprintf(
        'select status, %s as closing_at, subscription_state, suspended_at, %s as updated_at from tenants where id = ?',
        SqlText::instantColumn('closing_at'),
        SqlText::instantColumn('updated_at'),
    ), [$tenantId]);
}

/**
 * What the lapsed subscribers' line logged, the stranded line of the tenants table, which names no
 * tenant (the drop's line names the one it dropped).
 *
 * @return list<array<mixed>>
 */
function lapsedLogged(SeamLog $log): array
{
    return array_values(array_filter($log->records, fn (array $record): bool => ($record['context']['table'] ?? null) === 'tenants' && ! isset($record['context']['tenant'])));
}

/**
 * Each message the seam was handed, as its address and kind.
 *
 * @return list<string>
 */
function lapsedSent(RecordingPlatformMessages $seam): array
{
    return array_map(fn (PlatformMessage $message): string => $message->toAddress.' '.$message->kind->value, $seam->sent);
}

/**
 * An instant as the platform's mails spell it in a language, in Athens.
 */
function lapsedInstant(string $utc, SiteLanguage $language = SiteLanguage::English): string
{
    return app(BrandedMails::class)->in($language)->instant(new DateTimeImmutable($utc, new DateTimeZone('UTC')), new DateTimeZone('Europe/Athens'));
}

/** The lapsed subscriber's closer as the line builds it, on the clock bound now. */
function lapsedCloser(): LapsedClose
{
    return new LapsedClose(app(AccountClosing::class), app(Clock::class));
}

it('closes a former subscriber at thirty days from {case}, keeping it whole a second inside: closing_at the clock\'s now and the status closing, no audit row, nothing asked of the provider, logged by its table and its count', function (?string $periodEnd, string $updatedAt, string $clock) {
    $luna = lapsedSubscriber('luna', $periodEnd, $updatedAt);
    $trail = DB::table('tenant_luna.audit_log')->count();
    $before = lapsedColumns($luna->id);
    [$inside, $at, $zone] = $clock === 'athens'
        ? ['2026-11-02 07:59:59', '2026-11-02 08:00:00', 'Europe/Athens']
        : ['2026-11-02 05:59:59', LAPSED_CLOSES_AT, 'UTC'];

    expect(lapsedSweep($inside, $zone))->toBe(Command::SUCCESS)
        ->and(lapsedColumns($luna->id))->toBe($before)
        ->and(lapsedLogged($this->log))->toBe([['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'tenants', 'count' => 0]]])
        ->and($this->seam->sent)->toBe([]);

    $this->log->records = [];

    expect(lapsedSweep($at, $zone))->toBe(Command::SUCCESS)
        ->and(lapsedColumns($luna->id))->toMatchArray(['status' => 'closing', 'closing_at' => SqlText::instant(new DateTimeImmutable(LAPSED_CLOSES_AT, new DateTimeZone('UTC'))), 'subscription_state' => 'canceled', 'suspended_at' => null])
        ->and(lapsedLogged($this->log))->toBe([['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'tenants', 'count' => 1]]])
        ->and($this->log->errors())->toBe([])
        ->and(lapsedSent($this->seam))->toBe(['owner@luna.example closing'])
        ->and(DB::table('platform_audit_log')->count())->toBe(0)
        ->and(DB::table('tenant_luna.audit_log')->count())->toBe($trail)
        ->and(billingFake()->asked)->toBe([])
        ->and(Artisan::output())->toContain('tenants: 1 row swept.');
})->with([
    'its last period\'s end, its row last updated a month before it' => ['2026-10-03 06:00:00', '2026-09-03 06:00:00'],
    'its row\'s last update, no period ever recorded' => [null, LAPSED_BOUNDARY],
])->with([
    'on a clock spelled in UTC' => ['utc'],
    'on a clock spelled in Athens, the autumn change inside the thirty days' => ['athens'],
]);

it('closes every due row in id order, one close through the writer each, and counts them', function () {
    $luna = lapsedSubscriber('luna', '2026-09-01 06:00:00', '2026-09-01 06:00:00');
    $pier = lapsedSubscriber('pier', null, '2026-08-01 06:00:00');

    expect(lapsedSweep(LAPSED_CLOSES_AT))->toBe(Command::SUCCESS)
        ->and(lapsedColumns($luna->id)['status'])->toBe('closing')
        ->and(lapsedColumns($pier->id)['status'])->toBe('closing')
        ->and(lapsedSent($this->seam))->toBe(['owner@luna.example closing', 'owner@pier.example closing'])
        ->and(lapsedLogged($this->log))->toBe([['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'tenants', 'count' => 2]]]);
});

it('leaves alone every row that is no lapsed subscriber, however long past: {case}', function (Closure $shape) {
    $luna = lapsedSubscriber('luna', '2026-01-01 06:00:00', '2026-01-01 06:00:00');
    $shape($luna);
    $before = lapsedColumns($luna->id);

    expect(lapsedSweep(LAPSED_CLOSES_AT))->toBe(Command::SUCCESS)
        ->and(lapsedColumns($luna->id))->toBe($before)
        ->and(lapsedLogged($this->log))->toBe([['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'tenants', 'count' => 0]]])
        ->and($this->seam->sent)->toBe([])
        ->and($this->log->errors())->toBe([]);
})->with([
    'a trial that ended unpaid, which the hourly lapse closes by its trial end' => [fn (Tenant $tenant) => DB::table('tenants')->where('id', $tenant->id)->update(['trial_ends_at' => '2026-01-01 06:00:00+00'])],
    'a trial still running' => [fn (Tenant $tenant) => DB::table('tenants')->where('id', $tenant->id)->update(['subscription_state' => 'trial', 'trial_ends_at' => '2026-11-05 06:00:00+00'])],
    'a former subscriber the console suspended, as the lapse leaves one' => [fn (Tenant $tenant) => DB::table('tenants')->where('id', $tenant->id)->update(['status' => TenantStatus::Suspended->value, 'suspended_at' => '2026-02-01 06:00:00+00', 'suspension_reason' => 'Chargebacks under review'])],
    'a former subscriber already closing' => [fn (Tenant $tenant) => DB::table('tenants')->where('id', $tenant->id)->update(['status' => TenantStatus::Closing->value, 'closing_at' => '2026-10-30 06:00:00+00'])],
    'a subscriber whose subscription is active' => [fn (Tenant $tenant) => DB::table('tenants')->where('id', $tenant->id)->update(['subscription_state' => 'active'])],
    'a subscriber past due' => [fn (Tenant $tenant) => DB::table('tenants')->where('id', $tenant->id)->update(['subscription_state' => 'past_due'])],
    'a subscriber whose first charge is still pending' => [fn (Tenant $tenant) => DB::table('tenants')->where('id', $tenant->id)->update(['subscription_state' => 'incomplete'])],
    'the free shape, no subscriptions row' => [function (Tenant $tenant) {
        DB::table('subscriptions')->where('tenant_id', $tenant->id)->delete();
        DB::table('tenants')->where('id', $tenant->id)->update(['subscription_state' => 'active', 'plan_ref' => null]);
    }],
]);

it('never reaches the demo tenant demo:reset rebuilds, even on a row somehow left a lapsed subscriber', function () {
    expect(Artisan::call('demo:reset'))->toBe(Command::SUCCESS);

    $demo = lapsedSubscriber(siteDemoSlug()->value, '2026-01-01 06:00:00', '2026-01-01 06:00:00');
    $before = lapsedColumns($demo->id);

    expect(lapsedSweep(LAPSED_CLOSES_AT))->toBe(Command::SUCCESS)
        ->and(lapsedColumns($demo->id))->toBe($before)
        ->and(lapsedLogged($this->log))->toBe([['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'tenants', 'count' => 0]]])
        ->and($this->seam->sent)->toBe([]);
});

it('asks the rule again of the row as it stands under the lock, closing nothing {case} since the line read it: nothing written, nothing asked of the provider, nobody told', function (Closure $moved) {
    $luna = lapsedSubscriber('luna', LAPSED_BOUNDARY, '2026-09-03 06:00:00');
    app()->instance(Clock::class, FixedClock::at(LAPSED_CLOSES_AT));
    $moved($luna);
    $before = lapsedColumns($luna->id);

    expect(fn () => app(AccountClose::class)->close($luna, lapsedCloser()))->toThrow(CloseRefused::class, CloseRefused::NOT_DUE)
        ->and(lapsedColumns($luna->id))->toBe($before)
        ->and(billingFake()->asked)->toBe([])
        ->and($this->seam->sent)->toBe([]);
})->with([
    'where the way back\'s subscription landed active' => [fn (Tenant $tenant) => DB::table('tenants')->where('id', $tenant->id)->update(['subscription_state' => 'active'])],
    'where a later subscription ended canceled with a period still inside the thirty days' => [fn (Tenant $tenant) => DB::table('subscriptions')->where('tenant_id', $tenant->id)->update(['current_period_end' => '2026-10-20 06:00:00+00'])],
    'where the console suspended it' => [fn (Tenant $tenant) => DB::table('tenants')->where('id', $tenant->id)->update(['status' => TenantStatus::Suspended->value, 'suspended_at' => '2026-11-01 06:00:00+00', 'suspension_reason' => 'Chargebacks under review'])],
]);

it('mails every owner once, and nobody else, the lapsed subscriber\'s opening naming the day its last billing period ended in the tenant\'s clock, the window\'s end with the zone, what stays and the Billing screen as the way back', function () {
    lapsedSubscriber('luna', LAPSED_BOUNDARY, '2026-09-03 06:00:00');
    addTenantUser('second@luna.example', 'owner');
    addTenantUser('manager@luna.example', 'manager');
    addTenantUser('staff@luna.example', 'staff');

    lapsedSweep(LAPSED_CLOSES_AT);

    expect(lapsedSent($this->seam))->toBe(['owner@luna.example closing', 'second@luna.example closing']);
    $message = $this->seam->sent[0];

    expect($message->kind)->toBe(PlatformMessageKind::Closing)
        ->and($message->tenantId)->toBe(LunaFixture::tenant()->id)
        ->and($message->toName)->toBe('Demo Owner')
        ->and($message->replyTo)->toBeNull()
        ->and($message->subject)->toBe('Your account closes in 30 days')
        ->and($message->body)->toBe(implode("\n", [
            'Hello Demo Owner,',
            '',
            'The subscription for Luna Hair Studio ended on Saturday 3 October 2026 at 09:00, at the close of its last billing period, and no plan has been chosen since, so the account is now closing.',
            '',
            'We keep it for 30 days, until Wednesday 2 December 2026 at 08:00.',
            'Until then your bookings keep running and your booking page stays open, and changes to your setup stay paused. After that, the data in your account is deleted.',
            '',
            'To keep your account, choose a plan on the Billing screen before then:',
            '',
            tenantUrl('luna', '/admin/billing'),
            '',
            'Times are in Europe/Athens, the clock you keep.',
        ]))
        ->and($message->html)->toContain('<html lang="en">')
        ->and($message->html)->toContain('<a href="'.tenantUrl('luna', '/admin/billing').'"')
        ->and($message->html)->toContain('ended on Saturday 3 October 2026 at 09:00, at the close of its last billing period')
        ->and($message->html)->toContain(siteSentence('mail.closing.reason', SiteLanguage::English, ['business' => 'Luna Hair Studio']))
        ->and($this->seam->sent[1]->body)->toStartWith("Hello second@luna.example,\n\nThe subscription for Luna Hair Studio ended on Saturday 3 October 2026 at 09:00");
});

it('says the subscription ended with no billing period on record where none was, the way back the same', function () {
    lapsedSubscriber('luna', null, LAPSED_BOUNDARY);

    lapsedSweep(LAPSED_CLOSES_AT);

    expect($this->seam->sent)->toHaveCount(1)
        ->and($this->seam->sent[0]->body)->toStartWith(implode("\n", [
            'Hello Demo Owner,',
            '',
            'The subscription for Luna Hair Studio ended with no billing period on record, and no plan has been chosen since, so the account is now closing.',
            '',
            'We keep it for 30 days, until Wednesday 2 December 2026 at 08:00.',
        ]))
        ->and($this->seam->sent[0]->body)->toContain("To keep your account, choose a plan on the Billing screen before then:\n\n".tenantUrl('luna', '/admin/billing'));
});

it('says the subscription ended because its first payment never went through where the provider expired the first charge, naming no period, the way back the same', function () {
    $luna = lapsedSubscriber('luna', LAPSED_BOUNDARY, '2026-09-03 06:00:00');
    DB::table('subscriptions')->where('tenant_id', $luna->id)->update(['status' => 'incomplete_expired']);

    lapsedSweep(LAPSED_CLOSES_AT);

    expect($this->seam->sent)->toHaveCount(1)
        ->and($this->seam->sent[0]->body)->toStartWith(implode("\n", [
            'Hello Demo Owner,',
            '',
            'The subscription for Luna Hair Studio ended because its first payment never went through, and no plan has been chosen since, so the account is now closing.',
            '',
            'We keep it for 30 days, until Wednesday 2 December 2026 at 08:00.',
        ]))
        ->and($this->seam->sent[0]->body)->not->toContain('billing period')
        ->and($this->seam->sent[0]->body)->toContain("To keep your account, choose a plan on the Billing screen before then:\n\n".tenantUrl('luna', '/admin/billing'));
});

it('mails a Greek row\'s owners in Greek, the lapsed subscriber\'s lines from lang/el and the instants spelled in Greek in the tenant\'s clock, {case}', function (?string $periodEnd, string $opening) {
    $luna = lapsedSubscriber('luna', $periodEnd, LAPSED_BOUNDARY);
    $replace = $periodEnd === null || $opening === 'lapsed_unpaid' ? [] : ['end' => lapsedInstant($periodEnd, SiteLanguage::Greek)];

    if ($opening === 'lapsed_unpaid') {
        DB::table('subscriptions')->where('tenant_id', $luna->id)->update(['status' => 'incomplete_expired']);
    }

    billingSet(['locale' => 'el']);

    lapsedSweep(LAPSED_CLOSES_AT);

    $message = $this->seam->sent[0];

    expect($this->seam->sent)->toHaveCount(1)
        ->and($message->subject)->toBe(mailChoice('mail.closing.subject', ClosingWindow::days(), SiteLanguage::Greek))
        ->and($message->body)->toContain(siteSentence('mail.closing.'.$opening, SiteLanguage::Greek, ['business' => 'Luna Hair Studio', ...$replace]))
        ->and($message->body)->toContain(pageChoice('mail.closing.kept', ClosingWindow::days(), ['until' => lapsedInstant('2026-12-02 06:00', SiteLanguage::Greek)], SiteLanguage::Greek))
        ->and($message->body)->toContain(siteSentence('mail.closing.billing', SiteLanguage::Greek))
        ->and($message->body)->not->toContain('The subscription for')
        ->and($message->html)->toContain('<html lang="el">');
})->with([
    'the day its last billing period ended' => [LAPSED_BOUNDARY, 'lapsed'],
    'no billing period on record' => [null, 'lapsed_unrecorded'],
    'a first payment that never went through' => [LAPSED_BOUNDARY, 'lapsed_unpaid'],
]);

it('closes nothing and mails nothing a second time, in the same instant or a day on, the closing standing as written', function () {
    $luna = lapsedSubscriber('luna', LAPSED_BOUNDARY, '2026-09-03 06:00:00');
    lapsedSweep(LAPSED_CLOSES_AT);
    $closed = lapsedColumns($luna->id);
    $this->log->records = [];

    expect(lapsedSweep(LAPSED_CLOSES_AT))->toBe(Command::SUCCESS)
        ->and(lapsedSweep('2026-11-03 06:00:00'))->toBe(Command::SUCCESS)
        ->and(lapsedColumns($luna->id))->toBe($closed)
        ->and($this->seam->sent)->toHaveCount(1)
        ->and(lapsedLogged($this->log))->toBe([
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'tenants', 'count' => 0]],
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'tenants', 'count' => 0]],
        ]);
});

it('stops at a close that throws, the rows closed before it standing with their owners told, logs the throw once by its table, its class and its SQLSTATE alone, runs neither the checkout records nor the drop, and the next run closes the rest', function () {
    $luna = lapsedSubscriber('luna', LAPSED_BOUNDARY, '2026-09-03 06:00:00');
    $pier = lapsedSubscriber('pier', LAPSED_BOUNDARY, '2026-09-03 06:00:00');
    DB::table('subscriptions')->where('tenant_id', $pier->id)->update(['checkout_session_id' => 'cs_pier', 'checkout_url' => 'https://checkout.fake.test/cs_pier', 'checkout_opened_at' => '2026-10-01 06:00:00+00']);
    // pier's write of the closing shape is refused as a statement can be: the database answers it
    // with an SQLSTATE and a message that spells the row.
    DB::statement("alter table tenants add constraint lapsed_test_refuses_closing check (status <> 'closing' or slug <> 'pier')");

    expect(lapsedSweep(LAPSED_CLOSES_AT))->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('tenants: the line failed, and the sweep stopped.')
        ->and(lapsedColumns($luna->id)['status'])->toBe('closing')
        ->and(lapsedColumns($pier->id)['status'])->toBe('active')
        ->and(lapsedColumns($pier->id)['closing_at'])->toBeNull()
        ->and(lapsedSent($this->seam))->toBe(['owner@luna.example closing'])
        ->and($this->log->errors())->toBe([
            ['level' => 'error', 'message' => 'The retention sweep stopped', 'context' => ['table' => 'tenants', 'exception' => QueryException::class, 'sqlstate' => '23514']],
        ])
        ->and(collect($this->log->records)->contains(fn (array $record): bool => ($record['context']['table'] ?? null) === 'subscriptions'))->toBeFalse()
        ->and(DB::table('subscriptions')->where('tenant_id', $pier->id)->value('checkout_session_id'))->toBe('cs_pier');

    DB::statement('alter table tenants drop constraint lapsed_test_refuses_closing');
    $this->log->records = [];

    expect(lapsedSweep('2026-11-03 06:00:00'))->toBe(Command::SUCCESS)
        ->and(lapsedColumns($pier->id)['status'])->toBe('closing')
        ->and(lapsedSent($this->seam))->toBe(['owner@luna.example closing', 'owner@pier.example closing'])
        ->and(lapsedLogged($this->log))->toBe([['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'tenants', 'count' => 1]]])
        ->and(DB::table('subscriptions')->where('tenant_id', $pier->id)->value('checkout_session_id'))->toBeNull();
});

it('logs and prints nothing of a row: no business, owner, address, customer or subscription of a row it closed', function () {
    lapsedSubscriber('luna', LAPSED_BOUNDARY, '2026-09-03 06:00:00');

    lapsedSweep(LAPSED_CLOSES_AT);
    $output = Artisan::output();
    $closing = array_values(array_filter($this->log->records, fn (array $record): bool => ! isset($record['context']['tenant'])));

    expect($this->seam->sent)->toHaveCount(1);

    foreach (['Luna Hair Studio', 'owner@luna.example', 'Demo Owner', 'cus_luna', 'sub_luna'] as $content) {
        expect($this->log->text())->not->toContain($content)
            ->and($output)->not->toContain($content);
    }

    foreach ($closing as $record) {
        expect(array_keys($record['context']))->toBe(['table', 'count']);
    }
});

it('drops the closed row thirty days after the close through the drop line, the stranded row ended for good', function () {
    $luna = lapsedSubscriber('luna', LAPSED_BOUNDARY, '2026-09-03 06:00:00');
    lapsedSweep(LAPSED_CLOSES_AT);

    expect(lapsedSweep('2026-12-02 05:59:59'))->toBe(Command::SUCCESS)
        ->and(DB::table('tenants')->where('id', $luna->id)->value('status'))->toBe('closing')
        ->and(DB::table('pg_namespace')->where('nspname', 'tenant_luna')->exists())->toBeTrue();

    expect(lapsedSweep('2026-12-02 06:00:00'))->toBe(Command::SUCCESS)
        ->and(DB::table('tenants')->where('id', $luna->id)->first(['status', 'slug']))->toEqual((object) ['status' => 'closed', 'slug' => null])
        ->and(DB::table('pg_namespace')->where('nspname', 'tenant_luna')->exists())->toBeFalse()
        ->and(DB::table('users')->where('tenant_id', $luna->id)->count())->toBe(0)
        ->and(DB::table('subscriptions')->where('tenant_id', $luna->id)->value('provider_customer_id'))->toBe('cus_luna')
        ->and($this->seam->sent)->toHaveCount(1)
        ->and($this->log->errors())->toBe([]);
});

it('is the stranded rows\' first line, after the tenant lines and before the checkout records and the drop', function () {
    expect(array_map(fn (RetentionLine $line): string => $line::class, app(RetentionSweep::class)->strandedLines()))->toBe([LapsedSubscribersLine::class, CheckoutRecordsLine::class])
        ->and(app(LapsedSubscribersLine::class)->table())->toBe('tenants')
        ->and(LapsedClose::CLOSES_AFTER)->toBe('P30D');
});
