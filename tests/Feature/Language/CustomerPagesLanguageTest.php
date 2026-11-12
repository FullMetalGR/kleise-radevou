<?php

use App\Application\Clock;
use App\Domain\Audit\Actor;
use App\Http\BookingPage;
use App\Http\BusinessPage;
use App\Http\Controllers\MyBookingsController;
use App\Http\CustomerNotice;
use App\Http\PayHopAnswer;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\CustomerAccess;
use App\Infrastructure\Db\TextArray;
use App\Providers\AppServiceProvider;
use App\Site\SiteLanguage;
use App\Tenancy\PayLinks;
use App\Tenancy\Slug;
use App\Tenancy\TenantPages;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The tenant host's server pages in the request's language: every sentence a customer meets there
 * is a key of pages.php, read through the translator in the language the path's prefix names,
 * else the tenant default, and the business page's weekdays from ICU and its country from
 * countries.php in that language. The transport cases add a Greek line for the case alone, ASCII
 * and marked GREEK; the real Greek of ICU, of countries.php and of pages.php is read through the
 * formatter and the translator, never spelled. The walks read each page by the keys of pages.php
 * (linesShownIn): every key the page should show shown in Greek, no English line of the file left
 * but the shared, no :word unfilled, and the same route asked in English showing no Greek line. A
 * flashed sentence rides the flash as its key and is read in the language of the page that shows
 * it; the pay hop's redirect and the provider's return keep the hop's prefix.
 *
 * Not wrapped in a transaction: a hold enters the transaction runner. luna is the demo seeder's;
 * the clock is pinned before a hold is taken.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
    Http::preventStrayRequests();
    seamTransport()->flush();
});

