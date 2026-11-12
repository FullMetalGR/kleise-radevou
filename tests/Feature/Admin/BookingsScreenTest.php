<?php

use App\Application\Dto\BookingFilter;
use App\Application\Dto\BookingRecord;
use App\Domain\Audit\AuditKey;
use App\Http\BookingPayload;
use App\Http\BookingTrail;
use App\Infrastructure\Db\BookingRecords;
use App\Models\User;
use App\Retention\Erasure;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Bookings screen and the booking dialog: every filter a server parameter ANDed, the To
 * date inclusive, the fixed page and N of M, the dialog as a route, and every booking
 * through the payload whitelist with the viewer's permissions.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
});

/**
 * A booking of luna written straight to the rows: one item per segment, each [service, staff,
 * Athens start, minutes], chained in order.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $segments
 */
function listed(array $segments, string $name = 'Casey Customer', string $status = 'confirmed', string $email = 'casey@example.test', string $currency = 'EUR', int $total = 3500): string
{
    $uuid = (string) Str::uuid();
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid, 'status' => $status, 'customer_name' => $name, 'customer_email' => $email,
        'customer_phone' => '+30 210 7654321', 'total_minor' => $total, 'currency' => $currency,
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
        'rejection_reason' => $status === 'rejected' ? 'Fully booked that week' : null,
    ]);

    foreach ($segments as $sort => [$service, $staff, $start, $minutes]) {
        $from = LunaFixture::at($start);
        $to = $from->add(new DateInterval(sprintf('PT%dM', $minutes)));
        DB::table('tenant_luna.booking_items')->insert([
            'booking_id' => $id, 'sort' => $sort,
            'service_id' => LunaFixture::id('services', $service), 'staff_id' => LunaFixture::id('staff', $staff),
            'start_utc' => LunaFixture::instant($from), 'end_utc' => LunaFixture::instant($to),
            'block_start_utc' => LunaFixture::instant($from), 'block_end_utc' => LunaFixture::instant($to->add(new DateInterval('PT10M'))),
            'price_minor' => $total, 'blocks' => false,
        ]);
    }

    return $uuid;
}

function bookingsOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * The uuids of the rows the list answers for a query.
 *
 * @param  array<string, string|int>  $query
 * @return list<string>
 */
function listedUuids(TestCase $test, array $query): array
{
    $rows = data_get($test->actingAs(bookingsOwner())->get(tenantUrl('luna', '/admin/bookings?'.http_build_query($query)))->assertOk()->viewData('page'), 'props.bookings.rows');
    assert(is_array($rows));

    return array_values(array_map(fn (mixed $row): string => is_array($row) && is_string($row['uuid'] ?? null) ? $row['uuid'] : '', $rows));
}

it('narrows the list on the server by each filter, ANDs two, and holds the To date inclusive', function () {
    $novEarly = listed([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]], 'Ada North', email: 'ada@example.test');
    $novLate = listed([['Beard trim', 'Nikos Georgiou', '2026-11-20 10:00', 30]], 'Bo South');
    $dec = listed([['Haircut', 'Nikos Georgiou', '2026-12-04 10:00', 45]], 'Cy East', 'cancelled');
    $toDay = listed([['Haircut', 'Elena Papadaki', '2026-11-30 23:30', 30]], 'Di West');
    $nextDay = listed([['Haircut', 'Elena Papadaki', '2026-12-01 00:00', 30]], 'Ed Next');
    $chain = listed([['Haircut', 'Elena Papadaki', '2026-11-10 09:00', 45], ['Colour', 'Elena Papadaki', '2026-11-10 09:45', 60]], 'Flo Chain');

    expect(listedUuids($this, []))->toHaveCount(6)
        ->and(listedUuids($this, ['from' => '2026-12-01']))->toEqualCanonicalizing([$dec, $nextDay])
        ->and(listedUuids($this, ['to' => '2026-11-30']))->toEqualCanonicalizing([$novEarly, $novLate, $toDay, $chain])
        ->and(listedUuids($this, ['from' => '2026-11-15', 'to' => '2026-11-30']))->toEqualCanonicalizing([$novLate, $toDay])
        ->and(listedUuids($this, ['status' => 'cancelled']))->toBe([$dec])
        ->and(listedUuids($this, ['staff' => LunaFixture::id('staff', 'Nikos Georgiou')]))->toEqualCanonicalizing([$novLate, $dec])
        ->and(listedUuids($this, ['service' => LunaFixture::id('services', 'Colour')]))->toBe([$chain])
        ->and(listedUuids($this, ['search' => 'ADA@']))->toBe([$novEarly])
        ->and(listedUuids($this, ['search' => 'south']))->toBe([$novLate])
        ->and(listedUuids($this, ['staff' => LunaFixture::id('staff', 'Nikos Georgiou'), 'status' => 'confirmed']))->toBe([$novLate])
        ->and(listedUuids($this, ['status' => 'not-a-status', 'from' => '2026-02-31']))->toHaveCount(6);
});

