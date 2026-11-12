<?php

use App\Application\Clock;
use App\Retention\Erasure;
use App\Tenancy\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FixedClock;

pest()->use(RefreshDatabase::class);

/*
 * Erasure's primitive: over the bookings it is given, in one statement and one transaction, the
 * three customer columns nulled, the intake rows deleted and the manage token's hash and sealed
 * copy nulled, the row standing with everything else it holds, its items and its trail untouched,
 * and nothing sent.
 *
 * Two schemas built for real, luna and bravo, each holding a booking of the same id with items, a
 * note, both token columns and a trail, and luna a second booking the primitive is not given: an
 * erasure of luna's first booking must leave every other row of both schemas as it found it.
 *
 * Wrapped in a transaction: the primitive enters no transaction runner, and its own transaction
 * is a savepoint inside the wrap, so a statement that fails rolls back to it and the test reads
 * what stood before.
 */

beforeEach(function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-02 09:00:00'));
    $this->luna = provisionTenant('luna', ['name' => 'Luna Studio', 'ownerEmail' => 'owner@luna.example'])->tenant;
    $this->bravo = provisionTenant('bravo', ['name' => 'Bravo Studio', 'ownerEmail' => 'owner@bravo.example'])->tenant;
    $this->erased = erasureBooking($this->luna, 'Casey Customer', 'casey@example.com');
    $this->kept = erasureBooking($this->luna, 'Robin Regular', 'robin@example.com');
    $this->neighbour = erasureBooking($this->bravo, 'Casey Customer', 'casey@example.com');
});

/**
 * A booking of the tenant as the booking path leaves one: a two-segment chain, a note, the
 * manage token's hash and a sealed copy, and a trail of two rows. Answers its id.
 */
function erasureBooking(Tenant $tenant, string $name, string $email): int
{
    $schema = $tenant->schema_name;
    $service = DB::table($schema.'.services')->where('name', 'Cut')->value('id') ?? DB::table($schema.'.services')->insertGetId(serviceRow('Cut'));
    assert(is_int($service));
    $id = DB::table($schema.'.bookings')->insertGetId([
        ...bookingRow(),
        'customer_name' => $name,
        'customer_email' => $email,
        'customer_phone' => '+30 210 765 4321',
        'manage_token_hash' => hash('sha256', $email),
        'manage_token_sealed' => 'eyJpdiI6InNlYWxlZCJ9',
        'created_at' => '2026-10-01 09:00:00+00',
        'updated_at' => '2026-10-01 09:05:00+00',
    ]);

    foreach ([['2026-10-20 08:00:00+00', '2026-10-20 09:00:00+00'], ['2026-10-20 09:30:00+00', '2026-10-20 10:00:00+00']] as $sort => [$start, $end]) {
        DB::table($schema.'.booking_items')->insert([...itemRow($id, implicitStaffId($schema), $start, $end), 'sort' => $sort, 'service_id' => $service, 'blocks' => false]);
    }

    DB::table($schema.'.booking_meta')->insert(['booking_id' => $id, 'key' => 'note', 'value' => sprintf('%s would like the window seat.', $name)]);
    DB::table($schema.'.audit_log')->insert([
        ['booking_id' => $id, 'actor' => 'customer', 'action' => 'held', 'payload' => '{"status_to":"pending","item_count":2}', 'created_at' => '2026-10-01 09:00:00+00'],
        ['booking_id' => $id, 'actor' => 'customer', 'action' => 'confirmed', 'payload' => '{"status_from":"pending","status_to":"confirmed"}', 'created_at' => '2026-10-01 09:05:00+00'],
    ]);

    return $id;
}

/**
 * Every row of one table of a schema, in the order given, as plain arrays.
 *
 * @param  'id'|'booking_id, key'  $order
 * @return list<array<mixed>>
 */
function erasureTable(Tenant $tenant, string $table, string $order): array
{
    $rows = [];

    foreach (DB::select(sprintf('select * from %s.%s order by %s', $tenant->schema_name, $table, $order)) as $row) {
        assert($row instanceof stdClass);
        $rows[] = get_object_vars($row);
    }

    return $rows;
}

/**
 * Every row of the tables an erasure may and may not write, in a schema, as plain arrays.
 *
 * @return array{bookings: list<array<mixed>>, booking_meta: list<array<mixed>>, booking_items: list<array<mixed>>, audit_log: list<array<mixed>>}
 */
function erasureRows(Tenant $tenant): array
{
    return [
        'bookings' => erasureTable($tenant, 'bookings', 'id'),
        'booking_meta' => erasureTable($tenant, 'booking_meta', 'booking_id, key'),
        'booking_items' => erasureTable($tenant, 'booking_items', 'id'),
        'audit_log' => erasureTable($tenant, 'audit_log', 'id'),
    ];
}

/**
 * The rows of a table that belong to one booking: the booking's own row, or the rows naming it.
 *
 * @param  list<array<mixed>>  $rows
 * @return list<array<mixed>>
 */
