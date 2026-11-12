<?php

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Retention\ContactMessagesLine;
use App\Retention\PasswordLinksLine;
use App\Retention\PaymentEventsLine;
use App\Retention\PlatformAuditLine;
use App\Retention\SupportSessionsLine;
use App\Retention\WebhookEventsLine;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Psr\Log\LoggerInterface;
use Tests\Support\FixedClock;
use Tests\Support\SeamLog;

pest()->use(RefreshDatabase::class);

/*
 * The retention sweep's central lines: one daily command, retention:sweep, each line one statement
 * in its own transaction on the application's clock, each logged by its table and its count and
 * nothing of a row. The checkout records past their 24 hours, one of the stranded rows run after
 * the tenant lines, are a line of the same shape and proven here beside them; the lapsed
 * subscribers, the other, in LapsedSubscribersTest.
 *
 * Every line is proven on both sides of its boundary: a row one second inside its period stays, a
 * row one second past it goes, on a pinned clock spelled in UTC and on one spelled in Athens an
 * hour and a half after the spring change, where a period counted on Athens' wall clock rather
 * than as elapsed time from the instant would sit an hour away from the true one, and with the
 * database session in three zones, since an instant crosses as UTC text with its offset and the
 * session's zone may decide nothing.
 *
 * Each line is proven in every state its table holds a row in, one state to a case: contact
 * messages archived and not, webhook events processed and never processed, payment events
 * verified, processed or not, and refused. A line sweeps a row past its period whatever its
 * state, so a line that kept a state would keep that state's row a second past. Where a row
 * carries an instant later than the one its line reads, an archived message's archived_at and a
 * processed event's processed_at and updated_at, the later one is a minute on, so the row a
 * second past holds it inside the period and a line counting from it rather than from created_at
 * would keep that row too.
 *
 * Wrapped in a transaction: no line enters the transaction runner, and inside the wrap each
 * line's own transaction is a savepoint, so a line that throws rolls back to its own and the test
 * reads what the earlier lines left, while a run held in one transaction would roll them back with
 * it, and a line run in none would leave the wrap aborted for every read after it.
 */

beforeEach(function () {
    $this->log = new SeamLog;
    app()->instance(LoggerInterface::class, $this->log);
});

/**
 * Runs the sweep while the application's clock reads the given time in the given zone, and
 * answers its exit code and what it printed.
 *
 * @return array{code: int, output: string}
 */
function retentionSweepAt(string $now, string $zone = 'UTC'): array
{
    app()->instance(Clock::class, FixedClock::at($now, $zone));
    $code = Artisan::call('retention:sweep');

    return ['code' => $code, 'output' => trim(Artisan::output())];
}

/**
 * One row of the line's table, in the state the line's name gives, whose instant, the one the line
 * reads, is the given one. Any later instant the state carries is a minute on: when an archived
 * message was archived, and when a processed event was marked, the mark writing processed_at and
 * updated_at together as BillingWebhook and PaymentEvents do.
 */
