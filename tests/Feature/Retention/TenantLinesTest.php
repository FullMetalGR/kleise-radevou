<?php

use App\Application\Clock;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\Db\SqlText;
use App\Retention\AuditLogLine;
use App\Retention\BookingCustomersLine;
use App\Retention\BookingMetaLine;
use App\Retention\CustomerLinksLine;
use App\Retention\CustomerNotesLine;
use App\Retention\CustomerProfilesLine;
use App\Retention\CustomerSessionsLine;
use App\Retention\ManageTokensLine;
use App\Retention\TenantLine;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Query\Builder;
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
 * The retention sweep's tenant lines: after the central lines, every tenant line once over each
 * schema whose row is not provisioning, in id order, each one statement in its own transaction on
 * the application's clock, logged by the tenant, the table and the count and nothing of a row.
 *
 * Every line is proven on both sides of its boundary, in luna's schema beside bravo's holding the
 * same rows at the same instants, which the line run over luna must leave as they were: a row one
 * second inside its period stays, a row one second past it goes, and the row at the boundary
 * stays where the period is kept to it. On a pinned clock spelled in UTC and on one spelled in
 * Athens an hour and a half after the spring change, where a period counted on Athens' wall clock
 * rather than from the instant in UTC would sit an hour away from the true one, and with the
 * database session in three zones, since an instant crosses as UTC text with its offset.
 *
 * Every line is proven in every state its table holds a row in, all states in one case: a
 * booking in each of its nine statuses, a token as its hash, its sealed copy or both, an intake
 * row under a customer standing and under one erased already, a trail row by each of the five
 * actors, a link used and unused, a session live and revoked. A line sweeps a row past its period
 * whatever its state, so a line that kept a state would keep that state's row a second past.
 *
 * The booking lines read a booking's last segment end, its latest item's end_utc, and every other
 * instant a booking carries sits where a line reading it would fail the case: the first segment
 * ends two hours earlier, so a line reading the first end would sweep the row a second inside;
 * the last segment's buffer and the row's updated_at run a minute past its end, and its
 * processing gap half an hour past it, so a line reading any of the three would keep the row a
 * second past, max(coalesce(processing_ends_utc, end_utc)) among them; its start and the row's
 * created_at are earlier, so a line reading them would sweep the row a second inside. A last
 * segment followed by a gap is what the booking path writes for a service that has one, last in
 * its chain or booked alone: HoldPlanner gives every such segment its processing end, the last
 * included, and PostgresBookingWriter stores it. A link's created_at and used_at, and a session's
 * created_at and revoked_at, sit before the expiry the line reads in the same way.
 *
 * Wrapped in a transaction: no line enters the transaction runner, and inside the wrap each
 * line's own transaction is a savepoint.
 */

beforeEach(function () {
    $this->log = new SeamLog;
    app()->instance(LoggerInterface::class, $this->log);
    app()->instance(Clock::class, FixedClock::at('2026-11-02 09:00:00'));
    $this->luna = provisionTenant('luna', ['name' => 'Luna Studio', 'ownerEmail' => 'owner@luna.example'])->tenant;
    $this->bravo = provisionTenant('bravo', ['name' => 'Bravo Studio', 'ownerEmail' => 'owner@bravo.example'])->tenant;
});

/**
 * The nine statuses a booking row may hold, BookingStatus's cases as the CHECK lists them.
 *
 * @return list<string>
 */
function tenantSweepStatuses(): array
{
    return array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::cases());
}

/**
 * An interval of elapsed time as an ISO duration of seconds.
 */
function tenantSweepSeconds(int $seconds): DateInterval
{
    return new DateInterval(sprintf('PT%dS', $seconds));
}

/**
 * The tenant's service of that name, seeded with the processing gap given on first asking.
 */
function tenantSweepService(Tenant $tenant, string $name, int $gapMin): int
{
    $services = fn () => DB::table($tenant->schema_name.'.services');
    $id = $services()->where('name', $name)->value('id') ?? $services()->insertGetId(serviceRow($name, ['processing_gap_min' => $gapMin]));
    assert(is_int($id));

    return $id;
}

/**
 * A booking of the tenant whose last segment ends at the instant given, in the status given, as
 * the booking path leaves one: two segments, a Cut and a Colour, each of a service with a
 * processing gap and so each followed by its gap's end, the last one's half an hour past the
 * booking's end; a buffer after the last; a note, the manage token's hash and its sealed copy,
 * unless the columns say other. Alone, the Colour by itself, booked alone as the demo's is.
 * Answers its id.
 *
 * @param  array<string, mixed>  $columns
 */
