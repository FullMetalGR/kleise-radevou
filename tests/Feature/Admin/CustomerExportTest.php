<?php

use App\Application\Clock;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The export of one customer's data from their page, through GET /admin/customers/{email}/export:
 * one JSON document in UTF-8, downloaded as customer-{slug}-{date}.json with the tenant's today,
 * holding every booking of the address as citext compares it in any status and nothing of another
 * address, no hash, token, sealed value or serial id; one customer_exported trail row per booking,
 * the user as the actor and the payload empty, in one transaction before the document goes out;
 * the route under manageBookings and outside the subscription gate. The download is asserted here
 * rather than walked in a browser.
 *
 * The export enters the transaction runner, so this file truncates rather than wrapping each
 * test in a transaction, and seeds the demo afresh. The rows are written straight to luna's
 * tables with literal instants, so the clock is pinned first: the export's instant and the file's
 * date are the pinned clock's, 2026-11-02 08:00 in Athens. The bookings straddle the October
 * change, so an instant before it reads +03:00 and one after it +02:00.
 */

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/** The address the exported customer booked under, as its page links it. */
const CUSTOMER_EXPORT_EMAIL = 'casey@example.com';

/** A neighbour whose booking, note included, an export of the first must leave out. */
const CUSTOMER_EXPORT_NEIGHBOUR = 'robin@example.test';

/**
 * A booking of luna written straight to the rows: the columns given over a completed booking on
 * site with no hold, its manage token's hash and a sealed copy, an item per entry of the service,
 * the member, the start and the end in UTC and the price, and an intake row per key.
 *
 * @param  array<string, mixed>  $columns
 * @param  non-empty-list<array{string, string, string, string, int}>  $items
 * @param  array<string, string>  $intake
 * @return array{id: int, uuid: string}
 */
function customerExportBooking(array $columns, array $items, array $intake = [], ?int $itemIds = null): array
{
    $uuid = (string) Str::uuid();
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'uuid' => $uuid, 'status' => 'completed', 'source' => 'embed',
        'customer_phone' => null,
        'total_minor' => array_sum(array_column($items, 4)), 'currency' => 'EUR', 'payment_mode' => 'onsite',
        'requires_approval' => false, 'on_approval_timeout' => 'expire',
        'manage_token_hash' => hash('sha256', 'manage-'.$uuid), 'manage_token_sealed' => 'sealed.'.$uuid,
        'created_at' => '2026-10-01 07:00:00+00',
        ...$columns,
    ]);

    foreach ($items as $sort => [$service, $staff, $start, $end, $price]) {
        DB::table('tenant_luna.booking_items')->insert([
            ...($itemIds === null ? [] : ['id' => $itemIds + $sort]),
            'booking_id' => $id, 'sort' => $sort,
            'service_id' => LunaFixture::id('services', $service), 'staff_id' => LunaFixture::id('staff', $staff),
            'start_utc' => $start, 'end_utc' => $end, 'block_start_utc' => $start, 'block_end_utc' => $end,
            'price_minor' => $price, 'blocks' => false,
        ]);
    }

    foreach ($intake as $key => $value) {
        DB::table('tenant_luna.booking_meta')->insert(['booking_id' => $id, 'key' => $key, 'value' => $value]);
    }

    return ['id' => $id, 'uuid' => $uuid];
}

/**
 * The customer of three bookings under three spellings of one address, two names and two phones,
 * in three statuses across the October change, and a neighbour with a booking and a note.
 *
 * @return array{completed: array{id: int, uuid: string}, confirmed: array{id: int, uuid: string}, rejected: array{id: int, uuid: string}, neighbour: array{id: int, uuid: string}}
 */
