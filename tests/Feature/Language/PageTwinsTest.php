<?php

use App\Domain\Enum\Decision;
use App\Http\MyBookingsCookie;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\CustomerAccess;
use App\Providers\AppServiceProvider;
use App\Site\SiteLanguage;
use App\Tenancy\DecisionLinks;
use App\Tenancy\PayLinks;
use App\Tenancy\TenantPages;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The tenant host's pages at their bare path and under /en and /el, over luna's real rows: each
 * page answers the same status and the same body at all three but its lang, the language the
 * hosted page's config names, the address a signed page's form posts back to, and the business
 * page's My bookings link and the names it spells in its language; the bare path is the tenant
 * default; a prefix names its language. A token path under a prefix keeps no credential in the
 * session, the pay hop's refusal renders under a prefix, and a POST twin acts as its bare route
 * does, My bookings' coming back under its prefix.
 *
 * Not wrapped in a transaction: the holds and the decision enter the transaction runner. The
 * clock is the system's and every date is relative to today: the links are minted and read
 * against it, and the requests are placed on the first whole availability window.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    seamTransport()->flush();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A response's body with what a page's language changes taken out: its lang, the language its
 * inlined config names, the address its form posts back to, which a signed page re-mints under
 * its own prefix, the business page's My bookings link, which keeps the page's prefix, the names
 * the business page spells in its language, the weekdays ICU names and the country names of
 * countries.php, and the decision page's instants and its total's amount, which ICU spells in
 * the page's language, the month's name and the decimal separator, and the lines of pages.php
 * and decision.php in either language with the session's length its link page spells in words.
 *
 * @param  TestResponse<Response>  $response
 */
function twinBody(TestResponse $response): string
{
    $body = (string) preg_replace(
        ['/<html lang="(?:en|el)">/', '/"language":"(?:en|el)"/', '/ action="[^"]*"/', '#href="([^"]*)/(?:en|el)/my-bookings"#', '#<div class="when">[^<]*</div>#', '#<p class="total">[^<]*</p>#'],
        ['<html lang="">', '"language":""', ' action=""', 'href="$1/my-bookings"', '<div class="when"></div>', '<p class="total"></p>'],
        (string) $response->getContent(),
    );

    return str_replace(twinNames(), '', str_replace(twinLines(), '', $body));
}

/**
 * The lines of pages.php and decision.php in both languages as a page prints them, each case of a
 * choice with its condition stripped and cut at its placeholders, whose values both languages fill
 * alike, longest first so a line is taken out whole before a shorter one inside it.
 *
 * @return list<string>
 */
function twinLines(): array
{
    $parts = [];

    foreach (SiteLanguage::cases() as $language) {
        foreach (['pages.php', 'decision.php'] as $file) {
            foreach (Arr::dot((array) require lang_path($language->value.'/'.$file)) as $line) {
                if (! is_string($line)) {
                    continue;
                }

                foreach (explode('|', $line) as $form) {
                    $parts = [...$parts, ...(preg_split('/:[A-Za-z][A-Za-z0-9_]*/', (string) preg_replace('/^\s*[{[][^}\]]*[}\]]\s*/', '', $form)) ?: [])];
                }
            }
        }
    }

    $parts = array_values(array_unique(array_filter($parts, fn (string $part): bool => trim($part) !== '')));
    usort($parts, fn (string $one, string $other): int => strlen($other) <=> strlen($one));

    return $parts;
}

/**
 * The names the business page spells in its language, in both languages: the seven weekdays
 * standing alone as ICU names them, the session's length the link page spells in words, and the
 * countries of countries.php.
 *
 * @return list<string>
 */
function twinNames(): array
{
    $names = [];

    foreach (SiteLanguage::cases() as $language) {
        $formatter = new IntlDateFormatter($language->value, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, 'cccc');

        for ($day = 1; $day <= 7; $day++) {
            $names[] = (string) $formatter->format(new DateTimeImmutable(sprintf('2024-01-%02d', $day), new DateTimeZone('UTC')));
        }

        $names[] = (string) (new NumberFormatter($language->value, NumberFormatter::SPELLOUT))->format((new DateInterval(CustomerAccess::SESSION_LIFETIME))->d);
        $countries = __('countries', [], $language->value);

        foreach (is_array($countries) ? $countries : [] as $name) {
            $names[] = is_string($name) ? $name : '';
        }
    }

    return array_values(array_filter($names, fn (string $name): bool => $name !== ''));
}

/**
 * The lang the response's root element carries.
 *
 * @param  TestResponse<Response>  $response
 */
function twinLang(TestResponse $response): ?string
{
    return preg_match('/<html lang="([a-z]+)">/', (string) $response->getContent(), $found) === 1 ? $found[1] : null;
}

/**
 * The live My bookings link for an address with a booking at luna, minted through the link POST
 * as the page mints one, and the token the message carried.
 */
