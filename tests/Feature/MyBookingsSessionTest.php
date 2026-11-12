<?php

use App\Application\Clock;
use App\Http\BookingPage;
use App\Http\BookingPayload;
use App\Http\Controllers\MyBookingsController;
use App\Http\Middleware\KeepLinkTokensOutOfTheSession;
use App\Http\MyBookingsCookie;
use App\Infrastructure\Db\BookingReader;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * My bookings' page and its session: GET /my-bookings renders the hosted page's view with the
 * mount in list mode and a second JSON script beside the config, the session's bookings where
 * the cookie names a live session and null where it names none, which the app renders as the
 * form.
 *
 * luna is the demo seeder's, in Europe/Athens; the clock stands on 2026-11-02 at 06:00 UTC, so a
 * link minted now lapses at 06:15 and a session opened now ends on 2026-12-02 at 06:00.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    seamTransport()->flush();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

it('renders the form without a session: the mount in list mode, the config of seven keys, and beside it no bookings, no sentence and the form\'s cross-site token', function () {
    $response = $this->get(tenantUrl('luna', '/my-bookings'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
    $document = documentOf($response);
    $mount = mountOf($document);
    $inlined = mybInlined($document);

    expect($mount->getAttribute('data-mode'))->toBe(BookingPage::LIST)
        ->and($mount->hasAttribute('data-uuid'))->toBeFalse()
        ->and($mount->hasAttribute('data-token'))->toBeFalse()
        ->and(array_keys(configOf($document)))->toBe(['tenant_name', 'timezone', 'currency', 'granularity_min', 'important_info', 'default_language', 'language'])
        ->and($inlined)->toBe(['bookings' => null, 'notice' => null, 'csrf' => session()->token()])
        ->and($document->getElementsByTagName('h1')->item(0)?->textContent)->toBe('Luna Hair Studio');
});

it('carries the sentence a redirect flashed above the form', function () {
    $response = $this->withSession([MyBookingsController::FLASH => ['kind' => 'warning', 'message' => MyBookingsController::INVALID]])
        ->get(tenantUrl('luna', '/my-bookings'))
        ->assertOk();

    expect(mybInlined(documentOf($response))['notice'] ?? null)->toBe(pageSentence(MyBookingsController::INVALID));
});

/*
 * The session: a link's page offers one button and spends nothing (P9F-L16-a), the button's POST
 * opens the session once inside the link's fifteen minutes, the cookie names it, the page lists
 * its address's bookings, and the sign-out ends it. Every miss is the form.
 */

/**
 * A link minted for casey through the factory's road, as the POST mints one, and the token the
 * message carried.
 */
function mybMintedToken(TestCase $test, string $email = MYB_CASEY): string
{
    $test->post(tenantUrl('luna', '/my-bookings/link'), ['email' => $email])->assertRedirect(tenantUrl('luna', '/my-bookings'));

    return mybTokenIn((string) seamAt($email)->getTextBody());
}

/**
 * The session rows luna holds, oldest first.
 *
 * @return list<array<mixed>>
 */
function mybSessions(): array
{
    return array_values(array_map(fn (object $row): array => get_object_vars($row), DB::table('tenant_luna.customer_sessions')
        ->orderBy('id')
        ->selectRaw("email::text as email, session_hash, to_char(created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as created_at, to_char(expires_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as expires_at, to_char(revoked_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as revoked_at")
        ->get()
        ->all()));
}

/**
 * The session secret an opening's cookie carries, decrypted as the framework reads it back.
 *
 * @param  TestResponse<Response>  $opened
 */
function mybSecretOf(TestResponse $opened): string
{
    $cookie = $opened->getCookie(MyBookingsCookie::NAME);

    expect($cookie)->not->toBeNull();

    return (string) $cookie?->getValue();
}

it('renders the link\'s page for a live link, no-store, no-referrer and noindex, with one button whose form posts back to the page\'s own address and names no token, and spends nothing', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $token = mybMintedToken($this);
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:14:59'));

    $page = $this->get(tenantUrl('luna', '/my-bookings/'.$token))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertSessionMissing(MyBookingsController::FLASH);
    $document = documentOf($page);
    $xpath = new DOMXPath($document);
    $forms = $xpath->query('//form');
    $buttons = $xpath->query('//button');
    $csrf = $xpath->query('//form//input[@name="_token"]/@value');
    $robots = $xpath->query('//meta[@name="robots"]/@content');

    assert($forms !== false && $buttons !== false && $csrf !== false && $robots !== false);
    $form = $forms->item(0);

    expect($forms)->toHaveCount(1)
        ->and($form instanceof DOMElement ? $form->getAttribute('method') : null)->toBe('post')
        ->and($form instanceof DOMElement && $form->hasAttribute('action'))->toBeFalse()
        ->and($buttons)->toHaveCount(1)
        ->and($buttons->item(0)?->nodeValue)->toBe(pageSentence(MyBookingsController::OPEN_LABEL))
        ->and($csrf->item(0)?->nodeValue)->toBe(session()->token())
        ->and($robots->item(0)?->nodeValue)->toBe('noindex')
        ->and($document->getElementsByTagName('title')->item(0)?->textContent)->toBe(pageSentence(MyBookingsController::OPEN_TITLE, ['business' => 'Luna Hair Studio']))
        ->and((string) $page->getContent())->toContain(mybOpenSentence())
        ->and((string) $page->getContent())->not->toContain($token)
        ->and($page->getCookie(MyBookingsCookie::NAME))->toBeNull()
        ->and(mybLinks()[0]['used_at'] ?? null)->toBeNull()
        ->and(mybSessions())->toBe([]);
});

it('leaves the link live after any number of fetches, as a mail scanner makes them, so the button\'s POST after them still opens the session', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $token = mybMintedToken($this);

    $this->get(tenantUrl('luna', '/my-bookings/'.$token))->assertOk();
    $this->get(tenantUrl('luna', '/my-bookings/'.$token))->assertOk();

    expect(mybLinks()[0]['used_at'] ?? null)->toBeNull()
        ->and(mybSessions())->toBe([]);

    $opened = $this->post(tenantUrl('luna', '/my-bookings/'.$token))->assertRedirect(tenantUrl('luna', '/my-bookings'));

    expect(mybSecretOf($opened))->toMatch('/\A[0-9a-f]{64}\z/')
        ->and(mybSessions())->toHaveCount(1);
});