function customerExportTwoCustomers(): array
{
    return [
        // Inserted out of their order of first start, so the document's order is the export's own.
        'rejected' => customerExportBooking(
            ['status' => 'rejected', 'customer_name' => 'Casey Customer', 'customer_email' => 'CASEY@example.com', 'customer_phone' => '+30 699 1112223', 'payment_mode' => 'online', 'requires_approval' => true, 'rejection_reason' => 'The day is fully booked.', 'created_at' => '2026-11-01 08:00:00+00'],
            [['Bridal trial', 'Elena Papadaki', '2026-11-12 08:00:00+00', '2026-11-12 10:00:00+00', 15000]],
            ['note' => 'A second try at the date.'],
        ),
        'completed' => customerExportBooking(
            ['status' => 'completed', 'customer_name' => 'Casey Customer', 'customer_email' => 'Casey@Example.com', 'customer_phone' => '+30 210 7654321', 'payment_mode' => 'online', 'created_at' => '2026-10-18 09:30:00+00'],
            [
                ['Haircut', 'Elena Papadaki', '2026-10-20 08:00:00+00', '2026-10-20 08:45:00+00', 3500],
                ['Colour', 'Nikos Georgiou', '2026-10-20 08:45:00+00', '2026-10-20 09:45:00+00', 8000],
            ],
            ['note' => "Caf\u{e9} au lait, no sugar.", 'allergies' => 'Lavender'],
        ),
        'confirmed' => customerExportBooking(
            ['status' => 'confirmed', 'customer_name' => 'Casey C.', 'customer_email' => CUSTOMER_EXPORT_EMAIL, 'payment_mode' => 'online', 'source' => 'hosted', 'channel' => 'instagram', 'requires_approval' => true, 'approved_at' => '2026-10-31 07:15:00+00', 'created_at' => '2026-10-30 10:00:00+00'],
            [['Bridal trial', 'Elena Papadaki', '2026-11-10 08:00:00+00', '2026-11-10 10:00:00+00', 15000]],
        ),
        'neighbour' => customerExportBooking(
            ['status' => 'confirmed', 'customer_name' => 'Robin Regular', 'customer_email' => CUSTOMER_EXPORT_NEIGHBOUR, 'customer_phone' => '+30 210 5550000', 'created_at' => '2026-10-29 12:00:00+00'],
            [['Haircut', 'Nikos Georgiou', '2026-11-05 09:00:00+00', '2026-11-05 09:45:00+00', 3500]],
            ['note' => 'Mind the left knee.'],
        ),
    ];
}

/**
 * Every customer column and every intake value luna stores, read off the rows: those of the
 * exported address, or those of every other.
 *
 * @return list<string>
 */
function customerExportStored(bool $theirs): array
{
    $fields = [];
    $rows = DB::select(
        'select booking.customer_name, booking.customer_email::text as customer_email, booking.customer_phone, meta.value'
        .' from tenant_luna.bookings booking left join tenant_luna.booking_meta meta on meta.booking_id = booking.id'
        .' where (booking.customer_email = ?::citext) = ?',
        [CUSTOMER_EXPORT_EMAIL, $theirs],
    );

    foreach ($rows as $row) {
        if (! $row instanceof stdClass) {
            throw new LogicException('A row came back as no object.');
        }

        foreach ([$row->customer_name, $row->customer_email, $row->customer_phone, $row->value] as $field) {
            if (is_string($field)) {
                $fields[] = $field;
            }
        }
    }

    return array_values(array_unique($fields));
}

/**
 * A profile and two notes for an address, as the page's Profile card and Notes card leave
 * them, the notes written by the actor given.
 */
