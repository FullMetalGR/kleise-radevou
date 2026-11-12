<?php

use App\Application\Clock;
use App\Http\CsvDownload;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * Reports' Download CSV: what the screen shows under its range and its filters, a section a card,
 * each its title, its header row and its rows, a blank line between them; amounts in decimals in
 * the currency their header names, every count, amount and percentage by ICU in the request's
 * language, dates in the tenant's date format, UTF-8 behind a byte order mark, under the Reports
 * page's own guard. Every word is read through the translator.
 *
 * The clock stands on Wednesday 2026-10-21 at 08:00 in Athens: this week is the 19th to the 25th.
 * Elena works Monday to Friday 09:00 to 17:00; Nikos has no hours.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-10-21 08:00', 'Europe/Athens'));
    WeeklyHours::clear('tenant_luna');
    WeeklyHours::week('tenant_luna', LunaFixture::id('staff', 'Elena Papadaki'), [[1, '09:00', '17:00'], [2, '09:00', '17:00'], [3, '09:00', '17:00'], [4, '09:00', '17:00'], [5, '09:00', '17:00']]);
});

/**
 * A booking of luna written straight to the rows, one item of the service and member given.
 */
function csvBooking(string $status, string $start, string $service, string $member, int $minutes, int $price): void
{
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed', 'uuid' => (string) Str::uuid(), 'status' => $status,
        'customer_name' => 'Casey Customer', 'customer_email' => 'casey@example.com',
        'total_minor' => $price, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    $from = LunaFixture::at($start);
    $to = $from->add(new DateInterval(sprintf('PT%dM', $minutes)));
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', $service), 'staff_id' => LunaFixture::id('staff', $member),
        'start_utc' => LunaFixture::instant($from), 'end_utc' => LunaFixture::instant($to),
        'block_start_utc' => LunaFixture::instant($from), 'block_end_utc' => LunaFixture::instant($to),
        'price_minor' => $price, 'blocks' => false,
    ]);
}

function csvOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * @return TestResponse<Response>
 */
function csvGet(TestCase $test, string $query = '', ?User $user = null): TestResponse
{
    return $test->actingAs($user ?? csvOwner())->get(tenantUrl('luna', '/admin/reports/csv'.$query));
}

/**
 * The file's sections, each a list of its rows read as CSV, the blank lines between them dropped.
 *
 * @return array<string, list<list<string>>>
 */
function csvSections(string $body): array
{
    $sections = [];

    foreach (preg_split('/\r?\n\r?\n/', trim($body)) ?: [] as $block) {
        $rows = array_map(fn (string $line): array => array_map(fn (?string $cell): string => (string) $cell, str_getcsv($line, ',', '"', '')), preg_split('/\r?\n/', $block) ?: []);
        $sections[$rows[0][0] ?? ''] = array_slice($rows, 1);
    }

    return $sections;
}

/**
 * @param  TestResponse<Response>  $response
 */
function csvBody(TestResponse $response): string
{
    $body = $response->streamedContent();

    return str_starts_with($body, "\u{FEFF}") ? substr($body, 3) : $body;
}

/**
 * A line of the reports section in the language given, English unless told otherwise, read
 * through the translator as the file reads it.
 *
 * @param  array<string, string>  $replace
 */
function reportsCsvWord(string $key, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    return adminLine('admin/reports.'.$key, $replace, $language);
}

/**
 * A column's name with the tenant's currency beside it, as the file heads a column of amounts, in
 * the language given, English unless told otherwise.
 */
function reportsCsvInEuros(string $key, SiteLanguage $language = SiteLanguage::English): string
{
    return reportsCsvWord('csv.in_currency', ['column' => reportsCsvWord($key, [], $language), 'currency' => 'EUR'], $language);
}

/**
 * The sections' titles in the screen's order, each read through the translator in the language
 * given, English unless told otherwise.
 *
 * @return list<string>
 */