it('opens a session once inside the link\'s fifteen minutes on the button\'s POST and goes on to the page, the cookie host-only, Secure, HttpOnly, SameSite Lax and thirty days, naming the session by its secret', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $token = mybMintedToken($this);
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:14:59'));

    $opened = $this->post(tenantUrl('luna', '/my-bookings/'.$token))
        ->assertRedirect(tenantUrl('luna', '/my-bookings'))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSessionMissing(MyBookingsController::FLASH);
    $cookie = $opened->getCookie(MyBookingsCookie::NAME);
    $secret = mybSecretOf($opened);
    $sessions = mybSessions();

    expect($cookie?->isSecure())->toBeTrue()
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getSameSite())->toBe('lax')
        ->and($cookie?->getDomain())->toBeNull()
        ->and($cookie?->getPath())->toBe('/')
        ->and(abs(($cookie?->getExpiresTime() ?? 0) - time() - 30 * 86400))->toBeLessThan(120)
        ->and($secret)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and(mybLinks()[0]['used_at'] ?? null)->not->toBeNull()
        ->and($sessions)->toBe([[
            'email' => MYB_CASEY,
            'session_hash' => hash('sha256', $secret),
            'created_at' => '2026-11-02 06:14:59',
            'expires_at' => '2026-12-02 06:14:59',
            'revoked_at' => null,
        ]]);
});

