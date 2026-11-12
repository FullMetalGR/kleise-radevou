<?php

use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ConnectionState;
use App\Domain\Audit\Actor;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingChannel;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Infrastructure\Db\TextArray;
use App\Tenancy\MapState;
use App\Tenancy\StaffColour;
use App\Tenancy\TenantMigrator;
use App\Tenancy\Theme;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantMigration;

pest()->use(RefreshDatabase::class);

/**
 * The column's SQL type as PostgreSQL prints it, followed by "not null" where it applies, inside
 * a named schema.
 */
function schemaColumn(string $schema, string $table, string $column): mixed
{
    return DB::scalar(
        "select format_type(a.atttypid, a.atttypmod) || case when a.attnotnull then ' not null' else '' end
         from pg_attribute a
         join pg_class c on c.oid = a.attrelid
         join pg_namespace n on n.oid = c.relnamespace
         where n.nspname = ? and c.relname = ? and a.attname = ? and a.attnum > 0 and not a.attisdropped",
        [$schema, $table, $column],
    );
}

/**
 * @return list<string>
 */
function schemaTables(string $schema): array
{
    /** @var list<string> $tables */
    $tables = DB::table('pg_tables')->where('schemaname', $schema)->pluck('tablename')->all();

    // Sorted here rather than by the database, whose collation may rank an underscore after a
    // letter.
    sort($tables);

    return $tables;
}

function indexDefinition(string $schema, string $index): mixed
{
    return DB::table('pg_indexes')->where('schemaname', $schema)->where('indexname', $index)->value('indexdef');
}

function constraintDefinition(string $schema, string $table, string $constraint): mixed
{
    return DB::scalar(
        'select pg_get_constraintdef(c.oid)
         from pg_constraint c
         join pg_class t on t.oid = c.conrelid
         join pg_namespace n on n.oid = t.relnamespace
         where n.nspname = ? and t.relname = ? and c.conname = ?',
        [$schema, $table, $constraint],
    );
}

beforeEach(function () {
    provisionTenant('acme');
});

it('creates the seventeen tenant tables and the migrations table, and nothing else', function () {
    expect(schemaTables('tenant_acme'))
        ->toBe(['audit_log', 'blackouts', 'booking_items', 'booking_meta', 'bookings', 'customer_links', 'customer_notes', 'customer_profiles', 'customer_sessions', 'migrations', 'payment_connections', 'service_staff', 'services', 'settings', 'shift_overrides', 'shift_patterns', 'staff', 'staff_days']);
});

it('types settings.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'settings', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['checkout_ttl_min', 'integer not null'],
    ['approval_ttl_hours', 'integer not null'],
    ['payment_ttl_hours', 'integer not null'],
    ['reminder_lead_hours', 'integer not null'],
    ['granularity_min', 'integer not null'],
    ['start_interval_min', 'integer not null'],
    ['start_of_week', 'integer not null'],
    ['date_format', 'text not null'],
    ['time_format', 'text not null'],
    ['emails_off', 'text[] not null'],
    ['brand_logo_path', 'text'],
    ['hours_confirmed_at', 'timestamp with time zone'],
    ['link_copied_at', 'timestamp with time zone'],
    ['about', 'text'],
    ['address_line1', 'text'],
    ['address_line2', 'text'],
    ['city', 'text'],
    ['postal_code', 'text'],
    ['country', 'text'],
    ['phone', 'text'],
    ['public_email', 'text'],
    ['website', 'text'],
    ['instagram', 'text'],
    ['facebook', 'text'],
    ['google_reviews_url', 'text'],
    ['important_info', 'text'],
    ['cover_path', 'text'],
    ['gallery_paths', 'text[] not null'],
    ['lat', 'double precision'],
    ['lng', 'double precision'],
    ['map_path', 'text'],
    ['map_state', 'text not null'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
    ['language', 'text'],
    ['colour_by', 'text not null'],
    ['theme', 'text not null'],
]);

it('types staff.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'staff', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['user_id', 'bigint'],
    ['name', 'text not null'],
    ['email', 'citext'],
    ['status', 'text not null'],
    ['is_implicit', 'boolean not null'],
    ['sort', 'integer not null'],
    ['colour', 'text'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('types staff_days.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'staff_days', $column))->toBe($type);
})->with([
    ['staff_id', 'bigint not null'],
    ['day', 'date not null'],
    ['rev', 'bigint not null'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('gives staff_days no surrogate id', function () {
    expect(schemaColumn('tenant_acme', 'staff_days', 'id'))->toBeNull()
        ->and(constraintDefinition('tenant_acme', 'staff_days', 'staff_days_pkey'))->toBe('PRIMARY KEY (staff_id, day)');
});

it('types bookings.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'bookings', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['uuid', 'uuid not null'],
    ['status', 'text not null'],
    ['hold_class', 'text'],
    ['hold_expires_at', 'timestamp with time zone'],
    ['customer_name', 'text'],
    ['customer_email', 'citext'],
    ['customer_phone', 'text'],
    ['total_minor', 'bigint not null'],
    ['currency', 'character(3) not null'],
    ['payment_mode', 'text not null'],
    ['requires_approval', 'boolean not null'],
    ['approved_at', 'timestamp with time zone'],
    ['approved_by', 'bigint'],
    ['rejection_reason', 'text'],
    ['manage_token_hash', 'text'],
    ['payment_ref', 'text'],
    ['manage_token_sealed', 'text'],
    ['on_approval_timeout', 'text not null'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
    ['source', 'text not null'],
    ['channel', 'text'],
    ['language', 'text not null'],
]);

it('types booking_items.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'booking_items', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['booking_id', 'bigint not null'],
    ['sort', 'integer not null'],
    ['service_id', 'bigint not null'],
    ['staff_id', 'bigint not null'],
    ['start_utc', 'timestamp with time zone not null'],
    ['end_utc', 'timestamp with time zone not null'],
    ['block_start_utc', 'timestamp with time zone not null'],
    ['block_end_utc', 'timestamp with time zone not null'],
    ['block_span', 'tstzrange'],
    ['processing_ends_utc', 'timestamp with time zone'],
    ['price_minor', 'bigint not null'],
    ['blocks', 'boolean not null'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('generates block_span as the stored half-open range of its endpoints', function () {
    $generated = DB::scalar(
        "select a.attgenerated::text || ':' || pg_get_expr(d.adbin, d.adrelid)
         from pg_attribute a
         join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
         join pg_class c on c.oid = a.attrelid
         join pg_namespace n on n.oid = c.relnamespace
         where n.nspname = 'tenant_acme' and c.relname = 'booking_items' and a.attname = 'block_span'",
    );

    expect($generated)->toBe("s:tstzrange(block_start_utc, block_end_utc, '[)'::text)");
});

it('installs btree_gist beside citext in the public schema, not in the tenant schema', function () {
    $extensions = DB::table('pg_extension')
        ->join('pg_namespace', 'pg_namespace.oid', '=', 'pg_extension.extnamespace')
        ->whereIn('extname', ['btree_gist', 'citext'])
        ->orderBy('extname')
        ->pluck('nspname', 'extname')
        ->all();

    expect($extensions)->toBe(['btree_gist' => 'public', 'citext' => 'public']);
});

it('declares the exclusion constraint over staff and span where the item blocks', function () {
    expect(constraintDefinition('tenant_acme', 'booking_items', 'booking_items_no_overlap'))
        ->toBe('EXCLUDE USING gist (staff_id WITH =, block_span WITH &&) WHERE (blocks)');
});

it('has the index {index} on booking_items and no second index over the exclusion columns', function (string $index, string $definition) {
    expect(indexDefinition('tenant_acme', $index))->toBe($definition);

    /** @var list<string> $indexes */
    $indexes = DB::table('pg_indexes')
        ->where('schemaname', 'tenant_acme')
        ->where('tablename', 'booking_items')
        ->orderBy('indexname')
        ->pluck('indexname')
        ->all();

    expect($indexes)->toBe(['booking_items_booking', 'booking_items_no_overlap', 'booking_items_pkey', 'booking_items_service']);
})->with([
    ['booking_items_booking', 'CREATE INDEX booking_items_booking ON tenant_acme.booking_items USING btree (booking_id)'],
    ['booking_items_service', 'CREATE INDEX booking_items_service ON tenant_acme.booking_items USING btree (service_id)'],
    ['booking_items_no_overlap', 'CREATE INDEX booking_items_no_overlap ON tenant_acme.booking_items USING gist (staff_id, block_span) WHERE blocks'],
]);

it('has the index {index} on bookings', function (string $index, string $definition) {
    expect(indexDefinition('tenant_acme', $index))->toBe($definition);
})->with([
    ['bookings_uuid_unique', 'CREATE UNIQUE INDEX bookings_uuid_unique ON tenant_acme.bookings USING btree (uuid)'],
    ['bookings_sweeper', 'CREATE INDEX bookings_sweeper ON tenant_acme.bookings USING btree (status, hold_expires_at)'],
    ['bookings_customer_email', 'CREATE INDEX bookings_customer_email ON tenant_acme.bookings USING btree (customer_email)'],
]);

it('cascades an item away with its booking', function () {
    itemService('tenant_acme');
    $bookingId = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());
    DB::table('tenant_acme.booking_items')->insert(itemRow($bookingId, 1, '2026-10-01 09:00+00', '2026-10-01 10:00+00'));

    DB::table('tenant_acme.bookings')->where('id', $bookingId)->delete();

    expect(DB::table('tenant_acme.booking_items')->count())->toBe(0);
});

it('accepts the booking status {status}', function (string $status) {
    DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'status' => $status]);

    expect(DB::table('tenant_acme.bookings')->where('status', $status)->exists())->toBeTrue();
})->with(['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed', 'completed', 'no_show', 'cancelled', 'rejected', 'expired']);

/**
 * The quoted values a CHECK constraint lists, in the order the constraint spells them.
 *
 * @return list<string>
 */
function checkedValues(string $table, string $constraint): array
{
    $definition = constraintDefinition('tenant_acme', $table, $constraint);

    expect($definition)->toBeString();
    assert(is_string($definition));
    preg_match_all("/'([a-z_]+)'::text/", $definition, $matches);

    return $matches[1];
}

it('lists in bookings_status_check exactly the cases of BookingStatus, in order, so the enum and the constraint cannot drift apart unseen', function () {
    expect(checkedValues('bookings', 'bookings_status_check'))
        ->toBe(array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::cases()));
});

it('lists in bookings_hold_class_check exactly the cases of HoldClass, in order', function () {
    expect(checkedValues('bookings', 'bookings_hold_class_check'))
        ->toBe(array_map(fn (HoldClass $class): string => $class->value, HoldClass::cases()));
});

it('refuses a tenth booking status', function () {
    expect(fn () => DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'status' => 'held']))
        ->toThrow(QueryException::class, '23514');
});

