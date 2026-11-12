<?php

use App\Application\Clock;
use App\Http\BusinessPage;
use App\Infrastructure\Db\TextArray;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The business page: the tenant's profile around the flow on GET / alone, read as a document. The
 * hours come from the staff rows, the rest from the settings row's profile columns; the manage
 * page renders through the same view and carries none of it. The order is the title block, the
 * photo grid, the flow, the team, the about text, the full gallery, the hours beside where to
 * find us, the footer. The open state reads the application clock, pinned where a case names an
 * instant; the other cases leave it on the system clock and read nothing that depends on the day.
 */

/** @return array<string, string|null> */
function fullProfile(): array
{
    return [
        'about' => "Cuts and colour since 1998.\nWalk-ins welcome.",
        'address_line1' => '12 Ermou Street',
        'address_line2' => 'Second floor',
        'city' => 'Athens',
        'postal_code' => '10563',
        'country' => 'GR',
        'phone' => '+30 210 1234567',
        'public_email' => 'hello@acme.example',
        'website' => 'https://acme.example',
        'instagram' => 'https://instagram.com/acme',
        'facebook' => 'https://facebook.com/acme',
        'google_reviews_url' => 'https://g.page/r/acme/review',
    ];
}

function businessXPath(TestCase $test, string $slug = 'acme'): DOMXPath
{
    return new DOMXPath(documentOf($test->get(hostedPage($slug))->assertOk()));
}

/** @return list<string> */
function textsAt(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
{
    $texts = [];

    foreach ($xpath->query($query, $context) ?: [] as $node) {
        if ($node instanceof DOMNode) {
            $texts[] = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
        }
    }

    return $texts;
}

/** @return array<string, string> */
function hoursOf(DOMXPath $xpath): array
{
    $hours = [];

    foreach ($xpath->query('//ul[@class="hosted-hours"]/li') ?: [] as $row) {
        assert($row instanceof DOMNode);
        $cells = textsAt($xpath, './span', $row);
        $hours[$cells[0] ?? ''] = $cells[1] ?? '';
    }

    return $hours;
}

/**
 * The title block's meta line as its parts read, the separators left out, each with the class
 * it carries: the open state's, a plain part's none, Get directions a link.
 *
 * @return list<array{0: string, 1: string}>
 */
function businessMetaOf(DOMXPath $xpath): array
{
    $parts = [];

    foreach ($xpath->query('//section[@class="hosted-intro"]//p[@class="hosted-meta"]/*[not(@aria-hidden)]') ?: [] as $part) {
        assert($part instanceof DOMElement);
        $parts[] = [trim($part->textContent), $part->tagName === 'a' ? 'a' : $part->getAttribute('class')];
    }

    return $parts;
}

/**
 * The photo grid's shape class and its cells in order: the cover as "cover", a gallery photo as
 * its href with its alt.
 *
 * @return array{0: string, 1: list<string>}
 */
function businessGridOf(DOMXPath $xpath): array
{
    $grid = ($xpath->query('//section[@class="hosted-intro"]/div[contains(@class, "hosted-grid")]') ?: null)?->item(0);
    assert($grid instanceof DOMElement);
    $cells = [];

    foreach ($xpath->query('./*[contains(@class, "hosted-cell")]', $grid) ?: [] as $cell) {
        assert($cell instanceof DOMElement);
        $image = $cell->getElementsByTagName('img')->item(0);
        $cells[] = $cell->tagName === 'div' && $image?->getAttribute('class') === 'hosted-cover' && $image->getAttribute('alt') === ''
            ? 'cover'
            : $cell->getAttribute('href').' '.$image?->getAttribute('alt');
    }

    return [$grid->getAttribute('class'), $cells];
}

/** Binds the application clock to an instant on the Athens wall, before the page reads it. */
function businessAt(string $wall): void
{
    app()->instance(Clock::class, FixedClock::at($wall, 'Europe/Athens'));
}

/** @return array<mixed> */
function jsonLdOf(DOMXPath $xpath): array
{
    $texts = textsAt($xpath, '//script[@type="application/ld+json"]');

    expect($texts)->toHaveCount(1);

    $data = json_decode($texts[0] ?? '', true, flags: JSON_THROW_ON_ERROR);

    assert(is_array($data));

    return $data;
}

/**
 * Writes the cover and the gallery straight onto the settings row, as ImagesRouteTest's
 * holdImages() does: the page reads the paths and spells their URLs, and no file needs to exist
 * for that.
 *
 * @param  list<string>  $gallery
 */
function profileImages(string $slug, ?string $cover, array $gallery = []): void
{
    settingsOf($slug)->update(['cover_path' => $cover, 'gallery_paths' => TextArray::encode($gallery)]);
}

/** @return list<string> */
function attributesAt(DOMXPath $xpath, string $query, string $attribute): array
{
    $values = [];

    foreach ($xpath->query($query) ?: [] as $node) {
        if ($node instanceof DOMElement) {
            $values[] = $node->getAttribute($attribute);
        }
    }

    return $values;
}

/** @param  list<array{int, string, string}>  $windows */
function addMember(string $slug, string $name, string $status, array $windows): int
{
    $id = DB::table(sprintf('tenant_%s.staff', $slug))->insertGetId(['name' => $name, 'status' => $status, 'sort' => 1]);

    foreach ($windows as [$weekday, $start, $end]) {
        WeeklyHours::add(sprintf('tenant_%s', $slug), $id, $weekday, $start, $end);
    }

    return $id;
}

it('renders every profile field the row holds, the title block above the flow and the rest below it in order', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    settingsOf('acme')->update(fullProfile());
    $search = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode('12 Ermou Street, Second floor, 10563 Athens, GR');

    $xpath = businessXPath($this);

    expect(textsAt($xpath, '//h1'))->toBe(['Acme Salon'])
        ->and(textsAt($xpath, '//section[@class="hosted-intro"]/div[@class="hosted-title"]/h1'))->toBe(['Acme Salon'])
        ->and(textsAt($xpath, '//section[@class="hosted-section"]/h2[.="'.pageSentence(BusinessPage::ABOUT).'"]/following-sibling::p[@class="hosted-about"]'))->toBe(['Cuts and colour since 1998. Walk-ins welcome.'])
        ->and(textsAt($xpath, '//address[@class="hosted-address"]/span'))->toBe(['12 Ermou Street', 'Second floor', '10563 Athens', pageSentence('countries.GR')])
        ->and(textsAt($xpath, '//h2[.="'.pageSentence(BusinessPage::FIND_US).'"]/following-sibling::ul[@class="hosted-links"]/li/a'))->toBe(['+30 210 1234567', 'hello@acme.example', pageSentence(BusinessPage::WEBSITE), pageSentence(BusinessPage::INSTAGRAM), pageSentence(BusinessPage::FACEBOOK), pageSentence(BusinessPage::REVIEWS)])
        ->and(attributesAt($xpath, '//ul[@class="hosted-links"]/li/a', 'href'))->toBe(['tel:+302101234567', 'mailto:hello@acme.example', 'https://acme.example', 'https://instagram.com/acme', 'https://facebook.com/acme', 'https://g.page/r/acme/review'])
        ->and(textsAt($xpath, '//h2[.="'.pageSentence(BusinessPage::FIND_US).'"]/following-sibling::address/a[not(@class)]'))->toBe([pageSentence(BusinessPage::DIRECTIONS)])
        ->and(attributesAt($xpath, '//address[@class="hosted-address"]/a[not(@class)]', 'href'))->toBe([$search])
        ->and(attributesAt($xpath, '//p[@class="hosted-meta"]/a', 'href'))->toBe([$search]);

    expect($xpath->query('//section[@class="hosted-intro"]/following-sibling::section[@class="hosted-flow"]/h2[@id="book"]/following-sibling::div[@class="kr-booking"]/following::p[@class="hosted-about"]/following::div[@class="hosted-visit"]/section[1]/ul[@class="hosted-hours"]/following::section/address/following-sibling::ul[@class="hosted-links"]/following::footer'))->toHaveCount(1)
        ->and(textsAt($xpath, '//h2[@id="book"]'))->toBe([pageSentence(BusinessPage::FLOW_TITLE)])
        ->and(textsAt($xpath, '//h2'))->toBe([pageSentence(BusinessPage::FLOW_TITLE), pageSentence(BusinessPage::ABOUT), pageSentence(BusinessPage::HOURS), pageSentence(BusinessPage::FIND_US)]);
});