function customerExportProfile(string $email, string $actor = 'user:1'): void
{
    DB::table('tenant_luna.customer_profiles')->insert([
        'email' => $email, 'tags' => '{VIP,colour}', 'birthday' => '1988-04-12', 'language' => 'el',
        'blocked_at' => '2026-10-31 22:30:00+00', 'blocked_reason' => 'Three missed bookings',
        'created_at' => '2026-10-01 09:00:00+00', 'updated_at' => '2026-10-31 22:30:00+00',
    ]);
    DB::table('tenant_luna.customer_notes')->insert(['email' => $email, 'body' => 'Asked for a quote on a bridal package.', 'author_actor' => $actor, 'author_name' => 'Elena Papadaki', 'created_at' => '2026-07-14 08:40:00+00']);
    DB::table('tenant_luna.customer_notes')->insert(['email' => $email, 'body' => 'Allergic to ammonia colour.', 'author_actor' => $actor, 'author_name' => 'Luna Owner', 'created_at' => '2026-09-03 15:12:00+00']);
}

function customerExportOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

function customerExportUrl(string $email): string
{
    return tenantUrl('luna', '/admin/customers/'.rawurlencode($email).'/export');
}

/**
 * The export's GET for an address, as the user given or the owner.
 *
 * @return TestResponse<Response>
 */
function customerExportGet(TestCase $test, string $email, ?User $as = null): TestResponse
{
    return $test->actingAs($as ?? customerExportOwner())->get(customerExportUrl($email));
}

/**
 * The document a landed export streamed, decoded.
 *
 * @param  TestResponse<Response>  $response
 * @return array<mixed>
 */
function customerExportDocument(TestResponse $response): array
{
    $document = json_decode($response->assertOk()->streamedContent(), true, 512, JSON_THROW_ON_ERROR);

    return is_array($document) ? $document : throw new LogicException('The export is no JSON object.');
}

/**
 * The trail rows of luna an export wrote, oldest first.
 *
 * @return list<array<mixed>>
 */
function customerExportTrail(): array
{
    return array_values(array_filter(LunaFixture::trail(), fn (array $row): bool => $row['action'] === 'customer_exported'));
}

/**
 * Every key of a decoded document at every depth, and every scalar value, each as text.
 *
 * @param  array<mixed>  $document
 * @return array{keys: list<string>, values: list<string>}
 */
function customerExportLeaves(array $document): array
{
    $keys = [];
    $values = [];

    array_walk_recursive($document, function (mixed $value, int|string $key) use (&$values): void {
        $values[] = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : '');
    });

    $walk = function (array $node) use (&$walk, &$keys): void {
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($document);

    return ['keys' => array_values(array_unique($keys)), 'values' => $values];
}