function retentionRow(string $line, DateTimeImmutable $at): int
{
    $instant = SqlText::instant($at);
    $later = SqlText::instant($at->add(new DateInterval('PT1M')));
    $processed = in_array($line, ['webhook_events processed', 'payment_events verified and processed'], true);

    return match ($line) {
        'contact_messages unarchived', 'contact_messages archived' => DB::table('contact_messages')->insertGetId([
            'name' => 'Ada Prospect',
            'email' => 'ada@prospect.example',
            'message' => 'Do you take a group of six on a Saturday?',
            'locale' => 'en',
            'created_at' => $instant,
            'archived_at' => $line === 'contact_messages archived' ? $later : null,
        ]),
        // Ended ten minutes after it opened and twenty before its sixty were up, so a session
        // whose end is a second past the boundary has its expiry still inside it, and a line
        // reading the expiry before the end would keep it.
        'support_sessions ended' => DB::table('support_sessions')->insertGetId([
            'operator_id' => retentionOperator(),
            'tenant_id' => retentionTenant(),
            'opened_at' => SqlText::instant($at->sub(new DateInterval('PT10M'))),
            'expires_at' => SqlText::instant($at->add(new DateInterval('PT20M'))),
            'ended_at' => $instant,
            'created_at' => SqlText::instant($at->sub(new DateInterval('PT40M'))),
        ]),
        'support_sessions never ended' => DB::table('support_sessions')->insertGetId([
            'operator_id' => retentionOperator(),
            'tenant_id' => retentionTenant(),
            'opened_at' => SqlText::instant($at->sub(new DateInterval('PT58M'))),
            'expires_at' => $instant,
            'created_at' => SqlText::instant($at->sub(new DateInterval('PT60M'))),
        ]),
        'users password link' => DB::table('users')->insertGetId([
            'tenant_id' => retentionTenant(),
            'name' => 'Lin Manager',
            'email' => sprintf('lin.%s@luna.example', bin2hex(random_bytes(4))),
            'password' => 'not a hash anybody checks',
            'role' => 'manager',
            'password_link_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'password_link_expires_at' => $instant,
        ]),
        'platform_audit_log' => DB::table('platform_audit_log')->insertGetId([
            'operator_id' => retentionOperator(),
            'action' => 'sign_in',
            'payload' => '{}',
            'created_at' => $instant,
        ]),
        'webhook_events processed', 'webhook_events unprocessed' => DB::table('webhook_events')->insertGetId([
            'provider_event_id' => 'evt_'.bin2hex(random_bytes(8)),
            'type' => 'customer.subscription.updated',
            'payload' => json_encode(['data' => ['object' => ['customer' => 'cus_luna', 'email' => 'owner@luna.example']]], JSON_THROW_ON_ERROR),
            'processed_at' => $processed ? $later : null,
            'created_at' => $instant,
            'updated_at' => $processed ? $later : $instant,
        ]),
        'payment_events verified and processed', 'payment_events verified unprocessed' => DB::table('payment_events')->insertGetId([
            'provider' => 'stripe',
            'route' => 'own',
            'event_id' => 'evt_'.bin2hex(random_bytes(8)),
            'type' => 'checkout.session.completed',
            'processed_at' => $processed ? $later : null,
            'created_at' => $instant,
            'updated_at' => $processed ? $later : $instant,
        ]),
        // Why it was refused and nothing of its body, never processed: the shape the CHECKs
        // payment_events_verified_or_refused and payment_events_refused_unprocessed hold it to.
        'payment_events refused' => DB::table('payment_events')->insertGetId([
            'provider' => 'stripe',
            'route' => 'own',
            'refusal' => 'unknown_route',
            'created_at' => $instant,
            'updated_at' => $instant,
        ]),
        // A checkout the Billing screen recorded on a subscriptions row of its own, a row per
        // tenant: its session, its URL and when it opened, the three the CHECK holds together.
        'subscriptions checkout' => DB::table('subscriptions')->insertGetId([
            'tenant_id' => retentionSignup(),
            'provider_customer_id' => 'cus_'.bin2hex(random_bytes(6)),
            'checkout_session_id' => 'cs_'.bin2hex(random_bytes(8)),
            'checkout_url' => 'https://checkout.fake.test/'.bin2hex(random_bytes(8)),
            'checkout_opened_at' => $instant,
            'created_at' => $instant,
            'updated_at' => $instant,
        ]),
        default => throw new LogicException(sprintf('No row is spelled for the line %s.', $line)),
    };
}

/**
 * A tenant row of its own for a subscriptions row, which takes one per tenant; its schema is never
 * built, so it stays provisioning, as retentionTenant()'s does.
 */
function retentionSignup(): int
{
    $tenant = Tenant::factory()->create();
    DB::table('tenants')->where('id', $tenant->id)->update(['status' => TenantStatus::Provisioning->value]);

    return $tenant->id;
}

/**
 * The operator the console's rows name, made once per test.
 */
function retentionOperator(): int
{
    $id = DB::table('operators')->where('email', 'pat@inbox.example')->value('id');

    return is_int($id) ? $id : addOperator()->id;
}

/**
 * The tenant the central rows name, made once per test; its schema is never built, since no
 * central line reads one, so its row stays provisioning, which the tenant lines pass over as
 * they pass over a signup in flight.
 */