it('heads the page with the brand disc or the logo and the name as a link to the top, the title block holding the one h1', function () {
    provisionTenant('acme', ['name' => 'acme Salon']);

    $xpath = businessXPath($this);

    expect(attributesAt($xpath, '/html/body', 'id'))->toBe(['top'])
        ->and(textsAt($xpath, '/html/body/*[1][self::header][@class="hosted-header hosted-header-sticky"]/a[@class="hosted-brand"][@href="#top"]/span[@class="hosted-name"]'))->toBe(['acme Salon'])
        ->and(textsAt($xpath, '//header/a[@class="hosted-brand"]/span[@class="hosted-disc"][@aria-hidden="true"]'))->toBe(['A'])
        ->and($xpath->query('//header//h1 | //header//img'))->toHaveCount(0)
        ->and(textsAt($xpath, '//h1'))->toBe(['acme Salon']);

    settingsOf('acme')->update(['brand_logo_path' => 'tenants/acme/logo-0123456789abcdef.png']);
    $xpath = businessXPath($this);

    expect(attributesAt($xpath, '//header/a[@class="hosted-brand"]/img[@class="hosted-logo"]', 'alt'))->toBe([''])
        ->and($xpath->query('//header//*[@class="hosted-disc"]'))->toHaveCount(0);
});

it('renders the name and the flow alone for an empty profile, no label over nothing', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    WeeklyHours::clear('tenant_acme');

    $xpath = businessXPath($this);

    expect(textsAt($xpath, '//h1'))->toBe(['Acme Salon'])
        ->and($xpath->query('//p[@class="hosted-meta"] | //*[contains(@class, "hosted-grid")] | //section[@class="hosted-section"] | //*[@class="hosted-visit"]'))->toHaveCount(0)
        ->and($xpath->query('//address | //p[@class="hosted-about"] | //ul[@class="hosted-hours"] | //ul[@class="hosted-links"] | //a[starts-with(@href, "tel:")] | //a[starts-with(@href, "mailto:")]'))->toHaveCount(0)
        ->and($xpath->query('//img | //*[@class="hosted-gallery"]'))->toHaveCount(0)
        ->and($xpath->query('//div[@class="kr-booking"]'))->toHaveCount(1)
        ->and(textsAt($xpath, '//h2'))->toBe([pageSentence(BusinessPage::FLOW_TITLE)]);
});

it('treats a country alone as no address, and writes no country line for a null country', function () {
    provisionTenant('acme');

    expect(businessXPath($this)->query('//address'))->toHaveCount(0);

    settingsOf('acme')->update(['city' => 'Athens', 'country' => null]);

    expect(textsAt(businessXPath($this), '//address[@class="hosted-address"]/span'))->toBe(['Athens']);
});

it('reads the hours off the implicit member on a floor tenant, in the tenant time format, Closed where no window', function () {
    provisionTenant('acme');
    addMember('acme', 'Ann', 'active', [[6, '10:00:00', '14:00:00']]);
    settingsOf('acme')->update(['time_format' => 'g:i A']);

    expect(hoursOf(businessXPath($this)))->toBe([
        'Monday' => '9:00 AM - 5:00 PM', 'Tuesday' => '9:00 AM - 5:00 PM', 'Wednesday' => '9:00 AM - 5:00 PM',
        'Thursday' => '9:00 AM - 5:00 PM', 'Friday' => '9:00 AM - 5:00 PM', 'Saturday' => pageSentence(BusinessPage::CLOSED), 'Sunday' => pageSentence(BusinessPage::CLOSED),
    ]);
});

it('reads the hours as the union of active members on a staff tenant, starting the week where the tenant does', function () {
    provisionTenant('acme', [], ['staff']);
    DB::table('tenant_acme.staff')->where('is_implicit', true)->update(['status' => 'inactive']);
    addMember('acme', 'Ann', 'active', [[1, '09:00:00', '13:00:00'], [2, '16:00:00', '19:00:00']]);
    addMember('acme', 'Bob', 'active', [[1, '12:00:00', '18:00:00'], [2, '10:00:00', '14:00:00']]);
    addMember('acme', 'Cy', 'inactive', [[3, '08:00:00', '20:00:00']]);
    settingsOf('acme')->update(['start_of_week' => 7]);

    expect(hoursOf(businessXPath($this)))->toBe([
        'Sunday' => pageSentence(BusinessPage::CLOSED), 'Monday' => '09:00 - 18:00', 'Tuesday' => '10:00 - 14:00, 16:00 - 19:00',
        'Wednesday' => pageSentence(BusinessPage::CLOSED), 'Thursday' => pageSentence(BusinessPage::CLOSED), 'Friday' => pageSentence(BusinessPage::CLOSED), 'Saturday' => pageSentence(BusinessPage::CLOSED),
    ]);
});