afterEach(function () {
    Http::assertNothingSent();
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A weekday's name standing alone, as ICU spells it in a language, by its ISO number.
 */
function cplWeekday(int $weekday, SiteLanguage $language): string
{
    $formatter = new IntlDateFormatter($language->value, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, 'cccc');

    return (string) $formatter->format(new DateTimeImmutable(sprintf('2024-01-%02d', $weekday), new DateTimeZone('UTC')));
}

/**
 * @return list<string>
 */
function cplTexts(DOMDocument $document, string $query): array
{
    $texts = [];

    foreach ((new DOMXPath($document))->query($query) ?: [] as $node) {
        if ($node instanceof DOMNode) {
            $texts[] = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
        }
    }

    return $texts;
}

/**
 * The href of the first element the query finds, or null.
 */
function cplHref(DOMDocument $document, string $query): ?string
{
    $element = ((new DOMXPath($document))->query($query) ?: null)?->item(0);

    return $element instanceof DOMElement ? $element->getAttribute('href') : null;
}

/**
 * @param  TestResponse<Response>  $response
 */
function cplLang(TestResponse $response): ?string
{
    return preg_match('/<html lang="([a-z]+)">/', (string) $response->getContent(), $found) === 1 ? $found[1] : null;
}

/**
 * A hold of luna's online Colour taken through the API, as the booking page takes one, at the
 * fixture's start unless another is given, with the fake provider connected so the hop goes on
 * to it.
 *
 * @return array{uuid: string, token: string}
 */
function cplHold(TestCase $test, string $start = FixtureDay::START): array
{
    connectPaymentFake();
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'items' => [['service_id' => DB::table('tenant_luna.services')->where('name', 'Colour')->value('id'), 'staff_id' => DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id')]],
        'start' => (new DateTimeImmutable($start, new DateTimeZone(FixtureDay::ZONE)))->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    return ['uuid' => $held['uuid'], 'token' => $held['manage_token']];
}

it('renders the business page under /el in Greek: its sentences from pages.php, the weekdays from ICU standing alone, the country from countries.php, lang el and My bookings under /el, the structured data keeping the schema.org days', function () {
    settingsOf('luna')->update(['address_line1' => '12 Ermou Street', 'city' => 'Athens', 'postal_code' => '10563', 'country' => 'GR', 'website' => 'https://luna.example']);
    greekLines([
        'pages.business.book_now' => 'BOOK NOW',
        'pages.business.my_bookings' => 'MY BOOKINGS',
        'pages.business.hours' => 'HOURS',
        'pages.business.find_us' => 'FIND US',
        'pages.business.website' => 'WEBSITE',
        'pages.business.booking_by' => 'BOOKING BY :name',
        'pages.noscript' => 'NOSCRIPT',
    ]);

    $response = $this->get(tenantUrl('luna', '/el'))->assertOk();
    $document = documentOf($response);
    $days = cplTexts($document, '//ul[@class="hosted-hours"]/li/span[@class="hosted-day"]');
    $jsonLd = json_decode(cplTexts($document, '//script[@type="application/ld+json"]')[0] ?? '', true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($jsonLd));

    expect(cplLang($response))->toBe('el')
        ->and(cplTexts($document, '//header/a[@class="hosted-book"]'))->toBe(['GREEK BOOK NOW'])
        ->and(cplTexts($document, '//header/a[@class="hosted-mine"]'))->toBe(['GREEK MY BOOKINGS'])
        ->and(cplHref($document, '//header/a[@class="hosted-mine"]'))->toBe(app(TenantUrls::class)->myBookings(Slug::of('luna'), SiteLanguage::Greek))
        ->and(cplTexts($document, '//h2'))->toContain('GREEK HOURS')
        ->and(cplTexts($document, '//h2'))->toContain('GREEK FIND US')
        ->and(cplTexts($document, '//ul[@class="hosted-links"]/li/a'))->toContain('GREEK WEBSITE')
        ->and(cplTexts($document, '//footer/span[@class="hosted-by"]'))->toBe(['GREEK BOOKING BY '.siteName()])
        ->and(cplTexts($document, '//div[@class="kr-booking"]/noscript'))->toBe(['GREEK NOSCRIPT'])
        ->and($days)->toBe(array_map(fn (int $day): string => cplWeekday($day, SiteLanguage::Greek), [1, 2, 3, 4, 5, 6, 7]))
        ->and($days)->not->toContain(cplWeekday(1, SiteLanguage::English))
        ->and(cplTexts($document, '//address[@class="hosted-address"]/span'))->toBe(['12 Ermou Street', '10563 Athens', siteSentence('countries.GR', SiteLanguage::Greek)])
        ->and(data_get($jsonLd, 'openingHoursSpecification.0.dayOfWeek'))->toBe('https://schema.org/Monday')
        ->and(data_get($jsonLd, 'address.addressCountry'))->toBe('GR')
        ->and(app()->getLocale())->toBe('en');

    // The bare path is the tenant default, English here, its days and country English too.
    $bare = documentOf($this->get(tenantUrl('luna', '/'))->assertOk());

    expect(cplTexts($bare, '//ul[@class="hosted-hours"]/li/span[@class="hosted-day"]'))->toBe(array_map(fn (int $day): string => cplWeekday($day, SiteLanguage::English), [1, 2, 3, 4, 5, 6, 7]))
        ->and(cplTexts($bare, '//address[@class="hosted-address"]/span[last()]'))->toBe([siteSentence('countries.GR', SiteLanguage::English)])
        ->and(cplTexts($bare, '//header/a[@class="hosted-book"]'))->toBe([pageSentence(BusinessPage::BOOK_NOW)])
        ->and(cplHref($bare, '//header/a[@class="hosted-mine"]'))->toBe(app(TenantUrls::class)->myBookings(Slug::of('luna')));
});

it('renders the link page under /el in Greek, the session\'s thirty days spelled by ICU in the language its sentence is read in, the Greek of pages.php first and a fabricated line after it', function () {
    mybBooking(MYB_CASEY, '2026-11-12 08:00:00');
    $this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => MYB_CASEY])->assertRedirect();
    $token = mybTokenIn((string) seamAt(MYB_CASEY)->getTextBody());
    $days = (new DateInterval(CustomerAccess::SESSION_LIFETIME))->d;

    $written = $this->get(tenantUrl('luna', '/el/my-bookings/'.$token))->assertOk();

    expect(cplLang($written))->toBe('el')
        ->and(cplTexts(documentOf($written), '//main/p'))->toBe([mybOpenSentence(SiteLanguage::Greek)]);

    greekLines(['pages.link.title' => 'BOOKINGS AT :business', 'pages.link.label' => 'SHOW']);
    // A choice line, so the marker sits inside each of its cases.
    app('translator')->addLines(['pages.link.sentence' => '{1} GREEK ONE DAY|[2,*] GREEK :count DAYS'], 'el');
    $greek = documentOf($this->get(tenantUrl('luna', '/el/my-bookings/'.$token))->assertOk());

    expect(cplTexts($greek, '//title'))->toBe(['GREEK BOOKINGS AT Luna Hair Studio'])
        ->and(cplTexts($greek, '//main/p'))->toBe(['GREEK '.(new NumberFormatter('el', NumberFormatter::SPELLOUT))->format($days).' DAYS'])
        ->and(cplTexts($greek, '//form/button'))->toBe(['GREEK SHOW']);
});

it('reads a flashed sentence in the language of the page that shows it, whichever address flashed it, and the link\'s refusal past its limit in the request\'s', function () {
    greekLines(['pages.my_bookings.flashes.signed_out' => 'SIGNED OUT', 'pages.my_bookings.refusals.too_many' => 'TOO MANY']);
    $flash = [MyBookingsController::FLASH => ['kind' => 'success', 'message' => MyBookingsController::SIGNED_OUT]];

    expect(mybInlined(documentOf($this->withSession($flash)->get(tenantUrl('luna', '/el/my-bookings'))->assertOk()))['notice'] ?? null)->toBe('GREEK SIGNED OUT')
        ->and(mybInlined(documentOf($this->withSession($flash)->get(tenantUrl('luna', '/my-bookings'))->assertOk()))['notice'] ?? null)->toBe(pageSentence(MyBookingsController::SIGNED_OUT));

    // The sign-out under the bare path lands on the bare page in its language, under /el on /el's.
    $this->post(tenantUrl('luna', '/my-bookings/sign-out'))->assertRedirect(tenantUrl('luna', '/my-bookings'))
        ->assertSessionHas(CustomerNotice::KEY, ['kind' => 'success', 'message' => MyBookingsController::SIGNED_OUT]);
    $this->post(tenantUrl('luna', '/el/my-bookings/sign-out'))->assertRedirect(tenantUrl('luna', '/el/my-bookings'));

    expect(mybInlined(documentOf($this->get(tenantUrl('luna', '/el/my-bookings'))->assertOk()))['notice'] ?? null)->toBe('GREEK SIGNED OUT');

    for ($sent = 0; $sent < AppServiceProvider::MY_BOOKINGS_LINK_REQUESTS_PER_MINUTE; $sent++) {
        $this->post(tenantUrl('luna', '/el/my-bookings/link'), ['email' => 'limited@example.test'])->assertRedirect();
    }

    $refused = $this->post(tenantUrl('luna', '/el/my-bookings/link'), ['email' => 'limited@example.test'])->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
    $bare = $this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => 'limited@example.test'])->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);

    expect(mybInlined(documentOf($refused))['notice'] ?? null)->toBe('GREEK TOO MANY')
        ->and(cplLang($refused))->toBe('el')
        ->and(mybInlined(documentOf($bare))['notice'] ?? null)->toBe(pageSentence(MyBookingsController::TOO_MANY))
        ->and(app()->getLocale())->toBe('en');
});