function retentionTenant(): int
{
    $id = DB::table('tenants')->where('slug', 'luna')->value('id');

    if (is_int($id)) {
        return $id;
    }

    $tenant = Tenant::factory()->create(['slug' => 'luna', 'name' => 'Luna Hair']);
    DB::table('tenants')->where('id', $tenant->id)->update(['status' => TenantStatus::Provisioning->value]);

    return $tenant->id;
}

/**
 * Which of the rows the line keeps are still kept, in the order given.
 *
 * @param  list<int>  $ids
 * @return list<int>
 */
function retentionKept(string $line, array $ids): array
{
    $kept = match ($line) {
        'contact_messages unarchived', 'contact_messages archived' => DB::table('contact_messages')->whereIn('id', $ids)->pluck('id')->all(),
        'support_sessions ended', 'support_sessions never ended' => DB::table('support_sessions')->whereIn('id', $ids)->pluck('id')->all(),
        'users password link' => DB::table('users')->whereIn('id', $ids)->whereNotNull('password_link_hash')->pluck('id')->all(),
        'platform_audit_log' => DB::table('platform_audit_log')->whereIn('id', $ids)->pluck('id')->all(),
        'webhook_events processed', 'webhook_events unprocessed' => DB::table('webhook_events')->whereIn('id', $ids)->pluck('id')->all(),
        'payment_events verified and processed', 'payment_events verified unprocessed', 'payment_events refused' => DB::table('payment_events')->whereIn('id', $ids)->pluck('id')->all(),
        'subscriptions checkout' => DB::table('subscriptions')->whereIn('id', $ids)->whereNotNull('checkout_session_id')->pluck('id')->all(),
        default => throw new LogicException(sprintf('No read is spelled for the line %s.', $line)),
    };

    return array_values(array_filter($ids, fn (int $id): bool => in_array($id, $kept, true)));
}

/**
 * The count each table's line logged, by table, in the order the lines ran.
 *
 * @return array<string, mixed>
 */
function retentionLogged(SeamLog $log): array
{
    $counts = [];

    foreach ($log->records as $record) {
        if ($record['level'] === 'info' && is_string($record['context']['table'] ?? null)) {
            $counts[$record['context']['table']] = $record['context']['count'] ?? null;
        }
    }

    return $counts;
}