it('accepts the hold class {class} and null', function (?string $class) {
    DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'hold_class' => $class]);

    expect(DB::table('tenant_acme.bookings')->count())->toBe(1);
})->with(['checkout', 'approval', 'payment', null]);

it('refuses a fourth hold class', function () {
    expect(fn () => DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'hold_class' => 'manual']))
        ->toThrow(QueryException::class, '23514');
});

it('refuses a payment mode outside free, online and onsite', function () {
    expect(fn () => DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'payment_mode' => 'deposit']))
        ->toThrow(QueryException::class, '23514');
});

it('gives bookings.source no default, so a booking written without one fails at the database', function () {
    $row = bookingRow();
    unset($row['source']);

    expect(DB::scalar("select column_default from information_schema.columns where table_schema = 'tenant_acme' and table_name = 'bookings' and column_name = 'source'"))->toBeNull()
        ->and(fn () => DB::table('tenant_acme.bookings')->insert($row))->toThrow(QueryException::class, '23502');
});

it('names a booking written before the column existed embed, and one an admin created admin, read off the trail', function () {
    $migration = require database_path('migrations/tenant/0001_01_01_000023_add_source_and_channel_to_bookings.php');
    assert(is_object($migration) && method_exists($migration, 'up') && method_exists($migration, 'down'));

    // The migration names its tables unqualified, as the tenant migrator's search path reads
    // them; set for this transaction alone, which the test rolls back.
    DB::statement('set local search_path to tenant_acme, public');
    $migration->down();

    $row = bookingRow();
    unset($row['source']);
    $trail = function (int $booking, string $actor, string $action): void {
        DB::table('tenant_acme.audit_log')->insert(['booking_id' => $booking, 'actor' => $actor, 'action' => $action]);
    };

    $held = DB::table('tenant_acme.bookings')->insertGetId([...$row, 'uuid' => (string) Str::uuid()]);
    $trail($held, 'customer', 'held');
    $trail($held, 'customer', 'confirmed');
    $approved = DB::table('tenant_acme.bookings')->insertGetId([...$row, 'uuid' => (string) Str::uuid()]);
    $trail($approved, 'customer', 'held');
    $trail($approved, 'user:7', 'approved');
    $manual = DB::table('tenant_acme.bookings')->insertGetId([...$row, 'uuid' => (string) Str::uuid()]);
    $trail($manual, 'user:7', 'confirmed');
    $untraced = DB::table('tenant_acme.bookings')->insertGetId([...$row, 'uuid' => (string) Str::uuid()]);

    $migration->up();

    expect(DB::table('tenant_acme.bookings')->orderBy('id')->pluck('source', 'id')->all())
        ->toBe([$held => 'embed', $approved => 'embed', $manual => 'admin', $untraced => 'embed'])
        ->and(DB::table('tenant_acme.bookings')->whereNotNull('channel')->count())->toBe(0)
        ->and(schemaColumn('tenant_acme', 'bookings', 'source'))->toBe('text not null');
});

it('lists in bookings_source_check exactly the cases of BookingSource, in order', function () {
    expect(checkedValues('bookings', 'bookings_source_check'))
        ->toBe(array_map(fn (BookingSource $source): string => $source->value, BookingSource::cases()));
});

it('lists in bookings_channel_check exactly the cases of BookingChannel, in order, and admits null', function () {
    DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'channel' => null]);

    expect(checkedValues('bookings', 'bookings_channel_check'))
        ->toBe(array_map(fn (BookingChannel $channel): string => $channel->value, BookingChannel::cases()))
        ->and(DB::table('tenant_acme.bookings')->whereNull('channel')->count())->toBe(1);
});

it('holds the three customer columns to all null, an erased customer, or a name and an address set, through bookings_customer_check of migration 000028', function () {
    $erased = DB::table('tenant_acme.bookings')->insertGetId([...bookingRow(), 'customer_name' => null, 'customer_email' => null]);
    $phoned = DB::table('tenant_acme.bookings')->insertGetId([...bookingRow(), 'customer_phone' => '+30 210 000 0000']);

    expect(constraintDefinition('tenant_acme', 'bookings', 'bookings_customer_check'))->toBe(
        'CHECK ((((customer_name IS NULL) AND (customer_email IS NULL) AND (customer_phone IS NULL)) OR ((customer_name IS NOT NULL) AND (customer_email IS NOT NULL))))',
    )
        ->and(DB::table('tenant_acme.bookings')->whereIn('id', [$erased, $phoned])->orderBy('id')->get(['customer_name', 'customer_email', 'customer_phone'])->map(fn (stdClass $row): array => (array) $row)->all())->toBe([
            ['customer_name' => null, 'customer_email' => null, 'customer_phone' => null],
            ['customer_name' => 'Casey Customer', 'customer_email' => 'casey@example.com', 'customer_phone' => '+30 210 000 0000'],
        ]);
});

it('refuses a booking holding {what} through bookings_customer_check', function (array $customer) {
    expect(fn () => DB::transaction(fn () => DB::table('tenant_acme.bookings')->insert([...bookingRow(), ...$customer])))
        ->toThrow(QueryException::class, 'bookings_customer_check')
        ->and(DB::table('tenant_acme.bookings')->count())->toBe(0);
})->with([
    'a name without an address' => [['customer_email' => null]],
    'an address without a name' => [['customer_name' => null]],
    'a phone without a name or an address' => [['customer_name' => null, 'customer_email' => null, 'customer_phone' => '+30 210 000 0000']],
    'a phone and an address without a name' => [['customer_name' => null, 'customer_phone' => '+30 210 000 0000']],
]);

it('refuses to null a booked customer\'s name alone, or the address alone, on a row that stands', function (string $column) {
    $id = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());

    expect(fn () => DB::transaction(fn () => DB::table('tenant_acme.bookings')->where('id', $id)->update([$column => null])))
        ->toThrow(QueryException::class, 'bookings_customer_check')
        ->and(DB::table('tenant_acme.bookings')->where('id', $id)->value($column))->toBeString();
})->with(['customer_name', 'customer_email']);

it('refuses a booking whose {column} is {value}', function (string $column, string $value) {
    expect(fn () => DB::table('tenant_acme.bookings')->insert([...bookingRow(), $column => $value]))
        ->toThrow(QueryException::class, '23514');
})->with([
    ['source', 'widget'],
    ['channel', 'tiktok'],
    ['channel', ''],
]);

it('types booking_meta.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'booking_meta', $column))->toBe($type);
})->with([
    ['booking_id', 'bigint not null'],
    ['key', 'text not null'],
    ['value', 'text not null'],
]);

it('keys booking_meta by the booking and the key and takes the rows away with their booking', function () {
    $bookingId = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());
    DB::table('tenant_acme.booking_meta')->insert(['booking_id' => $bookingId, 'key' => 'note', 'value' => 'A quiet chair, please.']);

    expect(constraintDefinition('tenant_acme', 'booking_meta', 'booking_meta_pkey'))->toBe('PRIMARY KEY (booking_id, key)')
        ->and(constraintDefinition('tenant_acme', 'booking_meta', 'booking_meta_booking_id_fkey'))->toBe('FOREIGN KEY (booking_id) REFERENCES tenant_acme.bookings(id) ON DELETE CASCADE');

    DB::table('tenant_acme.bookings')->where('id', $bookingId)->delete();

    expect(DB::table('tenant_acme.booking_meta')->count())->toBe(0);
});

it('refuses a second row of one key for one booking', function () {
    $bookingId = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());
    DB::table('tenant_acme.booking_meta')->insert(['booking_id' => $bookingId, 'key' => 'note', 'value' => 'A quiet chair, please.']);

    expect(fn () => DB::table('tenant_acme.booking_meta')->insert(['booking_id' => $bookingId, 'key' => 'note', 'value' => 'Again.']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('admits a note of {length} characters', function (int $length) {
    $bookingId = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());
    DB::table('tenant_acme.booking_meta')->insert(['booking_id' => $bookingId, 'key' => 'note', 'value' => str_repeat('e', $length)]);

    expect(DB::table('tenant_acme.booking_meta')->count())->toBe(1);
})->with([1, 500]);

it('refuses a note of {length} characters through the CHECK, as the hold refuses past five hundred and stores a blank one as no row', function (int $length) {
    $bookingId = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());

    expect(fn () => DB::table('tenant_acme.booking_meta')->insert(['booking_id' => $bookingId, 'key' => 'note', 'value' => str_repeat('e', $length)]))
        ->toThrow(QueryException::class, '23514');
})->with([0, 501]);

/*
 * The customer's implicit account: the one-time link a My bookings request mints and the session
 * it opens, each keyed by the address as citext compares it and each holding the sha256 of its
 * secret, never the secret.
 */

it('types {table}.{column} as {type}', function (string $table, string $column, string $type) {
    expect(schemaColumn('tenant_acme', $table, $column))->toBe($type);
})->with([
    ['customer_links', 'id', 'bigint not null'],
    ['customer_links', 'email', 'citext not null'],
    ['customer_links', 'token_hash', 'text not null'],
    ['customer_links', 'created_at', 'timestamp with time zone not null'],
    ['customer_links', 'expires_at', 'timestamp with time zone not null'],
    ['customer_links', 'used_at', 'timestamp with time zone'],
    ['customer_sessions', 'id', 'bigint not null'],
    ['customer_sessions', 'email', 'citext not null'],
    ['customer_sessions', 'session_hash', 'text not null'],
    ['customer_sessions', 'created_at', 'timestamp with time zone not null'],
    ['customer_sessions', 'expires_at', 'timestamp with time zone not null'],
    ['customer_sessions', 'revoked_at', 'timestamp with time zone'],
]);

/**
 * A row of one of the two tables as its writer makes it: an address, the sha256 of a secret, and
 * a window of the given minutes from a fixed instant written as UTC text with its offset.
 *
 * @return array<string, string>
 */
function accessRow(string $hashColumn, string $email = 'Casey@Example.com', int $minutes = 15, string $hash = ''): array
{
    return [
        'email' => $email,
        $hashColumn => $hash === '' ? hash('sha256', bin2hex(random_bytes(32))) : $hash,
        'created_at' => '2026-11-02 06:00:00+00:00',
        'expires_at' => (new DateTimeImmutable('2026-11-02 06:00:00', new DateTimeZone('UTC')))->add(new DateInterval('PT'.$minutes.'M'))->format('Y-m-d H:i:sP'),
    ];
}

it('holds one row per hash in {table}, through {unique}, and finds a row by its address in any case', function (string $table, string $hashColumn, string $unique) {
    $hash = hash('sha256', 'one secret');
    DB::table('tenant_acme.'.$table)->insert(accessRow($hashColumn, hash: $hash));

    expect(constraintDefinition('tenant_acme', $table, $unique))->toBe('UNIQUE ('.$hashColumn.')')
        ->and(DB::table('tenant_acme.'.$table)->where('email', 'casey@example.com')->exists())->toBeTrue()
        ->and(fn () => DB::table('tenant_acme.'.$table)->insert(accessRow($hashColumn, 'someone@example.com', hash: $hash)))
        ->toThrow(UniqueConstraintViolationException::class);
})->with([
    ['customer_links', 'token_hash', 'customer_links_token_hash_unique'],
    ['customer_sessions', 'session_hash', 'customer_sessions_session_hash_unique'],
]);

it('refuses in {table} {what} through the CHECK', function (string $table, string $hashColumn, array $row, string $what) {
    expect(fn () => DB::table('tenant_acme.'.$table)->insert([...accessRow($hashColumn), ...$row]))
        ->toThrow(QueryException::class, '23514');
})->with([
    ['customer_links', 'token_hash', ['token_hash' => 'not-a-digest'], 'a hash that is no sha256 in hex'],
    ['customer_links', 'token_hash', ['token_hash' => strtoupper(hash('sha256', 'x'))], 'a digest in upper case'],
    ['customer_links', 'token_hash', ['expires_at' => '2026-11-02 06:00:00+00:00'], 'an expiry that is not after the minting'],
    ['customer_sessions', 'session_hash', ['session_hash' => 'not-a-digest'], 'a hash that is no sha256 in hex'],
    ['customer_sessions', 'session_hash', ['expires_at' => '2026-11-01 06:00:00+00:00'], 'an expiry before the opening'],
]);

it('refuses a processing end at or before the item end', function () {
    itemService('tenant_acme');
    $bookingId = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());

    expect(fn () => DB::table('tenant_acme.booking_items')->insert([
        ...itemRow($bookingId, 1, '2026-10-01 09:00+00', '2026-10-01 10:00+00'),
        'processing_ends_utc' => '2026-10-01 10:00+00',
    ]))->toThrow(QueryException::class, '23514');
});