it('pages at twenty-five with the count of every match, the server order standing', function () {
    for ($n = 0; $n < 61; $n++) {
        listed([['Haircut', 'Elena Papadaki', sprintf('2026-11-%02d 09:00', 1 + intdiv($n, 3)), 15]], sprintf('Customer %02d', $n));
    }

    $first = $this->actingAs(bookingsOwner())->get(tenantUrl('luna', '/admin/bookings'));
    $first->assertInertia(fn (Assert $page) => $page->component('Bookings')
        ->where('bookings.total', 61)->has('bookings.rows', 25)->where('bookings.page', 1)->where('bookings.page_size', 25)
        ->where('filters.page', 1));

    $this->get(tenantUrl('luna', '/admin/bookings?page=3'))
        ->assertInertia(fn (Assert $page) => $page->where('bookings.total', 61)->has('bookings.rows', 11)->where('filters.page', 3));
});

it('reads the calendar\'s first day as From in a zone east of UTC and its last as To, each a date like any other, as the console reads them', function () {
    $nov = listed([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]], 'Ada North');
    $dec = listed([['Haircut', 'Nikos Georgiou', '2026-12-04 10:00', 45]], 'Cy East');

    // luna's zone is Athens, so the first day's midnight is an instant of the year before the
    // first the database spells: it bounds nothing, since no booking precedes it.
    $this->actingAs(bookingsOwner())->get(tenantUrl('luna', '/admin/bookings?from=0001-01-01&to=9999-12-31'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.from', '0001-01-01')->where('filters.to', '9999-12-31')->where('bookings.total', 2));

    expect(listedUuids($this, ['from' => '0001-01-01']))->toEqualCanonicalizing([$nov, $dec])
        ->and(listedUuids($this, ['to' => '0001-01-01']))->toBe([])
        ->and(listedUuids($this, ['from' => '9999-12-31']))->toBe([]);
});

it('reads a search that is not UTF-8 or holds a NUL as no search, as the console reads one, and a page past nine digits as the first, as the customers read one', function (string $key, string $value) {
    listed([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]], 'Ada North');
    listed([['Haircut', 'Nikos Georgiou', '2026-12-04 10:00', 45]], 'Cy East');

    $this->actingAs(bookingsOwner())->get(tenantUrl('luna', '/admin/bookings?'.http_build_query([$key => $value])))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.search', '')->where('filters.page', 1)->where('bookings.page', 1)->where('bookings.total', 2)->has('bookings.rows', 2));
})->with([
    'a search that is not UTF-8' => ['search', "Ad\xC3"],
    'a search holding a NUL' => ['search', "Ad\0a"],
    'a page past nine digits' => ['page', '1234567890'],
    'a page of twenty digits' => ['page', '99999999999999999999'],
]);

it('carries the bookings section of the admin\'s words beside the shell\'s, the list\'s and the dialog\'s, in the request\'s language', function () {
    $this->actingAs(bookingsOwner())->get(tenantUrl('luna', '/admin/bookings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Bookings')
            ->where('strings.bookings', __('admin/bookings', [], 'en'))
            ->where('strings.shell', __('admin/shell', [], 'en')));
});

