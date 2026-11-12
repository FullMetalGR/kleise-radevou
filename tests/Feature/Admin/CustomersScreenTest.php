<?php

use App\Application\Clock;
use App\Application\Dto\CustomerFilter;
use App\Application\Dto\CustomerProfile;
use App\Application\Dto\CustomerRecord;
use App\Billing\Entitlements;
use App\Http\AdminNav;
use App\Http\BookingPayload;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\CustomerPayload;
use App\Http\Middleware\HandleInertiaRequests;
use App\Infrastructure\Db\CustomerRecords;
use App\Models\User;
use App\Retention\Erasure;
use App\Tenancy\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;

pest()->use(RefreshDatabase::class);

/*
 * The Customers screen: one customer per address over luna's bookings as citext groups it, the
 * names and phones seen most recent first, the first visit off each booking's first item and the
 * last, the latest first start at or before now in confirmed or completed (null where none is),
 * the count in any status, the tiles' counts (upcoming, completed, no-shows, cancelled), the
 * latest first start in any status first, a search over the name and the email as the Bookings
 * search runs it, a page of twenty-five; and an address's own page, listing its bookings through
 * the Bookings list's reader with the booking dialog read by a partial reload, an unknown
 * address answering the shell's 404.
 *
 * The bookings are written straight to the rows at literal dates, so the clock is pinned first;
 * the dialog's actions are read off it.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FixtureDay::pinClock();
});

/**
 * A booking of luna under an address, written straight to the rows: one Haircut item on Elena
 * per Athens start given, chained in order, so a booking's first start is its first item's.
 *
 * @param  non-empty-list<string>  $starts
 */
function customersBooking(string $email, string $name, array $starts, ?string $phone = '+30 210 7654321', string $status = 'confirmed'): string
{
    $uuid = (string) Str::uuid();
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid, 'status' => $status, 'customer_name' => $name, 'customer_email' => $email,
        'customer_phone' => $phone, 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);

    foreach ($starts as $sort => $start) {
        $from = LunaFixture::at($start);
        $to = $from->add(new DateInterval('PT45M'));
        DB::table('tenant_luna.booking_items')->insert([
            'booking_id' => $id, 'sort' => $sort,
            'service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki'),
            'start_utc' => LunaFixture::instant($from), 'end_utc' => LunaFixture::instant($to),
            'block_start_utc' => LunaFixture::instant($from), 'block_end_utc' => LunaFixture::instant($to),
            'price_minor' => 3500, 'blocks' => false,
        ]);
    }

    return $uuid;
}

/**
 * A customer as plain fields, the visits spelled in UTC.
 *
 * @return array{email: string, names: list<string>, phones: list<string>, first: string, last: ?string, bookings: int, upcoming: int, completed: int, no_shows: int, cancelled: int}
 */
function customersPlain(CustomerRecord $customer): array
{
    return [
        'email' => $customer->email,
        'names' => $customer->names,
        'phones' => $customer->phones,
        'first' => $customer->firstVisit->format(DateTimeInterface::RFC3339),
        'last' => $customer->lastVisit?->format(DateTimeInterface::RFC3339),
        'bookings' => $customer->bookings,
        'upcoming' => $customer->upcoming,
        'completed' => $customer->completed,
        'no_shows' => $customer->noShows,
        'cancelled' => $customer->cancelled,
    ];
}

/**
 * The page the reader answers for a filter, each customer as its plain fields.
 *
 * @return array{total: int, customers: list<array{email: string, names: list<string>, phones: list<string>, first: string, last: ?string, bookings: int, upcoming: int, completed: int, no_shows: int, cancelled: int}>}
 */
function customersRead(CustomerFilter $filter = new CustomerFilter): array
{
    $page = app(CustomerRecords::class)->page(LunaFixture::tenant(), $filter);

    return ['total' => $page['total'], 'customers' => array_map(customersPlain(...), $page['customers'])];
}

/**
 * The addresses a search answers, searching the columns given.
 *
 * @param  list<string>  $columns
 * @return list<string>
 */