it('refuses a second staff status', function () {
    expect(fn () => DB::table('tenant_acme.staff')->insert(['name' => 'Sam', 'status' => 'on-leave']))
        ->toThrow(QueryException::class, '23514');
});

it('holds the settings defaults, with a null brand', function () {
    $row = DB::table('tenant_acme.settings')->first();

    expect($row)->not->toBeNull()
        ->and($row?->id)->toBe(1)
        ->and($row?->checkout_ttl_min)->toBe(15)
        ->and($row?->approval_ttl_hours)->toBe(48)
        ->and($row?->payment_ttl_hours)->toBe(24)
        ->and($row?->reminder_lead_hours)->toBe(24)
        ->and($row?->granularity_min)->toBe(5)
        ->and($row?->start_interval_min)->toBe(15)
        ->and($row?->start_of_week)->toBe(1)
        ->and($row?->date_format)->toBe('d/m/Y')
        ->and($row?->time_format)->toBe('H:i')
        ->and($row?->emails_off)->toBe('{}')
        ->and($row?->brand_logo_path)->toBeNull()
        ->and($row?->colour_by)->toBe('staff')
        ->and($row?->theme)->toBe('neutral');
});

it('refuses a second settings row through the CHECK', function () {
    expect(fn () => DB::table('tenant_acme.settings')->insert(['id' => 2]))
        ->toThrow(QueryException::class, '23514');
});

it('checks the start interval to be positive, to divide the hour and to fall on the granularity', function () {
    expect(constraintDefinition('tenant_acme', 'settings', 'settings_start_interval_check'))
        ->toBe('CHECK (((start_interval_min > 0) AND ((60 % start_interval_min) = 0) AND ((start_interval_min % granularity_min) = 0)))');
});

it('holds the gallery at eight photos or fewer through the CHECK', function () {
    $paths = array_map(fn (int $at): string => sprintf('tenants/acme/gallery-%016d.png', $at), range(1, 9));

    DB::table('tenant_acme.settings')->where('id', 1)->update(['gallery_paths' => TextArray::encode(array_slice($paths, 0, 8))]);

    expect(constraintDefinition('tenant_acme', 'settings', 'settings_gallery_paths_check'))->toBe('CHECK ((cardinality(gallery_paths) <= 8))')
        ->and(fn () => DB::table('tenant_acme.settings')->where('id', 1)->update(['gallery_paths' => TextArray::encode($paths)]))->toThrow(QueryException::class, '23514');
});

it('lists in settings_map_state_check exactly the cases of MapState, in order, defaulting a new row to none', function () {
    expect(checkedValues('settings', 'settings_map_state_check'))
        ->toBe(array_map(fn (MapState $state): string => $state->value, MapState::cases()))
        ->and(DB::table('tenant_acme.settings')->where('id', 1)->value('map_state'))->toBe('none')
        ->and(fn () => DB::table('tenant_acme.settings')->where('id', 1)->update(['map_state' => 'drawing']))->toThrow(QueryException::class, '23514');
});

it('refuses settings whose {what} through the CHECK', function (array $values) {
    /** @var array<string, int> $values */
    expect(fn () => DB::table('tenant_acme.settings')->where('id', 1)->update($values))
        ->toThrow(QueryException::class, '23514');
})->with([
    'start interval is 7, which does not divide the hour' => [['start_interval_min' => 7]],
    'start interval is 25, which does not divide the hour' => [['start_interval_min' => 25]],
    'start interval is 0' => [['start_interval_min' => 0]],
    'start interval is -15, which PostgreSQL finds divides the hour' => [['start_interval_min' => -15]],
    'start interval is 15 over a granularity of 10' => [['granularity_min' => 10, 'start_interval_min' => 15]],
    'granularity moves to 10 under the default start interval of 15' => [['granularity_min' => 10]],
    'payment link lives zero hours' => [['payment_ttl_hours' => 0]],
    'payment link lives -24 hours' => [['payment_ttl_hours' => -24]],
]);

it('holds the payment link at one hour or more through the CHECK of migration 000027, admitting one hour', function () {
    DB::table('tenant_acme.settings')->where('id', 1)->update(['payment_ttl_hours' => 1]);

    expect(constraintDefinition('tenant_acme', 'settings', 'settings_payment_ttl_hours_check'))->toBe('CHECK ((payment_ttl_hours >= 1))')
        ->and(DB::table('tenant_acme.settings')->where('id', 1)->value('payment_ttl_hours'))->toBe(1);
});

it('admits a start interval of {interval} over a granularity of {granularity}', function (int $granularity, int $interval) {
    DB::table('tenant_acme.settings')->where('id', 1)->update(['granularity_min' => $granularity, 'start_interval_min' => $interval]);

    expect(DB::table('tenant_acme.settings')->where('id', 1)->value('start_interval_min'))->toBe($interval);
})->with([
    [5, 60],
    [5, 5],
    [10, 30],
]);