it('keeps a row one second inside its period, and the row at its boundary where the period is kept to it, and sweeps the row one second past, whatever its state and whatever later instant it carries', function (string $line, string $table, string $onTheUtcClock, string $onTheSpringClock, bool $keepsTheBoundary, string $clock, string $now, string $zone, string $session) {
    DB::statement(sprintf("set local time zone '%s'", $session));
    $boundary = new DateTimeImmutable($clock === 'utc' ? $onTheUtcClock : $onTheSpringClock, new DateTimeZone('UTC'));
    $inside = retentionRow($line, $boundary->add(new DateInterval('PT1S')));
    $at = retentionRow($line, $boundary);
    $past = retentionRow($line, $boundary->sub(new DateInterval('PT1S')));

    expect(retentionSweepAt($now, $zone)['code'])->toBe(0)
        ->and(retentionKept($line, [$inside, $at, $past]))->toBe($keepsTheBoundary ? [$inside, $at] : [$inside])
        ->and(retentionLogged($this->log)[$table] ?? null)->toBe($keepsTheBoundary ? 1 : 2);
})->with([
    'contact_messages, twelve months from created_at, unarchived' => ['contact_messages unarchived', 'contact_messages', '2025-10-29 09:00:00', '2025-03-29 01:30:00', true],
    'contact_messages, twelve months from created_at, archived a minute on' => ['contact_messages archived', 'contact_messages', '2025-10-29 09:00:00', '2025-03-29 01:30:00', true],
    'support_sessions, ninety days from ended_at' => ['support_sessions ended', 'support_sessions', '2026-07-31 09:00:00', '2025-12-29 01:30:00', true],
    'support_sessions, ninety days from expires_at where never ended' => ['support_sessions never ended', 'support_sessions', '2026-07-31 09:00:00', '2025-12-29 01:30:00', true],
    'users, a password link nulled once its expiry has passed, at its own instant as the link reads it' => ['users password link', 'users', '2026-10-29 09:00:00', '2026-03-29 01:30:00', false],
    'platform_audit_log, twenty-four months from created_at' => ['platform_audit_log', 'platform_audit_log', '2024-10-29 09:00:00', '2024-03-29 01:30:00', true],
    'webhook_events, ninety days from created_at and not from processed_at or updated_at, processed a minute on' => ['webhook_events processed', 'webhook_events', '2026-07-31 09:00:00', '2025-12-29 01:30:00', true],
    'webhook_events, ninety days from created_at, never processed' => ['webhook_events unprocessed', 'webhook_events', '2026-07-31 09:00:00', '2025-12-29 01:30:00', true],
    'payment_events, ninety days from created_at and not from processed_at or updated_at, verified and processed a minute on' => ['payment_events verified and processed', 'payment_events', '2026-07-31 09:00:00', '2025-12-29 01:30:00', true],
    'payment_events, ninety days from created_at, verified and never processed' => ['payment_events verified unprocessed', 'payment_events', '2026-07-31 09:00:00', '2025-12-29 01:30:00', true],
    'payment_events, ninety days from created_at, refused' => ['payment_events refused', 'payment_events', '2026-07-31 09:00:00', '2025-12-29 01:30:00', true],
    'subscriptions, a checkout record nulled 24 hours after it opened, at its own instant as the checkout reads it' => ['subscriptions checkout', 'subscriptions', '2026-10-28 09:00:00', '2026-03-28 01:30:00', false],
])->with([
    'a UTC clock, the session in UTC' => ['utc', '2026-10-29 09:00:00', 'UTC', 'UTC'],
    'an Athens clock past the spring change, the session in Athens' => ['spring', '2026-03-29 04:30:00', 'Europe/Athens', 'Europe/Athens'],
    'an Athens clock past the spring change, the session in New York' => ['spring', '2026-03-29 04:30:00', 'Europe/Athens', 'America/New_York'],
]);

it('nulls a lapsed link\'s hash and expiry together and keeps its user, stamping updated_at with the clock\'s now, and leaves a live link and its row as they were', function () {
    $lapsed = retentionRow('users password link', new DateTimeImmutable('2026-10-29 08:59:59', new DateTimeZone('UTC')));
    $live = retentionRow('users password link', new DateTimeImmutable('2026-10-29 09:00:01', new DateTimeZone('UTC')));
    DB::table('users')->whereIn('id', [$lapsed, $live])->update(['updated_at' => '2026-10-26 09:00:00+00']);
    $before = DB::table('users')->where('id', $live)->first();

    // 11:00 in Athens, the autumn change four days behind it, is 09:00 UTC.
    expect(retentionSweepAt('2026-10-29 11:00:00', 'Europe/Athens')['code'])->toBe(0)
        ->and(DB::scalar("select (password_link_hash is null)::text || '/' || (password_link_expires_at is null)::text || '/' || to_char(updated_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') from users where id = ?", [$lapsed]))->toBe('true/true/2026-10-29 09:00:00')
        ->and(DB::table('users')->where('id', $live)->first())->toEqual($before);
});