function customersFound(string $search, array $columns): array
{
    return array_column(customersRead((new CustomerFilter(search: $search))->searchingIn($columns))['customers'], 'email');
}

/**
 * Binds the application's clock at an Athens wall-clock time, for a test whose now must stand
 * among its bookings rather than before them.
 */
function customersNow(string $athens): void
{
    app()->instance(Clock::class, new FixedClock(LunaFixture::at($athens)));
}

it('counts the tiles of a customer against now: upcoming in confirmed, awaiting approval or awaiting payment ahead of now, completed, no-shows and cancelled by status, the last visit the latest start passed in confirmed or completed', function () {
    customersNow('2026-11-12 12:00');
    // Passed: a completed, a no-show, a cancelled, a rejected, an expired, and a confirmed whose
    // start is the latest passed in confirmed or completed, a passed awaiting approval after it.
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-03 10:00'], status: 'completed');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-04 10:00'], status: 'no_show');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-05 10:00'], status: 'cancelled');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-06 10:00'], status: 'rejected');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-07 10:00'], status: 'expired');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-10 09:00', '2026-11-12 13:00']);
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-11 10:00'], status: 'awaiting_approval');
    // Ahead: one in each upcoming status, a pending hold and a cancelled that count as none.
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-12 12:30']);
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-14 10:00'], status: 'awaiting_approval');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-15 10:00'], status: 'awaiting_payment');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-16 10:00'], status: 'pending');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-17 10:00'], status: 'cancelled');
    // An address with nothing passed in confirmed or completed has no last visit.
    customersBooking('ada@example.test', 'Ada North', ['2026-11-04 10:00'], status: 'no_show');
    customersBooking('ada@example.test', 'Ada North', ['2026-11-20 10:00']);

    $tiles = fn (array $customer): array => array_intersect_key($customer, array_flip(['email', 'last', 'bookings', 'upcoming', 'completed', 'no_shows', 'cancelled']));

    expect(array_map($tiles, customersRead()['customers']))->toBe([
        ['email' => 'ada@example.test', 'last' => null, 'bookings' => 2, 'upcoming' => 1, 'completed' => 0, 'no_shows' => 1, 'cancelled' => 0],
        ['email' => 'casey@example.com', 'last' => '2026-11-10T07:00:00+00:00', 'bookings' => 12, 'upcoming' => 3, 'completed' => 1, 'no_shows' => 1, 'cancelled' => 2],
    ]);

    // An hour on, the 12:30 booking has passed: it leaves Upcoming and becomes the last visit.
    customersNow('2026-11-12 13:30');

    expect($tiles(customersPlain(app(CustomerRecords::class)->one(LunaFixture::tenant(), 'casey@example.com') ?? throw new LogicException('casey'))))
        ->toBe(['email' => 'casey@example.com', 'last' => '2026-11-12T10:30:00+00:00', 'bookings' => 12, 'upcoming' => 2, 'completed' => 1, 'no_shows' => 1, 'cancelled' => 2]);

    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customer')
            ->where('customer.upcoming', 2)->where('customer.completed', 1)->where('customer.no_shows', 1)->where('customer.cancelled', 2)
            ->where('customer.last_visit', '2026-11-12T10:30:00+00:00'));

    $this->get(tenantUrl('luna', '/admin/customers'))
        ->assertInertia(fn (Assert $page) => $page->where('customers.rows.0.email', 'ada@example.test')->where('customers.rows.0.last_visit', null)
            ->where('customers.rows.1.last_visit', '2026-11-12T10:30:00+00:00')->where('customers.rows.1.upcoming', 2));
});