it('downloads every booking of the address as one JSON document named for the tenant and its today, the address matched as citext compares it: the customer as the bookings know them, each name, spelling and phone once, and each booking with its items on the tenant\'s clock, its facts and its intake', function () {
    $bookings = customerExportTwoCustomers();

    $response = customerExportGet($this, CUSTOMER_EXPORT_EMAIL)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename=customer-luna-2026-11-02.json')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $body = $response->streamedContent();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(customerExportDocument($response))->toBe([
            'business' => 'Luna Hair Studio',
            'exported_at' => '2026-11-02T08:00:00+02:00',
            'timezone' => 'Europe/Athens',
            'customer' => [
                'names' => ['Casey Customer', 'Casey C.'],
                'emails' => ['CASEY@example.com', 'casey@example.com', 'Casey@Example.com'],
                'phones' => ['+30 699 1112223', '+30 210 7654321'],
            ],
            'profile' => null,
            'notes' => [],
            'bookings' => [
                [
                    'uuid' => $bookings['completed']['uuid'],
                    'status' => 'completed',
                    'booked_at' => '2026-10-18T12:30:00+03:00',
                    'name' => 'Casey Customer',
                    'email' => 'Casey@Example.com',
                    'phone' => '+30 210 7654321',
                    'items' => [
                        ['service' => 'Haircut', 'staff' => 'Elena Papadaki', 'starts_at' => '2026-10-20T11:00:00+03:00', 'ends_at' => '2026-10-20T11:45:00+03:00', 'price_minor' => 3500],
                        ['service' => 'Colour', 'staff' => 'Nikos Georgiou', 'starts_at' => '2026-10-20T11:45:00+03:00', 'ends_at' => '2026-10-20T12:45:00+03:00', 'price_minor' => 8000],
                    ],
                    'total_minor' => 11500,
                    'currency' => 'EUR',
                    'payment_mode' => 'online',
                    'source' => 'embed',
                    'channel' => null,
                    'requires_approval' => false,
                    'approved_at' => null,
                    'rejection_reason' => null,
                    'intake' => ['allergies' => 'Lavender', 'note' => "Caf\u{e9} au lait, no sugar."],
                ],
                [
                    'uuid' => $bookings['confirmed']['uuid'],
                    'status' => 'confirmed',
                    'booked_at' => '2026-10-30T12:00:00+02:00',
                    'name' => 'Casey C.',
                    'email' => 'casey@example.com',
                    'phone' => null,
                    'items' => [
                        ['service' => 'Bridal trial', 'staff' => 'Elena Papadaki', 'starts_at' => '2026-11-10T10:00:00+02:00', 'ends_at' => '2026-11-10T12:00:00+02:00', 'price_minor' => 15000],
                    ],
                    'total_minor' => 15000,
                    'currency' => 'EUR',
                    'payment_mode' => 'online',
                    'source' => 'hosted',
                    'channel' => 'instagram',
                    'requires_approval' => true,
                    'approved_at' => '2026-10-31T09:15:00+02:00',
                    'rejection_reason' => null,
                    'intake' => [],
                ],
                [
                    'uuid' => $bookings['rejected']['uuid'],
                    'status' => 'rejected',
                    'booked_at' => '2026-11-01T10:00:00+02:00',
                    'name' => 'Casey Customer',
                    'email' => 'CASEY@example.com',
                    'phone' => '+30 699 1112223',
                    'items' => [
                        ['service' => 'Bridal trial', 'staff' => 'Elena Papadaki', 'starts_at' => '2026-11-12T10:00:00+02:00', 'ends_at' => '2026-11-12T12:00:00+02:00', 'price_minor' => 15000],
                    ],
                    'total_minor' => 15000,
                    'currency' => 'EUR',
                    'payment_mode' => 'online',
                    'source' => 'embed',
                    'channel' => null,
                    'requires_approval' => true,
                    'approved_at' => null,
                    'rejection_reason' => 'The day is fully booked.',
                    'intake' => ['note' => 'A second try at the date.'],
                ],
            ],
        ])
        // UTF-8 as written, never escaped, and a booking with no intake an empty object.
        ->and($body)->toContain("Caf\u{e9} au lait")
        ->and($body)->not->toContain(trim(json_encode("Caf\u{e9}", JSON_THROW_ON_ERROR), '"'))
        ->and(substr_count($body, '"intake": {}'))->toBe(1)
        ->and(mb_check_encoding($body, 'UTF-8'))->toBeTrue();
});