it('shows each member\'s usual week from their pattern, a pattern every two weeks drawn as the usual week and a day edited alone not drawn', function () {
    businessAt('2026-11-02 10:00');
    provisionTenant('acme', [], ['staff']);
    DB::table('tenant_acme.staff')->where('is_implicit', true)->update(['status' => 'inactive']);
    $ann = addMember('acme', 'Ann', 'active', []);
    $bob = addMember('acme', 'Bob', 'active', []);
    WeeklyHours::pattern('tenant_acme', $ann, '2026-11-09', 2, null, [[3, '10:00', '16:00']]);
    WeeklyHours::week('tenant_acme', $bob, [[4, '08:00', '12:00']]);
    DB::table('tenant_acme.shift_overrides')->insert(['staff_id' => $ann, 'date' => '2026-11-07', 'windows' => '[{"start": "09:00", "end": "11:00"}]']);

    expect(hoursOf(businessXPath($this)))->toBe([
        'Monday' => pageSentence(BusinessPage::CLOSED), 'Tuesday' => pageSentence(BusinessPage::CLOSED), 'Wednesday' => '10:00 - 16:00',
        'Thursday' => '08:00 - 12:00', 'Friday' => pageSentence(BusinessPage::CLOSED), 'Saturday' => pageSentence(BusinessPage::CLOSED), 'Sunday' => pageSentence(BusinessPage::CLOSED),
    ]);
});

it('leaves out a pattern whose end is before the tenant\'s today and keeps one that ends today or starts later, so the table shows no hours the flow cannot offer', function () {
    businessAt('2026-11-02 10:00');
    provisionTenant('acme', [], ['staff']);
    DB::table('tenant_acme.staff')->where('is_implicit', true)->update(['status' => 'inactive']);
    $ann = addMember('acme', 'Ann', 'active', []);
    $bob = addMember('acme', 'Bob', 'active', []);
    $cat = addMember('acme', 'Cat', 'active', []);
    WeeklyHours::pattern('tenant_acme', $ann, '1999-12-27', 1, '2026-10-30', [[1, '09:00', '17:00']]);
    WeeklyHours::pattern('tenant_acme', $bob, '1999-12-27', 1, '2026-11-02', [[2, '10:00', '14:00']]);
    WeeklyHours::pattern('tenant_acme', $cat, '2026-11-16', 2, null, [[5, '12:00', '18:00']]);

    expect(hoursOf(businessXPath($this)))->toBe([
        'Monday' => pageSentence(BusinessPage::CLOSED), 'Tuesday' => '10:00 - 14:00', 'Wednesday' => pageSentence(BusinessPage::CLOSED),
        'Thursday' => pageSentence(BusinessPage::CLOSED), 'Friday' => '12:00 - 18:00', 'Saturday' => pageSentence(BusinessPage::CLOSED), 'Sunday' => pageSentence(BusinessPage::CLOSED),
    ]);
});

it('judges an ended pattern by the tenant\'s today, not UTC\'s: at 00:30 in Athens a pattern that ended the day before is left out, though in UTC that day is still today', function () {
    businessAt('2026-11-02 00:30');
    provisionTenant('acme', [], ['staff']);
    DB::table('tenant_acme.staff')->where('is_implicit', true)->update(['status' => 'inactive']);
    $ann = addMember('acme', 'Ann', 'active', []);
    $bob = addMember('acme', 'Bob', 'active', []);
    WeeklyHours::pattern('tenant_acme', $ann, '1999-12-27', 1, '2026-11-01', [[1, '09:00', '17:00']]);
    WeeklyHours::pattern('tenant_acme', $bob, '1999-12-27', 1, '2026-11-02', [[2, '10:00', '14:00']]);

    expect(hoursOf(businessXPath($this)))->toMatchArray(['Monday' => pageSentence(BusinessPage::CLOSED), 'Tuesday' => '10:00 - 14:00']);
});

it('keeps the implicit week on a staff tenant whose roster holds no other row', function () {
    provisionTenant('acme', [], ['staff']);

    expect(hoursOf(businessXPath($this))['Monday'] ?? null)->toBe('09:00 - 17:00');
});