it('answers {0} on {2} with the way back to the form and the sentence saying the link is no longer valid, opening nothing', function (string $case, Closure $arrange, string $verb) {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    // What happens to the link before it is opened, answering the token then presented.
    $token = $arrange($this, mybMintedToken($this));

    expect($token)->toBeString();
    assert(is_string($token));
    $before = mybSessions();
    $links = mybLinks();

    $refused = $this->call($verb === 'the page\'s GET' ? 'GET' : 'POST', tenantUrl('luna', '/my-bookings/'.$token))
        ->assertRedirect(tenantUrl('luna', '/my-bookings'))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSessionHas(MyBookingsController::FLASH, ['kind' => 'warning', 'message' => MyBookingsController::INVALID]);

    expect($refused->getCookie(MyBookingsCookie::NAME))->toBeNull()
        ->and(mybSessions())->toBe($before)
        ->and(mybLinks())->toBe($links);
})->with([
    ['a second use', function (TestCase $test, string $token): string {
        $test->post(tenantUrl('luna', '/my-bookings/'.$token))->assertSessionMissing(MyBookingsController::FLASH);

        return $token;
    }],
    ['a lapsed link, at its fifteenth minute', function (TestCase $test, string $token): string {
        app()->instance(Clock::class, FixedClock::at('2026-11-02 06:15:00'));

        return $token;
    }],
    ['an unknown token', fn (TestCase $test, string $token): string => strrev($token)],
    ['a token that is no token at all', fn (TestCase $test, string $token): string => 'not-a-token'],
])->with(['the page\'s GET', 'the button\'s POST']);

it('lists every booking of the session\'s address, upcoming first by start then past by start descending, in the guest view\'s shape, and none of another address', function () {
    $later = mybBooking(MYB_CASEY, '2026-11-05 08:00:00');
    $sooner = mybBooking(MYB_CASEY, '2026-11-03 08:00:00', 'cancelled');
    $longAgo = mybBooking(MYB_CASEY, '2026-10-20 08:00:00', 'completed');
    $recent = mybBooking('CASEY@example.test', '2026-10-28 08:00:00', 'no_show');
    mybBooking('someone.else@example.test', '2026-11-04 08:00:00');
    $secret = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));

    $listed = $this->withCookie(MyBookingsCookie::NAME, $secret)->get(tenantUrl('luna', '/my-bookings'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
    $inlined = mybInlined(documentOf($listed));
    $bookings = $inlined['bookings'];

    $guest = fn (string $uuid): array => BookingPayload::guest(app(BookingReader::class)->snapshot(mybTenant(), $uuid) ?? throw new LogicException('No booking '.$uuid));

    expect($bookings)->toBe([
        'upcoming' => [$guest($sooner), $guest($later)],
        'past' => [$guest($recent), $guest($longAgo)],
    ])
        ->and($guest($sooner)['status'])->toBe('cancelled')
        ->and($guest($sooner)['items'][0]['service_name'])->toBe('Haircut')
        ->and($guest($sooner)['items'][0]['staff_name'])->toBe('Elena Papadaki')
        ->and($inlined['notice'])->toBeNull()
        ->and($listed->getCookie(MyBookingsCookie::NAME))->toBeNull();
});

it('reads a {0} session as none: the form, and the cookie cleared on the way', function (string $case, Closure $end) {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $secret = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));
    $end($secret);

    $response = $this->withCookie(MyBookingsCookie::NAME, $secret)->get(tenantUrl('luna', '/my-bookings'))->assertOk();
    $cleared = $response->getCookie(MyBookingsCookie::NAME, decrypt: false);

    $inlined = mybInlined(documentOf($response));

    expect($inlined)->toHaveKey('bookings')
        ->and($inlined['bookings'])->toBeNull()
        ->and($cleared?->getExpiresTime())->toBeLessThan(time());
})->with([
    ['revoked', fn (string $secret) => DB::table('tenant_luna.customer_sessions')->update(['revoked_at' => '2026-11-02 06:01:00+00:00'])],
    ['lapsed, at its thirtieth day', fn (string $secret) => app()->instance(Clock::class, FixedClock::at('2026-12-02 06:00:00'))],
    ['unknown', fn (string $secret) => DB::table('tenant_luna.customer_sessions')->delete()],
]);