it('holds every stored personal field of the address and no field of the other customer, on a tenant with two customers and a booking with a note', function () {
    $bookings = customerExportTwoCustomers();

    $body = customerExportGet($this, 'Casey@EXAMPLE.com')->assertOk()->streamedContent();

    $theirs = customerExportStored(true);
    $others = customerExportStored(false);

    expect($theirs)->toContain('Casey C.', 'CASEY@example.com', '+30 699 1112223', 'A second try at the date.', 'Lavender')
        ->and($others)->toContain('Robin Regular', CUSTOMER_EXPORT_NEIGHBOUR, '+30 210 5550000', 'Mind the left knee.')
        ->and(array_values(array_filter($theirs, fn (string $field): bool => ! str_contains($body, json_encode($field, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))))->toBe([])
        ->and(array_values(array_filter($others, fn (string $field): bool => stripos($body, $field) !== false)))->toBe([])
        ->and($body)->not->toContain($bookings['neighbour']['uuid']);
});

it('names no hash, token, sealed value, payment reference, hold or serial id in the document, by key or by value', function () {
    $owner = customerExportOwner();
    $booking = customerExportBooking(
        [
            'id' => 7300001, 'status' => 'awaiting_payment', 'customer_name' => 'Casey Customer', 'customer_email' => CUSTOMER_EXPORT_EMAIL, 'customer_phone' => '+30 210 7654321',
            'payment_mode' => 'online', 'requires_approval' => true, 'approved_at' => '2026-11-01 07:00:00+00', 'approved_by' => $owner->id,
            'hold_class' => 'payment', 'hold_expires_at' => '2026-11-04 09:17:00+00', 'payment_ref' => 'cs_test_export_reference',
            'manage_token_hash' => hash('sha256', 'export-manage-token'), 'manage_token_sealed' => 'sealed-export-manage-token',
        ],
        [
            ['Haircut', 'Elena Papadaki', '2026-11-06 08:00:00+00', '2026-11-06 08:45:00+00', 3500],
            ['Colour', 'Elena Papadaki', '2026-11-06 08:45:00+00', '2026-11-06 09:45:00+00', 8000],
        ],
        ['note' => 'Please call before the appointment.'],
        itemIds: 7300101,
    );
    DB::table('tenant_luna.booking_items')->where('booking_id', $booking['id'])->orderByDesc('sort')->limit(1)->update(['processing_ends_utc' => '2026-11-06 10:15:00+00', 'block_end_utc' => '2026-11-06 09:46:00+00']);
    customerExportProfile(CUSTOMER_EXPORT_EMAIL, 'user:'.$owner->id);

    $response = customerExportGet($this, CUSTOMER_EXPORT_EMAIL);
    $body = $response->streamedContent();
    $leaves = customerExportLeaves(customerExportDocument($response));
    $forbidden = ['actor' => 'user:'.$owner->id, 'hash' => hash('sha256', 'export-manage-token'), 'sealed' => 'sealed-export-manage-token', 'reference' => 'cs_test_export_reference', 'booking id' => '7300001', 'first item id' => '7300101', 'second item id' => '7300102', 'hold expiry' => ':17:00', 'block end' => ':46:00', 'processing end' => ':15:00'];

    expect($leaves['keys'])->toEqualCanonicalizing([
        'business', 'exported_at', 'timezone', 'customer', 'profile', 'notes', 'bookings',
        'names', 'emails', 'phones',
        'tags', 'birthday', 'language', 'blocked_at', 'blocked_reason',
        'body', 'author', 'written_at',
        'uuid', 'status', 'booked_at', 'name', 'email', 'phone', 'items', 'total_minor', 'currency', 'payment_mode', 'source', 'channel', 'requires_approval', 'approved_at', 'rejection_reason', 'intake',
        'service', 'staff', 'starts_at', 'ends_at', 'price_minor',
        'note',
    ])
        ->and(array_values(array_filter($leaves['keys'], fn (string $key): bool => preg_match('/(^|_)id$|token|hash|sealed|ref|hold|block(?!ed_)|processing|password|secret|by$/i', $key) === 1)))->toBe([])
        ->and(array_filter($forbidden, fn (string $value): bool => str_contains($body, $value)))->toBe([])
        ->and(array_values(array_filter($leaves['values'], fn (string $value): bool => in_array($value, [(string) $owner->id, (string) LunaFixture::id('services', 'Haircut'), (string) LunaFixture::id('staff', 'Elena Papadaki')], true))))->toBe([]);
});

it('writes one customer_exported row per booking of the address, the user as the actor and the payload empty, in one transaction before the document goes out', function () {
    $bookings = customerExportTwoCustomers();
    $owner = customerExportOwner();

    // Each trail row written records the transaction it ran in.
    DB::unprepared(<<<'SQL'
        create table tenant_luna.export_probe (seq bigserial primary key, booking_id bigint not null, txid bigint not null);
        create function tenant_luna.export_probe() returns trigger language plpgsql as $$
        begin
            insert into tenant_luna.export_probe (booking_id, txid) values (new.booking_id, txid_current());
            return null;
        end
        $$;
        create trigger export_probe after insert on tenant_luna.audit_log for each row execute function tenant_luna.export_probe();
        SQL);

    $document = customerExportDocument(customerExportGet($this, CUSTOMER_EXPORT_EMAIL));
    $exported = [$bookings['completed'], $bookings['confirmed'], $bookings['rejected']];

    expect(customerExportTrail())->toBe(array_map(fn (array $booking): array => [
        'actor' => 'user:'.$owner->id,
        'action' => 'customer_exported',
        'payload' => [],
        'uuid' => $booking['uuid'],
    ], $exported))
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'customer_exported')->pluck('payload')->all())->toBe(['{}', '{}', '{}'])
        ->and(array_column(is_array($document['bookings']) ? $document['bookings'] : [], 'uuid'))->toBe(array_column($exported, 'uuid'))
        ->and(DB::table('tenant_luna.export_probe')->orderBy('seq')->pluck('booking_id')->all())->toBe(array_column($exported, 'id'))
        ->and(DB::table('tenant_luna.export_probe')->distinct()->count('txid'))->toBe(1);

    customerExportGet($this, CUSTOMER_EXPORT_NEIGHBOUR)->assertOk();

    expect(array_column(customerExportTrail(), 'uuid'))->toBe([...array_column($exported, 'uuid'), $bookings['neighbour']['uuid']]);
});