it('allows exactly one implicit staff row and refuses a second through the index', function () {
    expect(DB::table('tenant_acme.staff')->where('is_implicit', true)->count())->toBe(1);

    DB::table('tenant_acme.staff')->insert(['name' => 'Sam', 'status' => 'active']);

    expect(fn () => DB::table('tenant_acme.staff')->insert(['name' => 'Second Business', 'status' => 'active', 'is_implicit' => true]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('compares staff and customer emails case-insensitively', function () {
    DB::table('tenant_acme.staff')->insert(['name' => 'Sam', 'status' => 'active', 'email' => 'Sam@Example.com']);

    expect(DB::table('tenant_acme.staff')->where('email', 'sam@example.com')->exists())->toBeTrue();
});

it('keeps the central schema free of tenant tables', function () {
    expect(array_intersect(schemaTables('public'), ['settings', 'staff', 'staff_days', 'bookings', 'booking_items', 'booking_meta', 'customer_links', 'customer_sessions', 'services', 'service_staff', 'shift_patterns', 'shift_overrides', 'blackouts']))->toBe([]);
});

it('types services.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'services', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['name', 'text not null'],
    ['description', 'text not null'],
    ['duration_min', 'integer not null'],
    ['processing_gap_min', 'integer not null'],
    ['buffer_before_min', 'integer not null'],
    ['buffer_after_min', 'integer not null'],
    ['price_minor', 'bigint not null'],
    ['currency', 'character(3) not null'],
    ['payment_mode', 'text not null'],
    ['requires_approval', 'boolean not null'],
    ['approval_hold_hours', 'integer'],
    ['on_approval_timeout', 'text not null'],
    ['cancel_window_hours', 'integer not null'],
    ['reschedule_window_hours', 'integer not null'],
    ['lead_time_min', 'integer not null'],
    ['horizon_days', 'integer not null'],
    ['status', 'text not null'],
    ['sort', 'integer not null'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('has no service type column and no capacity column', function () {
    expect(schemaColumn('tenant_acme', 'services', 'type'))->toBeNull()
        ->and(schemaColumn('tenant_acme', 'services', 'capacity'))->toBeNull();
});

it('holds the services defaults', function () {
    DB::table('tenant_acme.services')->insert(serviceRow('Cut'));

    $row = DB::table('tenant_acme.services')->where('name', 'Cut')->first();

    expect($row)->not->toBeNull()
        ->and($row?->description)->toBe('')
        ->and($row?->processing_gap_min)->toBe(0)
        ->and($row?->buffer_before_min)->toBe(0)
        ->and($row?->buffer_after_min)->toBe(0)
        ->and($row?->price_minor)->toBe(0)
        ->and($row?->requires_approval)->toBeFalse()
        ->and($row?->approval_hold_hours)->toBeNull()
        ->and($row?->on_approval_timeout)->toBe('expire')
        ->and($row?->cancel_window_hours)->toBe(24)
        ->and($row?->reschedule_window_hours)->toBe(24)
        ->and($row?->lead_time_min)->toBe(0)
        ->and($row?->horizon_days)->toBe(60)
        ->and($row?->sort)->toBe(0);
});

it('lists in services_payment_mode_check exactly the cases of PaymentMode, in order, so the enum and the constraint cannot drift apart unseen', function () {
    expect(checkedValues('services', 'services_payment_mode_check'))
        ->toBe(array_map(fn (PaymentMode $mode): string => $mode->value, PaymentMode::cases()));
});

it('lists in services_on_approval_timeout_check and bookings_on_approval_timeout_check exactly the cases of ApprovalTimeout, in order, so the enum and both constraints cannot drift apart unseen', function () {
    $cases = array_map(fn (ApprovalTimeout $outcome): string => $outcome->value, ApprovalTimeout::cases());

    expect(checkedValues('services', 'services_on_approval_timeout_check'))->toBe($cases)
        ->and(checkedValues('bookings', 'bookings_on_approval_timeout_check'))->toBe($cases);
});

it('lists in services_on_approval_timeout_check the two outcomes, expire first', function () {
    expect(checkedValues('services', 'services_on_approval_timeout_check'))->toBe(['expire', 'auto_approve']);
});

it('lists in services_status_check the two states', function () {
    expect(checkedValues('services', 'services_status_check'))->toBe(['active', 'inactive']);
});

it('refuses a service whose {column} is {value}', function (string $column, string $value) {
    expect(fn () => DB::table('tenant_acme.services')->insert(serviceRow('Cut', [$column => $value])))
        ->toThrow(QueryException::class, '23514');
})->with([
    ['payment_mode', 'deposit'],
    ['on_approval_timeout', 'reject'],
    ['status', 'archived'],
]);

it('refuses services.{column} at {value}, one below its bound', function (string $column, int $value) {
    expect(fn () => DB::table('tenant_acme.services')->insert(serviceRow('Cut', [$column => $value])))
        ->toThrow(QueryException::class, '23514');
})->with([
    ['duration_min', 0],
    ['processing_gap_min', -1],
    ['buffer_before_min', -1],
    ['buffer_after_min', -1],
    ['price_minor', -1],
    ['cancel_window_hours', -1],
    ['reschedule_window_hours', -1],
    ['lead_time_min', -1],
    ['horizon_days', -1],
    ['approval_hold_hours', 0],
]);

it('accepts services.{column} at {value}, its bound', function (string $column, int $value) {
    DB::table('tenant_acme.services')->insert(serviceRow('Cut', [$column => $value]));

    expect(DB::table('tenant_acme.services')->where('name', 'Cut')->value($column))->toBe($value);
})->with([
    ['duration_min', 1],
    ['processing_gap_min', 0],
    ['buffer_before_min', 0],
    ['buffer_after_min', 0],
    ['price_minor', 0],
    ['cancel_window_hours', 0],
    ['reschedule_window_hours', 0],
    ['lead_time_min', 0],
    ['horizon_days', 0],
    ['approval_hold_hours', 1],
]);

it('bounds services.horizon_days on both sides through one CHECK, migration 000027 and P9F-L15-d: 366 refused, 365 accepted', function () {
    DB::table('tenant_acme.services')->insert(serviceRow('Cut', ['horizon_days' => 365]));

    expect(constraintDefinition('tenant_acme', 'services', 'services_horizon_days_check'))->toBe('CHECK (((horizon_days >= 0) AND (horizon_days <= 365)))')
        ->and(DB::table('tenant_acme.services')->where('name', 'Cut')->value('horizon_days'))->toBe(365)
        ->and(fn () => DB::transaction(fn () => DB::table('tenant_acme.services')->insert(serviceRow('Colour', ['horizon_days' => 366]))))->toThrow(QueryException::class, '23514')
        ->and(fn () => DB::transaction(fn () => DB::table('tenant_acme.services')->where('name', 'Cut')->update(['horizon_days' => 366])))->toThrow(QueryException::class, '23514')
        ->and(DB::table('tenant_acme.services')->pluck('horizon_days', 'name')->all())->toBe(['Cut' => 365]);
});

it('types service_staff.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'service_staff', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['service_id', 'bigint not null'],
    ['staff_id', 'bigint not null'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('keeps each service and staff pair unique', function () {
    $serviceId = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut'));
    $staffId = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Sam', 'status' => 'active']);
    DB::table('tenant_acme.service_staff')->insert(['service_id' => $serviceId, 'staff_id' => $staffId]);

    expect(constraintDefinition('tenant_acme', 'service_staff', 'service_staff_pair_unique'))->toBe('UNIQUE (service_id, staff_id)')
        ->and(fn () => DB::table('tenant_acme.service_staff')->insert(['service_id' => $serviceId, 'staff_id' => $staffId]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('has the index service_staff_staff for the staff side of a replace-all', function () {
    expect(indexDefinition('tenant_acme', 'service_staff_staff'))
        ->toBe('CREATE INDEX service_staff_staff ON tenant_acme.service_staff USING btree (staff_id)');
});

it('takes an assignment away with its {side}', function (string $side) {
    $serviceId = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut'));
    $staffId = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Sam', 'status' => 'active']);
    DB::table('tenant_acme.service_staff')->insert(['service_id' => $serviceId, 'staff_id' => $staffId]);

    match ($side) {
        'service' => DB::table('tenant_acme.services')->where('id', $serviceId)->delete(),
        'staff member' => DB::table('tenant_acme.staff')->where('id', $staffId)->delete(),
        default => throw new InvalidArgumentException($side),
    };

    expect(DB::table('tenant_acme.service_staff')->count())->toBe(0);
})->with(['service', 'staff member']);

it('refuses an assignment to a {side} that does not exist', function (string $side) {
    $serviceId = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut'));
    $staffId = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Sam', 'status' => 'active']);

    $pair = match ($side) {
        'service' => ['service_id' => $serviceId + 1, 'staff_id' => $staffId],
        'staff member' => ['service_id' => $serviceId, 'staff_id' => $staffId + 1],
        default => throw new InvalidArgumentException($side),
    };

    expect(fn () => DB::table('tenant_acme.service_staff')->insert($pair))->toThrow(QueryException::class, '23503');
})->with(['service', 'staff member']);

it('defaults bookings.on_approval_timeout to expire and lists in its CHECK the two outcomes, expire first', function () {
    $bookingId = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());

    expect(DB::table('tenant_acme.bookings')->where('id', $bookingId)->value('on_approval_timeout'))->toBe('expire')
        ->and(checkedValues('bookings', 'bookings_on_approval_timeout_check'))->toBe(['expire', 'auto_approve']);
});

it('accepts bookings.on_approval_timeout auto_approve', function () {
    DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'on_approval_timeout' => 'auto_approve']);

    expect(DB::table('tenant_acme.bookings')->where('on_approval_timeout', 'auto_approve')->count())->toBe(1);
});

it('refuses a third approval timeout outcome', function () {
    expect(fn () => DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'on_approval_timeout' => 'reject']))
        ->toThrow(QueryException::class, '23514');
});

/**
 * The implicit staff member's id, the one row provisioning made.
 */
function acmeImplicitStaffId(): int
{
    $id = DB::table('tenant_acme.staff')->where('is_implicit', true)->value('id');
    assert(is_int($id));

    return $id;
}

/**
 * A tenant-wide whole-day blackout row, with any column overridden.
 *
 * @param  array<mixed>  $overrides
 * @return array<mixed>
 */
function blackoutRow(array $overrides = []): array
{
    return ['staff_id' => null, 'date' => '2026-12-25', ...$overrides];
}

/**
 * A shift pattern row for a member, weekly from 2026-11-02 with one window, with any column
 * overridden.
 *
 * @param  array<mixed>  $overrides
 * @return array<mixed>
 */
function patternRow(int $staffId, array $overrides = []): array
{
    return ['staff_id' => $staffId, 'starts_on' => '2026-11-02', 'cadence_weeks' => 1, 'windows' => '[{"weekday": 1, "start": "09:00", "end": "17:00"}]', ...$overrides];
}

/**
 * A shift override row for a member on 2026-11-04, not working, with any column overridden.
 *
 * @param  array<mixed>  $overrides
 * @return array<mixed>
 */
function overrideRow(int $staffId, array $overrides = []): array
{
    return ['staff_id' => $staffId, 'date' => '2026-11-04', 'windows' => '[]', ...$overrides];
}

function acmeMember(): int
{
    return DB::table('tenant_acme.staff')->insertGetId(['name' => 'Sam', 'status' => 'active']);
}

it('types shift_patterns.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'shift_patterns', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['staff_id', 'bigint not null'],
    ['starts_on', 'date not null'],
    ['cadence_weeks', 'integer not null'],
    ['ends_on', 'date'],
    ['windows', 'jsonb not null'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('types shift_overrides.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'shift_overrides', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['staff_id', 'bigint not null'],
    ['date', 'date not null'],
    ['windows', 'jsonb not null'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('checks a pattern\'s cadence to one to four weeks, its end on or after its start and its windows to an array, and an override\'s windows to an array', function () {
    expect(constraintDefinition('tenant_acme', 'shift_patterns', 'shift_patterns_cadence_check'))->toBe('CHECK (((cadence_weeks >= 1) AND (cadence_weeks <= 4)))')
        ->and(constraintDefinition('tenant_acme', 'shift_patterns', 'shift_patterns_ends_check'))->toBe('CHECK (((ends_on IS NULL) OR (ends_on >= starts_on)))')
        ->and(constraintDefinition('tenant_acme', 'shift_patterns', 'shift_patterns_windows_check'))->toBe("CHECK ((jsonb_typeof(windows) = 'array'::text))")
        ->and(constraintDefinition('tenant_acme', 'shift_overrides', 'shift_overrides_windows_check'))->toBe("CHECK ((jsonb_typeof(windows) = 'array'::text))");
});

it('keeps one pattern per member and one override per member and date', function () {
    expect(constraintDefinition('tenant_acme', 'shift_patterns', 'shift_patterns_staff_unique'))->toBe('UNIQUE (staff_id)')
        ->and(constraintDefinition('tenant_acme', 'shift_overrides', 'shift_overrides_staff_date_unique'))->toBe('UNIQUE (staff_id, date)');

    $member = acmeMember();
    DB::table('tenant_acme.shift_patterns')->insert(patternRow($member));
    DB::table('tenant_acme.shift_overrides')->insert(overrideRow($member));
    DB::table('tenant_acme.shift_overrides')->insert(overrideRow($member, ['date' => '2026-11-05']));

    expect(fn () => DB::transaction(fn () => DB::table('tenant_acme.shift_patterns')->insert(patternRow($member, ['cadence_weeks' => 2]))))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('tenant_acme.shift_overrides')->insert(overrideRow($member, ['windows' => '[{"start": "09:00", "end": "10:00"}]']))))->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('tenant_acme.shift_overrides')->where('staff_id', $member)->count())->toBe(2);
});

it('refuses a pattern whose {what}', function (array $overrides) {
    $member = acmeMember();

    expect(fn () => DB::table('tenant_acme.shift_patterns')->insert(patternRow($member, $overrides)))
        ->toThrow(QueryException::class, '23514');
})->with([
    'cadence is 0' => [['cadence_weeks' => 0]],
    'cadence is 5' => [['cadence_weeks' => 5]],
    'end comes before its start' => [['ends_on' => '2026-11-01']],
    'windows are an object' => [['windows' => '{"weekday": 1}']],
    'windows are a string' => [['windows' => '"09:00"']],
]);

it('accepts a pattern ending on its start, a pattern of four weeks, and the empty list as its windows by default', function () {
    $one = acmeMember();
    $two = acmeMember();
    DB::table('tenant_acme.shift_patterns')->insert(patternRow($one, ['ends_on' => '2026-11-02', 'cadence_weeks' => 4]));
    DB::table('tenant_acme.shift_patterns')->insert(['staff_id' => $two, 'starts_on' => '2026-11-02', 'cadence_weeks' => 1]);

    expect(DB::table('tenant_acme.shift_patterns')->where('staff_id', $two)->value('windows'))->toBe('[]')
        ->and(DB::table('tenant_acme.shift_patterns')->where('staff_id', $one)->value('ends_on'))->toBe('2026-11-02');
});

it('refuses an override whose windows are not an array', function () {
    $member = acmeMember();

    expect(fn () => DB::table('tenant_acme.shift_overrides')->insert(overrideRow($member, ['windows' => '{}'])))
        ->toThrow(QueryException::class, '23514');
});

it('takes a member\'s pattern and overrides away with them', function () {
    $member = acmeMember();
    DB::table('tenant_acme.shift_patterns')->insert(patternRow($member));
    DB::table('tenant_acme.shift_overrides')->insert(overrideRow($member));

    DB::table('tenant_acme.staff')->where('id', $member)->delete();

    expect(DB::table('tenant_acme.shift_patterns')->where('staff_id', $member)->count())->toBe(0)
        ->and(DB::table('tenant_acme.shift_overrides')->where('staff_id', $member)->count())->toBe(0);
});

it('types blackouts.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'blackouts', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['staff_id', 'bigint'],
    ['date', 'date not null'],
    ['start_time', 'time without time zone'],
    ['end_time', 'time without time zone'],
    ['reason', 'text'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('has the index {index} on blackouts', function (string $index, string $definition) {
    expect(indexDefinition('tenant_acme', $index))->toBe($definition);
})->with([
    ['blackouts_date', 'CREATE INDEX blackouts_date ON tenant_acme.blackouts USING btree (date)'],
    ['blackouts_staff_date', 'CREATE INDEX blackouts_staff_date ON tenant_acme.blackouts USING btree (staff_id, date)'],
]);

it('checks a blackout window to both ends null or both set and ordered', function () {
    expect(constraintDefinition('tenant_acme', 'blackouts', 'blackouts_window_check'))
        ->toBe('CHECK ((((start_time IS NULL) AND (end_time IS NULL)) OR ((start_time IS NOT NULL) AND (end_time IS NOT NULL) AND (start_time < end_time))))');
});

it('accepts a blackout {what}', function (array $overrides) {
    DB::table('tenant_acme.blackouts')->insert(blackoutRow($overrides));

    expect(DB::table('tenant_acme.blackouts')->count())->toBe(1);
})->with([
    'tenant-wide for the whole day' => [[]],
    'personal for the whole day' => [['staff_id' => 1]],
    'with a window' => [['start_time' => '12:00:00', 'end_time' => '13:00:00']],
    'with a window closing at the end of the day' => [['start_time' => '16:00:00', 'end_time' => '24:00:00']],
    'with a reason' => [['reason' => 'Stocktake']],
]);

it('refuses a blackout whose {what}', function (array $overrides) {
    expect(fn () => DB::table('tenant_acme.blackouts')->insert(blackoutRow($overrides)))
        ->toThrow(QueryException::class, '23514');
})->with([
    'window has only a start' => [['start_time' => '12:00:00']],
    'window has only an end' => [['end_time' => '13:00:00']],
    'window closes as it opens' => [['start_time' => '12:00:00', 'end_time' => '12:00:00']],
    'window closes before it opens' => [['start_time' => '13:00:00', 'end_time' => '12:00:00']],
]);

it('takes a personal blackout away with its staff member and leaves a tenant-wide one', function () {
    $staffId = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Sam', 'status' => 'active']);
    DB::table('tenant_acme.blackouts')->insert(blackoutRow(['staff_id' => $staffId]));
    DB::table('tenant_acme.blackouts')->insert(blackoutRow());

    DB::table('tenant_acme.staff')->where('id', $staffId)->delete();

    expect(DB::table('tenant_acme.blackouts')->pluck('staff_id')->all())->toBe([null]);
});

/*
 * audit_log: what a transition writes and nothing else. A row here is never updated, so it
 * carries created_at and no updated_at, and the CHECK on actor is what keeps a name or an
 * address out of the column whatever a later caller passes.
 */

it('types audit_log.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'audit_log', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['booking_id', 'bigint not null'],
    ['actor', 'text not null'],
    ['action', 'text not null'],
    ['payload', 'jsonb not null'],
    ['created_at', 'timestamp with time zone not null'],
]);

it('gives audit_log no updated_at, because a row of the trail is never updated', function () {
    expect(schemaColumn('tenant_acme', 'audit_log', 'updated_at'))->toBeNull();
});

it('indexes audit_log on the booking and the clock, for the dialog that reads a trail oldest first', function () {
    expect(indexDefinition('tenant_acme', 'audit_log_booking'))
        ->toBe('CREATE INDEX audit_log_booking ON tenant_acme.audit_log USING btree (booking_id, created_at)');
});

it('indexes audit_log on the clock alone, for the latest rows of the whole trail, and drops the index on the way down', function () {
    expect(indexDefinition('tenant_acme', 'audit_log_created_at'))
        ->toBe('CREATE INDEX audit_log_created_at ON tenant_acme.audit_log USING btree (created_at)');

    TenantMigration::takeBack('tenant_acme', TenantMigration::TRAIL_BY_TIME);

    expect(indexDefinition('tenant_acme', 'audit_log_created_at'))->toBeNull();
});

it('accepts the actor shape {actor}, which is one App\Domain\Audit\Actor builds', function (string $actor) {
    DB::table('tenant_acme.audit_log')->insert(['booking_id' => 1, 'actor' => $actor, 'action' => 'held']);

    expect(DB::table('tenant_acme.audit_log')->where('actor', $actor)->count())->toBe(1);
})->with([
    Actor::customer()->value,
    Actor::system()->value,
    'user:1',
    'user:90210',
    'job:expire-holds',
    Actor::support(1)->value,
    'support:4021',
]);

it('refuses the actor {actor} through the CHECK, an address among them', function (string $actor) {
    expect(fn () => DB::table('tenant_acme.audit_log')->insert(['booking_id' => 1, 'actor' => $actor, 'action' => 'held']))
        ->toThrow(QueryException::class, '23514');
})->with([
    'an email' => ['casey@example.com'],
    'a name' => ['Casey Customer'],
    'a job named with an address' => ['job:casey@example.com'],
    'a user named rather than numbered' => ['user:casey'],
    'a user with no id' => ['user:'],
    'the empty string' => [''],
    'support with no operator id' => ['support:'],
    'support with a trailing newline' => ["support:1\n"],
    'support named rather than numbered' => ['support:pat'],
    'support with an address for an operator' => ['support:pat@inbox.example'],
    'the bare word support' => ['support'],
]);

it('writes the actor CHECK from the shapes Actor builds, so the constraint and the class cannot drift apart unseen', function () {
    expect(constraintDefinition('tenant_acme', 'audit_log', 'audit_log_actor_check'))
        ->toContain(Actor::USER_SHAPE)
        ->toContain(Actor::JOB_SHAPE)
        ->toContain(Actor::SUPPORT_SHAPE)
        ->toContain("'".Actor::CUSTOMER."'")
        ->toContain("'".Actor::SYSTEM."'");
});

it('takes {actor} the same way in the class and in the CHECK, so a divergence the two patterns do not show in their text cannot hide either', function (string $actor) {
    // The same pattern text read by two engines is not the same pattern. PCRE lets a trailing
    // newline sit before the dollar and the POSIX operator does not, so holding the constraint
    // and the class to each other by the text alone would miss a whole family of strings one
    // accepts and the other refuses. This asks both of them instead.
    $constraint = true;

    try {
        DB::table('tenant_acme.audit_log')->insert(['booking_id' => 1, 'actor' => $actor, 'action' => 'held']);
    } catch (QueryException) {
        $constraint = false;
    }

    $class = true;

    try {
        Actor::of($actor);
    } catch (InvalidArgumentException) {
        $class = false;
    }

    expect($class)->toBe($constraint);
})->with([
    'customer' => ['customer'],
    'system' => ['system'],
    'a user' => ['user:1'],
    'a job' => ['job:expire-holds'],
    'a user with a trailing newline' => ["user:1\n"],
    'a job with a trailing newline' => ["job:expire-holds\n"],
    'the word customer with a trailing newline' => ["customer\n"],
    'a user with a leading newline' => ["\nuser:1"],
    'two users on two lines' => ["user:1\nuser:2"],
    'a user with a trailing space' => ['user:1 '],
    'support' => ['support:1'],
    'support with no operator id' => ['support:'],
    'support with a trailing newline' => ["support:1\n"],
    'support with a leading newline' => ["\nsupport:1"],
    'an email' => ['casey@example.com'],
    'a name' => ['Casey Customer'],
    'a user named rather than numbered' => ['user:casey'],
    'the empty string' => [''],
]);

it('lands the support shape on a schema built before it through tenant:migrate, keeping the rows of the four shapes it holds and touching no booking table', function () {
    // tenant_acme taken back to a schema built before migration 000026.
    $name = TenantMigration::SUPPORT_ACTOR;
    TenantMigration::takeBack('tenant_acme', $name);

    $four = [Actor::customer()->value, Actor::system()->value, 'user:1', 'job:expire-holds'];

    foreach ($four as $actor) {
        DB::table('tenant_acme.audit_log')->insert(['booking_id' => 1, 'actor' => $actor, 'action' => 'held']);
    }

    $bookingTables = fn (): array => DB::select(
        "select c.relname, c.relfilenode from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'tenant_acme' and c.relname in ('bookings', 'booking_items', 'booking_items_no_overlap') order by c.relname",
    );
    $before = $bookingTables();

    expect(constraintDefinition('tenant_acme', 'audit_log', 'audit_log_actor_check'))->not->toContain(Actor::SUPPORT_SHAPE)
        ->and($before)->toHaveCount(3);

    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);

    expect($code)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.$name)
        ->and(constraintDefinition('tenant_acme', 'audit_log', 'audit_log_actor_check'))->toContain(Actor::SUPPORT_SHAPE)
        ->and(DB::table('tenant_acme.audit_log')->orderBy('id')->pluck('actor')->all())->toBe($four)
        ->and($bookingTables())->toEqual($before);

    DB::table('tenant_acme.audit_log')->insert(['booking_id' => 1, 'actor' => Actor::support(1)->value, 'action' => 'cancelled']);

    expect(DB::table('tenant_acme.audit_log')->where('actor', 'support:1')->count())->toBe(1);
});

it('refuses to take audit_log back to the four shapes while a support row is on the trail, 000026 down(), leaving the five-shape CHECK, the row and the ran list as they were', function () {
    DB::table('tenant_acme.audit_log')->insert(['booking_id' => 1, 'actor' => Actor::support(1)->value, 'action' => 'cancelled']);

    expect(fn () => TenantMigration::takeBack('tenant_acme', TenantMigration::SUPPORT_ACTOR))
        ->toThrow(QueryException::class, '23514')
        ->and(constraintDefinition('tenant_acme', 'audit_log', 'audit_log_actor_check'))->toContain(Actor::SUPPORT_SHAPE)
        ->and(DB::table('tenant_acme.audit_log')->pluck('actor')->all())->toBe(['support:1'])
        ->and(DB::table('tenant_acme.migrations')->where('migration', TenantMigration::SUPPORT_ACTOR)->exists())->toBeTrue();
});

/**
 * The (service, staff) pairs of a schema's service_staff, by service name and staff name, in
 * the order of the service's id.
 *
 * @return list<array{string, string}>
 */
function performedBy(string $schema): array
{
    return array_values(array_map(
        fn (stdClass $row): array => [is_string($row->service) ? $row->service : '', is_string($row->member) ? $row->member : ''],
        DB::table($schema.'.service_staff')
            ->join($schema.'.services', 'services.id', '=', 'service_staff.service_id')
            ->join($schema.'.staff', 'staff.id', '=', 'service_staff.staff_id')
            ->orderBy('services.id')
            ->get(['services.name as service', 'staff.name as member'])
            ->all(),
    ));
}

it('lands 000027 on schemas built before it through tenant:migrate: a horizon past 365 days brought to 365, every service nobody performs in a one-member schema linked to the implicit member, and a schema with a second member left as ticked', function () {
    provisionTenant('bravo', ['name' => 'Bravo Studio', 'ownerEmail' => 'owner@bravo.example']);
    TenantMigration::takeBack('tenant_acme', TenantMigration::BOUNDS_AND_LINK);
    TenantMigration::takeBack('tenant_bravo', TenantMigration::BOUNDS_AND_LINK);

    $implicit = DB::table('tenant_acme.staff')->where('is_implicit', true)->value('id');
    DB::table('tenant_acme.services')->insert(serviceRow('Far', ['horizon_days' => 400]));
    DB::table('tenant_acme.services')->insert(serviceRow('Cut'));
    DB::table('tenant_acme.services')->insert(serviceRow('Old', ['status' => 'inactive', 'online' => false]));
    $colour = DB::table('tenant_acme.services')->insertGetId(serviceRow('Colour', ['horizon_days' => 365]));
    DB::table('tenant_acme.service_staff')->insert(['service_id' => $colour, 'staff_id' => $implicit]);

    $sam = DB::table('tenant_bravo.staff')->insertGetId(['name' => 'Sam', 'status' => 'active']);
    DB::table('tenant_bravo.services')->insert(serviceRow('Cut', ['horizon_days' => 500]));
    $shave = DB::table('tenant_bravo.services')->insertGetId(serviceRow('Shave'));
    DB::table('tenant_bravo.service_staff')->insert(['service_id' => $shave, 'staff_id' => $sam]);

    expect(constraintDefinition('tenant_acme', 'settings', 'settings_payment_ttl_hours_check'))->toBeNull()
        ->and(performedBy('tenant_acme'))->toBe([['Colour', 'Acme Salon']]);

    $code = Artisan::call('tenant:migrate');

    expect($code)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.TenantMigration::BOUNDS_AND_LINK."\n".'tenant_bravo: ran '.TenantMigration::BOUNDS_AND_LINK)
        ->and(DB::table('tenant_acme.services')->orderBy('id')->pluck('horizon_days', 'name')->all())->toBe(['Far' => 365, 'Cut' => 60, 'Old' => 60, 'Colour' => 365])
        ->and(performedBy('tenant_acme'))->toBe([['Far', 'Acme Salon'], ['Cut', 'Acme Salon'], ['Old', 'Acme Salon'], ['Colour', 'Acme Salon']])
        ->and(DB::table('tenant_bravo.services')->orderBy('id')->pluck('horizon_days', 'name')->all())->toBe(['Cut' => 365, 'Shave' => 60])
        ->and(performedBy('tenant_bravo'))->toBe([['Shave', 'Sam']]);

    foreach (['tenant_acme', 'tenant_bravo'] as $schema) {
        expect(constraintDefinition($schema, 'settings', 'settings_payment_ttl_hours_check'))->toBe('CHECK ((payment_ttl_hours >= 1))')
            ->and(constraintDefinition($schema, 'services', 'services_horizon_days_check'))->toBe('CHECK (((horizon_days >= 0) AND (horizon_days <= 365)))');
    }
});

it('clamps no payment link: a schema whose link lives zero hours fails 000027 and is rolled back whole, named, its services left unperformed', function () {
    TenantMigration::takeBack('tenant_acme', TenantMigration::BOUNDS_AND_LINK);
    DB::table('tenant_acme.settings')->where('id', 1)->update(['payment_ttl_hours' => 0]);
    DB::table('tenant_acme.services')->insert(serviceRow('Far', ['horizon_days' => 400]));

    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);
    $output = trim(Artisan::output());

    expect($code)->toBe(1)
        ->and($output)->toStartWith('tenant_acme: failed, rolled back, and the run stopped here: ')
        ->and($output)->toContain('settings_payment_ttl_hours_check')
        ->and(DB::table('tenant_acme.migrations')->where('migration', TenantMigration::BOUNDS_AND_LINK)->exists())->toBeFalse()
        ->and(DB::table('tenant_acme.services')->value('horizon_days'))->toBe(400)
        ->and(performedBy('tenant_acme'))->toBe([])
        ->and(constraintDefinition('tenant_acme', 'services', 'services_horizon_days_check'))->toBe('CHECK ((horizon_days >= 0))');
});

it('lands 000028 on a schema built before it through tenant:migrate, every booking kept as it was, the customer columns nullable and held together by the CHECK, nothing of booking_items rebuilt', function () {
    TenantMigration::takeBack('tenant_acme', TenantMigration::ERASABLE_CUSTOMER);
    itemService('tenant_acme');
    $booked = DB::table('tenant_acme.bookings')->insertGetId([...bookingRow(), 'customer_phone' => '+30 210 000 0000']);
    DB::table('tenant_acme.booking_items')->insert(itemRow($booked, 1, '2026-10-01 09:00+00', '2026-10-01 10:00+00'));
    $items = fn (): array => DB::select(
        "select c.relname, c.relfilenode from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'tenant_acme' and c.relname in ('booking_items', 'booking_items_no_overlap') order by c.relname",
    );
    $before = DB::table('tenant_acme.bookings')->where('id', $booked)->first();
    $itemsBefore = $items();

    expect(schemaColumn('tenant_acme', 'bookings', 'customer_name'))->toBe('text not null')
        ->and(schemaColumn('tenant_acme', 'bookings', 'customer_email'))->toBe('citext not null')
        ->and(constraintDefinition('tenant_acme', 'bookings', 'bookings_customer_check'))->toBeNull();

    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);

    expect($code)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.TenantMigration::ERASABLE_CUSTOMER)
        ->and(schemaColumn('tenant_acme', 'bookings', 'customer_name'))->toBe('text')
        ->and(schemaColumn('tenant_acme', 'bookings', 'customer_email'))->toBe('citext')
        ->and(constraintDefinition('tenant_acme', 'bookings', 'bookings_customer_check'))->toBeString()
        ->and(DB::table('tenant_acme.bookings')->where('id', $booked)->first())->toEqual($before)
        ->and($items())->toEqual($itemsBefore)
        ->and(DB::scalar("select current_setting('lock_timeout')"))->toBe('0');
});

it('refuses to take bookings back to a required name and address while an erased booking stands, 000028 down(), leaving the CHECK, the row and the ran list as they were', function () {
    DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'customer_name' => null, 'customer_email' => null]);

    expect(fn () => TenantMigration::takeBack('tenant_acme', TenantMigration::ERASABLE_CUSTOMER))
        ->toThrow(QueryException::class, '23502')
        ->and(schemaColumn('tenant_acme', 'bookings', 'customer_email'))->toBe('citext')
        ->and(constraintDefinition('tenant_acme', 'bookings', 'bookings_customer_check'))->toBeString()
        ->and(DB::table('tenant_acme.bookings')->whereNull('customer_email')->count())->toBe(1)
        ->and(DB::table('tenant_acme.migrations')->where('migration', TenantMigration::ERASABLE_CUSTOMER)->exists())->toBeTrue();
});

it('lands 000029 on a schema built before it through tenant:migrate, the settings row with no language and every booking kept reading en, both columns held to en or el by their CHECKs, nothing of booking_items rebuilt', function () {
    TenantMigration::takeBack('tenant_acme', TenantMigration::LANGUAGES);
    itemService('tenant_acme');
    $booked = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());
    DB::table('tenant_acme.booking_items')->insert(itemRow($booked, 1, '2026-10-01 09:00+00', '2026-10-01 10:00+00'));
    $items = fn (): array => DB::select(
        "select c.relname, c.relfilenode from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'tenant_acme' and c.relname in ('booking_items', 'booking_items_no_overlap') order by c.relname",
    );
    $before = (array) DB::table('tenant_acme.bookings')->where('id', $booked)->first();
    $itemsBefore = $items();

    expect(schemaColumn('tenant_acme', 'settings', 'language'))->toBeNull()
        ->and(schemaColumn('tenant_acme', 'bookings', 'language'))->toBeNull();

    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);

    expect($code)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.TenantMigration::LANGUAGES)
        ->and(schemaColumn('tenant_acme', 'settings', 'language'))->toBe('text')
        ->and(schemaColumn('tenant_acme', 'bookings', 'language'))->toBe('text not null')
        ->and(constraintDefinition('tenant_acme', 'settings', 'settings_language_check'))->toBe("CHECK ((language = ANY (ARRAY['en'::text, 'el'::text])))")
        ->and(constraintDefinition('tenant_acme', 'bookings', 'bookings_language_check'))->toBe("CHECK ((language = ANY (ARRAY['en'::text, 'el'::text])))")
        ->and(DB::table('tenant_acme.settings')->pluck('language')->all())->toBe([null])
        ->and((array) DB::table('tenant_acme.bookings')->where('id', $booked)->first())->toBe([...$before, 'language' => 'en'])
        ->and($items())->toEqual($itemsBefore)
        ->and(DB::scalar("select current_setting('lock_timeout')"))->toBe('0');
});