it('signs out: the session revoked, the cookie cleared, the way back to the form with its sentence, and the cookie naming nothing after', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $secret = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));
    app()->instance(Clock::class, FixedClock::at('2026-11-02 07:00:00'));

    $signedOut = $this->withCookie(MyBookingsCookie::NAME, $secret)->post(tenantUrl('luna', '/my-bookings/sign-out'))
        ->assertRedirect(tenantUrl('luna', '/my-bookings'))
        ->assertSessionHas(MyBookingsController::FLASH, ['kind' => 'success', 'message' => MyBookingsController::SIGNED_OUT]);
    $cleared = $signedOut->getCookie(MyBookingsCookie::NAME, decrypt: false);

    expect($cleared?->getExpiresTime())->toBeLessThan(time())
        ->and($cleared?->isSecure())->toBeTrue()
        ->and($cleared?->isHttpOnly())->toBeTrue()
        ->and(mybSessions()[0]['revoked_at'] ?? null)->toBe('2026-11-02 07:00:00');

    $after = $this->withCookie(MyBookingsCookie::NAME, $secret)->get(tenantUrl('luna', '/my-bookings'))->assertOk();

    expect(mybInlined(documentOf($after)))->toHaveKey('bookings')
        ->and(mybInlined(documentOf($after))['bookings'])->toBeNull();
});

it('signs out a browser holding no session as one holding one: the form and its sentence', function () {
    $this->post(tenantUrl('luna', '/my-bookings/sign-out'))
        ->assertRedirect(tenantUrl('luna', '/my-bookings'))
        ->assertSessionHas(MyBookingsController::FLASH, ['kind' => 'success', 'message' => MyBookingsController::SIGNED_OUT]);
});

it('comes back under the prefix it was asked under, {0}: the link POST, a dead link\'s page and its button, the button\'s POST that opens the session, and the sign-out', function (string $case, string $under) {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $back = tenantUrl('luna', $under.'/my-bookings');

    $this->post(tenantUrl('luna', $under.'/my-bookings/link'), ['email' => MYB_CASEY])
        ->assertRedirect($back)
        ->assertSessionHas(MyBookingsController::FLASH, ['kind' => 'success', 'message' => MyBookingsController::SENT]);
    $token = mybTokenIn((string) seamAt(MYB_CASEY)->getTextBody());

    $this->get(tenantUrl('luna', $under.'/my-bookings/not-a-token'))->assertRedirect($back);
    $this->post(tenantUrl('luna', $under.'/my-bookings/not-a-token'))->assertRedirect($back);
    $opened = $this->post(tenantUrl('luna', $under.'/my-bookings/'.$token))->assertRedirect($back);

    $this->withCookie(MyBookingsCookie::NAME, mybSecretOf($opened))->post(tenantUrl('luna', $under.'/my-bookings/sign-out'))
        ->assertRedirect($back)
        ->assertSessionHas(MyBookingsController::FLASH, ['kind' => 'success', 'message' => MyBookingsController::SIGNED_OUT]);

    // The page the way back lands on answers in the prefix's language, the sentence above it.
    $page = $this->get($back)->assertOk();

    expect(configOf(documentOf($page)))->toMatchArray(['language' => $under === '/el' ? 'el' : 'en']);
})->with([
    ['at the bare path', ''],
    ['under /en', '/en'],
    ['under /el', '/el'],
]);