function reportsCsvTitles(SiteLanguage $language = SiteLanguage::English): array
{
    return array_map(fn (string $key): string => reportsCsvWord($key, [], $language), ['csv.filters', 'csv.figures', 'over_time.title', 'tables.statuses', 'tables.revenue', 'tables.no_show_rate', 'tables.cancelled_by', 'occupancy.title', 'tables.by_service', 'tables.by_staff', 'tables.by_source', 'tables.by_channel']);
}

it('streams the screen under its range and filters as text/csv with a BOM, named by the range\'s dates', function () {
    csvBooking('confirmed', '2026-10-20 10:00', 'Haircut', 'Elena Papadaki', 60, 3500);
    csvBooking('completed', '2026-10-21 10:00', 'Beard trim', 'Nikos Georgiou', 30, 2000);

    $response = csvGet($this)->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=reports-2026-10-19-to-2026-10-25.csv')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(str_starts_with($response->streamedContent(), "\u{FEFF}"))->toBeTrue();

    $sections = csvSections(csvBody($response));
    [$filters, $figures, $overTime, $statuses, $revenue, $noShow, $cancelledBy, $occupancy, $byService, $byStaff, $bySource, $byChannel] = reportsCsvTitles();
    $fromZero = reportsCsvWord('compare.week.from_zero');
    $hours = fn (string $hours, string $minutes): string => reportsCsvWord('tiles.hours_minutes', ['hours' => $hours, 'minutes' => $minutes]);

    expect(array_keys($sections))->toBe(reportsCsvTitles())
        ->and($sections[$filters])->toBe([
            [reportsCsvWord('filters.range'), reportsCsvWord('filters.from'), reportsCsvWord('filters.to'), reportsCsvWord('filters.staff'), reportsCsvWord('filters.service')],
            [reportsCsvWord('filters.words.week'), '19/10/2026', '25/10/2026', reportsCsvWord('filters.all_staff'), reportsCsvWord('filters.all_services')],
        ])
        ->and($sections[$figures][0])->toBe([reportsCsvWord('csv.figure'), reportsCsvWord('csv.value'), reportsCsvWord('csv.before'), reportsCsvWord('csv.change'), reportsCsvWord('csv.detail')])
        ->and($sections[$figures][1])->toBe([reportsCsvWord('tiles.bookings'), '2', '0', $fromZero, ''])
        ->and($sections[$figures][2])->toBe([reportsCsvInEuros('tiles.revenue'), '55.00', '0.00', $fromZero, ''])
        // Elena 60 of 2400; Nikos's half hour is outside any hours, so 30 of 30.
        ->and($sections[$figures][3])->toBe([reportsCsvWord('tiles.occupancy'), '3.7%', '0.0%', $fromZero, reportsCsvWord('tiles.occupancy_of', ['filled' => $hours('1h', '30m'), 'offered' => $hours('40h', '30m')])])
        ->and($sections[$overTime][0])->toBe([reportsCsvWord('over_time.from'), reportsCsvWord('over_time.to'), reportsCsvWord('over_time.bookings_head'), reportsCsvInEuros('over_time.revenue_head')])
        ->and($sections[$overTime][2])->toBe(['20/10/2026', '20/10/2026', '1', '35.00'])
        ->and($sections[$overTime])->toHaveCount(8)
        ->and($sections[$statuses][0])->toBe([reportsCsvWord('csv.status'), reportsCsvWord('tables.bookings')])
        ->and(array_slice($sections[$statuses], 1, 2))->toBe([[adminLine('admin/shell.kit.statuses.confirmed'), '1'], [adminLine('admin/shell.kit.statuses.completed'), '1']])
        ->and($sections[$revenue])->toBe([[reportsCsvWord('csv.status'), reportsCsvInEuros('tables.revenue_head')], [reportsCsvWord('tables.confirmed'), '35.00'], [reportsCsvWord('tables.completed'), '20.00']])
        ->and($sections[$noShow])->toBe([[reportsCsvWord('tables.no_show_rate'), reportsCsvWord('tables.no_shows'), reportsCsvWord('tables.completed')], ['0%', '0', '1']])
        ->and($sections[$cancelledBy])->toBe([[reportsCsvWord('tables.cancelled_by'), reportsCsvWord('tables.bookings')], [reportsCsvWord('tables.by_customer'), '0'], [reportsCsvWord('tables.by_business'), '0']])
        ->and($sections[$occupancy])->toBe([
            [reportsCsvWord('tables.staff'), reportsCsvWord('occupancy.booked'), reportsCsvWord('occupancy.available'), reportsCsvWord('tiles.occupancy')],
            ['Elena Papadaki', '1h', '40h', '2.5%'],
            ['Nikos Georgiou', $hours('0h', '30m'), $hours('0h', '30m'), '100.0%'],
        ])
        ->and($sections[$byService][0])->toBe([reportsCsvWord('tables.service'), reportsCsvWord('tables.bookings'), reportsCsvInEuros('tables.revenue_head')])
        ->and($sections[$byStaff][0])->toBe([reportsCsvWord('tables.staff'), reportsCsvWord('tables.bookings'), reportsCsvInEuros('tables.revenue_head')])
        ->and($sections[$bySource][0])->toBe([reportsCsvWord('tables.source'), reportsCsvWord('tables.bookings'), reportsCsvInEuros('tables.revenue_head')])
        ->and($sections[$byChannel][0])->toBe([reportsCsvWord('tables.channel'), reportsCsvWord('tables.bookings'), reportsCsvInEuros('tables.revenue_head')]);
});

