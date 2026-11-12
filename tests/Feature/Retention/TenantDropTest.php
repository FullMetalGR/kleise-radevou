<?php

use App\Application\Clock;
use App\Application\Notification\PlatformMessenger;
use App\Billing\NoticeKind;
use App\Billing\SignupCompletion;
use App\Console\Commands\RetentionSweepCommand;
use App\Http\ConsoleSession;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\TenantImages;
use App\Jobs\JobTenant;
use App\Jobs\SendReminder;
use App\Platform\AccountClose;
use App\Platform\OwnerClose;
use App\Platform\PlatformAuditAction;
use App\Platform\SupportSessions;
use App\Platform\TenantDrop;
use App\Tenancy\SignupVerification;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Psr\Log\LoggerInterface;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FakeDisks;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\Support\RecordingPlatformMessages;
use Tests\Support\SeamLog;

pest()->use(DatabaseTruncation::class);

/*
 * The drop, the retention sweep's last line: thirty days after a tenant closed, its schema
 * dropped, its users, payment routes and support sessions deleted, the billing notifications
 * naming its provider customer deleted and its uploaded images deleted from the disk, the central
 * row written closed with closed_at and its slug released, the subscriptions row, the platform
 * audit rows and the payment events kept, one transaction per tenant in id order, a neighbouring
 * tenant whole.
 *
 * luna is the tenant with everything: a second user, a payment connection with its route, a
 * support session and its platform audit row, a logo and a cover on the disk, a subscriptions row
 * naming its provider customer and a notification naming it, a payment event, a booking with its
 * items, its intake row and its trail. pier, the demo's other tenant, holds the same of its own
 * and must come through whole. luna is closed by the one writer of a close, the owner's, on the
 * pinned clock 26 hours before the fixture's start, 2026-11-02 06:00 in UTC, so the window ends
 * at 2026-12-02 06:00 in UTC, and the sweep runs on a clock bound at or past that instant. The
 * billing notifications are stored in the fake's own shape, which names the customer at its top,
 * so the drop reads the bound adapter's shape (BillingProvider::customerPath()).
 *
 * The drop drops a schema and the close enters the transaction runner, so this file truncates.
 */

/** The instant luna's window ends, thirty days after the pinned clock's close, in UTC. */
const DROP_WINDOW_ENDS = '2026-12-02 06:00:00';

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    FakeDisks::fake(TenantImages::DISK);
    FixtureDay::pinClock();
    app()->instance(PlatformMessenger::class, new RecordingPlatformMessages);
    $this->log = new SeamLog;
    app()->instance(LoggerInterface::class, $this->log);
});

afterEach(function () {
    dropDemoSchemas();
    dropSiteDemo();

    foreach (['platform_audit_log', 'support_sessions', 'operators', 'webhook_events', 'payment_events', 'payment_routes', 'subscriptions', 'users', 'tenants'] as $table) {
        DB::table($table)->delete();
    }
});

/**
 * Everything a tenant holds, written for the slug: a second user, a connection with its route, a
 * support session opened by the operator with its platform audit row, a logo and a cover on the
 * disk named on the settings row, a subscriptions row naming the customer cus_{slug}, a
 * notification naming that customer, a payment event naming the tenant, and a booking with its
 * item, its intake row and its trail. Answers the tenant.
 */