it('ends a session the browser already held when a link opens another, one cookie, one session', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    mybBooking('pat@example.test', '2026-11-04 08:00:00');
    $first = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));
    $token = mybMintedToken($this, 'pat@example.test');

    $second = mybSecretOf($this->withCookie(MyBookingsCookie::NAME, $first)->post(tenantUrl('luna', '/my-bookings/'.$token)));

    expect($second)->not->toBe($first)
        ->and(array_map(fn (array $row): bool => $row['revoked_at'] !== null, mybSessions()))->toBe([true, false]);
});

it('keeps the link\'s token and the session\'s secret out of every log line, page body, flash and answer header, the cookie alone carrying the secret, encrypted', function () {
    $log = new SeamLog;
    Log::swap($log);
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');

    $asked = $this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => MYB_CASEY]);
    $token = mybTokenIn((string) seamAt(MYB_CASEY)->getTextBody());
    $page = $this->get(tenantUrl('luna', '/my-bookings/'.$token));
    $opened = $this->post(tenantUrl('luna', '/my-bookings/'.$token));
    $secret = mybSecretOf($opened);
    $listed = $this->withCookie(MyBookingsCookie::NAME, $secret)->get(tenantUrl('luna', '/my-bookings'));
    $again = $this->get(tenantUrl('luna', '/my-bookings/'.$token));
    $pressedAgain = $this->post(tenantUrl('luna', '/my-bookings/'.$token));
    $signedOut = $this->withCookie(MyBookingsCookie::NAME, $secret)->post(tenantUrl('luna', '/my-bookings/sign-out'));

    foreach ([$asked, $page, $opened, $listed, $again, $pressedAgain, $signedOut] as $answer) {
        $headers = (string) $answer->headers;

        expect((string) $answer->getContent())->not->toContain($token)
            ->and((string) $answer->getContent())->not->toContain($secret)
            ->and($headers)->not->toContain($secret)
            ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($token)
            ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    }

    // The opening's Location names the page and never the token it was reached by.
    expect((string) $opened->headers->get('Location'))->toBe(tenantUrl('luna', '/my-bookings'))
        ->and((string) $opened->headers)->not->toContain($token)
        ->and($log->text())->not->toContain($token)
        ->and($log->text())->not->toContain($secret)
        ->and(DB::table('tenant_luna.customer_links')->where('token_hash', $token)->exists())->toBeFalse()
        ->and(DB::table('tenant_luna.customer_sessions')->where('session_hash', $secret)->exists())->toBeFalse();
});

it('starts every page\'s session with the class that keeps the link\'s URL, the token, out of the session store on its page and its POST, and writes the previous URL everywhere else', function () {
    $web = app(HttpKernel::class)->getMiddlewareGroups()['web'] ?? [];
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $token = mybMintedToken($this);

    $this->get(tenantUrl('luna', '/my-bookings/'.$token))->assertOk();
    $paged = (string) session()->previousUrl();
    $this->post(tenantUrl('luna', '/my-bookings/'.$token));
    $kept = (string) session()->previousUrl();
    $this->get(tenantUrl('luna', '/my-bookings'));

    expect($web)->toContain(KeepLinkTokensOutOfTheSession::class)
        ->and($web)->not->toContain(StartSession::class)
        ->and(KeepLinkTokensOutOfTheSession::ROUTES)->toBe(['my-bookings.open', 'my-bookings.open.act', 'admin.set-password', 'admin.set-password.malformed', 'admin.support.open', 'admin.support.malformed', 'manage', 'pay', 'pay.link', 'api.booking', 'api.booking.availability', 'signup.verify', 'signup.verify.malformed'])
        ->and($paged)->not->toContain($token)
        ->and($kept)->not->toContain($token)
        ->and(session()->previousUrl())->toBe(tenantUrl('luna', '/my-bookings'));
});

/*
 * The list's re-read and the prefill: after a cancel or a move the list mode reads GET
 * /my-bookings as JSON behind the same session, and with a session open the business page
 * inlines the customer its flow's details step starts from. Neither reaches the embed.
 */