it('nulls a checkout record\'s session, URL and opening together past its 24 hours and keeps the subscription with its customer and its billing columns, stamping updated_at with the clock\'s now, leaves a record inside them as it was, and the CHECK refuses any one of the three nulled alone', function () {
    $stale = retentionRow('subscriptions checkout', new DateTimeImmutable('2026-10-28 08:59:59', new DateTimeZone('UTC')));
    $open = retentionRow('subscriptions checkout', new DateTimeImmutable('2026-10-28 09:00:01', new DateTimeZone('UTC')));
    DB::table('subscriptions')->where('id', $stale)->update(['provider_subscription_id' => 'sub_stale', 'status' => 'canceled', 'current_period_end' => '2026-09-30 09:00:00+00', 'revision' => 3]);
    $columns = ['tenant_id', 'provider_customer_id', 'provider_subscription_id', 'status', 'current_period_end', 'items', 'revision', 'created_at'];
    $kept = DB::table('subscriptions')->where('id', $stale)->first($columns);
    $before = DB::table('subscriptions')->where('id', $open)->first();

    // 11:00 in Athens, the autumn change four days behind it, is 09:00 UTC.
    expect(retentionSweepAt('2026-10-29 11:00:00', 'Europe/Athens')['code'])->toBe(0)
        ->and(DB::scalar("select (checkout_session_id is null)::text || '/' || (checkout_url is null)::text || '/' || (checkout_opened_at is null)::text || '/' || to_char(updated_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') from subscriptions where id = ?", [$stale]))->toBe('true/true/true/2026-10-29 09:00:00')
        ->and(DB::table('subscriptions')->where('id', $stale)->first($columns))->toEqual($kept)
        ->and(DB::table('subscriptions')->where('id', $open)->first())->toEqual($before);

    foreach (['checkout_session_id', 'checkout_url', 'checkout_opened_at'] as $column) {
        DB::statement('savepoint one_column');

        try {
            DB::table('subscriptions')->where('id', $open)->update([$column => null]);
            $refused = null;
        } catch (QueryException $refusal) {
            $refused = $refusal->getCode().' '.(str_contains($refusal->getMessage(), 'subscriptions_checkout_whole') ? 'whole' : 'other');
        }

        DB::statement('rollback to savepoint one_column');

        expect($refused)->toBe('23514 whole');
    }
});

it('runs its lines in order, the central lines, then the stranded rows\' after the tenant lines, logging each table with its count and nothing of a row, and printing the same', function () {
    $past = new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('UTC'));
    $hash = DB::table('users')->where('id', retentionRow('users password link', $past))->value('password_link_hash');
    $email = DB::table('users')->whereNotNull('password_link_hash')->value('email');

    foreach (['contact_messages unarchived', 'support_sessions ended', 'support_sessions never ended', 'platform_audit_log', 'webhook_events processed', 'payment_events verified and processed'] as $line) {
        retentionRow($line, $past);
    }

    $checkout = DB::table('subscriptions')->where('id', retentionRow('subscriptions checkout', $past))->first(['provider_customer_id', 'checkout_session_id', 'checkout_url']);
    $event = DB::table('webhook_events')->value('provider_event_id');
    $swept = retentionSweepAt('2026-10-29 09:00:00');

    expect($swept)->toBe(['code' => 0, 'output' => implode("\n", [
        'contact_messages: 1 row swept.',
        'support_sessions: 2 rows swept.',
        'users: 1 row swept.',
        'platform_audit_log: 1 row swept.',
        'webhook_events: 1 row swept.',
        'payment_events: 1 row swept.',
        'tenants: 0 rows swept.',
        'subscriptions: 1 row swept.',
    ])])
        ->and($this->log->records)->toBe([
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'contact_messages', 'count' => 1]],
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'support_sessions', 'count' => 2]],
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'users', 'count' => 1]],
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'platform_audit_log', 'count' => 1]],
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'webhook_events', 'count' => 1]],
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'payment_events', 'count' => 1]],
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'tenants', 'count' => 0]],
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'subscriptions', 'count' => 1]],
        ]);

    foreach (['Ada Prospect', 'ada@prospect.example', 'group of six', 'cus_luna', 'owner@luna.example', $hash, $email, $event, $checkout?->provider_customer_id, $checkout?->checkout_session_id, $checkout?->checkout_url] as $content) {
        expect($content)->toBeString()
            ->and($this->log->text())->not->toContain($content)
            ->and($swept['output'])->not->toContain($content);
    }
});

