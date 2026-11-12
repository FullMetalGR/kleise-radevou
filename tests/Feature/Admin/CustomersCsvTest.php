<?php

use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Customers list's Download CSV: what the list shows under its search, every matching
 * address and not the page on screen, one row each with the list's columns, a title row and a
 * header row above them, a visit in the tenant's date and time formats in the tenant's zone,
 * UTF-8 behind a byte order mark, a cell a spreadsheet would read as a formula behind an
 * apostrophe, under the list's own guard, manageBookings. Every word is read through the
 * translator.
 *
 * The clock is pinned at FixtureDay's, 2026-11-02 08:00 in Athens.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FixtureDay::pinClock();
});

/**
 * A booking of luna under an address, written straight to the rows: one Haircut item on Elena at
 * the Athens start given.
 */
function csvCustomer(string $email, string $name, string $start, ?string $phone = '+30 210 7654321', string $status = 'confirmed'): void
{
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => (string) Str::uuid(), 'status' => $status, 'customer_name' => $name, 'customer_email' => $email,
        'customer_phone' => $phone, 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    $from = LunaFixture::at($start);
    $to = $from->add(new DateInterval('PT45M'));
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki'),
        'start_utc' => LunaFixture::instant($from), 'end_utc' => LunaFixture::instant($to),
        'block_start_utc' => LunaFixture::instant($from), 'block_end_utc' => LunaFixture::instant($to),
        'price_minor' => 3500, 'blocks' => false,
    ]);
}

function csvListOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * @return TestResponse<Response>
 */
function customersCsv(TestCase $test, string $query = '', ?User $user = null): TestResponse
{
    return $test->actingAs($user ?? csvListOwner())->get(tenantUrl('luna', '/admin/customers/csv'.$query));
}

/**
 * The file's rows read back as CSV, the byte order mark taken off first.
 *
 * @return list<list<string>>
 */
function customersCsvRows(string $body): array
{
    expect(str_starts_with($body, "\u{FEFF}"))->toBeTrue();
    $lines = preg_split('/\r?\n/', trim(substr($body, 3))) ?: [];

    return array_map(fn (string $line): array => array_map(fn (?string $cell): string => (string) $cell, str_getcsv($line, ',', '"', '')), $lines);
}

/**
 * A line of the customers section, read through the translator in the language named.
 *
 * @param  array<string, string>  $replace
 */
function customersCsvWord(string $key, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    return adminLine('admin/customers.'.$key, $replace, $language);
}

/**
 * The header row as the list writes it, the ways to reach a customer among the columns.
 *
 * @return list<string>
 */
function customersCsvHead(SiteLanguage $language = SiteLanguage::English): array
{
    return array_map(fn (string $key): string => customersCsvWord($key, [], $language), ['table.name', 'csv.also_booked_as', 'profile.tags', 'table.blocked', 'table.email', 'table.phone', 'table.first_visit', 'table.last_visit', 'table.bookings']);
}

it('downloads the list as text/csv behind a byte order mark, attached under a dated name, a title row and the list\'s columns over a row per address', function () {
    csvCustomer('casey@example.com', 'Casey C.', '2026-10-01 11:00', status: 'completed');
    csvCustomer('Casey@Example.com', 'Casey Customer', '2026-10-20 10:00');
    csvCustomer('casey@example.com', 'Casey Customer', '2026-11-20 10:00');
    DB::table('tenant_luna.customer_profiles')->insert([
        'email' => 'casey@example.com', 'tags' => '{VIP,colour}', 'blocked_at' => '2026-10-30 09:00:00+00', 'blocked_reason' => 'No-shows',
        'created_at' => '2026-10-30 09:00:00+00', 'updated_at' => '2026-10-30 09:00:00+00',
    ]);

    $response = customersCsv($this, '?search=casey')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=customers-2026-11-02.csv')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(customersCsvRows($response->streamedContent()))->toBe([
            [customersCsvWord('csv.searched', ['search' => 'casey'])],
            customersCsvHead(),
            ['Casey Customer', 'Casey C.', 'VIP, colour', customersCsvWord('csv.yes'), 'casey@example.com', "'+30 210 7654321", '01/10/2026 11:00', '20/10/2026 10:00', '3'],
        ]);
});