it('follows the Staff and Service filters and a custom range as the page does, an invalid range as this week', function () {
    csvBooking('confirmed', '2026-10-20 10:00', 'Haircut', 'Elena Papadaki', 60, 3500);
    csvBooking('completed', '2026-10-21 10:00', 'Beard trim', 'Nikos Georgiou', 30, 2000);
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    [$filters, $figures, , , , , , $occupancy] = reportsCsvTitles();
    $half = reportsCsvWord('tiles.hours_minutes', ['hours' => '0h', 'minutes' => '30m']);

    $sections = csvSections(csvBody(csvGet($this, '?staff='.$nikos)->assertOk()));

    expect($sections[$filters][1])->toBe([reportsCsvWord('filters.words.week'), '19/10/2026', '25/10/2026', 'Nikos Georgiou', reportsCsvWord('filters.all_services')])
        ->and($sections[$figures][1][1])->toBe('1')
        ->and($sections[$occupancy])->toBe([[reportsCsvWord('tables.staff'), reportsCsvWord('occupancy.booked'), reportsCsvWord('occupancy.available'), reportsCsvWord('tiles.occupancy')], ['Nikos Georgiou', $half, $half, '100.0%']]);

    $response = csvGet($this, '?range=custom&from=2026-10-01&to=2026-10-20&service='.LunaFixture::id('services', 'Haircut'))->assertOk();
    $sections = csvSections(csvBody($response));

    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=reports-2026-10-01-to-2026-10-20.csv')
        ->and($sections[$filters][1])->toBe([reportsCsvWord('filters.words.custom'), '01/10/2026', '20/10/2026', reportsCsvWord('filters.all_staff'), 'Haircut'])
        ->and($sections[$figures][2][1])->toBe('35.00');

    expect(csvSections(csvBody(csvGet($this, '?range=custom&from=2026-10-20&to=2026-10-01')->assertOk()))[$filters][1][0])->toBe(reportsCsvWord('filters.words.week'));
});