it('falls back to the implicit member\'s week on a staff tenant whose every member is inactive, the implicit one included, in the hours list, the open state and the structured data', function () {
    businessAt('2026-11-02 10:00');
    provisionTenant('acme', [], ['staff']);
    DB::table('tenant_acme.staff')->update(['status' => 'inactive']);
    addMember('acme', 'Ann', 'inactive', [[6, '10:00:00', '14:00:00']]);

    $xpath = businessXPath($this);

    expect(hoursOf($xpath))->toBe([
        'Monday' => '09:00 - 17:00', 'Tuesday' => '09:00 - 17:00', 'Wednesday' => '09:00 - 17:00',
        'Thursday' => '09:00 - 17:00', 'Friday' => '09:00 - 17:00', 'Saturday' => pageSentence(BusinessPage::CLOSED), 'Sunday' => pageSentence(BusinessPage::CLOSED),
    ])
        ->and(businessMetaOf($xpath))->toBe([[pageSentence(BusinessPage::OPEN_UNTIL, ['time' => '17:00']), 'hosted-status hosted-open']])
        ->and(data_get(jsonLdOf($xpath), 'openingHoursSpecification.*.dayOfWeek'))->toBe(array_map(fn (string $day): string => 'https://schema.org/'.$day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']));
});

it('merges two windows of a day where one ends as the next begins, in the hours list and the structured data, and keeps apart two with a gap between', function () {
    provisionTenant('acme', [], ['staff']);
    DB::table('tenant_acme.staff')->where('is_implicit', true)->update(['status' => 'inactive']);
    addMember('acme', 'Ann', 'active', [[1, '09:00:00', '13:00:00']]);
    addMember('acme', 'Bob', 'active', [[1, '13:00:00', '18:00:00'], [2, '09:00:00', '12:00:00'], [2, '12:30:00', '15:00:00']]);

    $xpath = businessXPath($this);
    $window = fn (string $day, string $opens, string $closes): array => ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/'.$day, 'opens' => $opens, 'closes' => $closes];

    expect(hoursOf($xpath)['Monday'] ?? null)->toBe('09:00 - 18:00')
        ->and(hoursOf($xpath)['Tuesday'] ?? null)->toBe('09:00 - 12:00, 12:30 - 15:00')
        ->and(data_get(jsonLdOf($xpath), 'openingHoursSpecification'))->toBe([$window('Monday', '09:00', '18:00'), $window('Tuesday', '09:00', '12:00'), $window('Tuesday', '12:30', '15:00')]);
});

it('lists the hours with a closed day marked and the tenant\'s today current, on a pinned clock', function () {
    businessAt('2026-11-07 12:00');
    provisionTenant('acme');

    $xpath = businessXPath($this);

    expect(textsAt($xpath, '//ul[@class="hosted-hours"]/li[@class="hosted-closed"]/span[@class="hosted-day"]'))->toBe(['Saturday', 'Sunday'])
        ->and(textsAt($xpath, '//ul[@class="hosted-hours"]/li[@aria-current="date"]/span[@class="hosted-day"]'))->toBe(['Saturday'])
        ->and($xpath->query('//ul[@class="hosted-hours"]/li[not(@class)]'))->toHaveCount(5)
        ->and($xpath->query('//ul[@class="hosted-hours"]/li[@aria-current]'))->toHaveCount(1);
});

it('states the open state in the title block on the tenant\'s clock: {0}', function (string $wall, string $key, ?string $time, string $class) {
    businessAt($wall);
    provisionTenant('acme');

    expect(businessMetaOf(businessXPath($this)))->toBe([[pageSentence($key, $time === null ? [] : ['time' => $time]), $class]]);
})->with([
    'open until the end of the window holding the instant' => ['2026-11-02 10:00', BusinessPage::OPEN_UNTIL, '17:00', 'hosted-status hosted-open'],
    'opening at the start of a window still to come today' => ['2026-11-02 08:59', BusinessPage::OPENS_AT, '09:00', 'hosted-status'],
    'closed today from the end of the last window' => ['2026-11-02 17:00', BusinessPage::CLOSED_TODAY, null, 'hosted-status'],
    'closed today on a day with no window' => ['2026-11-07 12:00', BusinessPage::CLOSED_TODAY, null, 'hosted-status'],
]);

it('places the instant on the tenant\'s zone, spells the open state in the tenant\'s time format, and opens at the next window after a break', function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-02 07:30'));
    provisionTenant('acme');
    settingsOf('acme')->update(['time_format' => 'g:i A']);

    expect(businessMetaOf(businessXPath($this)))->toBe([[pageSentence(BusinessPage::OPEN_UNTIL, ['time' => '5:00 PM']), 'hosted-status hosted-open']]);

    WeeklyHours::reshape('tenant_acme', 1, '09:00:00', '13:00:00');
    WeeklyHours::add('tenant_acme', implicitStaffId('tenant_acme'), 1, '14:00:00', '18:00:00');
    businessAt('2026-11-02 13:30');

    expect(businessMetaOf(businessXPath($this)))->toBe([[pageSentence(BusinessPage::OPENS_AT, ['time' => '2:00 PM']), 'hosted-status']]);
});

it('joins the meta line\'s parts with middle dots, the short address of the street line and the city, Get directions by the address block\'s href, and leaves out a part with nothing to say', function () {
    businessAt('2026-11-02 10:00');
    provisionTenant('acme');
    settingsOf('acme')->update(fullProfile());
    $search = 'https://www.google.com/maps/search/?api=1&query=';

    $response = $this->get(hostedPage('acme'))->assertOk();
    $xpath = new DOMXPath(documentOf($response));

    expect(businessMetaOf($xpath))->toBe([[pageSentence(BusinessPage::OPEN_UNTIL, ['time' => '17:00']), 'hosted-status hosted-open'], ['12 Ermou Street, Athens', ''], [pageSentence(BusinessPage::DIRECTIONS), 'a']])
        ->and($xpath->query('//p[@class="hosted-meta"]/span[@aria-hidden="true"]'))->toHaveCount(2)
        ->and(substr_count((string) $response->getContent(), '<span aria-hidden="true">&middot;</span>'))->toBe(2);

    WeeklyHours::clear('tenant_acme');
    settingsOf('acme')->update(['address_line1' => null]);
    $xpath = businessXPath($this);

    expect(businessMetaOf($xpath))->toBe([['Athens', ''], [pageSentence(BusinessPage::DIRECTIONS), 'a']])
        ->and($xpath->query('//p[@class="hosted-meta"]/span[@aria-hidden="true"]'))->toHaveCount(1)
        ->and(attributesAt($xpath, '//p[@class="hosted-meta"]/a', 'href'))->toBe([$search.rawurlencode('Second floor, 10563 Athens, GR')]);

    settingsOf('acme')->update(['city' => null]);
    $xpath = businessXPath($this);

    expect(businessMetaOf($xpath))->toBe([[pageSentence(BusinessPage::DIRECTIONS), 'a']])
        ->and($xpath->query('//p[@class="hosted-meta"]/span'))->toHaveCount(0);
});

it('carries Book now in the sticky header and the phone bar, both to the flow under its visually hidden heading, the bar giving way to the flow\'s running summary, and no script of its own', function () {
    provisionTenant('acme');

    $xpath = businessXPath($this);

    expect(textsAt($xpath, '//header/a[@class="hosted-book"][@href="#book"]'))->toBe([pageSentence(BusinessPage::BOOK_NOW)])
        ->and(textsAt($xpath, '//nav[@class="hosted-bar"]/a[@href="#book"]'))->toBe([pageSentence(BusinessPage::BOOK_NOW)])
        ->and(attributesAt($xpath, '//section[@class="hosted-flow"]/*[1][self::h2][@id="book"]', 'class'))->toBe(['hosted-flow-title hosted-sr'])
        ->and(attributesAt($xpath, '//section[@class="hosted-flow"]', 'aria-labelledby'))->toBe(['book'])
        ->and($xpath->query('//script[not(@src) and not(@type="application/json") and not(@type="application/ld+json")]'))->toHaveCount(0)
        ->and($xpath->query('//*[@onclick or @onload]'))->toHaveCount(0);

    $style = implode('', textsAt($xpath, '//style'));

    expect(substr_count($style, '@media'))->toBe(1)
        ->and($style)->toContain('@media (max-width: 640px)')
        ->and($style)->toContain('position: sticky')
        ->and($style)->toContain('position: fixed')
        ->and($style)->toContain('.hosted-business:has(.kr-booking__estimate:not(.kr-booking__estimate--empty), .kr-booking__layout--single) .hosted-bar { display: none; }')
        ->and(substr_count($style, 'kr-booking'))->toBe(3)
        ->and($style)->toMatch('~\.hosted-sr \{[^}]*position: absolute;[^}]*width: 1px;[^}]*height: 1px;[^}]*overflow: hidden;[^}]*clip: rect\(0, 0, 0, 0\);~')
        ->and($style)->not->toMatch('~\.hosted-sr \{[^}]*display: none~')
        ->and($style)->toMatch('~\.hosted-header \{[^}]*height: 72px;[^}]*border-bottom: 1px solid #e5e7eb;[^}]*background: #ffffff;~')
        ->and($style)->toMatch('~\.hosted-book \{[^}]*border-radius: 999px;[^}]*background: var\(--kr-primary\);~');
});

it('emits one LocalBusiness with the address, telephone, hours, socials and an offer per listed service', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    settingsOf('acme')->update(fullProfile());
    $cut = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut', ['price_minor' => 2500]));
    DB::table('tenant_acme.services')->insertGetId(serviceRow('Gone', ['status' => 'inactive']));
    $origin = app(TenantUrls::class)->origin(Slug::of('acme'));

    $data = jsonLdOf(businessXPath($this));

    expect($data['@context'])->toBe('https://schema.org')
        ->and($data['@type'])->toBe('LocalBusiness')
        ->and($data['name'])->toBe('Acme Salon')
        ->and($data['url'])->toBe($origin.'/')
        ->and($data['telephone'])->toBe('+30 210 1234567')
        ->and($data['address'])->toBe([
            '@type' => 'PostalAddress', 'streetAddress' => '12 Ermou Street, Second floor',
            'addressLocality' => 'Athens', 'postalCode' => '10563', 'addressCountry' => 'GR',
        ])
        ->and($data['sameAs'])->toBe(['https://acme.example', 'https://instagram.com/acme', 'https://facebook.com/acme'])
        ->and($data['openingHoursSpecification'])->toHaveCount(5)
        ->and(data_get($data, 'openingHoursSpecification.0'))->toBe([
            '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Monday', 'opens' => '09:00', 'closes' => '17:00',
        ])
        ->and($data['makesOffer'])->toBe([[
            '@type' => 'Offer', 'name' => 'Cut', 'price' => '25.00', 'priceCurrency' => 'EUR', 'url' => $origin.'/?service='.$cut,
        ]]);
});