function tenantSweepBooking(Tenant $tenant, DateTimeImmutable $lastEnd, string $status = 'confirmed', array $columns = [], bool $alone = false): int
{
    $schema = $tenant->schema_name;
    $cut = tenantSweepService($tenant, 'Cut', 15);
    $colour = tenantSweepService($tenant, 'Colour', 30);
    $at = fn (int $seconds): string => SqlText::instant($seconds < 0 ? $lastEnd->sub(tenantSweepSeconds(-$seconds)) : $lastEnd->add(tenantSweepSeconds($seconds)));
    $email = sprintf('casey.%s@example.com', bin2hex(random_bytes(4)));

    $id = DB::table($schema.'.bookings')->insertGetId([
        ...bookingRow(),
        'status' => $status,
        'customer_name' => 'Casey Customer',
        'customer_email' => $email,
        'customer_phone' => '+30 210 765 4321',
        'manage_token_hash' => hash('sha256', $email),
        'manage_token_sealed' => 'eyJpdiI6InNlYWxlZCJ9',
        'created_at' => $at(-30 * 86400),
        'updated_at' => $at(60),
        ...$columns,
    ]);

    $staff = implicitStaffId($schema);
    $last = [...itemRow($id, $staff, $at(-1800), $at(0)), 'service_id' => $colour, 'block_start_utc' => $at(-2100), 'block_end_utc' => $at(60), 'processing_ends_utc' => $at(1800), 'blocks' => false, 'created_at' => $at(-30 * 86400), 'updated_at' => $at(60)];
    DB::table($schema.'.booking_items')->insert($alone ? [$last] : [
        [...itemRow($id, $staff, $at(-9000), $at(-7200)), 'sort' => 0, 'service_id' => $cut, 'processing_ends_utc' => $at(-6300), 'blocks' => false, 'created_at' => $at(-30 * 86400), 'updated_at' => $at(60)],
        [...$last, 'sort' => 1],
    ]);

    DB::table($schema.'.booking_meta')->insert(['booking_id' => $id, 'key' => 'note', 'value' => 'Casey would like the window seat.']);

    return $id;
}

/**
 * One row of a tenant line's table in the state named, whose instant, the one the line reads, is
 * the given one. Answers its id, a booking's for the three booking lines.
 */
function tenantSweepRow(Tenant $tenant, string $line, string $state, DateTimeImmutable $at): int
{
    $schema = $tenant->schema_name;
    $before = fn (int $seconds): string => SqlText::instant($at->sub(tenantSweepSeconds($seconds)));
    $hash = hash('sha256', bin2hex(random_bytes(32)));

    return match ($line) {
        'bookings' => tenantSweepBooking($tenant, $at, $state),
        'booking_meta' => tenantSweepBooking($tenant, $at, 'completed', $state === 'erased'
            ? ['customer_name' => null, 'customer_email' => null, 'customer_phone' => null, 'manage_token_hash' => null, 'manage_token_sealed' => null]
            : []),
        'manage tokens' => tenantSweepBooking($tenant, $at, in_array($state, tenantSweepStatuses(), true) ? $state : 'completed', match ($state) {
            'hash only' => ['manage_token_sealed' => null],
            'sealed only' => ['manage_token_hash' => null],
            default => [],
        }),
        'audit_log' => DB::table($schema.'.audit_log')->insertGetId([
            'booking_id' => 1,
            'actor' => $state,
            'action' => 'cancelled',
            'payload' => '{"status_from":"confirmed","status_to":"cancelled"}',
            'created_at' => SqlText::instant($at),
        ]),
        'customer_links' => DB::table($schema.'.customer_links')->insertGetId([
            'email' => 'casey@example.com',
            'token_hash' => $hash,
            'created_at' => $before(900),
            'expires_at' => SqlText::instant($at),
            'used_at' => $state === 'used' ? $before(840) : null,
        ]),
        'customer_sessions' => DB::table($schema.'.customer_sessions')->insertGetId([
            'email' => 'casey@example.com',
            'session_hash' => $hash,
            'created_at' => $before(30 * 86400),
            'expires_at' => SqlText::instant($at),
            'revoked_at' => $state === 'revoked' ? $before(29 * 86400) : null,
        ]),
        default => throw new LogicException(sprintf('No row is spelled for the line %s.', $line)),
    };
}

/**
 * Which of the rows given the line keeps are still kept, in the order given: a booking whose
 * customer stands, an intake row that stands, a booking whose token columns stand as written,
 * and a trail row, a link or a session that is still there.
 *
 * @param  list<int>  $ids
 * @return list<int>
 */