it('answers the page\'s list as JSON to a read asking for it, behind the same session and no-store: the shape the page inlines, the one JSON read the list mode makes', function () {
    $later = mybBooking(MYB_CASEY, '2026-11-05 08:00:00');
    $past = mybBooking(MYB_CASEY, '2026-10-20 08:00:00', 'completed');
    mybBooking('someone.else@example.test', '2026-11-04 08:00:00');
    $secret = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));
    $guest = fn (string $uuid): array => BookingPayload::guest(app(BookingReader::class)->snapshot(mybTenant(), $uuid) ?? throw new LogicException('No booking '.$uuid));

    $read = $this->withCredentials()->withCookie(MyBookingsCookie::NAME, $secret)
        ->getJson(tenantUrl('luna', '/my-bookings'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Type', 'application/json');

    expect($read->json())->toBe([
        'bookings' => ['upcoming' => [$guest($later)], 'past' => [$guest($past)]],
        'notice' => null,
        'csrf' => session()->token(),
    ])
        ->and((string) $read->getContent())->not->toContain($secret);
});

it('answers the JSON read with no live session as the page does, bookings null, and clears a cookie naming none', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $secret = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));
    DB::table('tenant_luna.customer_sessions')->update(['revoked_at' => '2026-11-02 06:01:00+00:00']);

    $this->getJson(tenantUrl('luna', '/my-bookings'))->assertOk()->assertJsonPath('bookings', null);

    $read = $this->withCredentials()->withCookie(MyBookingsCookie::NAME, $secret)->getJson(tenantUrl('luna', '/my-bookings'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('bookings', null);

    expect($read->getCookie(MyBookingsCookie::NAME, decrypt: false)?->getExpiresTime())->toBeLessThan(time());
});

/**
 * Two bookings of casey's, the one made later starting sooner and spelled differently, so "the
 * most recent" can only be read off when each was made.
 *
 * @return array{made_first: string, made_last: string}
 */
function mybTwoMade(): array
{
    $first = mybBooking(MYB_CASEY, '2026-11-05 08:00:00');
    $last = mybBooking('Casey@Example.TEST', '2026-11-03 08:00:00', 'completed');

    DB::table('tenant_luna.bookings')->where('uuid', $first)->update(['customer_name' => 'Casey Long Ago', 'customer_phone' => '+30 210 1111111', 'created_at' => '2026-10-01 08:00:00+00:00']);
    DB::table('tenant_luna.bookings')->where('uuid', $last)->update(['customer_name' => 'Casey Lately', 'customer_phone' => '+30 210 2222222', 'created_at' => '2026-10-20 08:00:00+00:00']);

    return ['made_first' => $first, 'made_last' => $last];
}

it('inlines on the business page, with a session open, the name, the email and the phone of the session\'s most recent booking by when it was made, of those that did not come to nothing, as the config\'s customer, no-store', function () {
    mybTwoMade();
    $secret = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));

    $page = $this->withCookie(MyBookingsCookie::NAME, $secret)->get(tenantUrl('luna', '/'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(configOf(documentOf($page)))->toBe([
        'tenant_name' => 'Luna Hair Studio',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
        'granularity_min' => 5,
        'important_info' => null,
        'default_language' => 'en',
        'language' => 'en',
        'customer' => ['name' => 'Casey Lately', 'email' => 'Casey@Example.TEST', 'phone' => '+30 210 2222222'],
    ])
        ->and((string) $page->getContent())->not->toContain($secret);
});

it('carries a null phone where the most recent booking has none', function () {
    ['made_last' => $last] = mybTwoMade();
    DB::table('tenant_luna.bookings')->where('uuid', $last)->update(['customer_phone' => null]);
    $secret = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));

    $config = configOf(documentOf($this->withCookie(MyBookingsCookie::NAME, $secret)->get(tenantUrl('luna', '/'))->assertOk()));

    expect($config['customer'] ?? null)->toBe(['name' => 'Casey Lately', 'email' => 'Casey@Example.TEST', 'phone' => null]);
});