function dropEverything(string $slug): Tenant
{
    $tenant = Tenant::query()->where('slug', $slug)->sole();
    $schema = $tenant->schema_name;
    $images = app(TenantImages::class);

    DB::table('users')->insert(['tenant_id' => $tenant->id, 'name' => 'Sam Staff', 'email' => 'sam@'.$slug.'.example', 'password' => bcrypt('correct horse battery staple'), 'role' => 'staff']);
    connectPaymentFake(slug: $slug);
    app(SupportSessions::class)->open(addOperator('pat.'.$slug.'@inbox.example'), $tenant);
    settingsOf($slug)->update([
        'brand_logo_path' => $images->store($tenant, ImageKind::Logo, UploadedFile::fake()->image('logo.png', 60, 40)),
        'cover_path' => $images->store($tenant, ImageKind::Cover, UploadedFile::fake()->image('cover.png', 120, 80)),
    ]);
    DB::table('subscriptions')->insert(['tenant_id' => $tenant->id, 'provider_customer_id' => 'cus_'.$slug]);
    DB::table('webhook_events')->insert(['provider_event_id' => 'evt_'.$slug, 'type' => 'fake.subscription_changed', 'payload' => FakeBillingProvider::notice('evt_'.$slug, NoticeKind::SubscriptionChanged, 'cus_'.$slug, 'sub_'.$slug)]);
    DB::table('payment_events')->insert(['provider' => 'fake', 'route' => 'own', 'tenant_id' => $tenant->id, 'event_id' => 'pay_'.$slug, 'type' => 'payment.succeeded']);

    $booking = DB::table($schema.'.bookings')->insertGetId([...bookingRow(), 'status' => 'confirmed']);
    DB::table($schema.'.booking_items')->insert(itemRow($booking, implicitStaffId($schema), '2026-11-20 08:00:00+00:00', '2026-11-20 09:00:00+00:00'));
    DB::table($schema.'.booking_meta')->insert(['booking_id' => $booking, 'key' => 'note', 'value' => 'By the window, please.']);
    DB::table($schema.'.audit_log')->insert(['booking_id' => $booking, 'actor' => 'customer', 'action' => 'confirmed', 'payload' => '{}']);

    return $tenant;
}

/** Closes luna as its owner does from the Settings Danger zone, on the clock bound now. */
function dropClose(): Tenant
{
    $luna = LunaFixture::tenant();
    app(AccountClose::class)->close($luna, app(OwnerClose::class));

    return $luna;
}

/**
 * Runs the sweep on a clock bound at the instant given, in UTC, and answers its exit code.
 */
function dropSweep(string $at): int
{
    app()->instance(Clock::class, FixedClock::at($at));

    return Artisan::call('retention:sweep');
}

/**
 * How many rows of each central table a tenant's rows sit in, and whether its schema stands.
 *
 * @return array<string, int|bool>
 */
function dropHoldings(int $tenantId, string $schema, string $customer): array
{
    $count = fn (string $table): int => DB::table($table)->where('tenant_id', $tenantId)->count();

    return [
        'schema' => DB::table('pg_namespace')->where('nspname', $schema)->exists(),
        'users' => $count('users'),
        'payment_routes' => $count('payment_routes'),
        'support_sessions' => $count('support_sessions'),
        'webhook_events' => DB::table('webhook_events')->whereRaw("payload->>'customer' = ?", [$customer])->count(),
        'subscriptions' => $count('subscriptions'),
        'platform_audit_log' => $count('platform_audit_log'),
        'payment_events' => $count('payment_events'),
    ];
}

/**
 * The files a tenant's directory holds on the faked public disk.
 *
 * @return list<string>
 */
function dropFiles(string $slug): array
{
    return array_values(Storage::disk(TenantImages::DISK)->allFiles('tenants/'.$slug));
}

/** A logger bound afresh, so a case reads what was logged after this point alone. */
function dropFreshLog(): SeamLog
{
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);

    return $log;
}

/**
 * The tenants the log's lines name, each once, in the order first named.
 *
 * @return list<int|string>
 */
function dropTenantsLogged(SeamLog $log): array
{
    $named = [];

    foreach ($log->records as $record) {
        $tenant = $record['context']['tenant'] ?? null;

        if ((is_int($tenant) || is_string($tenant)) && ! in_array($tenant, $named, true)) {
            $named[] = $tenant;
        }
    }

    return $named;
}