it('keeps the pay hop\'s prefix on its redirect and on the provider\'s return, and the hop\'s flash reaches the manage page under /el in Greek', function () {
    greekLines(['pages.pay.flashes.on_its_way' => 'ON ITS WAY']);
    $held = cplHold($this);
    $back = app(TenantUrls::class)->manageLink(Slug::of('luna'), $held['uuid'], $held['token'], SiteLanguage::Greek);
    $hop = tenantUrl('luna', TenantPages::uri('/pay/'.$held['uuid'], SiteLanguage::Greek)).'?token='.$held['token'];

    $this->get($hop)->assertStatus(303)->assertRedirect($back.'&paydesk_session=pd_1');

    expect(paymentFake()->sessions['pd_1']['returnUrl'] ?? null)->toBe($back);

    paymentFake()->completed[] = 'pd_1';
    $this->get($hop)->assertStatus(303)->assertRedirect($back)
        ->assertSessionHas(CustomerNotice::KEY, ['kind' => 'info', 'message' => PayHopAnswer::PAYMENT_ON_ITS_WAY]);
    $landed = documentOf($this->get($back)->assertOk())->getElementById(BookingPage::NOTICE_ID)?->textContent;

    expect(json_decode((string) $landed, true))->toBe(['notice' => 'GREEK ON ITS WAY']);

    // A settled booking goes back to its manage page under the hop's prefix too.
    DB::update("update tenant_luna.bookings set status = 'confirmed', hold_class = null, hold_expires_at = null where uuid = ?", [$held['uuid']]);

    $this->get($hop)->assertStatus(303)->assertRedirect($back);
    $this->get(tenantUrl('luna', '/en/pay/'.$held['uuid']).'?token='.$held['token'])->assertStatus(303)
        ->assertRedirect(app(TenantUrls::class)->manageLink(Slug::of('luna'), $held['uuid'], $held['token'], SiteLanguage::English));
});