it('writes a rise and a fall as one signed figure in the comparison\'s sentence, an amount past a thousand grouped, every count, amount and percentage by one rule', function () {
    csvBooking('confirmed', '2026-10-13 10:00', 'Haircut', 'Elena Papadaki', 60, 100000);
    csvBooking('confirmed', '2026-10-14 10:00', 'Haircut', 'Elena Papadaki', 60, 3500);
    csvBooking('confirmed', '2026-10-20 10:00', 'Haircut', 'Elena Papadaki', 60, 123456);
    [, $figures, $overTime, , $revenue] = reportsCsvTitles();

    $sections = csvSections(csvBody(csvGet($this)->assertOk()));

    // A sentence opening on its sign is kept as text behind the apostrophe, as every such cell is.
    expect($sections[$figures][1])->toBe([reportsCsvWord('tiles.bookings'), '1', '2', CsvDownload::safe(reportsCsvWord('compare.week.by', ['change' => '-50%'])), ''])
        ->and($sections[$figures][2])->toBe([reportsCsvInEuros('tiles.revenue'), '1,234.56', '1,035.00', CsvDownload::safe(reportsCsvWord('compare.week.by', ['change' => '+19%'])), ''])
        ->and($sections[$overTime][2])->toBe(['20/10/2026', '20/10/2026', '1', '1,234.56'])
        ->and($sections[$revenue][1])->toBe([reportsCsvWord('tables.confirmed'), '1,234.56']);
});

it('writes a fall the server rounded to nought as minus nought, behind the formula guard', function () {
    csvBooking('confirmed', '2026-10-13 10:00', 'Haircut', 'Elena Papadaki', 60, 100000);
    csvBooking('confirmed', '2026-10-20 10:00', 'Haircut', 'Elena Papadaki', 60, 99900);
    [, $figures] = reportsCsvTitles();

    $sections = csvSections(csvBody(csvGet($this)->assertOk()));

    // -0.1% rounds to a fall at nought, which still reads as a fall.
    expect($sections[$figures][2])->toBe([reportsCsvInEuros('tiles.revenue'), '999.00', '1,000.00', CsvDownload::safe(reportsCsvWord('compare.week.by', ['change' => '-0%'])), '']);
});

it('writes every date in the tenant\'s date format, as every tenant screen does', function () {
    csvBooking('confirmed', '2026-10-20 10:00', 'Haircut', 'Elena Papadaki', 60, 3500);
    DB::table('tenant_luna.settings')->where('id', 1)->update(['date_format' => 'Y-m-d']);
    [$filters, , $overTime] = reportsCsvTitles();

    $sections = csvSections(csvBody(csvGet($this)->assertOk()));

    expect(array_slice($sections[$filters][1], 1, 2))->toBe(['2026-10-19', '2026-10-25'])
        ->and(array_slice($sections[$overTime][2], 0, 2))->toBe(['2026-10-20', '2026-10-20']);
});

/**
 * A user of luna at the language given, added in the role given where one is named.
 */
function reportsCsvUser(string $email, SiteLanguage $language, ?string $role = null): User
{
    if ($role !== null) {
        addTenantUser($email, $role);
    }

    DB::table('users')->where('email', $email)->update(['locale' => $language->value]);

    return User::query()->where('email', $email)->sole();
}

/**
 * A figure in a unit as ReportCsv::unit writes it in the language given: ICU's long width (the
 * skeleton's full-name) in Greek, the narrow in English.
 */
function reportsCsvUnit(int $value, string $unit, SiteLanguage $language): string
{
    $width = $language === SiteLanguage::Greek ? 'full-name' : 'narrow';
    $text = (new MessageFormatter($language->value, '{0, number, ::measure-unit/duration-'.$unit.' unit-width-'.$width.'}'))->format([$value]);
    assert(is_string($text));

    return $text;
}

/**
 * The lines a group of the reports section holds, by key, read from the English file.
 *
 * @return array<string, mixed>
 */
function reportsCsvGroup(string $group): array
{
    $lines = __('admin/reports.'.$group, [], SiteLanguage::English->value);
    assert(is_array($lines));

    /** @var array<string, mixed> $lines */
    return $lines;
}