it('drops a tenant thirty days after its close, leaving nothing but the central row and its subscriptions row, its neighbour whole', function () {
    $luna = dropEverything('luna');
    $pier = dropEverything('pier');
    DB::table('webhook_events')->insert(['provider_event_id' => 'evt_nobody', 'type' => 'fake.other', 'payload' => FakeBillingProvider::notice('evt_nobody', NoticeKind::Other)]);
    $pierBefore = dropHoldings($pier->id, 'tenant_pier', 'cus_pier');
    $pierBookings = DB::table('tenant_pier.bookings')->count();

    expect(dropHoldings($luna->id, 'tenant_luna', 'cus_luna'))->toBe(['schema' => true, 'users' => 2, 'payment_routes' => 1, 'support_sessions' => 1, 'webhook_events' => 1, 'subscriptions' => 1, 'platform_audit_log' => 1, 'payment_events' => 1])
        ->and(dropFiles('luna'))->toHaveCount(2);

    // The acceptance of the terms is no personal data and stays on the row.
    DB::table('tenants')->where('id', $luna->id)->update(['terms_accepted_at' => '2026-10-01 09:00:00+00:00', 'terms_version' => '2026-09-15']);
    dropClose();
    $code = dropSweep(DROP_WINDOW_ENDS);
    $row = (array) DB::selectOne(sprintf('select status, slug, name, schema_name, %s as closed_at, %s as closing_at, %s as terms_accepted_at, terms_version::text as terms_version from tenants where id = ?', SqlText::instantColumn('closed_at'), SqlText::instantColumn('closing_at'), SqlText::instantColumn('terms_accepted_at')), [$luna->id]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(dropHoldings($luna->id, 'tenant_luna', 'cus_luna'))->toBe(['schema' => false, 'users' => 0, 'payment_routes' => 0, 'support_sessions' => 0, 'webhook_events' => 0, 'subscriptions' => 1, 'platform_audit_log' => 1, 'payment_events' => 1])
        ->and(dropFiles('luna'))->toBe([])
        ->and(Storage::disk(TenantImages::DISK)->directoryExists('tenants/luna'))->toBeFalse()
        ->and($row)->toBe([
            'status' => 'closed',
            'slug' => null,
            'name' => $luna->name,
            'schema_name' => 'tenant_luna',
            'closed_at' => SqlText::instant(new DateTimeImmutable(DROP_WINDOW_ENDS, new DateTimeZone('UTC'))),
            'closing_at' => SqlText::instant(new DateTimeImmutable('2026-11-02 06:00:00', new DateTimeZone('UTC'))),
            'terms_accepted_at' => SqlText::instant(new DateTimeImmutable('2026-10-01 09:00:00', new DateTimeZone('UTC'))),
            'terms_version' => '2026-09-15',
        ])
        ->and(DB::table('platform_audit_log')->where('tenant_id', $luna->id)->value('action'))->toBe(PlatformAuditAction::SupportOpened->value)
        ->and(DB::table('subscriptions')->where('tenant_id', $luna->id)->value('provider_customer_id'))->toBe('cus_luna')
        ->and(DB::table('webhook_events')->where('provider_event_id', 'evt_nobody')->exists())->toBeTrue()
        ->and(dropHoldings($pier->id, 'tenant_pier', 'cus_pier'))->toBe($pierBefore)
        ->and(DB::table('tenant_pier.bookings')->count())->toBe($pierBookings)
        ->and(DB::table('tenant_pier.booking_meta')->count())->toBe(1)
        ->and(dropFiles('pier'))->toHaveCount(2)
        ->and(Tenant::query()->whereKey($pier->id)->sole()->status)->toBe(TenantStatus::Active)
        ->and(Tenant::query()->whereKey($pier->id)->sole()->slug)->toBe('pier')
        ->and($this->log->errors())->toBe([])
        // The drop's line names the tenant it dropped; the lapsed subscribers' line logs the same
        // table by its count alone.
        ->and(array_values(array_filter($this->log->records, fn (array $record): bool => ($record['context']['table'] ?? null) === RetentionSweepCommand::DROPPED && isset($record['context']['tenant']))))->toBe([[
            'level' => 'info',
            'message' => 'Retention sweep',
            'context' => ['tenant' => $luna->id, 'table' => 'tenants', 'schema' => 'tenant_luna', 'users' => 2, 'payment_routes' => 1, 'support_sessions' => 1, 'webhook_events' => 1],
        ]])
        ->and(Artisan::output())->toContain('tenant_luna: dropped, tenant '.$luna->id.' closed; 2 users, 1 payment routes, 1 support sessions and 1 webhook events deleted.');
});

it('keeps a closed tenant whole a second inside its window, the row closing and its schema, its users and its files standing, and drops it at the window\'s end', function () {
    $luna = dropEverything('luna');
    dropClose();

    expect(dropSweep('2026-12-02 05:59:59'))->toBe(Command::SUCCESS)
        ->and(dropHoldings($luna->id, 'tenant_luna', 'cus_luna'))->toBe(['schema' => true, 'users' => 2, 'payment_routes' => 1, 'support_sessions' => 1, 'webhook_events' => 1, 'subscriptions' => 1, 'platform_audit_log' => 1, 'payment_events' => 1])
        ->and(dropFiles('luna'))->toHaveCount(2)
        ->and(DB::table('tenants')->where('id', $luna->id)->value('status'))->toBe('closing')
        ->and(DB::table('tenants')->where('id', $luna->id)->value('slug'))->toBe('luna');

    expect(dropSweep(DROP_WINDOW_ENDS))->toBe(Command::SUCCESS)
        ->and(DB::table('tenants')->where('id', $luna->id)->value('status'))->toBe('closed')
        ->and(dropHoldings($luna->id, 'tenant_luna', 'cus_luna')['schema'])->toBeFalse();
});

it('keeps a tenant twenty-nine days into its window whole beside one past it, dropping in id order the tenants due alone', function () {
    $luna = dropEverything('luna');
    $pier = dropEverything('pier');
    DB::table('tenants')->where('id', $luna->id)->update(['status' => 'closing', 'closing_at' => '2026-11-01 06:00:00+00:00']);
    DB::table('tenants')->where('id', $pier->id)->update(['status' => 'closing', 'closing_at' => '2026-11-03 06:00:00+00:00']);

    expect(app(TenantDrop::class)->due())->toBe([]);

    app()->instance(Clock::class, FixedClock::at('2026-12-02 06:00:00'));

    expect(app(TenantDrop::class)->due())->toBe([$luna->id])
        ->and(dropSweep('2026-12-02 06:00:00'))->toBe(Command::SUCCESS)
        ->and(DB::table('tenants')->where('id', $luna->id)->value('status'))->toBe('closed')
        ->and(DB::table('tenants')->where('id', $pier->id)->value('status'))->toBe('closing')
        ->and(dropHoldings($pier->id, 'tenant_pier', 'cus_pier'))->toBe(['schema' => true, 'users' => 2, 'payment_routes' => 1, 'support_sessions' => 1, 'webhook_events' => 1, 'subscriptions' => 1, 'platform_audit_log' => 1, 'payment_events' => 1]);
});

it('drops a row a reopening took first nothing, answering null, since the drop reads the row again under its lock', function () {
    $luna = dropClose();
    app()->instance(Clock::class, FixedClock::at(DROP_WINDOW_ENDS));
    $due = app(TenantDrop::class)->due();
    DB::table('tenants')->where('id', $luna->id)->update(['status' => 'active', 'closing_at' => null]);

    expect($due)->toBe([$luna->id])
        ->and(app(TenantDrop::class)->drop($luna->id))->toBeNull()
        ->and(DB::table('pg_namespace')->where('nspname', 'tenant_luna')->exists())->toBeTrue()
        ->and(DB::table('tenants')->where('id', $luna->id)->value('slug'))->toBe('luna');
});

it('leaves the closed row out of every tenant line of the sweep after its drop, the next run sweeping the neighbour and dropping nothing twice', function () {
    $luna = dropClose();
    dropSweep(DROP_WINDOW_ENDS);
    $log = dropFreshLog();

    expect(dropSweep('2026-12-03 06:00:00'))->toBe(Command::SUCCESS)
        ->and($log->errors())->toBe([])
        ->and(dropTenantsLogged($log))->toBe(['pier'])
        ->and(DB::table('tenants')->where('id', $luna->id)->value('status'))->toBe('closed');
});

it('signs up again under a released slug: the reservation finds no live row under it and the build takes the schema name the closed row keeps', function () {
    $luna = dropEverything('luna');
    dropClose();
    dropSweep(DROP_WINDOW_ENDS);

    $reserved = app(SignupVerification::class)->reserve(signupFor('luna', ['name' => 'Luna Again', 'ownerEmail' => 'owner@luna.example']));
    $built = app(SignupVerification::class)->verify($reserved['token']);
    $again = Tenant::query()->where('slug', 'luna')->sole();

    expect($reserved['tenant']->id)->not->toBe($luna->id)
        ->and($built?->built)->toBeTrue()
        ->and($again->id)->toBe($reserved['tenant']->id)
        ->and($again->status)->toBe(TenantStatus::Active)
        ->and($again->schema_name)->toBe('tenant_luna')
        ->and(DB::table('tenant_luna.bookings')->count())->toBe(0)
        ->and(DB::table('tenants')->where('schema_name', 'tenant_luna')->orderBy('id')->pluck('status')->all())->toBe(['closed', 'active'])
        ->and(dropFiles('luna'))->toBe([]);
});

it('lists the dropped row and reads its detail at its id, reading no schema', function () {
    $luna = dropClose();
    dropSweep(DROP_WINDOW_ENDS);
    $operator = addOperator();

    $this->actingAs($operator, ConsoleSession::GUARD)->withSession(freshConsoleSession())
        ->get(consoleUrl('/?status=closed'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tenants.total', 1)->where('tenants.rows.0.slug', null)->where('tenants.rows.0.detail', '/tenants/closed/'.$luna->id));

    $this->actingAs($operator, ConsoleSession::GUARD)->withSession(freshConsoleSession())
        ->get(consoleUrl('/tenants/closed/'.$luna->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Tenant')->where('tenant.status', 'closed')->where('tenant.closed', 'Wednesday 2 December 2026 at 08:00')->where('tenant.activity', null)->where('links', null));

    $this->actingAs($operator, ConsoleSession::GUARD)->withSession(freshConsoleSession())
        ->get(consoleUrl('/tenants/luna'))
        ->assertNotFound();
});

it('ends a queued job on a dropped tenant quietly, the reminder of its future booking among them, logged once at info and never as an error', function () {
    $luna = dropEverything('luna');
    $booking = DB::table('tenant_luna.bookings')->orderByDesc('id')->value('uuid');
    assert(is_string($booking));
    dropClose();
    dropSweep(DROP_WINDOW_ENDS);
    $log = dropFreshLog();

    // Queued for a day after the drop, so the reminder has not lapsed by the clock: it is the
    // reminder of a booking the drop took with the schema.
    app()->call([new SendReminder($luna->id, $booking, '2026-12-19 08:00:00+00:00', 0), 'handle']);

    expect($log->records)->toBe([['level' => 'info', 'message' => JobTenant::CLOSED, 'context' => ['tenant' => $luna->id, 'job' => SendReminder::class]]]);
});

it('never reaches the demo tenant demo:reset rebuilds active with no closing, even on a row somehow left closing past its window', function () {
    expect(Artisan::call('demo:reset'))->toBe(Command::SUCCESS);

    $demo = Tenant::query()->where('slug', siteDemoSlug()->value)->sole();

    expect($demo->status)->toBe(TenantStatus::Active)
        ->and($demo->closing_at)->toBeNull();

    DB::table('tenants')->where('id', $demo->id)->update(['status' => 'closing', 'closing_at' => '2026-10-01 06:00:00+00:00']);

    expect(dropSweep(DROP_WINDOW_ENDS))->toBe(Command::SUCCESS)
        ->and(app(TenantDrop::class)->due())->toBe([])
        ->and(DB::table('tenants')->where('id', $demo->id)->value('slug'))->toBe(siteDemoSlug()->value)
        ->and(DB::table('pg_namespace')->where('nspname', siteDemoSlug()->schemaName())->exists())->toBeTrue();
});

it('leaves a closed row closed when a checkout completion naming its subscription lands after the drop, the reopen reaching a closing row alone', function () {
    $luna = dropClose();
    DB::table('subscriptions')->insert(['tenant_id' => $luna->id, 'provider_customer_id' => 'cus_luna', 'provider_subscription_id' => 'sub_luna', 'status' => 'active']);
    dropSweep(DROP_WINDOW_ENDS);
    DB::table('tenants')->where('id', $luna->id)->update(['subscription_state' => 'active']);

    app(SignupCompletion::class)->completed('cus_luna', 'sub_luna', 0);

    expect(DB::table('tenants')->where('id', $luna->id)->value('status'))->toBe('closed')
        ->and(DB::table('tenants')->where('id', $luna->id)->value('slug'))->toBeNull();
});

it('reads the slug of a closed row by the model as a fault, the address being released', function () {
    $luna = dropClose();
    dropSweep(DROP_WINDOW_ENDS);

    $closed = Tenant::query()->whereKey($luna->id)->sole();

    expect($closed->status)->toBe(TenantStatus::Closed)
        ->and(fn (): string => $closed->slug)->toThrow(LogicException::class, sprintf('Tenant %d is closed', $luna->id))
        ->and(Slug::of('luna')->schemaName())->toBe($closed->schema_name);
});