it('renders the pay hop\'s refusal and the suspended page under /el in Greek with lang el, the suspended page read before the tenant is bound', function () {
    greekLines([
        'pages.pay.refused.title' => 'LINK DOES NOT WORK',
        'pages.pay.refused.sentence' => 'CHANGED ON ITS WAY',
        'pages.suspended.title' => 'PAUSED',
        'pages.suspended.heading' => 'PAUSED HEADING',
        'pages.suspended.sentence' => 'NOT AVAILABLE',
    ]);
    $booking = mybBooking(MYB_CASEY, '2026-11-12 08:00:00');

    $refused = $this->get(tenantUrl('luna', '/el/pay/'.$booking))->assertStatus(Response::HTTP_BAD_REQUEST);
    $document = documentOf($refused);

    expect(cplLang($refused))->toBe('el')
        ->and(cplTexts($document, '//title'))->toBe(['GREEK LINK DOES NOT WORK'])
        ->and(cplTexts($document, '//main/*'))->toBe(['GREEK LINK DOES NOT WORK', 'GREEK CHANGED ON ITS WAY'])
        ->and(cplTexts(documentOf($this->get(tenantUrl('luna', '/pay/'.$booking))->assertStatus(Response::HTTP_BAD_REQUEST)), '//title'))->toBe([pageSentence('pages.pay.refused.title')]);

    DB::table('tenants')->where('slug', 'luna')->update(['status' => 'suspended']);
    $suspended = $this->get(tenantUrl('luna', '/el/my-bookings'))->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE);
    $document = documentOf($suspended);

    expect(cplLang($suspended))->toBe('el')
        ->and(cplTexts($document, '//title'))->toBe(['GREEK PAUSED'])
        ->and(cplTexts($document, '//main/*'))->toBe(['GREEK PAUSED HEADING', 'GREEK NOT AVAILABLE'])
        ->and(cplTexts(documentOf($this->get(tenantUrl('luna', '/'))->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)), '//main/*'))->toBe([pageSentence('pages.suspended.heading'), pageSentence('pages.suspended.sentence')]);
});

it('reads the payment link\'s status page under /el in Greek, its words chosen by the booking as it stands', function () {
    greekLines(['pages.pay.confirmed' => 'CONFIRMED', 'pages.pay.nothing_more' => 'NOTHING MORE']);
    $booking = mybBooking(MYB_CASEY, '2026-11-12 08:00:00');
    $end = new DateTimeImmutable('2026-11-12 08:30:00', new DateTimeZone('UTC'));
    $link = app(PayLinks::class)->link(mybTenant(), $booking, $end, SiteLanguage::Greek);

    $page = $this->get($link)->assertOk();
    $document = documentOf($page);

    expect(cplLang($page))->toBe('el')
        ->and(cplTexts($document, '//main/*'))->toBe(['GREEK CONFIRMED', 'Luna Hair Studio', 'GREEK NOTHING MORE']);
});