it('sends no document and writes no trail row when the trail cannot be written, the row before the refused one rolling back with it', function () {
    $bookings = customerExportTwoCustomers();

    // The trail refuses the second booking's row, the first written a row before it in the same
    // statement.
    DB::unprepared(<<<'SQL'
        create table tenant_luna.export_refused (booking_id bigint primary key);
        create function tenant_luna.export_refuse() returns trigger language plpgsql as $$
        begin
            if exists (select 1 from tenant_luna.export_refused refused where refused.booking_id = new.booking_id) then
                raise exception 'the trail refuses this row';
            end if;
            return new;
        end
        $$;
        create trigger export_refuse before insert on tenant_luna.audit_log for each row execute function tenant_luna.export_refuse();
        SQL);
    DB::table('tenant_luna.export_refused')->insert(['booking_id' => $bookings['confirmed']['id']]);

    $response = customerExportGet($this, CUSTOMER_EXPORT_EMAIL)->assertStatus(500);

    expect($response->headers->get('Content-Disposition'))->toBeNull()
        ->and(DB::table('tenant_luna.audit_log')->count())->toBe(0);
});

it('names the file by the tenant\'s today and stamps the export on the tenant\'s clock, half an hour past midnight in Athens while UTC still reads the day before', function () {
    customerExportTwoCustomers();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 22:30'));

    $response = customerExportGet($this, CUSTOMER_EXPORT_EMAIL)->assertHeader('Content-Disposition', 'attachment; filename=customer-luna-2026-11-03.json');

    expect(customerExportDocument($response)['exported_at'])->toBe('2026-11-03T00:30:00+02:00');
});

it('answers an address no booking carries, an erased customer\'s among them, with the shell\'s NotFound page to a page visit and the bare 404 to a JSON caller, and writes no export row', function () {
    customerExportBooking(['customer_name' => 'Casey Customer', 'customer_email' => CUSTOMER_EXPORT_EMAIL], [['Haircut', 'Elena Papadaki', '2026-10-20 08:00:00+00', '2026-10-20 08:45:00+00', 3500]]);
    $page = tenantUrl('luna', '/admin/customers/'.rawurlencode(CUSTOMER_EXPORT_EMAIL));

    customerExportGet($this, 'nobody@example.test')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('NotFound'));
    $this->actingAs(customerExportOwner())->from($page)->post($page.'/erase')->assertRedirect(tenantUrl('luna', '/admin/customers'));
    customerExportGet($this, CUSTOMER_EXPORT_EMAIL)->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('NotFound'));
    $this->getJson(customerExportUrl(CUSTOMER_EXPORT_EMAIL))->assertNotFound()->assertHeader('Content-Type', 'application/json');

    expect(customerExportTrail())->toBe([])
        ->and(array_column(LunaFixture::trail(), 'action'))->toBe(['customer_erased']);
});