it('takes the last visit from a completed booking when it is the latest passed, on the tile and in the list\'s cell, a confirmed one before it and a confirmed one ahead left out', function () {
    customersNow('2026-11-12 12:00');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-05 10:00']);
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-10 09:00'], status: 'completed');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-20 10:00']);
    customersBooking('ada@example.test', 'Ada North', ['2026-11-04 10:00'], status: 'completed');

    expect(array_column(customersRead()['customers'], 'last', 'email'))->toBe([
        'casey@example.com' => '2026-11-10T07:00:00+00:00',
        'ada@example.test' => '2026-11-04T08:00:00+00:00',
    ]);

    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customer')
            ->where('customer.last_visit', '2026-11-10T07:00:00+00:00')->where('customer.completed', 1)->where('customer.upcoming', 1));

    $this->get(tenantUrl('luna', '/admin/customers'))
        ->assertInertia(fn (Assert $page) => $page->where('customers.rows.0.email', 'casey@example.com')->where('customers.rows.0.last_visit', '2026-11-10T07:00:00+00:00')
            ->where('customers.rows.1.email', 'ada@example.test')->where('customers.rows.1.last_visit', '2026-11-04T08:00:00+00:00'));
});

it('carries the bookings section on a customer\'s page, whose booking dialog reads it', function () {
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-12 12:30']);

    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customer')->where('strings.bookings', __('admin/bookings', [], 'en')));
});

it('carries the customers section on the list and on a customer\'s page, both of whose words it holds', function () {
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-12 12:30']);
    $section = __('admin/'.CustomerController::SECTION, [], 'en');

    expect($section)->toBeArray()->toHaveKeys(['page', 'table', 'customer', 'tiles', 'export', 'erasure', 'wizard', 'refusals', 'flashes']);

    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customers')->where('strings.customers', $section));

    $this->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customer')->where('strings.customers', $section)->where('strings.bookings', __('admin/bookings', [], 'en')));
});