/**
 * What a walk by the keys of pages.php reads off a page: its lang, the keys it shows in Greek, the
 * keys it shows in English, the shared left out of both, and every :word it leaves unfilled.
 *
 * @param  TestResponse<Response>  $response
 * @return array{lang: string|null, greek: list<string>, english: list<string>, unfilled: list<string>}
 */
function cplWalk(TestResponse $response): array
{
    $text = shownTextOf($response);

    return ['lang' => cplLang($response), 'greek' => linesShownIn($text, 'pages', SiteLanguage::Greek), 'english' => linesShownIn($text, 'pages', SiteLanguage::English), 'unfilled' => unfilledIn($text)];
}

/**
 * The walk's verdict on a page wholly in Greek: lang el, no English line of pages.php left, no
 * :word unfilled, and each of the keys named shown in Greek.
 *
 * @param  TestResponse<Response>  $response
 * @param  list<string>  $keys  under pages.
 */
function cplWalkedGreek(TestResponse $response, array $keys, string $page): void
{
    $walk = cplWalk($response);

    expect($walk['lang'])->toBe('el', $page)
        ->and($walk['english'])->toBe([], $page)
        ->and($walk['unfilled'])->toBe([], $page)
        ->and(array_values(array_diff($keys, $walk['greek'])))->toBe([], $page);
}

/**
 * The walk's verdict on the same page asked in English: lang en and no Greek line of pages.php.
 *
 * @param  TestResponse<Response>  $response
 */
function cplWalkedEnglish(TestResponse $response, string $page): void
{
    $walk = cplWalk($response);

    expect($walk['lang'])->toBe('en', $page)
        ->and($walk['greek'])->toBe([], $page);
}

/**
 * The about text of luna's full profile: the tenant's, never translated.
 */
const CPL_ABOUT = 'Luna Hair Studio, since 1998.';

/**
 * luna's profile with every field the business page renders: the address, the contact pills, an
 * about text, a cover and five gallery photos, so the grid shows fewer than the gallery holds, and
 * a ready map.
 */
function cplFullProfile(): void
{
    settingsOf('luna')->update([
        'about' => CPL_ABOUT,
        'address_line1' => '12 Ermou Street',
        'city' => 'Athens',
        'postal_code' => '10563',
        'country' => 'GR',
        'phone' => '+30 210 1234567',
        'public_email' => 'hello@luna.example',
        'website' => 'https://luna.example',
        'instagram' => 'https://instagram.com/luna',
        'facebook' => 'https://facebook.com/luna',
        'google_reviews_url' => 'https://g.page/r/luna/review',
        'cover_path' => 'tenants/luna/cover-aaaa.jpg',
        'gallery_paths' => TextArray::encode(array_map(fn (string $file): string => 'tenants/luna/gallery-'.$file.'.jpg', ['aaaa', 'bbbb', 'cccc', 'dddd', 'eeee'])),
        'lat' => 37.9763295,
        'lng' => 23.7323814,
        'map_path' => 'tenants/luna/map-0123456789abcdef.png',
        'map_state' => 'ready',
    ]);
}

/**
 * The keys of pages.php each walk below shows in Greek, by the page or the flashes it walks, the
 * business page's own set apart: the business walk holds that set to the file's business group
 * and noscript itself. The last case holds the business set and these together to the file.
 *
 * @return array<string, list<string>>
 */