it('writes no address, telephone or sameAs for an empty profile, and no addressCountry for a null country', function () {
    provisionTenant('acme');

    expect(jsonLdOf(businessXPath($this)))->not->toHaveKeys(['address', 'telephone', 'sameAs', 'image']);

    settingsOf('acme')->update(['city' => 'Athens', 'country' => null]);

    expect(jsonLdOf(businessXPath($this))['address'])->toBe(['@type' => 'PostalAddress', 'addressLocality' => 'Athens']);
});

it('keeps a closing tag in the profile as data inside the structured data', function () {
    provisionTenant('acme', ['name' => 'Acme</script><script>alert(1)</script>']);

    $page = $this->get(hostedPage('acme'))->assertOk()->getContent();

    expect(substr_count((string) $page, '</script>'))->toBe(3)
        ->and(jsonLdOf(businessXPath($this))['name'])->toBe('Acme</script><script>alert(1)</script>');
});

it('renders neither the profile nor the structured data on the manage page, which keeps the header of the disc and the name as its h1 and the mount at 720px', function () {
    provisionTenant('acme', [], ['staff']);
    settingsOf('acme')->update(fullProfile());
    profileImages('acme', 'tenants/acme/cover-0123456789abcdef.jpg', ['tenants/acme/gallery-0123456789abcdef.jpg']);
    $ann = addMember('acme', 'Ann Lee', 'active', []);
    DB::table('tenant_acme.staff')->where('id', $ann)->update(['title' => 'Senior stylist', 'photo_path' => 'tenants/acme/staff-0123456789abcdef.png']);
    $link = app(TenantUrls::class)->manageLink(Slug::of('acme'), '0190a4a6-0000-7000-8000-000000000001', 'token');

    $xpath = new DOMXPath(documentOf($this->get($link)->assertOk()));

    expect($xpath->query('//script[@type="application/ld+json"] | //section | //*[@id="book"] | //a[@href="#book"] | //nav | //footer | //img | //*[@class="hosted-gallery"] | //*[@class="hosted-team"]'))->toHaveCount(0)
        ->and(textsAt($xpath, '//h2'))->not->toContain(pageSentence(BusinessPage::TEAM))
        ->and(textsAt($xpath, '//header[@class="hosted-header"]/div[@class="hosted-brand"]/h1[@class="hosted-name"]'))->toBe(['Acme Salon'])
        ->and(textsAt($xpath, '//header[@class="hosted-header"]/div[@class="hosted-brand"]/span[@class="hosted-disc"][@aria-hidden="true"]'))->toBe(['A'])
        ->and($xpath->query('//h1'))->toHaveCount(1)
        ->and(attributesAt($xpath, '/html/body/main', 'class'))->toBe(['hosted-main'])
        ->and($xpath->query('/html/body[@class]'))->toHaveCount(0)
        ->and(implode('', textsAt($xpath, '//style')))->toMatch('~\.hosted-main \{[^}]*max-width: 720px;[^}]*margin: 32px auto;~');
});