it('runs each line as one statement in a transaction of its own, in order, binding the clock\'s instant and never reading the database\'s now()', function () {
    $trace = [];
    DB::listen(function (QueryExecuted $query) use (&$trace): void {
        // The read of the tenants the tenant lines run over is no line's statement; with no
        // tenant row there is nothing for those lines to run in.
        if (str_starts_with($query->sql, 'select * from "tenants"')) {
            return;
        }

        $trace[] = match (true) {
            str_starts_with($query->sql, 'select t.id from tenants t left join subscriptions') => 'read the lapsed subscribers due a close',
            str_starts_with($query->sql, 'select t.id from tenants t where') => 'read the tenants due a drop',
            preg_match('/^\s*(delete from|update) (\w+)/', $query->sql, $statement) === 1 => $statement[1].' '.$statement[2],
            default => $query->sql,
        };
        $trace[] = str_contains($query->sql, 'now()') ? 'reads now()' : null;
    });
    Event::listen(TransactionBeginning::class, function () use (&$trace): void {
        $trace[] = 'begin';
    });
    Event::listen(TransactionCommitted::class, function () use (&$trace): void {
        $trace[] = 'commit';
    });

    retentionSweepAt('2026-10-29 09:00:00');

    expect(array_values(array_filter($trace)))->toBe([
        'begin', 'delete from contact_messages', 'commit',
        'begin', 'delete from support_sessions', 'commit',
        'begin', 'update users', 'commit',
        'begin', 'delete from platform_audit_log', 'commit',
        'begin', 'delete from webhook_events', 'commit',
        'begin', 'delete from payment_events', 'commit',
        // The stranded rows: the lapsed subscribers read by the rows due a close, none here, so
        // no close runs; then the checkout records, one statement.
        'read the lapsed subscribers due a close',
        'begin', 'update subscriptions', 'commit',
        // The drop, last, reads the tenants due it; with none, it runs nothing.
        'read the tenants due a drop',
    ]);
});

it('sweeps nothing a second time in the same instant, the rows inside their periods standing', function () {
    $past = new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('UTC'));
    $inside = new DateTimeImmutable('2026-10-28 09:00:00', new DateTimeZone('UTC'));
    $kept = [];

    foreach (['contact_messages unarchived', 'support_sessions ended', 'support_sessions never ended', 'platform_audit_log', 'webhook_events processed', 'payment_events verified and processed'] as $line) {
        retentionRow($line, $past);
        $kept[$line] = retentionRow($line, $inside);
    }

    retentionRow('users password link', $past);
    $kept['users password link'] = retentionRow('users password link', new DateTimeImmutable('2026-10-30 09:00:00', new DateTimeZone('UTC')));
    retentionRow('subscriptions checkout', $past);
    $kept['subscriptions checkout'] = retentionRow('subscriptions checkout', new DateTimeImmutable('2026-10-29 08:00:00', new DateTimeZone('UTC')));

    retentionSweepAt('2026-10-29 09:00:00');
    $this->log->records = [];

    expect(retentionSweepAt('2026-10-29 09:00:00')['code'])->toBe(0)
        ->and(retentionLogged($this->log))->toBe(['contact_messages' => 0, 'support_sessions' => 0, 'users' => 0, 'platform_audit_log' => 0, 'webhook_events' => 0, 'payment_events' => 0, 'tenants' => 0, 'subscriptions' => 0]);

    foreach ($kept as $line => $id) {
        expect(retentionKept($line, [$id]))->toBe([$id]);
    }
});