it('hands the wizard\'s words beside the catalog on every page of a user who may book by hand, and null to every other', function () {
    $wizard = __('admin/customers.wizard', [], 'en');
    addTenantUser('staff@luna.example', 'staff');

    expect($wizard)->toBeArray()->toHaveKey('title');

    foreach (['/admin', '/admin/customers', '/admin/calendar', '/admin/bookings', '/admin/services'] as $path) {
        $this->actingAs(customersOwner())->get(tenantUrl('luna', $path))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('wizard', $wizard)->whereNot('catalog', null));
    }

    $this->actingAs(User::query()->where('email', 'staff@luna.example')->sole())->get(tenantUrl('luna', '/admin/calendar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('wizard', null)->where('catalog', null));
});

it('groups the bookings by the address as citext compares it: the names and phones seen most recent first, the first visit and the last passed in confirmed or completed, the count in any status, the latest start first', function () {
    customersNow('2026-12-02 00:00');
    customersBooking('Casey@Example.com', 'Casey C.', ['2026-11-03 10:00'], '+30 210 1111111', 'cancelled');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-10 09:00', '2026-11-10 09:45'], '+30 210 1111111');
    customersBooking('CASEY@example.com', 'Casey Customer', ['2026-11-15 10:00'], null, 'no_show');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-20 10:00'], '+30 210 2222222', 'expired');
    customersBooking('ada@example.test', 'Ada North', ['2026-12-01 10:00'], null);
    customersBooking('bo@example.test', 'Bo South', ['2026-11-04 10:00']);

    expect(customersRead())->toBe(['total' => 3, 'customers' => [
        ['email' => 'ada@example.test', 'names' => ['Ada North'], 'phones' => [], 'first' => '2026-12-01T08:00:00+00:00', 'last' => '2026-12-01T08:00:00+00:00', 'bookings' => 1, 'upcoming' => 0, 'completed' => 0, 'no_shows' => 0, 'cancelled' => 0],
        ['email' => 'casey@example.com', 'names' => ['Casey Customer', 'Casey C.'], 'phones' => ['+30 210 2222222', '+30 210 1111111'], 'first' => '2026-11-03T08:00:00+00:00', 'last' => '2026-11-10T07:00:00+00:00', 'bookings' => 4, 'upcoming' => 0, 'completed' => 0, 'no_shows' => 1, 'cancelled' => 1],
        ['email' => 'bo@example.test', 'names' => ['Bo South'], 'phones' => ['+30 210 7654321'], 'first' => '2026-11-04T08:00:00+00:00', 'last' => '2026-11-04T08:00:00+00:00', 'bookings' => 1, 'upcoming' => 0, 'completed' => 0, 'no_shows' => 0, 'cancelled' => 0],
    ]]);
});

it('reads a booking\'s visit off its first item and spells the address of the most recent booking, a tie on the start broken by the later row', function () {
    customersBooking('dee@example.test', 'Dee Early', ['2026-11-10 09:00', '2026-11-10 09:45']);
    customersBooking('DEE@example.test', 'Dee Later', ['2026-11-10 09:00']);
    customersBooking('dee@example.test', 'Dee Earlier', ['2026-11-05 11:00']);

    expect(customersRead()['customers'])->toBe([
        ['email' => 'DEE@example.test', 'names' => ['Dee Later', 'Dee Early', 'Dee Earlier'], 'phones' => ['+30 210 7654321'], 'first' => '2026-11-05T09:00:00+00:00', 'last' => null, 'bookings' => 3, 'upcoming' => 3, 'completed' => 0, 'no_shows' => 0, 'cancelled' => 0],
    ]);
});

it('searches the name and the email as the Bookings search runs it, any name seen finding the whole address, its wildcards literal', function () {
    customersBooking('Casey@Example.com', 'Casey C.', ['2026-11-03 10:00']);
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-20 10:00']);
    customersBooking('ada@example.test', 'Ada 100% North', ['2026-12-01 10:00']);
    $both = BookingPayload::searchable(true);

    expect(customersFound('CASEY@', $both))->toBe(['casey@example.com'])
        ->and(customersFound('north', $both))->toBe(['ada@example.test'])
        ->and(customersFound('100%', $both))->toBe(['ada@example.test'])
        ->and(customersFound('a%n', $both))->toBe([])
        ->and(customersFound('c_sey', $both))->toBe([])
        ->and(customersRead((new CustomerFilter(search: 'casey c.'))->searchingIn($both)))->toBe(['total' => 1, 'customers' => [
            ['email' => 'casey@example.com', 'names' => ['Casey Customer', 'Casey C.'], 'phones' => ['+30 210 7654321'], 'first' => '2026-11-03T08:00:00+00:00', 'last' => null, 'bookings' => 2, 'upcoming' => 2, 'completed' => 0, 'no_shows' => 0, 'cancelled' => 0],
        ]]);
});

it('searches the email only where the filter names its column, the name alone for a viewer the whitelist withholds it from and by default', function () {
    customersBooking('hidden@example.test', 'Ada North', ['2026-11-03 10:00']);

    expect(customersFound('hidden@', BookingPayload::searchable(false)))->toBe([])
        ->and(customersFound('north', BookingPayload::searchable(false)))->toBe(['hidden@example.test'])
        ->and(customersFound('hidden@', BookingPayload::searchable(true)))->toBe(['hidden@example.test'])
        ->and(customersRead(new CustomerFilter(search: 'hidden@')))->toBe(['total' => 0, 'customers' => []]);
});

it('pages at twenty-five with the count of every customer', function () {
    for ($n = 0; $n < 61; $n++) {
        customersBooking(sprintf('c%02d@example.test', $n), sprintf('Customer %02d', $n), [sprintf('2026-11-%02d %02d:00', 1 + intdiv($n, 3), 9 + $n % 3)]);
    }

    $first = customersRead();
    $third = customersRead(new CustomerFilter(page: 3));

    expect($first['total'])->toBe(61)
        ->and($first['customers'])->toHaveCount(25)
        ->and($first['customers'][0]['names'] ?? null)->toBe(['Customer 60'])
        ->and($third['total'])->toBe(61)
        ->and(array_column($third['customers'], 'email'))->toBe(array_map(fn (int $n): string => sprintf('c%02d@example.test', $n), range(10, 0)));
});

it('reads one customer by an address as citext compares it, and none for an address no booking was made under', function () {
    customersBooking('Casey@Example.com', 'Casey C.', ['2026-11-03 10:00']);
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-20 10:00']);
    customersBooking('a/b@example.test', 'Slash Customer', ['2026-11-04 10:00']);
    $one = fn (string $email): ?array => ($customer = app(CustomerRecords::class)->one(LunaFixture::tenant(), $email)) === null ? null : customersPlain($customer);

    expect($one('CASEY@EXAMPLE.COM'))->toBe(['email' => 'casey@example.com', 'names' => ['Casey Customer', 'Casey C.'], 'phones' => ['+30 210 7654321'], 'first' => '2026-11-03T08:00:00+00:00', 'last' => null, 'bookings' => 2, 'upcoming' => 2, 'completed' => 0, 'no_shows' => 0, 'cancelled' => 0])
        ->and($one('a/b@example.test')['names'] ?? null)->toBe(['Slash Customer'])
        ->and($one('nobody@example.test'))->toBeNull()
        ->and($one('casey'))->toBeNull();
});

it('leaves an erased booking out of its customer, out of the count and out of every search, so a customer every booking of which was erased leaves the list', function () {
    $erased = customersBooking('casey@example.com', 'Casey C.', ['2026-11-03 10:00'], '+30 210 1111111');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-20 10:00'], '+30 210 2222222');
    $gone = customersBooking('dee@example.test', 'Dee Gone', ['2026-11-05 10:00']);
    customersBooking('ada@example.test', 'Ada North', ['2026-12-01 10:00'], null);
    $ids = DB::table('tenant_luna.bookings')->whereIn('uuid', [$erased, $gone])->orderBy('id')->pluck('id')->all();
    assert(array_is_list($ids) && array_filter($ids, is_int(...)) === $ids);

    expect(app(Erasure::class)->bookings(LunaFixture::tenant(), $ids))->toBe(2)
        ->and(customersRead())->toBe(['total' => 2, 'customers' => [
            ['email' => 'ada@example.test', 'names' => ['Ada North'], 'phones' => [], 'first' => '2026-12-01T08:00:00+00:00', 'last' => null, 'bookings' => 1, 'upcoming' => 1, 'completed' => 0, 'no_shows' => 0, 'cancelled' => 0],
            ['email' => 'casey@example.com', 'names' => ['Casey Customer'], 'phones' => ['+30 210 2222222'], 'first' => '2026-11-20T08:00:00+00:00', 'last' => null, 'bookings' => 1, 'upcoming' => 1, 'completed' => 0, 'no_shows' => 0, 'cancelled' => 0],
        ]])
        ->and(customersFound('Casey C.', ['customer_name']))->toBe([])
        ->and(customersFound('Dee', ['customer_name', 'customer_email']))->toBe([])
        ->and(app(CustomerRecords::class)->one(LunaFixture::tenant(), 'dee@example.test'))->toBeNull()
        ->and(app(CustomerRecords::class)->one(LunaFixture::tenant(), 'casey@example.com')?->bookings)->toBe(1);

    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customers')
            ->where('customers.total', 2)
            ->where('customers.rows', fn (Collection $rows): bool => $rows->pluck('email')->all() === ['ada@example.test', 'casey@example.com']));

    $this->get(tenantUrl('luna', '/admin/customers/dee%40example.test'))->assertNotFound();
});

function customersOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

it('renders the list through the payload class with the query read back from the URL alone, the page of a malformed number the first', function () {
    customersBooking('Casey@Example.com', 'Casey C.', ['2026-11-03 10:00'], '+30 210 1111111', 'cancelled');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-20 10:00'], '+30 210 2222222');
    customersBooking('ada@example.test', 'Ada North', ['2026-12-01 10:00'], null);

    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customers')
            ->where('filters', ['search' => '', 'page' => 1])
            ->where('searchable', ['customer_name', 'customer_email'])
            ->where('customers.total', 2)->where('customers.page', 1)->where('customers.page_size', 25)
            ->where('customers.rows', [
                [
                    'name' => 'Ada North', 'names' => ['Ada North'], 'email' => 'ada@example.test', 'phones' => [],
                    'first_visit' => '2026-12-01T08:00:00+00:00', 'last_visit' => null,
                    'bookings' => 1, 'upcoming' => 1, 'completed' => 0, 'no_shows' => 0, 'cancelled' => 0, 'url' => '/admin/customers/ada%40example.test', 'tags' => [], 'blocked' => false,
                ],
                [
                    'name' => 'Casey Customer', 'names' => ['Casey Customer', 'Casey C.'], 'email' => 'casey@example.com',
                    'phones' => ['+30 210 2222222', '+30 210 1111111'],
                    'first_visit' => '2026-11-03T08:00:00+00:00', 'last_visit' => null,
                    'bookings' => 2, 'upcoming' => 1, 'completed' => 0, 'no_shows' => 0, 'cancelled' => 1, 'url' => '/admin/customers/casey%40example.com', 'tags' => [], 'blocked' => false,
                ],
            ])
            ->where('nav.6', ['label' => navLabel('customers'), 'url' => '/admin/customers', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => true])
            ->where('nav', fn (Collection $nav): bool => $nav->where('current', true)->count() === 1));

    $this->get(tenantUrl('luna', '/admin/customers?search=casey%40&page=1'))
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['search' => 'casey@', 'page' => 1])->where('customers.total', 1)->where('customers.rows.0.email', 'casey@example.com'));

    foreach (['nope', '0', '-2', '1234567890'] as $page) {
        $this->get(tenantUrl('luna', '/admin/customers?page='.$page))
            ->assertInertia(fn (Assert $props) => $props->where('filters.page', 1)->where('customers.page', 1)->has('customers.rows', 2));
    }

    $this->get(tenantUrl('luna', '/admin/customers?page=2'))
        ->assertInertia(fn (Assert $page) => $page->where('filters.page', 2)->where('customers.page', 2)->where('customers.total', 2)->has('customers.rows', 0));
});