it('renders the list with the dialog open at the booking\'s own route, and the not-found state for an unknown uuid', function () {
    $uuid = listed([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45], ['Colour', 'Nikos Georgiou', '2026-11-03 10:45', 60]], 'Ada North', 'rejected');
    DB::table('tenant_luna.audit_log')->insert([
        ['booking_id' => DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('id'), 'actor' => 'customer', 'action' => 'held', 'payload' => '{"status_to":"awaiting_approval"}', 'created_at' => '2026-10-01 08:00:00+00'],
        ['booking_id' => DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('id'), 'actor' => 'user:'.bookingsOwner()->id, 'action' => 'rejected', 'payload' => '{"status_from":"awaiting_approval","status_to":"rejected"}', 'created_at' => '2026-10-02 08:00:00+00'],
        ['booking_id' => DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('id'), 'actor' => 'job:expire_holds', 'action' => 'expired', 'payload' => '{}', 'created_at' => '2026-10-01 09:00:00+00'],
    ]);

    $this->actingAs(bookingsOwner())->get(tenantUrl('luna', '/admin/bookings/'.$uuid.'?staff='.LunaFixture::id('staff', 'Elena Papadaki')))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Bookings')
            ->where('filters.staff', LunaFixture::id('staff', 'Elena Papadaki'))
            ->has('bookings.rows', 1)
            ->where('detail.state', 'found')
            ->where('detail.booking.uuid', $uuid)
            ->where('detail.booking.customer_email', 'casey@example.test')
            ->where('detail.booking.customer_phone', '+30 210 7654321')
            ->where('detail.booking.rejection_reason', 'Fully booked that week')
            ->where('detail.booking.items.1.service_name', 'Colour')
            ->where('detail.trail.0.kind', 'customer')
            ->where('detail.trail.1.kind', 'job')
            ->where('detail.trail.2.name', bookingsOwner()->name)
            ->where('detail.trail.2.at', '2026-10-02T08:00:00+00:00')
            ->where('detail.trail.2.facts', ['status_from' => 'awaiting_approval', 'status_to' => 'rejected'])
            ->where('nav.2', ['label' => navLabel('bookings'), 'url' => '/admin/bookings', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => true]));

    $this->get(tenantUrl('luna', '/admin/bookings/'.Str::uuid()))
        ->assertInertia(fn (Assert $page) => $page->where('detail.state', 'missing'));
    $this->get(tenantUrl('luna', '/admin/bookings/not-a-uuid'))
        ->assertInertia(fn (Assert $page) => $page->where('detail.state', 'missing'));
    $this->get(tenantUrl('luna', '/admin/bookings'))
        ->assertInertia(fn (Assert $page) => $page->where('detail', null));
});

it('withholds email, phone, payment mode and the occupied ranges from a viewer without manageBookings, through the whitelist class', function () {
    $uuid = listed([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]]);
    $record = app(BookingRecords::class)->one(Tenant::query()->where('slug', 'luna')->sole(), $uuid);
    assert($record instanceof BookingRecord);

    $viewer = BookingPayload::admin($record, false);
    $manager = BookingPayload::admin($record, true);

    expect($viewer)->not->toHaveKeys(['customer_email', 'customer_phone', 'payment_mode'])
        ->and(data_get($viewer, 'items.0'))->not->toHaveKeys(['block_start_utc', 'block_end_utc', 'processing_ends_utc'])
        ->and($manager)->toHaveKeys(['customer_email', 'customer_phone', 'payment_mode'])
        ->and(data_get($manager, 'items.0.block_end_utc'))->toBe('2026-11-03T08:55:00+00:00');

    // The viewer's view names exactly the guest's columns less the ways to reach the customer,
    // and the viewer bucket besides: nothing of the manage-only bucket rides along.
    $expected = [...array_diff(BookingPayload::GUEST, BookingPayload::CONTACT), ...BookingPayload::VIEWER, 'items'];
    $keys = array_keys($viewer);
    sort($expected);
    sort($keys);

    expect($keys)->toBe($expected);
});