it('holds settings.language to null, en or el, and bookings.language to en or el, en where a writer names none', function () {
    DB::table('tenant_acme.settings')->where('id', 1)->update(['language' => 'el']);
    DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'language' => 'el']);
    $unnamed = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());

    // Each refused write in a savepoint of its own, so the next is asked of a live transaction.
    $refused = fn (Closure $write): Closure => fn () => DB::transaction(fn (): mixed => $write());

    expect(DB::table('tenant_acme.settings')->value('language'))->toBe('el')
        ->and(DB::table('tenant_acme.bookings')->where('id', $unnamed)->value('language'))->toBe('en')
        ->and($refused(fn () => DB::table('tenant_acme.settings')->where('id', 1)->update(['language' => 'fr'])))->toThrow(QueryException::class, 'settings_language_check')
        ->and($refused(fn () => DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'language' => 'EN'])))->toThrow(QueryException::class, 'bookings_language_check')
        ->and($refused(fn () => DB::table('tenant_acme.bookings')->insert([...bookingRow(), 'language' => null])))->toThrow(QueryException::class, '23502');
});

it('defaults the payload to an empty object', function () {
    DB::table('tenant_acme.audit_log')->insert(['booking_id' => 1, 'actor' => 'system', 'action' => 'expired']);

    expect(DB::table('tenant_acme.audit_log')->value('payload'))->toBe('{}');
});