it('refuses a viewer without manageBookings the export, 403, answers a signed-out JSON caller 401 rather than the sign-in page, and writes nothing', function () {
    customerExportTwoCustomers();
    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff'));

    $this->getJson(customerExportUrl(CUSTOMER_EXPORT_EMAIL))->assertUnauthorized();
    $this->get(customerExportUrl(CUSTOMER_EXPORT_EMAIL))->assertRedirect(tenantUrl('luna', '/admin/login'));
    customerExportGet($this, CUSTOMER_EXPORT_EMAIL, $staff)->assertForbidden();

    expect(DB::table('tenant_luna.audit_log')->count())->toBe(0);
});

it('exports the customer of a tenant whose subscription has lapsed, the export being outside the subscription gate', function () {
    customerExportTwoCustomers();
    billingSet(['subscription_state' => 'canceled', 'grace_ends_at' => null]);

    $document = customerExportDocument(customerExportGet($this, CUSTOMER_EXPORT_EMAIL));

    expect(is_array($document['bookings']) ? $document['bookings'] : [])->toHaveCount(3)
        ->and(customerExportTrail())->toHaveCount(3);
});

it('matches the address as the customer\'s page and its erasure match it, on the one constraint', function () {
    $routes = Route::getRoutes();

    expect($routes->getByName('admin.customers.export')?->wheres)->toBe($routes->getByName('admin.customers.show')?->wheres)
        ->and($routes->getByName('admin.customers.export')?->wheres)->toBe($routes->getByName('admin.customers.erase')?->wheres)
        ->and($routes->getByName('admin.customers.export')?->uri())->toBe('admin/customers/{email}/export');
});

it('carries the address\'s profile and its notes, oldest first, each instant on the tenant\'s clock and each author by the name they wrote under, and nothing of another address\'s', function () {
    customerExportTwoCustomers();
    customerExportProfile(CUSTOMER_EXPORT_EMAIL);
    DB::table('tenant_luna.customer_profiles')->insert(['email' => CUSTOMER_EXPORT_NEIGHBOUR, 'tags' => '{knee}', 'created_at' => '2026-10-01 09:00:00+00', 'updated_at' => '2026-10-01 09:00:00+00']);
    DB::table('tenant_luna.customer_notes')->insert(['email' => CUSTOMER_EXPORT_NEIGHBOUR, 'body' => 'Robin prefers mornings.', 'author_actor' => 'user:1', 'author_name' => 'Luna Owner', 'created_at' => '2026-09-01 08:00:00+00']);

    $response = customerExportGet($this, 'Casey@EXAMPLE.com')->assertOk();
    $document = customerExportDocument($response);

    expect($document['profile'] ?? null)->toBe([
        'tags' => ['VIP', 'colour'],
        'birthday' => '1988-04-12',
        'language' => 'el',
        'blocked_at' => '2026-11-01T00:30:00+02:00',
        'blocked_reason' => 'Three missed bookings',
    ])
        ->and($document['notes'] ?? null)->toBe([
            ['body' => 'Asked for a quote on a bridal package.', 'author' => 'Elena Papadaki', 'written_at' => '2026-07-14T11:40:00+03:00'],
            ['body' => 'Allergic to ammonia colour.', 'author' => 'Luna Owner', 'written_at' => '2026-09-03T18:12:00+03:00'],
        ])
        ->and($response->streamedContent())->not->toContain('Robin prefers mornings.')->not->toContain('knee');
});