function cplKeysWalked(): array
{
    $flash = fn (string $key): string => substr($key, strlen('pages.'));

    return [
        'the link page' => ['link.title', 'link.sentence', 'link.label'],
        'My bookings\' flashes and refusal' => [$flash(MyBookingsController::SENT), $flash(MyBookingsController::INVALID), $flash(MyBookingsController::SIGNED_OUT), $flash(MyBookingsController::TOO_MANY)],
        'the status page waiting for its payment' => ['pay.pay_now', 'pay.waiting.title', 'pay.waiting.approved', 'pay.waiting.just_paid'],
        'the status page confirmed' => ['pay.confirmed', 'pay.nothing_more'],
        'the status page past' => ['pay.passed', 'pay.nothing_more'],
        'the status page nothing to pay' => ['pay.nothing_to_pay', 'pay.nothing_more'],
        'the status page no longer held' => ['pay.ended.title', 'pay.ended.given_back'],
        'the refusal' => ['pay.refused.title', 'pay.refused.sentence'],
        'the unopened page' => ['pay.unopened.title', 'pay.unopened.charged', 'pay.unopened.again'],
        'the closed page' => ['pay.closed.title', 'pay.closed.held', 'pay.closed.given_back'],
        'the ended page' => ['pay.ended.title', 'pay.ended.given_back', 'pay.ended.again'],
        'the hop\'s flashes' => [$flash(PayHopAnswer::PAYMENT_ON_ITS_WAY), $flash(PayHopAnswer::PAY_AT_THE_VENUE), $flash(PayHopAnswer::SETTLEMENT_LOST)],
        'the suspended page' => ['suspended.title', 'suspended.heading', 'suspended.sentence'],
    ];
}

it('walks the business page in Greek by the keys of pages.php: every business key and the noscript line shown in Greek across the three open states, the weekdays from ICU, the country, the map\'s alt and the photo count in Greek, and the English request showing no Greek', function (bool $greekDefault) {
    cplFullProfile();

    if ($greekDefault) {
        settingsOf('luna')->update(['language' => 'el']);
    }

    $address = tenantUrl('luna', $greekDefault ? '/' : '/el');
    // Monday 2 November on luna's wall: the day's 09:00 to 18:00 holding the instant, still to
    // come, and ended.
    $states = [
        '2026-11-02 10:00' => pageSentence(BusinessPage::OPEN_UNTIL, ['time' => '18:00'], SiteLanguage::Greek),
        '2026-11-02 08:00' => pageSentence(BusinessPage::OPENS_AT, ['time' => '09:00'], SiteLanguage::Greek),
        '2026-11-02 19:00' => pageSentence(BusinessPage::CLOSED_TODAY, [], SiteLanguage::Greek),
    ];
    $shown = [];

    foreach ($states as $wall => $state) {
        app()->instance(Clock::class, FixedClock::at($wall, FixtureDay::ZONE));
        $response = $this->get($address)->assertOk();
        $walk = cplWalk($response);
        $document = documentOf($response);

        expect($walk['lang'])->toBe('el', $wall)
            ->and($walk['english'])->toBe([], $wall)
            ->and($walk['unfilled'])->toBe([], $wall)
            ->and(shownTextOf($response))->toContain($state);
        $shown = [...$shown, ...$walk['greek']];
    }

    $business = array_map(fn (string $key): string => 'business.'.$key, array_keys(Arr::dot((array) __('pages.business', [], 'en'))));
    $shared = ['business.instagram', 'business.facebook'];

    expect(array_values(array_diff([...$business, 'noscript'], $shared, $shown)))->toBe([])
        ->and(array_values(array_diff($shown, [...$business, 'noscript'])))->toBe([])
        ->and(cplTexts($document, '//ul[@class="hosted-hours"]/li/span[@class="hosted-day"]'))->toBe(array_map(fn (int $day): string => cplWeekday($day, SiteLanguage::Greek), [1, 2, 3, 4, 5, 6, 7]))
        ->and(cplTexts($document, '//address[@class="hosted-address"]/span[last()]'))->toBe([siteSentence('countries.GR', SiteLanguage::Greek)])
        ->and(cplTexts($document, '//a[@class="hosted-more"]'))->toBe([pageChoice(BusinessPage::SEE_ALL, 5, [], SiteLanguage::Greek)])
        ->and(shownTextOf($this->get($address)))->toContain(pageSentence(BusinessPage::MAP, [], SiteLanguage::Greek))
        ->and(shownTextOf($this->get($address)))->toContain(pageSentence(BusinessPage::PHOTO, ['n' => '1', 'count' => '5'], SiteLanguage::Greek))
        ->and(shownTextOf($this->get($address)))->toContain(CPL_ABOUT);

    // The same page asked in English shows no Greek line: /en on either road.
    cplWalkedEnglish($this->get(tenantUrl('luna', '/en'))->assertOk(), 'the business page under /en');
})->with([
    'the bare path of a tenant whose default is Greek' => [true],
    '/el of a tenant whose default is English' => [false],
]);