it('is a no-op to provision twice: the second run migrates nothing and the trail keeps its rows', function () {
    DB::table('tenant_acme.audit_log')->insert(['booking_id' => 1, 'actor' => 'system', 'action' => 'expired']);

    $again = app(TenantMigrator::class)->migrate('tenant_acme');

    expect($again)->toBe([])
        ->and(schemaTables('tenant_acme'))
        ->toBe(['audit_log', 'blackouts', 'booking_items', 'booking_meta', 'bookings', 'customer_links', 'customer_notes', 'customer_profiles', 'customer_sessions', 'migrations', 'payment_connections', 'service_staff', 'services', 'settings', 'shift_overrides', 'shift_patterns', 'staff', 'staff_days'])
        ->and(DB::table('tenant_acme.audit_log')->count())->toBe(1);
});

it('types payment_connections.{column} as {type}', function (string $column, string $type) {
    expect(schemaColumn('tenant_acme', 'payment_connections', $column))->toBe($type);
})->with([
    ['id', 'bigint not null'],
    ['provider', 'text not null'],
    ['mode', 'text not null'],
    ['state', 'text not null'],
    ['account_ref', 'text'],
    ['credentials', 'text'],
    ['webhook_token', 'text'],
    ['requirements', 'jsonb'],
    ['is_primary', 'boolean not null'],
    ['last_checked_at', 'timestamp with time zone'],
    ['last_check_ok', 'boolean'],
    ['created_at', 'timestamp with time zone not null'],
    ['updated_at', 'timestamp with time zone not null'],
]);