it('reads a search that is not UTF-8 or holds a NUL as no search on the list and a booking so on a customer\'s page, as the console reads one, the screen answering whole', function (string $search) {
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-20 10:00']);
    customersBooking('ada@example.test', 'Ada North', ['2026-12-01 10:00']);

    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers?'.http_build_query(['search' => $search, 'page' => '99999999999999999999'])))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['search' => '', 'page' => 1])->where('customers.total', 2)->has('customers.rows', 2));

    $this->get(tenantUrl('luna', '/admin/customers/casey%40example.com?'.http_build_query(['booking' => $search])))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customer')->where('bookings.total', 1)->where('detail', null));

    // An address in the path that is not UTF-8 never reaches the route: the framework's path
    // encoding check refuses the URL first, a 400 and never a 500.
    $this->get(tenantUrl('luna', '/admin/customers/cas%C3ey%40example.com'))->assertStatus(400);
})->with([
    'a search that is not UTF-8' => ["Cas\xC3"],
    'a search holding a NUL' => ["Ca\0sey"],
]);

it('withholds the email and the phones from a viewer without manageBookings exactly where the whitelist class names their columns contact', function () {
    customersBooking('hidden@example.test', 'Ada North', ['2026-11-03 10:00']);
    $customer = app(CustomerRecords::class)->one(LunaFixture::tenant(), 'hidden@example.test');
    assert($customer instanceof CustomerRecord);
    $viewer = array_keys(CustomerPayload::admin($customer, false, CustomerProfile::none()));
    $manager = array_keys(CustomerPayload::admin($customer, true, CustomerProfile::none()));
    sort($viewer);
    sort($manager);

    expect($viewer)->toBe(['blocked', 'bookings', 'cancelled', 'completed', 'first_visit', 'last_visit', 'name', 'names', 'no_shows', 'tags', 'upcoming', 'url'])
        ->and($manager)->toBe(['blocked', 'bookings', 'cancelled', 'completed', 'email', 'first_visit', 'last_visit', 'name', 'names', 'no_shows', 'phones', 'tags', 'upcoming', 'url'])
        ->and(array_keys(array_intersect(CustomerPayload::COLUMNS, BookingPayload::CONTACT)))->toBe(['email', 'phones'])
        ->and(array_values(array_unique(CustomerPayload::COLUMNS)))->toBe(['customer_name', 'customer_email', 'customer_phone']);
});