it('reads past a most recent booking that came to nothing, {0}, to the details of the one made before it', function (string $status) {
    ['made_last' => $last] = mybTwoMade();
    DB::table('tenant_luna.bookings')->where('uuid', $last)->update(['status' => $status]);
    $secret = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));

    $config = configOf(documentOf($this->withCookie(MyBookingsCookie::NAME, $secret)->get(tenantUrl('luna', '/'))->assertOk()));

    expect($config['customer'] ?? null)->toBe(['name' => 'Casey Long Ago', 'email' => MYB_CASEY, 'phone' => '+30 210 1111111']);
})->with(['cancelled', 'rejected', 'expired']);

it('reads {0} as most recent as any live booking, since it kept its time or is still on its way', function (string $status) {
    ['made_last' => $last] = mybTwoMade();
    DB::table('tenant_luna.bookings')->where('uuid', $last)->update(['status' => $status]);
    $secret = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));

    $config = configOf(documentOf($this->withCookie(MyBookingsCookie::NAME, $secret)->get(tenantUrl('luna', '/'))->assertOk()));

    expect($config['customer'] ?? null)->toBe(['name' => 'Casey Lately', 'email' => 'Casey@Example.TEST', 'phone' => '+30 210 2222222']);
})->with(['completed', 'no_show', 'confirmed']);

it('inlines no customer on the business page {0}: the seven keys, as today', function (string $case, Closure $arrange) {
    mybTwoMade();
    $secret = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));
    // What happens to the session, answering the cookie then presented, or null for none.
    $cookie = $arrange($secret);

    assert($cookie === null || is_string($cookie));

    $request = $cookie === null ? $this : $this->withCookie(MyBookingsCookie::NAME, $cookie);
    $page = $request->get(tenantUrl('luna', '/'))->assertOk();

    expect(array_keys(configOf(documentOf($page))))->toBe(['tenant_name', 'timezone', 'currency', 'granularity_min', 'important_info', 'default_language', 'language'])
        ->and($page->headers->get('Cache-Control'))->not->toContain('no-store');
})->with([
    ['without a cookie', fn (string $secret): ?string => null],
    ['for a revoked session', function (string $secret): string {
        DB::table('tenant_luna.customer_sessions')->update(['revoked_at' => '2026-11-02 06:01:00+00:00']);

        return $secret;
    }],
    ['for a session at its end', function (string $secret): string {
        app()->instance(Clock::class, FixedClock::at('2026-12-02 06:00:00'));

        return $secret;
    }],
    ['for a cookie naming no session', fn (string $secret): string => strrev($secret)],
    ['for a session whose every booking came to nothing', function (string $secret): string {
        DB::table('tenant_luna.bookings')->update(['status' => 'cancelled']);

        return $secret;
    }],
]);

it('gives the customer to the business page alone: the API\'s config, the manage page and My bookings carry the seven keys whatever the cookie says', function () {
    ['made_last' => $last] = mybTwoMade();
    $secret = mybSecretOf($this->post(tenantUrl('luna', '/my-bookings/'.mybMintedToken($this))));
    $seven = ['tenant_name', 'timezone', 'currency', 'granularity_min', 'important_info', 'default_language', 'language'];

    $this->withCredentials()->withCookie(MyBookingsCookie::NAME, $secret);

    expect(array_keys((array) $this->getJson(tenantUrl('luna', '/api/v1/config'))->assertOk()->json()))->toBe($seven)
        ->and(array_keys(configOf(documentOf($this->get(tenantUrl('luna', '/manage/'.$last.'?token=x'))->assertOk()))))->toBe($seven)
        ->and(array_keys(configOf(documentOf($this->get(tenantUrl('luna', '/my-bookings'))->assertOk()))))->toBe($seven);
});