it('writes the file in the language of the user who asks: Greek for an owner at el, English for a manager at en at the same tenant, the filters set and unset', function () {
    $users = [
        'el' => reportsCsvUser('owner@luna.example', SiteLanguage::Greek),
        'en' => reportsCsvUser('manager@luna.example', SiteLanguage::English, 'manager'),
    ];
    csvBooking('confirmed', '2026-10-20 10:00', 'Haircut', 'Elena Papadaki', 60, 3500);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $haircut = LunaFixture::id('services', 'Haircut');
    $queries = [
        'no filter' => ['', null, null],
        'the Staff filter' => ['?staff='.$elena, 'Elena Papadaki', null],
        'the Service filter' => ['?service='.$haircut, null, 'Haircut'],
        'both filters' => ['?staff='.$elena.'&service='.$haircut, 'Elena Papadaki', 'Haircut'],
    ];
    $read = 0;

    foreach ($queries as $name => [$query, $member, $service]) {
        foreach ($users as $code => $user) {
            $language = SiteLanguage::from($code);
            $word = fn (string $key): string => reportsCsvWord($key, [], $language);
            $sections = csvSections(csvBody(csvGet($this, $query, $user)->assertOk()));

            expect(array_keys($sections))->toBe(reportsCsvTitles($language), $name)
                ->and($sections[$word('csv.filters')])->toBe([
                    [$word('filters.range'), $word('filters.from'), $word('filters.to'), $word('filters.staff'), $word('filters.service')],
                    [$word('filters.words.week'), '19/10/2026', '25/10/2026', $member ?? $word('filters.all_staff'), $service ?? $word('filters.all_services')],
                ], $name);
            $read++;
        }
    }

    // The titles and the Filters section's words differ between the two files, each read
    // through the translator, so a Greek file left in English cannot pass.
    $greek = fn (string $key): string => reportsCsvWord($key, [], SiteLanguage::Greek);

    foreach (reportsCsvTitles(SiteLanguage::Greek) as $index => $title) {
        expect($title)->not->toBe(reportsCsvTitles()[$index]);
    }

    foreach (['filters.range', 'filters.from', 'filters.to', 'filters.staff', 'filters.service', 'filters.all_staff', 'filters.all_services'] as $key) {
        expect($greek($key))->not->toBe(reportsCsvWord($key), $key);
    }

    expect($read)->toBe(8);
});

it('writes each word of the Range and its comparison sentence in Greek, a malformed range as this week\'s', function () {
    $greek = reportsCsvUser('owner@luna.example', SiteLanguage::Greek);
    // The period each named range compares with, as the file names it (ReportCsv::COMPARED).
    $compared = ['today' => 'day', 'yesterday' => 'day', 'week' => 'week', 'last_7_days' => 'last_7_days', 'last_30_days' => 'last_30_days', 'month' => 'month', 'last_month' => 'month', 'year' => 'year'];
    $asked = [];

    foreach (array_keys(reportsCsvGroup('filters.words')) as $word) {
        if ($word === 'custom') {
            // The clock's Wednesday 2026-10-21 in Athens: the day before alone, and twenty days.
            $asked['a custom range of one day'] = ['?range=custom&from=2026-10-20&to=2026-10-20', $word, 1];
            $asked['a custom range of twenty days'] = ['?range=custom&from=2026-10-01&to=2026-10-20', $word, 20];
        } else {
            $asked[$word] = ['?range='.$word, $word, null];
        }
    }

    // To before From does not hold, and the file reads it as this week.
    $asked['a malformed range'] = ['?range=custom&from=2026-10-20&to=2026-10-01', 'week', null];
    [$filters, $figures] = reportsCsvTitles(SiteLanguage::Greek);
    $walked = [];

    foreach ($asked as $name => [$query, $word, $days]) {
        $sections = csvSections(csvBody(csvGet($this, $query, $greek)->assertOk()));
        // Nothing is seeded, so every range compares nought with nought: no change.
        [$el, $en] = $days === null
            ? [reportsCsvWord('compare.'.$compared[$word].'.same', [], SiteLanguage::Greek), reportsCsvWord('compare.'.$compared[$word].'.same')]
            : [adminChoice('admin/reports.compare.custom.same', $days, [], SiteLanguage::Greek), adminChoice('admin/reports.compare.custom.same', $days)];

        expect($sections[$filters][1][0])->toBe(reportsCsvWord('filters.words.'.$word, [], SiteLanguage::Greek), $name)
            ->and($sections[$filters][1][0])->not->toBe(reportsCsvWord('filters.words.'.$word), $name)
            ->and($sections[$figures][1][3])->toBe($el, $name)
            ->and($el)->not->toBe($en, $name);
        $walked[$word] = true;
    }

    expect(array_keys($walked))->toEqualCanonicalizing(array_keys(reportsCsvGroup('filters.words')))
        ->and($asked)->toHaveCount(count(reportsCsvGroup('filters.words')) + 2);
});