it('puts Customers on the rail of an owner and a manager after the Staff entry and before Reports, under manageBookings, refuses the list to a staff user, and never gates it by the subscription', function () {
    addTenantUser('manager@luna.example', 'manager');
    addTenantUser('staff@luna.example', 'staff');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-03 10:00']);
    $rail = fn (string $email, array $labels) => $this->actingAs(User::query()->where('email', $email)->sole())->get(tenantUrl('luna', '/admin/customers'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('nav', fn (Collection $nav): bool => $nav->pluck('label')->all() === $labels));

    $rail('owner@luna.example', [navLabel('home'), navLabel('calendar'), navLabel('bookings'), navLabel('shifts'), navLabel('services'), navLabel('staff'), navLabel('customers'), navLabel('reports'), navLabel('team'), navLabel('settings'), navLabel('billing')]);
    $rail('manager@luna.example', [navLabel('home'), navLabel('calendar'), navLabel('bookings'), navLabel('shifts'), navLabel('services'), navLabel('staff'), navLabel('customers'), navLabel('reports'), navLabel('settings')]);

    // No role holds viewBookings without manageBookings, so the entry's permission is read off
    // the rail of such a principal directly: Reports stays, under viewBookings, and Customers goes.
    $labels = fn (Permission ...$permissions): array => array_column(AdminNav::for(array_values($permissions), Entitlements::all(), null, 0, app('translator')), 'label');
    expect($labels(Permission::ViewBookings, Permission::ViewOwnCalendar))->toBe([navLabel('home'), navLabel('calendar'), navLabel('bookings'), navLabel('services'), navLabel('staff'), navLabel('reports')])
        ->and($labels(Permission::ViewBookings, Permission::ViewOwnCalendar, Permission::ManageBookings))->toBe([navLabel('home'), navLabel('calendar'), navLabel('bookings'), navLabel('services'), navLabel('staff'), navLabel('customers'), navLabel('reports')]);

    $this->actingAs(User::query()->where('email', 'staff@luna.example')->sole())->get(tenantUrl('luna', '/admin/customers'))->assertForbidden();

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'canceled']);
    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('customers.total', 1));
});

it('renders an address\'s page as citext matches it, headed by the most recent name, its bookings in the Bookings order and paged as that list is, the Customers entry lit', function () {
    $first = customersBooking('Casey@Example.com', 'Casey C.', ['2026-11-03 10:00'], status: 'cancelled');
    $chain = customersBooking('casey@example.com', 'Casey Customer', ['2026-11-10 09:00', '2026-11-10 09:45']);
    $latest = customersBooking('casey@example.com', 'Casey Customer', ['2026-11-20 10:00']);
    customersBooking('ada@example.test', 'Ada North', ['2026-12-01 10:00']);

    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers/CASEY%40EXAMPLE.COM'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customer')
            ->where('customer.name', 'Casey Customer')
            ->where('customer.names', ['Casey Customer', 'Casey C.'])
            ->where('customer.email', 'casey@example.com')
            ->where('customer.bookings', 3)
            ->where('customer.url', '/admin/customers/casey%40example.com')
            ->where('bookings.total', 3)->where('bookings.page', 1)->where('bookings.page_size', 25)
            ->where('bookings.rows', fn (Collection $rows): bool => $rows->pluck('uuid')->all() === [$latest, $chain, $first])
            ->where('bookings.rows.0.customer_email', 'casey@example.com')
            ->where('bookings.rows.2.status', 'cancelled')
            ->where('bookings.rows.1.items.1.start', '2026-11-10T07:45:00+00:00')
            ->where('members', DB::table('tenant_luna.staff')->count())
            ->where('detail', null)
            ->where('nav.6', ['label' => navLabel('customers'), 'url' => '/admin/customers', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => true])
            ->where('nav', fn (Collection $nav): bool => $nav->where('current', true)->count() === 1));

    $this->get(tenantUrl('luna', '/admin/customers/casey%40example.com?page=2'))
        ->assertInertia(fn (Assert $page) => $page->where('bookings.total', 3)->has('bookings.rows', 0)->where('bookings.page', 2));

    $this->get(tenantUrl('luna', '/admin/customers/casey%40example.com?page=nope'))
        ->assertInertia(fn (Assert $page) => $page->where('bookings.page', 1)->has('bookings.rows', 3));
});