it('lists a booking whose customer was erased with the name, the address and the phone null, the dialog reading it so with its trail standing, and a viewer without manageBookings shown the null name and no contact key', function () {
    $uuid = listed([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]], 'Ada North', 'completed');
    $kept = listed([['Haircut', 'Elena Papadaki', '2026-11-03 12:00', 45]], 'Bo South', 'confirmed', 'bo@example.test');
    $id = DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('id');
    assert(is_int($id));
    DB::table('tenant_luna.booking_meta')->insert(['booking_id' => $id, 'key' => 'note', 'value' => 'The quiet room, please.']);
    DB::table('tenant_luna.audit_log')->insert(['booking_id' => $id, 'actor' => 'user:'.bookingsOwner()->id, 'action' => 'completed', 'payload' => '{"status_from":"confirmed","status_to":"completed"}', 'created_at' => '2026-11-03 09:00:00+00']);
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    app(Erasure::class)->bookings($tenant, [$id]);

    $this->actingAs(bookingsOwner())->get(tenantUrl('luna', '/admin/bookings/'.$uuid))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Bookings')
            ->where('bookings.rows', fn (Collection $rows): bool => $rows->pluck('customer_name', 'uuid')->all() === [$kept => 'Bo South', $uuid => null])
            ->where('bookings.rows.1.customer_email', null)
            ->where('bookings.rows.1.customer_phone', null)
            ->where('detail.state', 'found')
            ->where('detail.booking.uuid', $uuid)
            ->where('detail.booking.status', 'completed')
            ->where('detail.booking.customer_name', null)
            ->where('detail.booking.customer_email', null)
            ->where('detail.booking.customer_phone', null)
            ->where('detail.booking.items.0.service_name', 'Haircut')
            ->where('detail.note', null)
            ->where('detail.trail.0.facts', ['status_from' => 'confirmed', 'status_to' => 'completed']));

    $record = app(BookingRecords::class)->one($tenant, $uuid);
    assert($record instanceof BookingRecord);

    expect(BookingPayload::admin($record, false))->toMatchArray(['uuid' => $uuid, 'customer_name' => null])
        ->and(BookingPayload::admin($record, false))->not->toHaveKeys(BookingPayload::CONTACT)
        ->and($record->snapshot->customer)->toBeNull()
        ->and(fn () => $record->snapshot->person())->toThrow(LogicException::class, 'names no customer');
});

it('shows the rejection reason to every viewer of the dialog, one without manageBookings included, and never to the guest', function () {
    $uuid = listed([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]], status: 'rejected');
    $record = app(BookingRecords::class)->one(Tenant::query()->where('slug', 'luna')->sole(), $uuid);
    assert($record instanceof BookingRecord);

    // The reason is the owner's note to their own staff, so the staff member without
    // manageBookings is the reader it is written for, and the customer holding the manage token
    // is the one reader it is withheld from.
    expect(BookingPayload::admin($record, false))->toHaveKey('rejection_reason', 'Fully booked that week')
        ->and(BookingPayload::admin($record, true))->toHaveKey('rejection_reason', 'Fully booked that week')
        ->and(BookingPayload::guest($record->snapshot))->not->toHaveKey('rejection_reason');
});

it('searches the customer\'s email only for a viewer the whitelist shows it to, and says which fields the search reads', function () {
    $hidden = listed([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]], 'Ada North', email: 'hidden@example.test');
    $tenant = Tenant::query()->where('slug', 'luna')->sole();
    $uuidsOf = fn (bool $manage, string $search): array => array_map(
        fn (BookingRecord $record): string => $record->snapshot->uuid,
        app(BookingRecords::class)->page($tenant, (new BookingFilter(search: $search))->searchingIn(BookingPayload::searchable($manage)))['records'],
    );

    expect(BookingPayload::searchable(false))->toBe(['customer_name'])
        ->and(BookingPayload::searchable(true))->toBe(['customer_name', 'customer_email'])
        ->and($uuidsOf(false, 'hidden@'))->toBe([])
        ->and($uuidsOf(false, 'north'))->toBe([$hidden])
        ->and($uuidsOf(true, 'hidden@'))->toBe([$hidden])
        ->and(app(BookingRecords::class)->page($tenant, new BookingFilter(search: 'hidden@'))['records'])->toBe([]);

    $this->actingAs(bookingsOwner())->get(tenantUrl('luna', '/admin/bookings?search=hidden%40'))
        ->assertInertia(fn (Assert $page) => $page->where('options.searchable', ['customer_name', 'customer_email'])->where('bookings.total', 1));
});