function tenantSweepKept(Tenant $tenant, string $line, array $ids): array
{
    $table = fn (string $name) => DB::table($tenant->schema_name.'.'.$name);
    $kept = match ($line) {
        'bookings' => $table('bookings')->whereIn('id', $ids)->whereNotNull('customer_email')->pluck('id')->all(),
        'booking_meta' => $table('booking_meta')->whereIn('booking_id', $ids)->pluck('booking_id')->all(),
        'manage tokens' => $table('bookings')->whereIn('id', $ids)->where(fn (Builder $any) => $any->whereNotNull('manage_token_hash')->orWhereNotNull('manage_token_sealed'))->pluck('id')->all(),
        'audit_log', 'customer_links', 'customer_sessions' => $table($line)->whereIn('id', $ids)->pluck('id')->all(),
        default => throw new LogicException(sprintf('No read is spelled for the line %s.', $line)),
    };

    return array_values(array_filter($ids, fn (int $id): bool => in_array($id, $kept, true)));
}

/**
 * Every row of a schema's tables the tenant lines write, as plain arrays, for asking whether a
 * line left a schema as it found it.
 *
 * @return array<string, list<array<mixed>>>
 */
function tenantSweepTables(Tenant $tenant): array
{
    $tables = [];

    foreach (['bookings' => 'id', 'booking_items' => 'id', 'booking_meta' => 'booking_id, key', 'audit_log' => 'id', 'customer_links' => 'id', 'customer_sessions' => 'id'] as $table => $order) {
        $tables[$table] = [];

        foreach (DB::select(sprintf('select * from %s.%s order by %s', $tenant->schema_name, $table, $order)) as $row) {
            assert($row instanceof stdClass);
            $tables[$table][] = get_object_vars($row);
        }
    }

    return $tables;
}

/**
 * Runs the sweep while the application's clock reads the given time in the given zone, and
 * answers its exit code and what it printed.
 *
 * @return array{code: int, output: string}
 */
function tenantSweepAt(string $now, string $zone = 'UTC'): array
{
    app()->instance(Clock::class, FixedClock::at($now, $zone));
    $code = Artisan::call('retention:sweep');

    return ['code' => $code, 'output' => trim(Artisan::output())];
}

/**
 * The tenant lines' records the sweep logged, as tenant, table and count, in the order logged.
 *
 * @return list<string>
 */
function tenantSweepLogged(SeamLog $log): array
{
    $lines = [];

    foreach ($log->records as $record) {
        if (isset($record['context']['tenant'])) {
            $lines[] = sprintf('%s %s %s %s', $record['level'], json_encode($record['context']['tenant']), json_encode($record['context']['table'] ?? null), json_encode($record['context']['count'] ?? $record['context']['sqlstate'] ?? null));
        }
    }

    return $lines;
}

/**
 * The line a case proves, as the sweep resolves it.
 */
function tenantSweepLine(string $line): TenantLine
{
    return app(match ($line) {
        'bookings' => BookingCustomersLine::class,
        'booking_meta' => BookingMetaLine::class,
        'audit_log' => AuditLogLine::class,
        'manage tokens' => ManageTokensLine::class,
        'customer_links' => CustomerLinksLine::class,
        'customer_sessions' => CustomerSessionsLine::class,
        default => throw new LogicException(sprintf('No line is called %s.', $line)),
    });
}

/**
 * Every state the line's table holds a row in, one row of each seeded on each side of the
 * boundary.
 *
 * @return list<string>
 */
function tenantSweepStates(string $line): array
{
    return match ($line) {
        'bookings' => tenantSweepStatuses(),
        'booking_meta' => ['standing', 'erased'],
        'audit_log' => ['customer', 'system', 'user:1', 'job:expire-holds', 'support:1'],
        'manage tokens' => [...tenantSweepStatuses(), 'hash only', 'sealed only'],
        'customer_links' => ['unused', 'used'],
        'customer_sessions' => ['live', 'revoked'],
        default => throw new LogicException(sprintf('No states are spelled for the line %s.', $line)),
    };
}

/**
 * Which of a state's three rows, inside, at and past the boundary, the line keeps: the row at the
 * boundary where the period is kept to it, as a cutoff keeps the row at its own instant, and not
 * where the row lapses at its own instant, a link or a session, nor any row of a revoked session.
 *
 * @return list<'inside'|'at'|'past'>
 */
function tenantSweepKeeps(string $line, string $state): array
{
    return match (true) {
        $line === 'customer_sessions' && $state === 'revoked' => [],
        in_array($line, ['customer_links', 'customer_sessions'], true) => ['inside'],
        default => ['inside', 'at'],
    };
}