function twinMintedToken(TestCase $test): string
{
    mybBooking(MYB_CASEY, firstWindowDay(2)->setTime(15, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    $test->post(tenantUrl('luna', '/my-bookings/link'), ['email' => MYB_CASEY])->assertRedirect(tenantUrl('luna', '/my-bookings'));

    return mybTokenIn((string) seamAt(MYB_CASEY)->getTextBody());
}

/**
 * A request awaiting the owner's decision, on the first whole availability window, and the
 * decision link under the prefix given, as the approval request would mint it.
 */
function twinDecisionLink(string $uuid, ?SiteLanguage $prefix): string
{
    $booking = app(BookingReader::class)->snapshot(LunaFixture::tenant(), $uuid);
    assert($booking?->holdExpiresAt instanceof DateTimeImmutable);

    return app(DecisionLinks::class)->link(LunaFixture::tenant(), $uuid, Decision::Approve, tenantUserId(), $booking->holdExpiresAt, $prefix);
}

/**
 * The three addresses of a page and the prefix each carries.
 *
 * @return array<string, SiteLanguage|null>
 */
function twinPrefixes(): array
{
    return ['bare' => null, 'en' => SiteLanguage::English, 'el' => SiteLanguage::Greek];
}

it('answers every page at its bare path, /en and /el with one status and one body but the lang, the bare path in the tenant default and each prefix in its language', function () {
    // luna chose Greek for itself, which the bare path reads.
    settingsOf('luna')->update(['language' => 'el']);
    $default = 'el';
    $booking = LunaFixture::booking('confirmed', start: firstWindowDay(4)->setTime(10, 0)->format('Y-m-d H:i'));
    $request = LunaFixture::hold(['Bridal trial'], firstWindowDay(2)->setTime(9, 0)->format('Y-m-d H:i'));
    $lastEnd = app(BookingReader::class)->snapshot(LunaFixture::tenant(), $booking)?->endsAt();
    assert($lastEnd instanceof DateTimeImmutable);
    $token = twinMintedToken($this);

    $pages = [
        'the business page' => fn (?SiteLanguage $prefix): string => tenantUrl('luna', TenantPages::uri('/', $prefix)),
        'the manage page' => fn (?SiteLanguage $prefix): string => tenantUrl('luna', TenantPages::uri('/manage/'.$booking, $prefix)).'?token=x',
        'My bookings' => fn (?SiteLanguage $prefix): string => tenantUrl('luna', TenantPages::uri('/my-bookings', $prefix)),
        'the link page' => fn (?SiteLanguage $prefix): string => tenantUrl('luna', TenantPages::uri('/my-bookings/'.$token, $prefix)),
        'the pay hop with no token' => fn (?SiteLanguage $prefix): string => tenantUrl('luna', TenantPages::uri('/pay/'.$booking, $prefix)),
        'the pay hop with a wrong token' => fn (?SiteLanguage $prefix): string => tenantUrl('luna', TenantPages::uri('/pay/'.$booking, $prefix)).'?token=x',
        'the payment link\'s page' => fn (?SiteLanguage $prefix): string => app(PayLinks::class)->link(LunaFixture::tenant(), $booking, $lastEnd, $prefix),
        'the decision page' => fn (?SiteLanguage $prefix): string => twinDecisionLink($request, $prefix),
    ];

    // What each page answers at every address: the pages, and the pay hop's two refusals.
    $statuses = ['the pay hop with no token' => Response::HTTP_BAD_REQUEST, 'the pay hop with a wrong token' => Response::HTTP_NOT_FOUND];

    foreach ($pages as $page => $address) {
        $answers = [];

        foreach (twinPrefixes() as $label => $prefix) {
            $response = $this->get($address($prefix));
            $answers[$label] = [$response->getStatusCode(), twinBody($response)];

            expect(twinLang($response))->toBe($prefix->value ?? $default, $page.' '.$label)
                ->and(app()->getLocale())->toBe('en', $page.' '.$label.' left the locale behind');
        }

        expect(array_unique(array_column($answers, 0)))->toBe([$statuses[$page] ?? Response::HTTP_OK], $page)
            ->and(array_unique(array_column($answers, 1)))->toHaveCount(1, $page);
    }

    // The business page's config names the request's language beside the tenant default.
    foreach (twinPrefixes() as $prefix) {
        expect(configOf(documentOf($this->get($pages['the business page']($prefix)))))->toMatchArray(['default_language' => $default, 'language' => $prefix->value ?? $default]);
    }

    // A signed page's form posts back under the prefix the page was opened at.
    foreach (twinPrefixes() as $prefix) {
        preg_match('/ action="([^"]*)"/', (string) $this->get($pages['the decision page']($prefix))->getContent(), $action);

        expect(html_entity_decode($action[1] ?? ''))->toStartWith(tenantUrl('luna', TenantPages::uri('/decide/'.$request.'/approve', $prefix)).'?');
    }
});

it('keeps no credential of a token path under a prefix in the session, the previous URL kept on a page that carries none', function () {
    $booking = LunaFixture::booking('confirmed', start: firstWindowDay(4)->setTime(10, 0)->format('Y-m-d H:i'));
    $lastEnd = app(BookingReader::class)->snapshot(LunaFixture::tenant(), $booking)?->endsAt();
    assert($lastEnd instanceof DateTimeImmutable);
    $token = twinMintedToken($this);
    $signed = app(PayLinks::class)->link(LunaFixture::tenant(), $booking, $lastEnd, SiteLanguage::Greek);
    parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);
    $signature = $query['signature'] ?? null;
    assert(is_string($signature));

    $this->get(tenantUrl('luna', '/el/my-bookings/'.$token))->assertOk();
    $linkPage = (string) session()->previousUrl();
    $this->get(tenantUrl('luna', '/en/manage/'.$booking.'?token=the-secret'))->assertOk();
    $managePage = (string) session()->previousUrl();
    $this->get(tenantUrl('luna', '/el/pay/'.$booking.'?token=the-secret'));
    $payHop = (string) session()->previousUrl();
    $this->get($signed)->assertOk();
    $payLink = (string) session()->previousUrl();
    $this->get(tenantUrl('luna', '/el/my-bookings'))->assertOk();

    expect($linkPage)->not->toContain($token)
        ->and($managePage)->not->toContain('the-secret')
        ->and($payHop)->not->toContain('the-secret')
        ->and($payLink)->not->toContain($signature)
        ->and(session()->previousUrl())->toBe(tenantUrl('luna', '/el/my-bookings'));
});

it('renders the pay hop\'s refusal under either prefix as at the bare path, 400 for no token and 404 for a wrong one, in the prefix\'s lang', function () {
    $booking = LunaFixture::booking('confirmed', start: firstWindowDay(4)->setTime(10, 0)->format('Y-m-d H:i'));

    foreach (['' => Response::HTTP_BAD_REQUEST, '?token=x' => Response::HTTP_NOT_FOUND] as $query => $status) {
        $bare = $this->get(tenantUrl('luna', '/pay/'.$booking.$query))->assertStatus($status);

        foreach (SiteLanguage::cases() as $language) {
            $twin = $this->get(tenantUrl('luna', TenantPages::uri('/pay/'.$booking, $language).$query))->assertStatus($status);

            expect(twinLang($twin))->toBe($language->value)
                ->and(twinBody($twin))->toBe(twinBody($bare));
        }
    }
});

it('acts on a POST twin as on its bare route: the link POST mints and mails a link, the link\'s button opens the session, each back under its prefix, and the decision form decides', function () {
    mybBooking(MYB_CASEY, firstWindowDay(2)->setTime(15, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

    $this->post(tenantUrl('luna', '/el/my-bookings/link'), ['email' => MYB_CASEY])->assertRedirect(tenantUrl('luna', '/el/my-bookings'));
    $token = mybTokenIn((string) seamAt(MYB_CASEY)->getTextBody());
    $opened = $this->post(tenantUrl('luna', '/en/my-bookings/'.$token))->assertRedirect(tenantUrl('luna', '/en/my-bookings'));

    expect($opened->getCookie(MyBookingsCookie::NAME))->not->toBeNull();

    $request = LunaFixture::hold(['Bridal trial'], firstWindowDay(2)->setTime(9, 0)->format('Y-m-d H:i'));
    preg_match('/ action="([^"]*)"/', (string) $this->get(twinDecisionLink($request, SiteLanguage::Greek))->assertOk()->getContent(), $action);
    $decide = html_entity_decode($action[1] ?? '');

    expect($decide)->toStartWith(tenantUrl('luna', '/el/decide/'.$request.'/approve').'?');

    $this->post($decide)->assertOk();

    expect(LunaFixture::row($request)['status'] ?? null)->not->toBe('awaiting_approval');
});

it('answers the link POST past its limit under a prefix with the form at 429 in the prefix\'s lang, the limit answering before the tenant group\'s locale runs', function () {
    for ($sent = 0; $sent < AppServiceProvider::MY_BOOKINGS_LINK_REQUESTS_PER_MINUTE; $sent++) {
        $this->post(tenantUrl('luna', '/el/my-bookings/link'), ['email' => 'limited@example.test'])->assertRedirect();
    }

    $refused = $this->post(tenantUrl('luna', '/el/my-bookings/link'), ['email' => 'limited@example.test'])->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);

    expect(twinLang($refused))->toBe('el')
        ->and(configOf(documentOf($refused)))->toMatchArray(['language' => 'el'])
        ->and(app()->getLocale())->toBe('en');
});