it('writes the Greek number forms and each kind of comparison in Greek: a rise, no change and up from nought', function () {
    $greek = reportsCsvUser('owner@luna.example', SiteLanguage::Greek);
    csvBooking('confirmed', '2026-10-13 10:00', 'Haircut', 'Elena Papadaki', 60, 100000);
    csvBooking('confirmed', '2026-10-20 10:00', 'Haircut', 'Elena Papadaki', 60, 123456);
    csvBooking('no_show', '2026-10-21 10:00', 'Haircut', 'Elena Papadaki', 60, 3500);
    [, $figures, $overTime] = reportsCsvTitles(SiteLanguage::Greek);
    $el = fn (string $key, string $change = ''): string => reportsCsvWord($key, $change === '' ? [] : ['change' => $change], SiteLanguage::Greek);
    // ICU's hour in the narrow width, as ReportCsv::unit writes it: '1h' in English.
    $hour = fn (int $value): string => reportsCsvUnit($value, 'hour', SiteLanguage::Greek);

    $sections = csvSections(csvBody(csvGet($this, '', $greek)->assertOk()));

    // One booking each week: no change. A fifth more revenue. A no-show this week and none the
    // week before: up from nought. No cancellation in either: no change.
    expect(reportsCsvUnit(1, 'hour', SiteLanguage::English))->toBe('1h')
        ->and($sections[$figures][1][3])->toBe($el('compare.week.same'))
        ->and($sections[$figures][2])->toBe([reportsCsvInEuros('tiles.revenue', SiteLanguage::Greek), '1.234,56', '1.000,00', CsvDownload::safe($el('compare.week.by', '+23%')), ''])
        ->and($sections[$figures][3][1])->toBe('2,5%')
        ->and($sections[$figures][3][4])->toBe(reportsCsvWord('tiles.occupancy_of', ['filled' => $hour(1), 'offered' => $hour(40)], SiteLanguage::Greek))
        ->and($sections[$figures][5][3])->toBe($el('compare.week.same'))
        ->and($sections[$figures][6][3])->toBe($el('compare.week.from_zero'))
        ->and($sections[$overTime][2])->toBe(['20/10/2026', '20/10/2026', '1', '1.234,56']);

    foreach (['compare.week.same', 'compare.week.from_zero'] as $key) {
        expect($el($key))->not->toBe(reportsCsvWord($key), $key);
    }

    expect($el('compare.week.by', '+23%'))->not->toBe(reportsCsvWord('compare.week.by', ['change' => '+23%']));
});