it('classifies every live column for the admin\'s audience: a manager\'s view names every column but the withheld ones', function () {
    listed([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]]);
    $record = app(BookingRecords::class)->page(Tenant::query()->where('slug', 'luna')->sole(), new BookingFilter)['records'][0];
    $view = BookingPayload::admin($record, true);
    $item = data_get($view, 'items.0');
    assert(is_array($item));
    $bookingKeys = array_values(array_diff(array_keys($view), ['items']));
    $itemKeys = array_values(array_diff(array_keys($item), ['service_name', 'staff_name', 'start', 'end']));
    $liveBookings = array_values(array_diff(liveColumns('tenant_luna', 'bookings'), BookingPayload::WITHHELD));
    $liveItems = array_values(array_diff(liveColumns('tenant_luna', 'booking_items'), BookingPayload::ITEM_WITHHELD, ['start_utc', 'end_utc']));
    sort($bookingKeys);
    sort($itemKeys);
    sort($liveBookings);
    sort($liveItems);

    expect($bookingKeys)->toBe($liveBookings)->and($itemKeys)->toBe($liveItems);
});

it('puts Bookings on the rail for viewBookings and leaves a staff user the Calendar alone', function () {
    $command = $this->artisan('tenant:add-user', ['slug' => 'luna', '--name' => 'Sam Staff', '--email' => 'sam@luna.example', '--password' => 'secret', '--role' => 'staff']);
    assert($command instanceof PendingCommand);
    $command->assertExitCode(0)->run();

    $this->actingAs(User::query()->where('email', 'sam@luna.example')->sole())->get(tenantUrl('luna', '/admin/calendar'))
        ->assertInertia(fn (Assert $page) => $page->where('nav', [['label' => navLabel('calendar'), 'url' => '/admin/calendar', 'group' => navLabel('groups.schedule'), 'pending' => null, 'current' => true]]));
    $this->get(tenantUrl('luna', '/admin/bookings'))->assertForbidden();
});

it('classifies every audit fact for the trail in the whitelist class, drops a fact it never named, and the trail passes through it', function () {
    $keys = array_map(fn (AuditKey $key): string => $key->value, AuditKey::cases());
    $classified = [...BookingPayload::TRAIL_FACTS, ...BookingPayload::TRAIL_WITHHELD];
    sort($keys);
    sort($classified);

    expect($classified)->toBe($keys)
        ->and(array_intersect(BookingPayload::TRAIL_FACTS, BookingPayload::TRAIL_WITHHELD))->toBe([]);

    // A payload carrying a key the whitelist never named, as a writer that forgot the whitelist
    // would leave one, cannot be written here: the suite's own audit check refuses it. So the
    // decision is asked directly, and the trail is shown to pass through it.
    expect(BookingPayload::trailFacts(['status_to' => 'confirmed', 'customer_email' => 'casey@example.test', 'note' => 'x']))
        ->toBe(['status_to' => 'confirmed'])
        ->and(BookingPayload::trailFacts(['payment_named' => true, 'paid_minor' => 4500, 'paid_currency' => 'EUR', 'session_cleared' => 1]))
        ->toBe(['paid_minor' => 4500, 'paid_currency' => 'EUR']);

    $uuid = listed([['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 45]]);
    DB::table('tenant_luna.audit_log')->insert([
        'booking_id' => DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('id'),
        'actor' => 'system',
        'action' => 'confirmed',
        'payload' => (string) json_encode(['status_from' => 'pending', 'status_to' => 'confirmed']),
    ]);

    $trail = app(BookingTrail::class)->of(Tenant::query()->where('slug', 'luna')->sole(), $uuid);

    expect(array_map(fn (array $entry): array => $entry['facts'], $trail))->toEqual([['status_from' => 'pending', 'status_to' => 'confirmed']]);
});