it('renders the cover in the photo grid under the title block, no longer before the header, with an empty alt and no link, as the structured data\'s image', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    profileImages('acme', 'tenants/acme/cover-0123456789abcdef.jpg');
    $cover = app(TenantUrls::class)->image(Slug::of('acme'), 'cover-0123456789abcdef.jpg');

    $xpath = businessXPath($this);

    expect($xpath->query('/html/body/*[1][self::header]'))->toHaveCount(1)
        ->and(attributesAt($xpath, '//section[@class="hosted-intro"]/div[@class="hosted-title"]/following-sibling::div[@class="hosted-grid hosted-grid-1"]/div[@class="hosted-cell"]/img[@class="hosted-cover"]', 'src'))->toBe([$cover])
        ->and(attributesAt($xpath, '//img[@class="hosted-cover"]', 'alt'))->toBe([''])
        ->and($xpath->query('//img[@class="hosted-cover"]/ancestor::a | //a[@class="hosted-more"] | //section[@id="photos"]'))->toHaveCount(0)
        ->and(jsonLdOf($xpath)['image'] ?? null)->toBe($cover);

    $style = implode('', textsAt($xpath, '//style'));

    expect($style)->toMatch('~\.hosted-grid \{[^}]*grid-template-columns: 2fr 1fr;[^}]*gap: 12px;[^}]*height: 440px;~')
        ->and($style)->toMatch('~\.hosted-grid-2 \{[^}]*grid-template-columns: 1fr 1fr;~')
        ->and($style)->toMatch('~\.hosted-grid-1 \.hosted-cell img \{[^}]*aspect-ratio: 21 / 9;[^}]*max-height: 440px;~')
        ->and($style)->toMatch('~\.hosted-cell \{[^}]*border-radius: 16px;~')
        ->and($style)->toMatch('~\.hosted-grid > \.hosted-cell:first-child \{ height: 180px;~');
});

it('lays the photo grid out from the cover: with the first two gallery photos and a way to the whole gallery where it holds more, with one beside it, alone', function () {
    provisionTenant('acme');
    $photo = fn (string $file, int $place, int $of): string => app(TenantUrls::class)->image(Slug::of('acme'), 'gallery-'.$file.'.jpg').' '.pageSentence(BusinessPage::PHOTO, ['n' => (string) $place, 'count' => (string) $of]);
    $gallery = array_map(fn (string $file): string => 'tenants/acme/gallery-'.$file.'.jpg', ['aaaa', 'bbbb', 'cccc', 'dddd', 'eeee', 'ffff']);

    profileImages('acme', 'tenants/acme/cover-aaaa.jpg', $gallery);
    $xpath = businessXPath($this);

    expect(businessGridOf($xpath))->toBe(['hosted-grid hosted-grid-3', ['cover', $photo('aaaa', 1, 6), $photo('bbbb', 2, 6)]])
        ->and(textsAt($xpath, '//section[@class="hosted-intro"]/div[contains(@class, "hosted-grid")]/a[@class="hosted-more"][@href="#photos"]'))->toBe([pageChoice(BusinessPage::SEE_ALL, 6)])
        ->and($xpath->query('//section[@id="photos"]/div[@class="hosted-gallery"]/a'))->toHaveCount(6);

    profileImages('acme', 'tenants/acme/cover-aaaa.jpg', array_slice($gallery, 0, 2));
    $xpath = businessXPath($this);

    expect(businessGridOf($xpath))->toBe(['hosted-grid hosted-grid-3', ['cover', $photo('aaaa', 1, 2), $photo('bbbb', 2, 2)]])
        ->and($xpath->query('//a[@class="hosted-more"]'))->toHaveCount(0);

    profileImages('acme', 'tenants/acme/cover-aaaa.jpg', array_slice($gallery, 0, 1));
    $xpath = businessXPath($this);

    expect(businessGridOf($xpath))->toBe(['hosted-grid hosted-grid-2', ['cover', $photo('aaaa', 1, 1)]])
        ->and($xpath->query('//a[@class="hosted-more"]'))->toHaveCount(0);

    profileImages('acme', 'tenants/acme/cover-aaaa.jpg');

    expect(businessGridOf(businessXPath($this)))->toBe(['hosted-grid hosted-grid-1', ['cover']]);
});

it('lays the photo grid out from the gallery alone, its first photo in the cover\'s cell, and renders no grid with neither', function () {
    provisionTenant('acme');
    $photo = fn (string $file, int $place, int $of): string => app(TenantUrls::class)->image(Slug::of('acme'), 'gallery-'.$file.'.jpg').' '.pageSentence(BusinessPage::PHOTO, ['n' => (string) $place, 'count' => (string) $of]);
    $gallery = array_map(fn (string $file): string => 'tenants/acme/gallery-'.$file.'.jpg', ['aaaa', 'bbbb', 'cccc', 'dddd']);

    profileImages('acme', null, $gallery);
    $xpath = businessXPath($this);

    expect(businessGridOf($xpath))->toBe(['hosted-grid hosted-grid-3', [$photo('aaaa', 1, 4), $photo('bbbb', 2, 4), $photo('cccc', 3, 4)]])
        ->and(textsAt($xpath, '//a[@class="hosted-more"][@href="#photos"]'))->toBe([pageChoice(BusinessPage::SEE_ALL, 4)])
        ->and($xpath->query('//img[@class="hosted-cover"]'))->toHaveCount(0);

    profileImages('acme', null, array_slice($gallery, 0, 3));

    expect(businessXPath($this)->query('//a[@class="hosted-more"]'))->toHaveCount(0);

    profileImages('acme', null, array_slice($gallery, 0, 2));

    expect(businessGridOf(businessXPath($this)))->toBe(['hosted-grid hosted-grid-2', [$photo('aaaa', 1, 2), $photo('bbbb', 2, 2)]]);

    profileImages('acme', null, array_slice($gallery, 0, 1));

    expect(businessGridOf(businessXPath($this)))->toBe(['hosted-grid hosted-grid-1', [$photo('aaaa', 1, 1)]]);

    profileImages('acme', null);

    expect(businessXPath($this)->query('//*[contains(@class, "hosted-grid")] | //section[@id="photos"]'))->toHaveCount(0);
});

it('renders the full gallery under Photos after the about text and before the hours, each photo a link to its image, in the stored order', function () {
    provisionTenant('acme');
    settingsOf('acme')->update(fullProfile());
    profileImages('acme', null, ['tenants/acme/gallery-bbbb.jpg', 'tenants/acme/gallery-aaaa.png', 'tenants/acme/gallery-cccc.webp']);
    $urls = app(TenantUrls::class);
    $photos = array_map(fn (string $file): string => $urls->image(Slug::of('acme'), $file), ['gallery-bbbb.jpg', 'gallery-aaaa.png', 'gallery-cccc.webp']);

    $xpath = businessXPath($this);

    expect(attributesAt($xpath, '//section[@class="hosted-section"][@id="photos"]/div[@class="hosted-gallery"]/a', 'href'))->toBe($photos)
        ->and(textsAt($xpath, '//section[@id="photos"]/h2'))->toBe([pageSentence(BusinessPage::PHOTOS)])
        ->and(attributesAt($xpath, '//*[@class="hosted-gallery"]/a/img', 'src'))->toBe($photos)
        ->and(attributesAt($xpath, '//*[@class="hosted-gallery"]/a/img', 'alt'))->toBe([
            pageSentence(BusinessPage::PHOTO, ['n' => '1', 'count' => '3']), pageSentence(BusinessPage::PHOTO, ['n' => '2', 'count' => '3']), pageSentence(BusinessPage::PHOTO, ['n' => '3', 'count' => '3']),
        ])
        ->and($xpath->query('//p[@class="hosted-about"]/following::section[@id="photos"]/following::ul[@class="hosted-hours"]/following::ul[@class="hosted-links"]'))->toHaveCount(1)
        ->and($xpath->query('//img[@class="hosted-cover"]'))->toHaveCount(0)
        ->and(jsonLdOf($xpath))->not->toHaveKey('image');
});