it('walks the link page and My bookings\' flashes and refusal in Greek by the keys of pages.php, and the English twins in English', function () {
    mybBooking(MYB_CASEY, '2026-11-12 08:00:00');
    $this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => MYB_CASEY])->assertRedirect();
    $token = mybTokenIn((string) seamAt(MYB_CASEY)->getTextBody());

    cplWalkedGreek($this->get(tenantUrl('luna', '/el/my-bookings/'.$token))->assertOk(), cplKeysWalked()['the link page'], 'the link page');
    cplWalkedEnglish($this->get(tenantUrl('luna', '/my-bookings/'.$token))->assertOk(), 'the link page');

    foreach ([MyBookingsController::SENT, MyBookingsController::INVALID, MyBookingsController::SIGNED_OUT] as $key) {
        $flash = [MyBookingsController::FLASH => ['kind' => 'success', 'message' => $key]];

        expect(mybInlined(documentOf($this->withSession($flash)->get(tenantUrl('luna', '/el/my-bookings'))->assertOk()))['notice'] ?? null)->toBe(pageSentence($key, [], SiteLanguage::Greek))
            ->and(mybInlined(documentOf($this->withSession($flash)->get(tenantUrl('luna', '/my-bookings'))->assertOk()))['notice'] ?? null)->toBe(pageSentence($key));
    }

    for ($sent = 0; $sent < AppServiceProvider::MY_BOOKINGS_LINK_REQUESTS_PER_MINUTE; $sent++) {
        $this->post(tenantUrl('luna', '/el/my-bookings/link'), ['email' => 'limited@example.test'])->assertRedirect();
    }

    expect(mybInlined(documentOf($this->post(tenantUrl('luna', '/el/my-bookings/link'), ['email' => 'limited@example.test'])->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)))['notice'] ?? null)
        ->toBe(pageSentence(MyBookingsController::TOO_MANY, [], SiteLanguage::Greek))
        ->and(mybInlined(documentOf($this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => 'limited@example.test'])->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)))['notice'] ?? null)
        ->toBe(pageSentence(MyBookingsController::TOO_MANY));
});

it('walks the payment link\'s status page in Greek by the keys of pages.php, its words for each state the booking stands in, and the English link in English', function () {
    $end = new DateTimeImmutable('2026-11-12 08:30:00', new DateTimeZone('UTC'));
    $held = cplHold($this);
    $cancelled = cplHold($this, FixtureDay::DATE.' 15:00');
    DB::update("update tenant_luna.bookings set status = 'cancelled', hold_class = null, hold_expires_at = null where uuid = ?", [$cancelled['uuid']]);
    $states = [
        'waiting for its payment' => $held['uuid'],
        'confirmed' => mybBooking(MYB_CASEY, '2026-11-12 08:00:00'),
        'past' => mybBooking(MYB_CASEY, '2026-11-12 08:00:00', 'completed'),
        'nothing to pay' => LunaFixture::hold(['Bridal trial'], '2026-11-04 10:00'),
        'no longer held' => $cancelled['uuid'],
    ];

    foreach ($states as $state => $booking) {
        cplWalkedGreek($this->get(app(PayLinks::class)->link(mybTenant(), $booking, $end, SiteLanguage::Greek))->assertOk(), cplKeysWalked()['the status page '.$state], 'the status page '.$state);
        cplWalkedEnglish($this->get(app(PayLinks::class)->link(mybTenant(), $booking, $end, SiteLanguage::English))->assertOk(), 'the status page '.$state);
    }
});