it('has the unique index {index} on payment_connections', function (string $index, string $definition) {
    expect(indexDefinition('tenant_acme', $index))->toBe($definition);
})->with([
    ['payment_connections_provider_mode', 'CREATE UNIQUE INDEX payment_connections_provider_mode ON tenant_acme.payment_connections USING btree (provider, mode)'],
    ['payment_connections_one_primary', 'CREATE UNIQUE INDEX payment_connections_one_primary ON tenant_acme.payment_connections USING btree ((true)) WHERE is_primary'],
]);

it('lists in the payment_connections checks exactly the cases of ConnectionMode and ConnectionState, in order', function () {
    expect(checkedValues('payment_connections', 'payment_connections_mode_check'))
        ->toBe(array_map(fn (ConnectionMode $mode): string => $mode->value, ConnectionMode::cases()))
        ->and(checkedValues('payment_connections', 'payment_connections_state_check'))
        ->toBe(array_map(fn (ConnectionState $state): string => $state->value, ConnectionState::cases()));
});

it('refuses a second primary payment connection whatever it names, and a provider connected twice in one mode, and holds a second row that is neither', function () {
    $row = fn (string $provider, string $mode, bool $primary): array => ['provider' => $provider, 'mode' => $mode, 'state' => 'active', 'is_primary' => $primary];
    DB::table('tenant_acme.payment_connections')->insert($row('stripe', 'own', true));
    DB::table('tenant_acme.payment_connections')->insert($row('paypal', 'own', false));

    expect(fn () => DB::transaction(fn () => DB::table('tenant_acme.payment_connections')->insert($row('viva', 'platform', true))))
        ->toThrow(QueryException::class, 'payment_connections_one_primary')
        ->and(fn () => DB::transaction(fn () => DB::table('tenant_acme.payment_connections')->insert($row('paypal', 'own', false))))
        ->toThrow(QueryException::class, 'payment_connections_provider_mode')
        ->and(DB::table('tenant_acme.payment_connections')->count())->toBe(2);
});

it('lands 000031 on a schema built before it through tenant:migrate, the settings row reading staff, the column held to staff, category or status by its CHECK', function () {
    TenantMigration::takeBack('tenant_acme', TenantMigration::COLOUR_BY);

    expect(schemaColumn('tenant_acme', 'settings', 'colour_by'))->toBeNull();

    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);

    expect($code)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.TenantMigration::COLOUR_BY)
        ->and(schemaColumn('tenant_acme', 'settings', 'colour_by'))->toBe('text not null')
        ->and(constraintDefinition('tenant_acme', 'settings', 'settings_colour_by_check'))->toBe("CHECK ((colour_by = ANY (ARRAY['staff'::text, 'category'::text, 'status'::text])))")
        ->and(DB::table('tenant_acme.settings')->pluck('colour_by')->all())->toBe(['staff'])
        ->and(DB::scalar("select current_setting('lock_timeout')"))->toBe('0');
});

it('holds settings.colour_by to staff, category or status', function () {
    $refused = fn (Closure $write): Closure => fn () => DB::transaction(fn (): mixed => $write());

    DB::table('tenant_acme.settings')->where('id', 1)->update(['colour_by' => 'category']);
    DB::table('tenant_acme.settings')->where('id', 1)->update(['colour_by' => 'status']);

    expect(DB::table('tenant_acme.settings')->value('colour_by'))->toBe('status')
        ->and($refused(fn () => DB::table('tenant_acme.settings')->where('id', 1)->update(['colour_by' => 'service'])))->toThrow(QueryException::class, 'settings_colour_by_check')
        ->and($refused(fn () => DB::table('tenant_acme.settings')->where('id', 1)->update(['colour_by' => null])))->toThrow(QueryException::class, '23502');
});

it('lands 000034 on a schema built before it through tenant:migrate, every member on Automatic, the column held to the palette\'s seven words by its CHECK', function () {
    TenantMigration::takeBack('tenant_acme', TenantMigration::STAFF_COLOUR);

    expect(schemaColumn('tenant_acme', 'staff', 'colour'))->toBeNull();

    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);

    expect($code)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.TenantMigration::STAFF_COLOUR)
        ->and(schemaColumn('tenant_acme', 'staff', 'colour'))->toBe('text')
        ->and(constraintDefinition('tenant_acme', 'staff', 'staff_colour_check'))->toBe('CHECK ((colour = ANY (ARRAY['.implode(', ', array_map(fn (string $word): string => "'".$word."'::text", StaffColour::words())).'])))')
        ->and(DB::table('tenant_acme.staff')->pluck('colour')->unique()->all())->toBe([null])
        ->and(DB::scalar("select current_setting('lock_timeout')"))->toBe('0');
});

it('drops staff.colour on the way down', function () {
    DB::table('tenant_acme.staff')->update(['colour' => 'purple']);
    TenantMigration::takeBack('tenant_acme', TenantMigration::STAFF_COLOUR);

    expect(schemaColumn('tenant_acme', 'staff', 'colour'))->toBeNull();
});

it('lands 000033 on a schema built before it through tenant:migrate, the saved accent mapped to its nearest theme: {case}', function (?string $accent, string $theme) {
    // The schema as it stood before 000033: 000040 taken back first, its down() putting back the
    // accent column 000033's mapping reads.
    TenantMigration::takeBack('tenant_acme', TenantMigration::ACCENT_DROPPED);
    DB::table('tenant_acme.settings')->where('id', 1)->update(['brand_accent' => $accent]);
    TenantMigration::takeBack('tenant_acme', TenantMigration::THEME);

    expect(schemaColumn('tenant_acme', 'settings', 'theme'))->toBeNull();

    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);

    expect($code)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.TenantMigration::THEME.', '.TenantMigration::ACCENT_DROPPED)
        ->and(schemaColumn('tenant_acme', 'settings', 'theme'))->toBe('text not null')
        ->and(DB::table('information_schema.columns')->where(['table_schema' => 'tenant_acme', 'table_name' => 'settings', 'column_name' => 'theme'])->value('column_default'))->toBe("'".Theme::DEFAULT->value."'::text")
        ->and(constraintDefinition('tenant_acme', 'settings', 'settings_theme_check'))->toBe('CHECK ((theme = ANY (ARRAY['.implode(', ', array_map(fn (string $word): string => "'".$word."'::text", Theme::words())).'])))')
        ->and(DB::table('tenant_acme.settings')->pluck('theme')->all())->toBe([$theme])
        ->and(schemaColumn('tenant_acme', 'settings', 'brand_accent'))->toBeNull()
        ->and(DB::scalar("select current_setting('lock_timeout')"))->toBe('0');
})->with([
    'a violet accent' => ['#7c3aed', 'violet'],
    'a teal-blue accent' => ['#0E7490', 'blue'],
    'no accent' => [null, 'neutral'],
    'a grey accent' => ['#808080', 'neutral'],
]);