it('renders a gallery alone as the grid and the Photos section, and a cover alone as the grid with no section under it', function () {
    provisionTenant('acme');
    WeeklyHours::clear('tenant_acme');
    profileImages('acme', null, ['tenants/acme/gallery-aaaa.png']);

    $xpath = businessXPath($this);

    expect($xpath->query('//section[@class="hosted-section"]'))->toHaveCount(1)
        ->and($xpath->query('//section[@id="photos"]/*[@class="hosted-gallery"]/a'))->toHaveCount(1)
        ->and($xpath->query('//*[contains(@class, "hosted-grid")]/a[@class="hosted-cell"]'))->toHaveCount(1);

    profileImages('acme', 'tenants/acme/cover-aaaa.jpg');
    $xpath = businessXPath($this);

    expect($xpath->query('//img[@class="hosted-cover"]'))->toHaveCount(1)
        ->and($xpath->query('//section[@class="hosted-section"] | //*[@class="hosted-visit"]'))->toHaveCount(0);
});

/**
 * Writes the map's columns straight onto the settings row, as the job's settling write leaves
 * them: the page reads the pair and the path and spells the URL, and no file needs to exist.
 */
function profileMap(string $slug, string $state): void
{
    settingsOf($slug)->update(['lat' => 37.9763295, 'lng' => 23.7323814, 'map_path' => 'tenants/'.$slug.'/map-0123456789abcdef.png', 'map_state' => $state]);
}

it('renders the map over the address lines on ready, the image from the tenant\'s origin linking to directions by the pair, and both Get directions links by the pair', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    settingsOf('acme')->update(fullProfile());
    profileMap('acme', 'ready');
    $directions = 'https://www.google.com/maps/dir/?api=1&destination=37.9763295,23.7323814';

    $xpath = businessXPath($this);

    expect(attributesAt($xpath, '//address[@class="hosted-address"]/*[1][self::a][@class="hosted-map"]', 'href'))->toBe([$directions])
        ->and(attributesAt($xpath, '//address[@class="hosted-address"]/a[@class="hosted-map"]/img', 'src'))->toBe([app(TenantUrls::class)->image(Slug::of('acme'), 'map-0123456789abcdef.png')])
        ->and(attributesAt($xpath, '//a[@class="hosted-map"]/img', 'alt'))->toBe([pageSentence(BusinessPage::MAP)])
        ->and(attributesAt($xpath, '//a[@class="hosted-map"]/img', 'width'))->toBe(['640'])
        ->and(attributesAt($xpath, '//a[@class="hosted-map"]/img', 'height'))->toBe(['320'])
        ->and(textsAt($xpath, '//address[@class="hosted-address"]/span'))->toBe(['12 Ermou Street', 'Second floor', '10563 Athens', pageSentence('countries.GR')])
        ->and(attributesAt($xpath, '//address[@class="hosted-address"]/a[not(@class)]', 'href'))->toBe([$directions])
        ->and(textsAt($xpath, '//address[@class="hosted-address"]/a[not(@class)]'))->toBe([pageSentence(BusinessPage::DIRECTIONS)])
        ->and(attributesAt($xpath, '//p[@class="hosted-meta"]/a', 'href'))->toBe([$directions])
        // Get directions in the theme colour and bold with no underline.
        ->and(implode('', textsAt($xpath, '//style')))->toMatch('~\.hosted-meta a, \.hosted-address a \{[^}]*text-decoration: none;~')
        ->and(jsonLdOf($xpath)['geo'] ?? null)->toBe(['@type' => 'GeoCoordinates', 'latitude' => 37.9763295, 'longitude' => 23.7323814]);
});

it('renders the address alone with the search link and no geo while the map is {0}, whatever the row last held', function (string $state) {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    settingsOf('acme')->update(fullProfile());
    profileMap('acme', $state);

    $xpath = businessXPath($this);

    expect($xpath->query('//*[@class="hosted-map"]'))->toHaveCount(0)
        ->and($xpath->query('//img[contains(@src, "map-")]'))->toHaveCount(0)
        ->and(attributesAt($xpath, '//address[@class="hosted-address"]/a', 'href'))->toBe(['https://www.google.com/maps/search/?api=1&query='.rawurlencode('12 Ermou Street, Second floor, 10563 Athens, GR')])
        ->and(array_key_exists('geo', jsonLdOf($xpath)))->toBeFalse();
})->with(['none', 'pending', 'failed']);

it('renders no map without an address to hang it on, even on ready', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    settingsOf('acme')->update(['about' => 'Cuts.']);
    profileMap('acme', 'ready');

    $xpath = businessXPath($this);

    expect($xpath->query('//address'))->toHaveCount(0)
        ->and($xpath->query('//*[@class="hosted-map"]'))->toHaveCount(0)
        ->and(array_key_exists('geo', jsonLdOf($xpath)))->toBeFalse();
});