it('keeps a row one second inside its period, and the row at its boundary where the period is kept to it, and sweeps the row one second past, in every state, and leaves the neighbouring schema\'s same rows as they were', function (string $line, string $onTheUtcClock, string $onTheSpringClock, string $clock, string $now, string $zone, string $session) {
    DB::statement(sprintf("set local time zone '%s'", $session));
    $boundary = new DateTimeImmutable($clock === 'utc' ? $onTheUtcClock : $onTheSpringClock, new DateTimeZone('UTC'));
    $rows = [];

    foreach (tenantSweepStates($line) as $state) {
        foreach ([$this->luna, $this->bravo] as $tenant) {
            $rows[$tenant->slug][$state] = [
                'inside' => tenantSweepRow($tenant, $line, $state, $boundary->add(tenantSweepSeconds(1))),
                'at' => tenantSweepRow($tenant, $line, $state, $boundary),
                'past' => tenantSweepRow($tenant, $line, $state, $boundary->sub(tenantSweepSeconds(1))),
            ];
        }
    }

    $neighbour = tenantSweepTables($this->bravo);
    app()->instance(Clock::class, FixedClock::at($now, $zone));
    $count = tenantSweepLine($line)->apply($this->luna);
    $kept = [];
    $wanted = [];
    $swept = 0;

    foreach (tenantSweepStates($line) as $state) {
        $kept[$state] = tenantSweepKept($this->luna, $line, array_values($rows['luna'][$state]));
        $wanted[$state] = array_values(array_intersect_key($rows['luna'][$state], array_flip(tenantSweepKeeps($line, $state))));
        $swept += 3 - count($wanted[$state]);
    }

    expect($kept)->toBe($wanted)
        ->and($count)->toBe($swept)
        ->and(tenantSweepTables($this->bravo))->toBe($neighbour);
})->with([
    'bookings, the customer kept 24 months past the last segment end, in each of the nine statuses' => ['bookings', '2024-11-02 09:00:00', '2024-03-29 01:30:00'],
    'booking_meta, 24 months past the last segment end, the customer standing or erased already' => ['booking_meta', '2024-11-02 09:00:00', '2024-03-29 01:30:00'],
    'audit_log, 24 months from created_at, by each of the five actors' => ['audit_log', '2024-11-02 09:00:00', '2024-03-29 01:30:00'],
    'manage tokens, 30 days past the last segment end, in each status and as the hash, the sealed copy or both' => ['manage tokens', '2026-10-03 09:00:00', '2026-02-27 01:30:00'],
    'customer_links, until expires_at, used or not, lapsed at its own instant' => ['customer_links', '2026-11-02 09:00:00', '2026-03-29 01:30:00'],
    'customer_sessions, until expires_at, lapsed at its own instant, or at once when revoked' => ['customer_sessions', '2026-11-02 09:00:00', '2026-03-29 01:30:00'],
])->with([
    'a UTC clock, the session in UTC' => ['utc', '2026-11-02 09:00:00', 'UTC', 'UTC'],
    'an Athens clock past the spring change, the session in Athens' => ['spring', '2026-03-29 04:30:00', 'Europe/Athens', 'Europe/Athens'],
    'an Athens clock past the spring change, the session in New York' => ['spring', '2026-03-29 04:30:00', 'Europe/Athens', 'America/New_York'],
]);

it('counts a booking line\'s period from the last item\'s end_utc alone, never from the processing gap after it: a Colour booked alone, its gap ending half an hour past the boundary, is swept a second past its end and kept at it', function (string $line, string $onTheUtcClock) {
    $boundary = new DateTimeImmutable($onTheUtcClock, new DateTimeZone('UTC'));
    $past = tenantSweepBooking($this->luna, $boundary->sub(tenantSweepSeconds(1)), alone: true);
    $at = tenantSweepBooking($this->luna, $boundary, alone: true);
    $gaps = DB::table('tenant_luna.booking_items')->whereIn('booking_id', [$past, $at])
        ->selectRaw("processing_ends_utc - end_utc = interval '30 minutes' as gap")->pluck('gap')->all();

    expect($gaps)->toBe([true, true])
        ->and(tenantSweepLine($line)->apply($this->luna))->toBe(1)
        ->and(tenantSweepKept($this->luna, $line, [$past, $at]))->toBe([$at]);
})->with([
    'bookings, 24 months' => ['bookings', '2024-11-02 09:00:00'],
    'booking_meta, 24 months' => ['booking_meta', '2024-11-02 09:00:00'],
    'manage tokens, 30 days' => ['manage tokens', '2026-10-03 09:00:00'],
]);

/**
 * One row past its period for every tenant line in the tenant's schema, on the clock the
 * command-level cases run at, 2026-11-02 09:00 UTC: a booking whose last segment ended in
 * January 2024, which the bookings line erases with its note and its tokens; a booking ended
 * forty days ago, whose tokens alone go; a trail row of 2024; a link and a session lapsed.
 * Answers the ids, by what each is.
 *
 * @return array{erased: int, tokens: int, trail: int, link: int, session: int}
 */