it('drops settings.theme on the way down, the accent left as it was', function () {
    TenantMigration::takeBack('tenant_acme', TenantMigration::ACCENT_DROPPED);
    DB::table('tenant_acme.settings')->where('id', 1)->update(['brand_accent' => '#e11d48']);
    TenantMigration::takeBack('tenant_acme', TenantMigration::THEME);

    expect(schemaColumn('tenant_acme', 'settings', 'theme'))->toBeNull()
        ->and(DB::table('tenant_acme.settings')->value('brand_accent'))->toBe('#e11d48');
});

it('holds no settings.brand_accent, the theme the one colour a tenant chooses', function () {
    expect(schemaColumn('tenant_acme', 'settings', 'brand_accent'))->toBeNull();
});

it('lands 000040 on a schema built before it through tenant:migrate, the accent column dropped and the theme kept', function () {
    DB::table('tenant_acme.settings')->where('id', 1)->update(['theme' => 'rose']);
    TenantMigration::takeBack('tenant_acme', TenantMigration::ACCENT_DROPPED);

    expect(schemaColumn('tenant_acme', 'settings', 'brand_accent'))->toBe('text');

    DB::table('tenant_acme.settings')->where('id', 1)->update(['brand_accent' => '#e11d48']);

    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);

    expect($code)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.TenantMigration::ACCENT_DROPPED)
        ->and(schemaColumn('tenant_acme', 'settings', 'brand_accent'))->toBeNull()
        ->and(DB::table('tenant_acme.settings')->value('theme'))->toBe('rose')
        ->and(DB::scalar("select current_setting('lock_timeout')"))->toBe('0');
});

it('puts settings.brand_accent back on the way down from 000040, nullable and empty', function () {
    TenantMigration::takeBack('tenant_acme', TenantMigration::ACCENT_DROPPED);

    expect(schemaColumn('tenant_acme', 'settings', 'brand_accent'))->toBe('text')
        ->and(DB::table('tenant_acme.settings')->value('brand_accent'))->toBeNull();
});

it('holds settings.theme to the seven words', function () {
    $refused = fn (Closure $write): Closure => fn () => DB::transaction(fn (): mixed => $write());

    foreach (Theme::words() as $word) {
        DB::table('tenant_acme.settings')->where('id', 1)->update(['theme' => $word]);
    }

    expect(DB::table('tenant_acme.settings')->value('theme'))->toBe('violet')
        ->and($refused(fn () => DB::table('tenant_acme.settings')->where('id', 1)->update(['theme' => 'teal'])))->toThrow(QueryException::class, 'settings_theme_check')
        ->and($refused(fn () => DB::table('tenant_acme.settings')->where('id', 1)->update(['theme' => 'Violet'])))->toThrow(QueryException::class, 'settings_theme_check')
        ->and($refused(fn () => DB::table('tenant_acme.settings')->where('id', 1)->update(['theme' => null])))->toThrow(QueryException::class, '23502');
});

/*
 * A customer's profile and notes: customer_profiles, one row per address, keyed by the address in
 * lower case, with its tags, birthday, preferred language and online-booking block;
 * customer_notes, the staff's notes on an address, each with its author as the trail names an
 * actor and the name they wrote it under.
 */

it('types {table}.{column} as {type} (tenant migration 000035)', function (string $table, string $column, string $type) {
    expect(schemaColumn('tenant_acme', $table, $column))->toBe($type);
})->with([
    ['customer_profiles', 'email', 'citext not null'],
    ['customer_profiles', 'tags', 'text[] not null'],
    ['customer_profiles', 'birthday', 'date'],
    ['customer_profiles', 'language', 'text'],
    ['customer_profiles', 'blocked_at', 'timestamp with time zone'],
    ['customer_profiles', 'blocked_reason', 'text'],
    ['customer_profiles', 'created_at', 'timestamp with time zone not null'],
    ['customer_profiles', 'updated_at', 'timestamp with time zone not null'],
    ['customer_notes', 'id', 'bigint not null'],
    ['customer_notes', 'email', 'citext not null'],
    ['customer_notes', 'body', 'text not null'],
    ['customer_notes', 'author_actor', 'text not null'],
    ['customer_notes', 'author_name', 'text not null'],
    ['customer_notes', 'created_at', 'timestamp with time zone not null'],
]);

/**
 * A profile row as its writer makes it, the stamps UTC text with the offset.
 *
 * @param  list<string>  $tags
 * @return array<string, mixed>
 */
function profileRow(string $email = 'casey@example.com', array $tags = ['VIP']): array
{
    return ['email' => $email, 'tags' => TextArray::encode($tags), 'created_at' => '2026-11-02 06:00:00+00:00', 'updated_at' => '2026-11-02 06:00:00+00:00'];
}

/**
 * A note row as its writer makes it.
 *
 * @return array<string, string>
 */
function noteRow(string $body = 'Prefers Elena.', string $actor = 'user:1'): array
{
    return ['email' => 'casey@example.com', 'body' => $body, 'author_actor' => $actor, 'author_name' => 'Alex Owner', 'created_at' => '2026-11-02 06:00:00+00:00'];
}

it('keys a profile by the address, one row per address in any case, and admits ten tags of up to twenty-four characters, words with a space inside, and none', function () {
    DB::table('tenant_acme.customer_profiles')->insert(profileRow(tags: array_map(fn (int $n): string => str_pad('tag '.$n, 24, 'x'), range(1, 10))));
    DB::table('tenant_acme.customer_profiles')->insert(profileRow('other@example.com', []));

    $tags = DB::table('tenant_acme.customer_profiles')->where('email', 'other@example.com')->value('tags');
    assert(is_string($tags));

    expect(DB::table('tenant_acme.customer_profiles')->where('email', 'Casey@Example.com')->count())->toBe(1)
        ->and(TextArray::decode($tags))->toBe([])
        ->and(fn () => DB::table('tenant_acme.customer_profiles')->insert(profileRow()))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a profile whose {what} through the CHECK', function (string $what, string $email, string $tags, string $language, string $reason) {
    $row = [...profileRow($email), 'tags' => $tags, 'language' => $language === '' ? null : $language, 'blocked_reason' => $reason === '' ? null : $reason];

    expect(fn () => DB::table('tenant_acme.customer_profiles')->insert($row))->toThrow(QueryException::class, '23514');
})->with([
    ['address is not in lower case', 'Casey@example.com', '{VIP}', '', ''],
    ['tags number eleven', 'casey@example.com', '{a,b,c,d,e,f,g,h,i,j,k}', '', ''],
    ['tag runs to twenty-five characters', 'casey@example.com', '{'.str_repeat('x', 25).'}', '', ''],
    ['tag is empty', 'casey@example.com', '{""}', '', ''],
    ['tag starts with a space', 'casey@example.com', '{" VIP"}', '', ''],
    ['tag holds a comma', 'casey@example.com', '{"a,b"}', '', ''],
    ['tag is null', 'casey@example.com', '{NULL}', '', ''],
    ['language is neither of the two', 'casey@example.com', '{}', 'fr', ''],
    ['block reason stands without a block', 'casey@example.com', '{}', '', 'Missed three bookings'],
]);

it('admits each of the two languages and none on a profile', function (string $language) {
    DB::table('tenant_acme.customer_profiles')->insert([...profileRow(), 'language' => $language === '' ? null : $language]);

    expect(DB::table('tenant_acme.customer_profiles')->count())->toBe(1);
})->with(['el', 'en', '']);

it('holds a note to the address in lower case, a body that is not blank, and an author the trail could name', function () {
    // Each refusal in a savepoint of its own, so one aborts nothing around it.
    $refused = fn (array $row): Closure => fn () => DB::transaction(fn (): bool => DB::table('tenant_acme.customer_notes')->insert($row));
    DB::table('tenant_acme.customer_notes')->insert(noteRow());
    DB::table('tenant_acme.customer_notes')->insert(noteRow('Asked for a quote.', 'support:3'));

    expect(DB::table('tenant_acme.customer_notes')->where('email', 'CASEY@example.com')->count())->toBe(2)
        ->and($refused([...noteRow(), 'email' => 'Casey@example.com']))->toThrow(QueryException::class, '23514')
        ->and($refused(noteRow('   ')))->toThrow(QueryException::class, '23514')
        ->and($refused(noteRow(str_repeat('n', 2001))))->toThrow(QueryException::class, '23514')
        ->and($refused(noteRow(actor: 'customer')))->toThrow(QueryException::class, '23514')
        ->and($refused(noteRow(actor: 'Alex Owner')))->toThrow(QueryException::class, '23514');
});

it('lands 000035 on a schema built before it through tenant:migrate, both tables empty', function () {
    TenantMigration::takeBack('tenant_acme', TenantMigration::CUSTOMER_PROFILES);

    expect(schemaTables('tenant_acme'))->not->toContain('customer_profiles')->not->toContain('customer_notes');

    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);

    expect($code)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.TenantMigration::CUSTOMER_PROFILES)
        ->and(DB::table('tenant_acme.customer_profiles')->count())->toBe(0)
        ->and(DB::table('tenant_acme.customer_notes')->count())->toBe(0);
});

it('drops both tables on the way down', function () {
    DB::table('tenant_acme.customer_profiles')->insert(profileRow());
    DB::table('tenant_acme.customer_notes')->insert(noteRow());
    TenantMigration::takeBack('tenant_acme', TenantMigration::CUSTOMER_PROFILES);

    expect(schemaTables('tenant_acme'))->not->toContain('customer_profiles')->not->toContain('customer_notes');
});