it('writes no English line in a Greek user\'s file with every card and table holding rows', function () {
    $greek = reportsCsvUser('owner@luna.example', SiteLanguage::Greek);
    // A booking of each status the Bookings by status card counts, on Tuesday of this week.
    $statuses = ['confirmed', 'completed', 'awaiting_approval', 'cancelled', 'no_show', 'awaiting_payment', 'pending', 'rejected', 'expired'];

    foreach ($statuses as $hour => $status) {
        csvBooking($status, sprintf('2026-10-20 %02d:00', 9 + $hour), 'Haircut', 'Elena Papadaki', 30, 2000);
    }

    // A second cancelled booking; one each by the customer and by the business, read from the
    // trail. Nikos's half hour, completed and outside any hours, reads hours and minutes.
    csvBooking('cancelled', '2026-10-22 10:00', 'Beard trim', 'Elena Papadaki', 30, 2000);
    csvBooking('completed', '2026-10-21 10:00', 'Beard trim', 'Nikos Georgiou', 30, 2000);
    $cancelled = DB::table('tenant_luna.bookings')->where('status', 'cancelled')->orderBy('id')->pluck('id')->all();
    DB::table('tenant_luna.audit_log')->insert([
        ['booking_id' => $cancelled[0], 'actor' => 'customer', 'action' => 'cancelled', 'payload' => '{}'],
        ['booking_id' => $cancelled[1], 'actor' => 'user:'.$greek->id, 'action' => 'cancelled', 'payload' => '{}'],
    ]);
    // One booking from the hosted page through a channel; the rest have none.
    DB::table('tenant_luna.bookings')->where('status', 'confirmed')->update(['source' => 'hosted', 'channel' => 'instagram']);

    $sections = csvSections(csvBody(csvGet($this, '', $greek)->assertOk()));
    $english = [];
    $lines = [
        'admin/reports' => __('admin/reports', [], 'en'),
        'admin/shell.kit.statuses' => __('admin/shell.kit.statuses', [], 'en'),
        'admin/shell.shared.sources' => __('admin/shell.shared.sources', [], 'en'),
        'admin/shell.shared.channels' => __('admin/shell.shared.channels', [], 'en'),
    ];

    foreach ($lines as $file => $group) {
        assert(is_array($group));

        foreach (Arr::dot($group) as $key => $line) {
            // A line that reads the same in both files, or is on the shared list, is no tell.
            if (is_string($line) && $line !== __($file.'.'.$key, [], 'el') && ! in_array($line, languageSharedValues(), true)) {
                $english[$line] = $file.'.'.$key;
            }
        }
    }

    $cells = 0;
    $titles = reportsCsvTitles(SiteLanguage::Greek);

    foreach ($sections as $title => $rows) {
        foreach ([$title, ...Arr::flatten($rows)] as $cell) {
            assert(is_string($cell));
            expect(array_key_exists($cell, $english))->toBeFalse($title.': '.$cell);
            $cells++;
        }
    }

    [, , , $statusCard, , , $cancelledBy, $occupancy, , , $bySource, $byChannel] = $titles;
    $channelRows = $sections[$byChannel];
    $lastChannelRow = end($channelRows);
    assert($lastChannelRow !== false);
    $noChannel = $lastChannelRow[0];
    $half = reportsCsvWord('tiles.hours_minutes', ['hours' => reportsCsvUnit(0, 'hour', SiteLanguage::Greek), 'minutes' => reportsCsvUnit(30, 'minute', SiteLanguage::Greek)], SiteLanguage::Greek);

    // Every status listed, each in the shell's Greek; both cancellers named; a row of each
    // source and channel and one with none; Nikos's half hour in Greek units.
    expect(array_column(array_slice($sections[$statusCard], 1), 0))->toBe(array_map(fn (string $status): string => adminLine('admin/shell.kit.statuses.'.$status, [], SiteLanguage::Greek), ['confirmed', 'completed', 'awaiting_approval', 'cancelled', 'no_show', 'awaiting_payment', 'pending', 'rejected', 'expired']))
        ->and(array_column(array_slice($sections[$cancelledBy], 1), 1))->toBe(['1', '1'])
        ->and($noChannel)->toBe(reportsCsvWord('tables.no_channel', [], SiteLanguage::Greek))
        ->and($sections[$bySource])->toHaveCount(4)
        ->and($sections[$occupancy][2])->toBe(['Nikos Georgiou', $half, $half, '100,0%'])
        ->and(reportsCsvUnit(30, 'minute', SiteLanguage::English))->toBe('30m')
        ->and($english)->not->toBeEmpty()
        // Each section's title and every cell of its rows, twelve sections: Filters 11, Figures
        // 36, Over time 33, Bookings by status 21, Revenue 7, No-show rate 7, Cancelled by 7,
        // Occupancy by staff 13, By source 13, By channel 22, and By service and By staff 29.
        ->and($cells)->toBe(199);
});