it('walks the pay hop\'s four worded pages and its three flashes in Greek by the keys of pages.php, and their English twins in English', function () {
    Exceptions::fake();
    $hop = fn (string $uuid, string $token, ?SiteLanguage $language): string => tenantUrl('luna', TenantPages::uri('/pay/'.$uuid, $language)).'?token='.$token;

    // Refused: no token.
    $refused = cplHold($this);
    cplWalkedGreek($this->get(tenantUrl('luna', '/el/pay/'.$refused['uuid']))->assertStatus(Response::HTTP_BAD_REQUEST), cplKeysWalked()['the refusal'], 'the refusal');
    cplWalkedEnglish($this->get(tenantUrl('luna', '/pay/'.$refused['uuid']))->assertStatus(Response::HTTP_BAD_REQUEST), 'the refusal');

    // Unopened: the provider failed.
    paymentFake()->failWith = 'the provider failed';
    cplWalkedGreek($this->get($hop($refused['uuid'], $refused['token'], SiteLanguage::Greek))->assertStatus(Response::HTTP_BAD_GATEWAY), cplKeysWalked()['the unopened page'], 'the unopened page');
    cplWalkedEnglish($this->get($hop($refused['uuid'], $refused['token'], null))->assertStatus(Response::HTTP_BAD_GATEWAY), 'the unopened page');
    paymentFake()->failWith = null;

    // Closed: a move cleared the session and the hold is too short for another.
    $this->get($hop($refused['uuid'], $refused['token'], null))->assertStatus(303);
    app(BookingTransitionFactory::class)->reschedule(LunaFixture::tenant())->reschedule($refused['uuid'], Actor::customer(), LunaFixture::at('2026-11-03 15:00'));
    paymentFake()->laterSessionTooShort = true;
    cplWalkedGreek($this->get($hop($refused['uuid'], $refused['token'], SiteLanguage::Greek))->assertStatus(Response::HTTP_CONFLICT), cplKeysWalked()['the closed page'], 'the closed page');
    cplWalkedEnglish($this->get($hop($refused['uuid'], $refused['token'], null))->assertStatus(Response::HTTP_CONFLICT), 'the closed page');

    // Ended: the hold was cancelled.
    DB::update("update tenant_luna.bookings set status = 'cancelled', hold_class = null, hold_expires_at = null where uuid = ?", [$refused['uuid']]);
    cplWalkedGreek($this->get($hop($refused['uuid'], $refused['token'], SiteLanguage::Greek))->assertStatus(Response::HTTP_CONFLICT), cplKeysWalked()['the ended page'], 'the ended page');
    cplWalkedEnglish($this->get($hop($refused['uuid'], $refused['token'], null))->assertStatus(Response::HTTP_CONFLICT), 'the ended page');

    // The three flashes, each read by the manage page in its language.
    $manage = fn (?SiteLanguage $language): string => app(TenantUrls::class)->manageLink(Slug::of('luna'), $refused['uuid'], $refused['token'], $language);
    $notice = fn (string $key, ?SiteLanguage $language): mixed => json_decode((string) documentOf($this->withSession([CustomerNotice::KEY => ['kind' => 'info', 'message' => $key]])->get($manage($language))->assertOk())->getElementById(BookingPage::NOTICE_ID)?->textContent, true);

    foreach ([PayHopAnswer::PAYMENT_ON_ITS_WAY, PayHopAnswer::PAY_AT_THE_VENUE, PayHopAnswer::SETTLEMENT_LOST] as $key) {
        expect($notice($key, SiteLanguage::Greek))->toBe(['notice' => pageSentence($key, [], SiteLanguage::Greek)])
            ->and($notice($key, SiteLanguage::English))->toBe(['notice' => pageSentence($key)]);
    }
});

it('walks the suspended page in Greek by the keys of pages.php under /el, and in English at the bare path', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['status' => 'suspended']);

    cplWalkedGreek($this->get(tenantUrl('luna', '/el/my-bookings'))->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE), cplKeysWalked()['the suspended page'], 'the suspended page');
    cplWalkedEnglish($this->get(tenantUrl('luna', '/my-bookings'))->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE), 'the suspended page');
});

it('reaches every key of pages.php by the walks together: the business page\'s set and the keys each other walk shows', function () {
    $business = array_map(fn (string $key): string => 'business.'.$key, array_keys(Arr::dot((array) __('pages.business', [], 'en'))));

    expect(array_values(array_unique([...$business, 'noscript', ...array_merge(...array_values(cplKeysWalked()))])))
        ->toEqualCanonicalizing(array_keys(Arr::dot((array) __('pages', [], 'en'))));
});