it('admits a slash before the at sign, the address rule of the hold admitting one, its page linked with the slash encoded', function () {
    customersBooking('a/b@example.test', 'Slash Customer', ['2026-11-03 10:00']);

    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers'))
        ->assertInertia(fn (Assert $page) => $page->where('customers.rows.0.url', '/admin/customers/a%2Fb%40example.test'));

    $this->get(tenantUrl('luna', '/admin/customers/a%2Fb%40example.test'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customer')->where('customer.email', 'a/b@example.test')->where('bookings.total', 1));
});

it('reads the dialog over an address\'s page by a partial reload of its detail alone, the actions this viewer may take among it, and the missing state for a booking nobody has', function () {
    $chain = customersBooking('casey@example.com', 'Casey Customer', ['2026-11-10 09:00', '2026-11-10 09:45']);
    $version = (string) app(HandleInertiaRequests::class)->version(request());
    $partial = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Customer', 'X-Inertia-Partial-Data' => 'detail'];

    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers/casey%40example.com?booking='.$chain), $partial)
        ->assertOk()
        ->assertJsonPath('component', 'Customer')
        ->assertJsonPath('props.detail.state', 'found')
        ->assertJsonPath('props.detail.booking.uuid', $chain)
        ->assertJsonPath('props.detail.booking.customer_phone', '+30 210 7654321')
        ->assertJsonPath('props.detail.booking.items.1.start', '2026-11-10T07:45:00+00:00')
        ->assertJsonPath('props.detail.actions', ['cancel'])
        ->assertJsonPath('props.detail.note', null)
        ->assertJsonMissingPath('props.bookings')
        ->assertJsonMissingPath('props.members')
        ->assertJsonMissingPath('props.nav');

    $this->get(tenantUrl('luna', '/admin/customers/casey%40example.com?booking='.Str::uuid()), $partial)
        ->assertJsonPath('props.detail', fn (mixed $detail): bool => is_array($detail) && $detail['state'] === 'missing');

    $this->get(tenantUrl('luna', '/admin/customers/casey%40example.com?booking=not-a-uuid'), $partial)
        ->assertJsonPath('props.detail.state', 'missing');
});

it('answers an unknown address, a segment holding no address and an address cut by a slash after its at sign with the shell\'s 404, no entry current', function (string $path) {
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-03 10:00']);

    $this->actingAs(customersOwner())->get(tenantUrl('luna', $path))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('NotFound')->has('nav', 11)
            ->where('nav', fn (Collection $nav): bool => $nav->where('current', true)->count() === 0));
})->with([
    'an address nobody booked under' => ['/admin/customers/nobody%40example.test'],
    'a segment with no at sign' => ['/admin/customers/casey'],
    'nothing before the at sign' => ['/admin/customers/%40example.com'],
    'a slash after the at sign' => ['/admin/customers/casey%40example.com%2Fx'],
    'a path under the address' => ['/admin/customers/casey%40example.com/bookings'],
    'an address holding a NUL' => ['/admin/customers/ca%00sey%40example.com'],
]);

it('refuses an address\'s page to a staff user and never gates it by the subscription', function () {
    addTenantUser('staff@luna.example', 'staff');
    customersBooking('casey@example.com', 'Casey Customer', ['2026-11-03 10:00']);

    $this->actingAs(User::query()->where('email', 'staff@luna.example')->sole())->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))->assertForbidden();

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'canceled']);
    $this->actingAs(customersOwner())->get(tenantUrl('luna', '/admin/customers/casey%40example.com'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Customer')->where('bookings.total', 1));
});