it('renders the team of online, active, named members straight after the flow, each with the photo or the initials, the name and the title, and never the bio', function () {
    provisionTenant('acme', ['name' => 'Acme Salon'], ['staff']);
    settingsOf('acme')->update(fullProfile());
    profileImages('acme', null, ['tenants/acme/gallery-aaaa.png']);
    $ann = addMember('acme', 'Ann Lee', 'active', []);
    addMember('acme', 'bob  stone', 'active', []);
    $cy = addMember('acme', 'Cy Ward', 'inactive', []);
    $dee = addMember('acme', 'Dee Park', 'active', []);
    DB::table('tenant_acme.staff')->where('id', $ann)->update(['title' => 'Senior stylist', 'bio' => 'Ten years at the chair.', 'photo_path' => 'tenants/acme/staff-0123456789abcdef.png']);
    DB::table('tenant_acme.staff')->where('id', $cy)->update(['title' => 'Colourist']);
    DB::table('tenant_acme.staff')->where('id', $dee)->update(['online' => false, 'title' => 'Barber']);

    $xpath = businessXPath($this);
    $html = $xpath->document->saveHTML();

    expect(textsAt($xpath, '//section[@class="hosted-section"]/h2'))->toContain(pageSentence(BusinessPage::TEAM))
        ->and($xpath->query('//ul[@class="hosted-team"]/li'))->toHaveCount(2)
        ->and(textsAt($xpath, '//ul[@class="hosted-team"]//*[@class="hosted-team-name"]'))->toBe(['Ann Lee', 'bob stone'])
        ->and(textsAt($xpath, '//ul[@class="hosted-team"]//*[@class="hosted-team-title"]'))->toBe(['Senior stylist'])
        ->and(attributesAt($xpath, '//ul[@class="hosted-team"]/li/img[@class="hosted-face"]', 'src'))->toBe([app(TenantUrls::class)->image(Slug::of('acme'), 'staff-0123456789abcdef.png')])
        ->and(attributesAt($xpath, '//ul[@class="hosted-team"]/li/img[@class="hosted-face"]', 'alt'))->toBe([''])
        ->and(textsAt($xpath, '//ul[@class="hosted-team"]/li/span[@class="hosted-face"][@aria-hidden="true"]'))->toBe(['BS'])
        ->and($xpath->query('//section[@class="hosted-flow"]/following-sibling::*[1][self::section][@class="hosted-section"]/h2[.="'.pageSentence(BusinessPage::TEAM).'"]/following-sibling::ul[@class="hosted-team"]/following::p[@class="hosted-about"]/following::section[@id="photos"]/following::ul[@class="hosted-links"]'))->toHaveCount(1)
        ->and(implode(' ', textsAt($xpath, '//ul[@class="hosted-team"]')))->not->toContain('Acme Salon')
        ->and($html)->not->toContain('Ten years at the chair.')
        ->and($html)->not->toContain('Cy Ward')
        ->and($html)->not->toContain('Dee Park');
});

it('omits the team and its heading where no member is online, active and named, and counts the team as profile', function () {
    provisionTenant('acme', [], ['staff']);
    WeeklyHours::clear('tenant_acme');
    $ann = addMember('acme', 'Ann Lee', 'active', []);
    DB::table('tenant_acme.staff')->where('id', $ann)->update(['online' => false]);

    $xpath = businessXPath($this);

    expect($xpath->query('//section[@class="hosted-section"] | //*[@class="hosted-team"]'))->toHaveCount(0)
        ->and(textsAt($xpath, '//h2'))->toBe([pageSentence(BusinessPage::FLOW_TITLE)]);

    DB::table('tenant_acme.staff')->where('id', $ann)->update(['online' => true]);
    $xpath = businessXPath($this);

    expect(textsAt($xpath, '//section[@class="hosted-section"]/h2'))->toBe([pageSentence(BusinessPage::TEAM)])
        ->and(textsAt($xpath, '//section[@class="hosted-section"]//*[@class="hosted-team-name"]'))->toBe(['Ann Lee'])
        ->and(textsAt($xpath, '//section[@class="hosted-section"]//span[@class="hosted-face"]'))->toBe(['AL']);
});

it('closes the page with a footer of the name, My bookings while the bookings link is on, and the platform\'s name at the right', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    $page = app(TenantUrls::class)->myBookings(Slug::of('acme'));
    $by = pageSentence(BusinessPage::BOOKING_BY);

    $xpath = businessXPath($this);

    expect($xpath->query('/html/body/main/following-sibling::*[1][self::footer][@class="hosted-footer"]'))->toHaveCount(1)
        ->and(textsAt($xpath, '//footer/*'))->toBe(['Acme Salon', pageSentence(BusinessPage::MY_BOOKINGS), $by])
        ->and(attributesAt($xpath, '//footer/a', 'href'))->toBe([$page])
        ->and(attributesAt($xpath, '//footer/span[last()]', 'class'))->toBe(['hosted-by'])
        ->and(implode('', textsAt($xpath, '//style')))->toMatch('~\.hosted-footer \{[^}]*border-top: 1px solid #e5e7eb;~')
        // The footer: the page's width, its rule inside it, 24px above and below and 40px at the
        // sides.
        ->and(implode('', textsAt($xpath, '//style')))->toMatch('~\.hosted-footer \{[^}]*max-width: 1120px; margin: 0 auto; padding: 24px 40px;~')
        // The column, 1120px with its 40px sides inside, so its content sits 40px in from the
        // header's.
        ->and(implode('', textsAt($xpath, '//style')))->toMatch('~\.hosted-business \.hosted-main \{[^}]*box-sizing: border-box;[^}]*max-width: 1120px;~');

    settingsOf('acme')->update(['emails_off' => TextArray::encode(['my_bookings_link'])]);

    expect(textsAt(businessXPath($this), '//footer/*'))->toBe(['Acme Salon', $by]);
});

it('links the header to My bookings on the tenant\'s origin while the bookings link is on, and not while it is off', function () {
    provisionTenant('acme');
    $page = app(TenantUrls::class)->myBookings(Slug::of('acme'));

    expect(textsAt(businessXPath($this), '//header/a[@class="hosted-mine"][@href="'.$page.'"]'))->toBe([pageSentence(BusinessPage::MY_BOOKINGS)]);

    settingsOf('acme')->update(['emails_off' => TextArray::encode(['my_bookings_link'])]);
    $off = businessXPath($this);

    expect($off->query('//a[contains(@href, "/my-bookings")]'))->toHaveCount(0)
        ->and(textsAt($off, '//header//a[@href="#book"]'))->toBe([pageSentence(BusinessPage::BOOK_NOW)]);
});

it('carries no My bookings link on the manage page, which keeps the header of the logo and the name alone', function () {
    provisionTenant('acme');
    $link = app(TenantUrls::class)->manageLink(Slug::of('acme'), '0190a4a6-0000-7000-8000-000000000001', 'token');

    $xpath = new DOMXPath(documentOf($this->get($link)->assertOk()));

    expect($xpath->query('//a[contains(@href, "/my-bookings")]'))->toHaveCount(0);
});