function tenantSweepPast(Tenant $tenant): array
{
    $old = new DateTimeImmutable('2024-01-01 09:00:00', new DateTimeZone('UTC'));

    return [
        'erased' => tenantSweepRow($tenant, 'bookings', 'completed', $old),
        'tokens' => tenantSweepRow($tenant, 'manage tokens', 'completed', new DateTimeImmutable('2026-09-23 09:00:00', new DateTimeZone('UTC'))),
        'trail' => tenantSweepRow($tenant, 'audit_log', 'customer', $old),
        'link' => tenantSweepRow($tenant, 'customer_links', 'used', $old),
        'session' => tenantSweepRow($tenant, 'customer_sessions', 'revoked', new DateTimeImmutable('2026-12-01 09:00:00', new DateTimeZone('UTC'))),
    ];
}

/**
 * What the sweep printed for one schema whose lines moved the counts given, in the lines' order.
 *
 * @param  array{int, int, int, int, int, int, int, int}  $counts
 * @return list<string>
 */
function tenantSweepPrinted(string $schema, array $counts): array
{
    $printed = [];

    foreach (['bookings', 'booking_meta', 'audit_log', 'bookings', 'customer_links', 'customer_sessions', 'customer_profiles', 'customer_notes'] as $index => $table) {
        $printed[] = sprintf('%s.%s: %d %s swept.', $schema, $table, $counts[$index], $counts[$index] === 1 ? 'row' : 'rows');
    }

    return $printed;
}

/** The central lines' printed lines on a database holding none of their rows. */
const TENANT_SWEEP_CENTRAL = [
    'contact_messages: 0 rows swept.',
    'support_sessions: 0 rows swept.',
    'users: 0 rows swept.',
    'platform_audit_log: 0 rows swept.',
    'webhook_events: 0 rows swept.',
    'payment_events: 0 rows swept.',
];

/**
 * The stranded rows' printed lines after the tenant lines, on a database holding no lapsed
 * subscriber and no checkout record.
 */
const TENANT_SWEEP_STRANDED = [
    'tenants: 0 rows swept.',
    'subscriptions: 0 rows swept.',
];

it('runs the tenant lines after the central ones, every line over each schema in turn in the tenants\' id order, a closing and a suspended tenant swept as an active one and a provisioning row passed over, logging the tenant, the table and the count', function () {
    DB::table('tenants')->where('id', $this->bravo->id)->update(['status' => TenantStatus::Closing->value, 'closing_at' => '2026-10-20 09:00:00+00']);
    $provisioning = provisionTenant('cora', ['name' => 'Cora Nails', 'ownerEmail' => 'owner@cora.example'])->tenant;
    DB::table('tenants')->where('id', $provisioning->id)->update(['status' => TenantStatus::Provisioning->value]);
    $aria = provisionTenant('aria', ['name' => 'Aria Spa', 'ownerEmail' => 'owner@aria.example'])->tenant;
    DB::table('tenants')->where('id', $aria->id)->update(['status' => TenantStatus::Suspended->value, 'suspended_at' => '2026-10-30 09:00:00+00', 'suspension_reason' => 'Chargebacks under review']);
    $rows = [];

    foreach ([$this->luna, $this->bravo, $provisioning, $aria] as $tenant) {
        $rows[$tenant->slug] = tenantSweepPast($tenant);
    }

    $swept = tenantSweepAt('2026-11-02 09:00:00');

    expect($swept)->toBe(['code' => 0, 'output' => implode("\n", [
        ...TENANT_SWEEP_CENTRAL,
        ...tenantSweepPrinted('tenant_luna', [1, 0, 1, 1, 1, 1, 0, 0]),
        ...tenantSweepPrinted('tenant_bravo', [1, 0, 1, 1, 1, 1, 0, 0]),
        ...tenantSweepPrinted('tenant_aria', [1, 0, 1, 1, 1, 1, 0, 0]),
        ...TENANT_SWEEP_STRANDED,
    ])])
        ->and(tenantSweepLogged($this->log))->toBe([
            'info "luna" "bookings" 1', 'info "luna" "booking_meta" 0', 'info "luna" "audit_log" 1', 'info "luna" "bookings" 1', 'info "luna" "customer_links" 1', 'info "luna" "customer_sessions" 1', 'info "luna" "customer_profiles" 0', 'info "luna" "customer_notes" 0',
            'info "bravo" "bookings" 1', 'info "bravo" "booking_meta" 0', 'info "bravo" "audit_log" 1', 'info "bravo" "bookings" 1', 'info "bravo" "customer_links" 1', 'info "bravo" "customer_sessions" 1', 'info "bravo" "customer_profiles" 0', 'info "bravo" "customer_notes" 0',
            'info "aria" "bookings" 1', 'info "aria" "booking_meta" 0', 'info "aria" "audit_log" 1', 'info "aria" "bookings" 1', 'info "aria" "customer_links" 1', 'info "aria" "customer_sessions" 1', 'info "aria" "customer_profiles" 0', 'info "aria" "customer_notes" 0',
        ])
        ->and(array_slice($this->log->records, 0, 6))->toBe(array_map(
            fn (string $table): array => ['level' => 'info', 'message' => 'Retention sweep', 'context' => ['table' => $table, 'count' => 0]],
            ['contact_messages', 'support_sessions', 'users', 'platform_audit_log', 'webhook_events', 'payment_events'],
        ))
        ->and($this->log->records[6] ?? null)->toBe(['level' => 'info', 'message' => 'Retention sweep', 'context' => ['tenant' => 'luna', 'table' => 'bookings', 'count' => 1]]);

    foreach (['luna', 'bravo', 'aria'] as $slug) {
        $schema = 'tenant_'.$slug;
        expect(DB::table($schema.'.bookings')->where('id', $rows[$slug]['erased'])->first(['customer_name', 'customer_email', 'customer_phone', 'manage_token_hash', 'manage_token_sealed']))->toEqual((object) ['customer_name' => null, 'customer_email' => null, 'customer_phone' => null, 'manage_token_hash' => null, 'manage_token_sealed' => null])
            ->and(DB::table($schema.'.booking_meta')->where('booking_id', $rows[$slug]['erased'])->exists())->toBeFalse()
            ->and(DB::table($schema.'.bookings')->where('id', $rows[$slug]['tokens'])->first(['customer_name', 'manage_token_hash', 'manage_token_sealed']))->toEqual((object) ['customer_name' => 'Casey Customer', 'manage_token_hash' => null, 'manage_token_sealed' => null]);
    }

    expect(DB::table('tenant_cora.bookings')->whereNull('customer_email')->count())->toBe(0)
        ->and(DB::table('tenant_cora.audit_log')->count())->toBe(1)
        ->and(DB::table('tenant_cora.customer_links')->count())->toBe(1)
        ->and(DB::table('tenant_cora.customer_sessions')->count())->toBe(1);
});