it('keeps the four counted lines\' cases in Greek, each bound reaching its form', function () {
    $bounds = fn (string $line): array => preg_match_all('/\{\d+\}|\[\d+,\*\]/', $line, $found) > 0 ? $found[0] : [];
    $raw = function (string $key, SiteLanguage $language): string {
        $line = __('admin/reports.'.$key, [], $language->value);
        assert(is_string($line));

        return $line;
    };
    // The four keys and the counts each is read at: the one form, the many forms, twenty-one.
    $counted = [
        'tiles.returning' => [['{1}', '[0,*]'], [], [0, 2, 21]],
        'compare.custom.by' => [['{1}', '[2,*]'], ['change' => '+5%'], [2, 11, 21]],
        'compare.custom.same' => [['{1}', '[2,*]'], [], [2, 11, 21]],
        'compare.custom.from_zero' => [['{1}', '[2,*]'], [], [2, 11, 21]],
    ];

    foreach ($counted as $key => [$cases, $replace, $many]) {
        $one = adminChoice('admin/reports.'.$key, 1, $replace, SiteLanguage::Greek);

        expect($bounds($raw($key, SiteLanguage::English)))->toBe($cases, $key)
            ->and($bounds($raw($key, SiteLanguage::Greek)))->toBe($cases, $key)
            ->and($one)->not->toMatch('/[{}\[\]|]/')
            ->and($one)->not->toBe(adminChoice('admin/reports.'.$key, 1, $replace), $key);

        foreach ($many as $count) {
            $line = adminChoice('admin/reports.'.$key, $count, $replace, SiteLanguage::Greek);

            expect($line)->not->toMatch('/[{}\[\]|]/')
                ->and($line)->toContain((string) $count)
                ->and($line)->not->toBe($one, $key.' at '.$count)
                ->and($line)->not->toBe(adminChoice('admin/reports.'.$key, $count, $replace), $key.' at '.$count);
        }
    }
});

it('writes a Greek user\'s durations in ICU\'s long width, as the screen\'s unitFigure does, the English in the narrow', function () {
    csvBooking('confirmed', '2026-10-20 10:00', 'Haircut', 'Elena Papadaki', 60, 3500);
    [, $figures] = reportsCsvTitles();
    [, $greekFigures] = reportsCsvTitles(SiteLanguage::Greek);
    $figure = function (string $locale, string $width): string {
        $text = (new MessageFormatter($locale, '{0, number, ::measure-unit/duration-hour unit-width-'.$width.'}'))->format([1]);
        assert(is_string($text));

        return $text;
    };

    $english = csvSections(csvBody(csvGet($this)->assertOk()))[$figures][3][4];
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => 'el']);
    $greek = csvSections(csvBody(csvGet($this)->assertOk()))[$greekFigures][3][4];

    expect($figure('el', 'full-name'))->not->toBe($figure('en', 'narrow'))
        ->and($figure('el', 'full-name'))->not->toBe($figure('el', 'narrow'))
        ->and($greek)->toContain($figure('el', 'full-name'))
        ->and($english)->toContain($figure('en', 'narrow'));
});

it('refuses the file to a staff user as it refuses the page', function () {
    $staff = User::query()->findOrFail(addTenantUser('staff@luna.example', 'staff'));

    csvGet($this, '', $staff)->assertForbidden();
});