it('holds every address the search matches, not the page on screen, and the screen\'s count without a search', function () {
    for ($n = 1; $n <= 27; $n++) {
        csvCustomer(sprintf('zed%02d@example.com', $n), sprintf('Zed %02d', $n), sprintf('2026-10-%02d 10:00', $n), phone: null);
    }

    csvCustomer('other@example.com', 'Somebody Else', '2026-10-05 12:00');

    $rows = customersCsvRows(customersCsv($this, '?search=zed&page=2')->assertOk()->streamedContent());

    expect($rows[0])->toBe([customersCsvWord('csv.searched', ['search' => 'zed'])])
        ->and(array_slice($rows, 2))->toHaveCount(27)
        ->and(array_column(array_slice($rows, 2), 0))->each->toStartWith('Zed ')
        ->and($rows[2])->toBe(['Zed 27', '', '', customersCsvWord('csv.no'), 'zed27@example.com', '', '27/10/2026 10:00', '27/10/2026 10:00', '1']);

    $total = null;
    $this->actingAs(csvListOwner())->get(tenantUrl('luna', '/admin/customers'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$total) {
            $page->where('customers.total', function (int $count) use (&$total): bool {
                $total = $count;

                return true;
            });
        });

    $all = customersCsvRows(customersCsv($this)->assertOk()->streamedContent());

    assert(is_int($total));

    expect($all[0])->toBe([customersCsvWord('page.title')])
        ->and(array_slice($all, 2))->toHaveCount($total);
});

it('writes a name a spreadsheet would read as a formula behind an apostrophe, and a visit not yet made in the list\'s words', function () {
    csvCustomer('mallory@example.com', '=HYPERLINK("http://example.com")', '2026-11-20 10:00', phone: '-1');
    csvCustomer('mallory@example.com', '@SUM(A1)', '2026-11-21 10:00', phone: '12345');

    $rows = customersCsvRows(customersCsv($this, '?search=mallory')->assertOk()->streamedContent());

    expect($rows[2])->toBe(["'@SUM(A1)", "'=HYPERLINK(\"http://example.com\")", '', customersCsvWord('csv.no'), 'mallory@example.com', '12345, -1', '20/11/2026 10:00', customersCsvWord('table.no_visit'), '2']);
});

it('writes a visit in the tenant\'s date and time formats, in the tenant\'s zone, as the list shows it', function () {
    csvCustomer('casey@example.com', 'Casey Customer', '2026-10-01 14:05');
    DB::table('tenant_luna.settings')->where('id', 1)->update(['date_format' => 'Y-m-d', 'time_format' => 'g:i a']);

    $rows = customersCsvRows(customersCsv($this, '?search=casey')->assertOk()->streamedContent());

    expect(array_slice($rows[2], 6, 2))->toBe(['2026-10-01 2:05 pm', '2026-10-01 2:05 pm']);
});

it('writes the file\'s words in the user\'s language: a user at el reads customers.php\'s Greek and a colleague at en its English, the visits in the tenant\'s formats', function () {
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $colleague = User::query()->findOrFail(addTenantUser('manager@luna.example', 'manager'));
    $colleague->forceFill(['locale' => SiteLanguage::English->value])->save();
    csvCustomer('casey@example.com', 'Casey Customer', '2026-10-01 11:00');

    $rows = customersCsvRows(customersCsv($this, '?search=casey')->assertOk()->streamedContent());
    $unsearched = customersCsvRows(customersCsv($this)->assertOk()->streamedContent());
    $inEnglish = customersCsvRows(customersCsv($this, '?search=casey', $colleague)->assertOk()->streamedContent());

    expect($rows[0])->toBe([customersCsvWord('csv.searched', ['search' => 'casey'], SiteLanguage::Greek)])
        ->and($rows[1])->toBe(customersCsvHead(SiteLanguage::Greek))
        ->and($rows[2][3])->toBe(customersCsvWord('csv.no', [], SiteLanguage::Greek))
        ->and(array_slice($rows[2], 6, 2))->toBe(['01/10/2026 11:00', '01/10/2026 11:00'])
        ->and($unsearched[0])->toBe([customersCsvWord('page.title', [], SiteLanguage::Greek)])
        ->and($inEnglish[0])->toBe([customersCsvWord('csv.searched', ['search' => 'casey'])])
        ->and($inEnglish[1])->toBe(customersCsvHead())
        ->and($inEnglish[2][3])->toBe(customersCsvWord('csv.no'))
        ->and(customersCsvHead(SiteLanguage::Greek))->not->toBe(customersCsvHead());
});

it('answers the list\'s guard: manageBookings, a staff user refused and a guest sent to sign in', function () {
    addTenantUser('staff@luna.example', 'staff');

    $this->get(tenantUrl('luna', '/admin/customers/csv'))->assertRedirect(tenantUrl('luna', '/admin/login'));
    customersCsv($this, user: User::query()->where('email', 'staff@luna.example')->sole())->assertForbidden();
});