function erasureOf(array $rows, int $booking): array
{
    return array_values(array_filter($rows, fn (array $row): bool => ($row['booking_id'] ?? $row['id'] ?? null) === $booking));
}

it('nulls the three customer columns and both token columns, deletes the booking\'s note, and leaves the row standing with every other column, its items and its trail as they were', function () {
    $before = erasureRows($this->luna);

    expect(app(Erasure::class)->bookings($this->luna, [$this->erased]))->toBe(1);

    $after = erasureRows($this->luna);
    [$row] = erasureOf($after['bookings'], $this->erased) + [[]];
    [$was] = erasureOf($before['bookings'], $this->erased) + [[]];
    $erasedColumns = array_flip(['customer_name', 'customer_email', 'customer_phone', 'manage_token_hash', 'manage_token_sealed']);

    expect($was)->not->toBe([])
        ->and(array_intersect_key($row, $erasedColumns))->toBe(array_map(fn (): mixed => null, $erasedColumns))
        ->and(array_diff_key($row, $erasedColumns))->toBe(array_diff_key($was, $erasedColumns))
        ->and(erasureOf($after['booking_meta'], $this->erased))->toBe([])
        ->and(erasureOf($before['booking_meta'], $this->erased))->toHaveCount(1)
        ->and($after['booking_items'])->toBe($before['booking_items'])
        ->and($after['audit_log'])->toBe($before['audit_log']);
});

it('touches nothing of a booking it was not given, in its own schema or in a neighbour\'s holding a booking of the same id', function () {
    $luna = erasureRows($this->luna);
    $bravo = erasureRows($this->bravo);

    app(Erasure::class)->bookings($this->luna, [$this->erased]);

    expect($this->neighbour)->toBe($this->erased)
        ->and(erasureRows($this->bravo))->toBe($bravo)
        ->and(erasureOf(erasureRows($this->luna)['bookings'], $this->kept))->toBe(erasureOf($luna['bookings'], $this->kept))->toHaveCount(1)
        ->and(erasureOf(erasureRows($this->luna)['booking_meta'], $this->kept))->toBe(erasureOf($luna['booking_meta'], $this->kept))->toHaveCount(1);
});

it('runs as one statement in one transaction, writing bookings and booking_meta alone, reading no clock, and sends nothing', function () {
    Queue::fake();
    Mail::fake();
    $trace = [];
    DB::listen(function (QueryExecuted $query) use (&$trace): void {
        $trace[] = $query->sql;
    });
    Event::listen(TransactionBeginning::class, function () use (&$trace): void {
        $trace[] = 'begin';
    });
    Event::listen(TransactionCommitted::class, function () use (&$trace): void {
        $trace[] = 'commit';
    });

    app(Erasure::class)->bookings($this->luna, [$this->erased]);

    expect($trace)->toHaveCount(3)
        ->and($trace[0])->toBe('begin')
        ->and($trace[2])->toBe('commit')
        ->and(preg_match_all('/\b(update|delete from|insert into) ([\w.]+)/', $trace[1], $writes))->toBe(2)
        ->and($writes[0])->toBe(['update tenant_luna.bookings', 'delete from tenant_luna.booking_meta'])
        ->and($trace[1])->not->toContain('audit_log')
        ->and($trace[1])->not->toContain('booking_items')
        ->and($trace[1])->not->toContain('now()');

    Queue::assertNothingPushed();
    Mail::assertNothingOutgoing();
});

it('erases together or not at all: a statement whose intake delete fails leaves the customer and the tokens on the row', function () {
    DB::unprepared(<<<'SQL'
        create function tenant_luna.refuse_meta_delete() returns trigger language plpgsql as $$
        begin
            raise exception 'the intake rows stay';
        end
        $$;
        create trigger refuse_meta_delete before delete on tenant_luna.booking_meta
            for each row execute function tenant_luna.refuse_meta_delete();
        SQL);
    $before = erasureRows($this->luna);

    expect(fn () => app(Erasure::class)->bookings($this->luna, [$this->erased]))->toThrow(QueryException::class, 'the intake rows stay')
        ->and(erasureRows($this->luna))->toBe($before);
});

it('erases every booking it is given and none it is not, answering how many, and nothing for an empty list or an id naming no booking', function () {
    $third = erasureBooking($this->luna, 'Sam Second', 'sam@example.com');

    expect(app(Erasure::class)->bookings($this->luna, []))->toBe(0)
        ->and(app(Erasure::class)->bookings($this->luna, [999999]))->toBe(0)
        ->and(DB::table('tenant_luna.bookings')->whereNull('customer_email')->count())->toBe(0)
        ->and(app(Erasure::class)->bookings($this->luna, [$this->erased, $third]))->toBe(2)
        ->and(DB::table('tenant_luna.bookings')->whereNull('customer_email')->orderBy('id')->pluck('id')->all())->toBe([$this->erased, $third])
        ->and(DB::table('tenant_luna.booking_meta')->pluck('booking_id')->all())->toBe([$this->kept]);
});