it('stops at a line that throws, the earlier lines\' work committed and standing, logs the throw once by its table and SQLSTATE alone, and the next run picks up where it left', function () {
    $past = new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('UTC'));
    $rows = [];

    foreach (['contact_messages unarchived', 'support_sessions ended', 'users password link', 'platform_audit_log', 'webhook_events processed', 'payment_events verified and processed', 'subscriptions checkout'] as $line) {
        $rows[$line] = retentionRow($line, $past);
    }

    // The fourth line's table is not there, so its statement fails as a statement can: the
    // database refuses it with an SQLSTATE and a message that spells the statement.
    DB::statement('alter table platform_audit_log rename to platform_audit_log_away');

    $stopped = retentionSweepAt('2026-10-29 09:00:00');

    expect($stopped['code'])->toBe(1)
        ->and($stopped['output'])->toEndWith('platform_audit_log: the line failed, and the sweep stopped.')
        ->and($this->log->records)->toBe([
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'contact_messages', 'count' => 1]],
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'support_sessions', 'count' => 1]],
            ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => 'users', 'count' => 1]],
            ['level' => 'error', 'message' => 'The retention sweep stopped', 'context' => ['table' => 'platform_audit_log', 'exception' => QueryException::class, 'sqlstate' => '42P01']],
        ])
        ->and(retentionKept('contact_messages unarchived', [$rows['contact_messages unarchived']]))->toBe([])
        ->and(retentionKept('support_sessions ended', [$rows['support_sessions ended']]))->toBe([])
        ->and(retentionKept('users password link', [$rows['users password link']]))->toBe([])
        ->and(DB::table('platform_audit_log_away')->where('id', $rows['platform_audit_log'])->count())->toBe(1)
        ->and(retentionKept('webhook_events processed', [$rows['webhook_events processed']]))->toBe([$rows['webhook_events processed']])
        ->and(retentionKept('payment_events verified and processed', [$rows['payment_events verified and processed']]))->toBe([$rows['payment_events verified and processed']])
        ->and(retentionKept('subscriptions checkout', [$rows['subscriptions checkout']]))->toBe([$rows['subscriptions checkout']]);

    DB::statement('alter table platform_audit_log_away rename to platform_audit_log');
    $this->log->records = [];

    expect(retentionSweepAt('2026-10-30 09:00:00')['code'])->toBe(0)
        ->and(retentionLogged($this->log))->toBe(['contact_messages' => 0, 'support_sessions' => 0, 'users' => 0, 'platform_audit_log' => 1, 'webhook_events' => 1, 'payment_events' => 1, 'tenants' => 0, 'subscriptions' => 1]);
});

it('touches nothing of a tenant\'s schema beside the central one, its own trail, links and sessions long past any period of the central lines', function () {
    $schema = provisionTenant('luna')->tenant->schema_name;
    $past = SqlText::instant(new DateTimeImmutable('2023-01-01 00:00:00', new DateTimeZone('UTC')));
    DB::table($schema.'.audit_log')->insert(['booking_id' => 1, 'actor' => 'system', 'action' => 'expired', 'created_at' => $past]);
    DB::table($schema.'.customer_links')->insert(['email' => 'ada@prospect.example', 'token_hash' => str_repeat('a', 64), 'created_at' => $past, 'expires_at' => SqlText::instant(new DateTimeImmutable('2023-01-01 00:15:00', new DateTimeZone('UTC')))]);
    DB::table($schema.'.customer_sessions')->insert(['email' => 'ada@prospect.example', 'session_hash' => str_repeat('b', 64), 'created_at' => $past, 'expires_at' => SqlText::instant(new DateTimeImmutable('2023-01-31 00:00:00', new DateTimeZone('UTC')))]);
    $central = retentionRow('contact_messages unarchived', new DateTimeImmutable('2023-01-01 00:00:00', new DateTimeZone('UTC')));
    app()->instance(Clock::class, FixedClock::at('2026-10-29 09:00:00'));

    // The central lines alone, by name: the tenant lines of the sweep reach these rows by
    // design.
    foreach ([ContactMessagesLine::class, SupportSessionsLine::class, PasswordLinksLine::class, PlatformAuditLine::class, WebhookEventsLine::class, PaymentEventsLine::class] as $line) {
        app($line)->apply();
    }

    expect(retentionKept('contact_messages unarchived', [$central]))->toBe([])
        ->and(DB::table($schema.'.audit_log')->count())->toBe(1)
        ->and(DB::table($schema.'.customer_links')->count())->toBe(1)
        ->and(DB::table($schema.'.customer_sessions')->count())->toBe(1);
});

it('runs daily at 04:30 Europe/Athens in the console schedule, without overlapping, after the demo\'s reset at 04:00', function () {
    $scheduled = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (ScheduledEvent $event): bool => str_contains($event->command ?? '', 'retention:sweep') || str_contains($event->command ?? '', 'demo:reset'),
    ));

    expect($scheduled)->toHaveCount(2)
        ->and($scheduled[0]->command)->toContain('demo:reset')
        ->and($scheduled[1]->command)->toContain('retention:sweep')
        ->and($scheduled[1]->expression)->toBe('30 4 * * *')
        ->and($scheduled[1]->timezone)->toBe('Europe/Athens')
        ->and($scheduled[1]->withoutOverlapping)->toBeTrue();
});