it('runs each tenant line as one statement in a transaction of its own over its one schema, the schemas in id order, binding the clock\'s instant and never reading the database\'s now()', function () {
    $trace = [];
    DB::listen(function (QueryExecuted $query) use (&$trace): void {
        $trace[] = match (true) {
            str_starts_with($query->sql, 'select * from "tenants"') => 'read tenants',
            str_starts_with($query->sql, 'select t.id from tenants t left join subscriptions') => 'read the lapsed subscribers due a close',
            str_starts_with($query->sql, 'select t.id from tenants t where') => 'read the tenants due a drop',
            preg_match('/\b(update|delete from) ([\w.]+)/', $query->sql, $statement) === 1 => $statement[1].' '.$statement[2],
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

    tenantSweepAt('2026-11-02 09:00:00');

    $central = [];

    foreach (['delete from contact_messages', 'delete from support_sessions', 'update users', 'delete from platform_audit_log', 'delete from webhook_events', 'delete from payment_events'] as $statement) {
        array_push($central, 'begin', $statement, 'commit');
    }

    $schema = fn (string $name): array => [
        'begin', 'update '.$name.'.bookings', 'commit',
        'begin', 'delete from '.$name.'.booking_meta', 'commit',
        'begin', 'delete from '.$name.'.audit_log', 'commit',
        'begin', 'update '.$name.'.bookings', 'commit',
        'begin', 'delete from '.$name.'.customer_links', 'commit',
        'begin', 'delete from '.$name.'.customer_sessions', 'commit',
        'begin', 'delete from '.$name.'.customer_profiles', 'commit',
        'begin', 'delete from '.$name.'.customer_notes', 'commit',
    ];

    // The stranded rows follow the tenant lines: the lapsed subscribers read by the rows due a
    // close, none here, and the checkout records cleared in one statement. The drop runs last and
    // reads the tenants due it; none is closing here.
    $stranded = ['read the lapsed subscribers due a close', 'begin', 'update subscriptions', 'commit'];

    expect(array_values(array_filter($trace)))->toBe([...$central, 'read tenants', ...$schema('tenant_luna'), ...$schema('tenant_bravo'), ...$stranded, 'read the tenants due a drop']);
});

it('sweeps nothing a second time in the same instant, every tenant line counting nought and the rows inside their periods standing', function () {
    $past = tenantSweepPast($this->luna);
    $inside = new DateTimeImmutable('2026-11-01 09:00:00', new DateTimeZone('UTC'));
    $kept = [
        'bookings' => tenantSweepRow($this->luna, 'bookings', 'confirmed', $inside),
        'audit_log' => tenantSweepRow($this->luna, 'audit_log', 'system', $inside),
        'customer_links' => tenantSweepRow($this->luna, 'customer_links', 'unused', new DateTimeImmutable('2026-11-02 09:10:00', new DateTimeZone('UTC'))),
        'customer_sessions' => tenantSweepRow($this->luna, 'customer_sessions', 'live', new DateTimeImmutable('2026-11-20 09:00:00', new DateTimeZone('UTC'))),
    ];

    tenantSweepAt('2026-11-02 09:00:00');
    $this->log->records = [];

    expect(tenantSweepAt('2026-11-02 09:00:00')['code'])->toBe(0)
        ->and(tenantSweepLogged($this->log))->toBe([
            'info "luna" "bookings" 0', 'info "luna" "booking_meta" 0', 'info "luna" "audit_log" 0', 'info "luna" "bookings" 0', 'info "luna" "customer_links" 0', 'info "luna" "customer_sessions" 0', 'info "luna" "customer_profiles" 0', 'info "luna" "customer_notes" 0',
            'info "bravo" "bookings" 0', 'info "bravo" "booking_meta" 0', 'info "bravo" "audit_log" 0', 'info "bravo" "bookings" 0', 'info "bravo" "customer_links" 0', 'info "bravo" "customer_sessions" 0', 'info "bravo" "customer_profiles" 0', 'info "bravo" "customer_notes" 0',
        ])
        ->and(DB::table('tenant_luna.bookings')->where('id', $past['erased'])->value('customer_email'))->toBeNull();

    foreach ($kept as $line => $id) {
        expect(tenantSweepKept($this->luna, $line, [$id]))->toBe([$id]);
    }

    expect(tenantSweepKept($this->luna, 'booking_meta', [$kept['bookings']]))->toBe([$kept['bookings']])
        ->and(tenantSweepKept($this->luna, 'manage tokens', [$kept['bookings']]))->toBe([$kept['bookings']]);
});

it('stops at a tenant line that throws, the earlier lines\' work in that schema and before it committed and standing, the later schemas untouched, logs the throw once by the tenant, the table and the SQLSTATE alone, and the next run picks up where it left', function () {
    $luna = tenantSweepPast($this->luna);
    $bravo = tenantSweepPast($this->bravo);
    DB::statement('alter table tenant_luna.customer_links rename to customer_links_away');

    $stopped = tenantSweepAt('2026-11-02 09:00:00');

    expect($stopped['code'])->toBe(1)
        ->and($stopped['output'])->toEndWith('tenant_luna.customer_links: the line failed, and the sweep stopped.')
        ->and(tenantSweepLogged($this->log))->toBe([
            'info "luna" "bookings" 1', 'info "luna" "booking_meta" 0', 'info "luna" "audit_log" 1', 'info "luna" "bookings" 1', 'error "luna" "customer_links" "42P01"',
        ])
        ->and(array_values(array_filter($this->log->records, fn (array $record): bool => $record['level'] === 'error')))->toBe([
            ['level' => 'error', 'message' => 'The retention sweep stopped', 'context' => ['tenant' => 'luna', 'table' => 'customer_links', 'exception' => QueryException::class, 'sqlstate' => '42P01']],
        ])
        ->and(tenantSweepKept($this->luna, 'bookings', [$luna['erased']]))->toBe([])
        ->and(tenantSweepKept($this->luna, 'manage tokens', [$luna['tokens']]))->toBe([])
        ->and(tenantSweepKept($this->luna, 'customer_sessions', [$luna['session']]))->toBe([$luna['session']])
        ->and(DB::table('tenant_luna.customer_links_away')->where('id', $luna['link'])->exists())->toBeTrue()
        ->and(tenantSweepKept($this->bravo, 'bookings', [$bravo['erased']]))->toBe([$bravo['erased']])
        ->and(tenantSweepKept($this->bravo, 'audit_log', [$bravo['trail']]))->toBe([$bravo['trail']]);

    DB::statement('alter table tenant_luna.customer_links_away rename to customer_links');
    $this->log->records = [];

    expect(tenantSweepAt('2026-11-03 09:00:00')['code'])->toBe(0)
        ->and(tenantSweepLogged($this->log))->toBe([
            'info "luna" "bookings" 0', 'info "luna" "booking_meta" 0', 'info "luna" "audit_log" 0', 'info "luna" "bookings" 0', 'info "luna" "customer_links" 1', 'info "luna" "customer_sessions" 1', 'info "luna" "customer_profiles" 0', 'info "luna" "customer_notes" 0',
            'info "bravo" "bookings" 1', 'info "bravo" "booking_meta" 0', 'info "bravo" "audit_log" 1', 'info "bravo" "bookings" 1', 'info "bravo" "customer_links" 1', 'info "bravo" "customer_sessions" 1', 'info "bravo" "customer_profiles" 0', 'info "bravo" "customer_notes" 0',
        ]);
});

it('logs and prints nothing of a row: no name, address, phone, note, hash or sealed value of anything the tenant lines swept', function () {
    $rows = tenantSweepPast($this->luna);
    $booking = DB::table('tenant_luna.bookings')->where('id', $rows['erased'])->first(['customer_email', 'manage_token_hash', 'manage_token_sealed']);
    $secrets = [
        'Casey Customer', '+30 210 765 4321', 'window seat',
        $booking?->customer_email, $booking?->manage_token_hash, $booking?->manage_token_sealed,
        DB::table('tenant_luna.customer_links')->where('id', $rows['link'])->value('token_hash'),
        DB::table('tenant_luna.customer_sessions')->where('id', $rows['session'])->value('session_hash'),
        'casey@example.com',
    ];

    $swept = tenantSweepAt('2026-11-02 09:00:00');

    foreach ($secrets as $secret) {
        expect($secret)->toBeString()
            ->and($this->log->text())->not->toContain($secret)
            ->and($swept['output'])->not->toContain($secret);
    }
});

/**
 * A profile and a note for an address, as the customer's page leaves them.
 */
function tenantSweepProfile(Tenant $tenant, string $email): void
{
    DB::table($tenant->schema_name.'.customer_profiles')->insert(['email' => $email, 'tags' => '{VIP}', 'birthday' => '1988-04-12', 'created_at' => '2023-12-01 09:00:00+00', 'updated_at' => '2023-12-01 09:00:00+00']);
    DB::table($tenant->schema_name.'.customer_notes')->insert(['email' => $email, 'body' => 'Allergic to ammonia colour.', 'author_actor' => 'user:1', 'author_name' => 'Luna Owner', 'created_at' => '2023-12-01 09:00:00+00']);
}

/**
 * The addresses whose profile, or whose notes, stand in a schema.
 *
 * @return list<string>
 */
function tenantSweepAddresses(Tenant $tenant, string $table): array
{
    /** @var list<string> $emails */
    $emails = DB::table($tenant->schema_name.'.'.$table)->orderBy('email')->selectRaw('email::text as email')->pluck('email')->all();

    return $emails;
}

it('removes a profile and its notes with the last booking that carried the address, in the run that takes that booking\'s customer, and keeps those of an address a booking still carries', function () {
    $old = new DateTimeImmutable('2024-01-01 09:00:00', new DateTimeZone('UTC'));
    $last = tenantSweepRow($this->luna, 'bookings', 'completed', $old);
    DB::table('tenant_luna.bookings')->where('id', $last)->update(['customer_email' => 'old@example.com']);
    $kept = tenantSweepRow($this->luna, 'bookings', 'completed', $old);
    DB::table('tenant_luna.bookings')->where('id', $kept)->update(['customer_email' => 'kept@example.com']);
    $recent = tenantSweepRow($this->luna, 'bookings', 'confirmed', new DateTimeImmutable('2026-11-01 09:00:00', new DateTimeZone('UTC')));
    DB::table('tenant_luna.bookings')->where('id', $recent)->update(['customer_email' => 'kept@example.com']);

    // gone@ is an address no booking carries any more, one erased before this run.
    foreach (['old@example.com', 'kept@example.com', 'gone@example.com'] as $email) {
        tenantSweepProfile($this->luna, $email);
        tenantSweepProfile($this->bravo, $email);
    }

    expect(app(BookingCustomersLine::class)->apply($this->luna))->toBe(2)
        ->and(app(CustomerProfilesLine::class)->table())->toBe('customer_profiles')
        ->and(app(CustomerNotesLine::class)->table())->toBe('customer_notes')
        ->and(app(CustomerProfilesLine::class)->apply($this->luna))->toBe(2)
        ->and(app(CustomerNotesLine::class)->apply($this->luna))->toBe(2)
        ->and(tenantSweepAddresses($this->luna, 'customer_profiles'))->toBe(['kept@example.com'])
        ->and(tenantSweepAddresses($this->luna, 'customer_notes'))->toBe(['kept@example.com'])
        ->and(tenantSweepAddresses($this->bravo, 'customer_profiles'))->toBe(['gone@example.com', 'kept@example.com', 'old@example.com'])
        ->and(app(CustomerProfilesLine::class)->apply($this->luna))->toBe(0)
        ->and(app(CustomerNotesLine::class)->apply($this->luna))->toBe(0);
});
