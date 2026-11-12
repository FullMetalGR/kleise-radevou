<?php

use App\Application\Clock;
use App\Application\Notification\MessageKey;
use App\Http\Controllers\MyBookingsController;
use App\Infrastructure\MyBookingsFactory;
use App\Notifications\BookingsLink;
use App\Providers\AppServiceProvider;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FixedClock;
use Tests\Support\RefusingTransport;
use Tests\Support\SeamLog;

pest()->use(DatabaseTruncation::class);

/*
 * The My bookings link: a customer asks for it with their address on the hosted page, and a link
 * is minted and mailed only where the tenant sends the key and the address has a booking here.
 * The request is the decision, the row holds the hash, and the message goes out through the seam
 * as every message does: a listener on the record, a job queued encrypted, the seam's switch and
 * its never-throw.
 *
 * luna is the demo seeder's, in Europe/Athens; the clock stands on 2026-11-02 at 06:00 UTC, so a
 * link minted now lapses at 06:15. Bookings are written as rows, a confirmed haircut with Elena
 * on a day of its own each, since what the link asks is whether an address has any.
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

it('mints a link of fifteen minutes for an address with a booking, keeps its hash, and mails the address one message carrying the link', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');

    app(MyBookingsFactory::class)->requestLink(mybTenant())->request(MYB_CASEY, 'en');

    $message = seamOnly();
    $body = (string) $message->getTextBody();
    $token = mybTokenIn($body);
    $links = mybLinks();

    expect(array_map(fn ($to): string => $to->getAddress(), $message->getTo()))->toBe([MYB_CASEY])
        ->and($message->getSubject())->toBe(customerMailLine(BookingsLink::SUBJECT, ['business' => 'Luna Hair Studio']))
        ->and($body)->toContain(customerMailLine(BookingsLink::BODY, ['business' => 'Luna Hair Studio']))
        // Under the prefix of the language the request handed over, English here.
        ->and(seamLinksIn($body))->toBe([app(TenantUrls::class)->myBookingsLink(Slug::of('luna'), $token, SiteLanguage::English)])
        ->and($links)->toHaveCount(1)
        ->and($links[0]['email'])->toBe(MYB_CASEY)
        ->and($links[0]['token_hash'])->toBe(hash('sha256', $token))
        ->and($links[0]['created_at'])->toBe('2026-11-02 06:00:00')
        ->and($links[0]['expires_at'])->toBe('2026-11-02 06:15:00')
        ->and($links[0]['used_at'])->toBeNull();
});

it('finds the address in any case, as citext compares it, and mails the address as it was typed', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');

    app(MyBookingsFactory::class)->requestLink(mybTenant())->request('Casey@Example.TEST', 'en');

    expect(seamAddresses())->toBe(['Casey@Example.TEST'])
        ->and(mybLinks())->toHaveCount(1);
});

it('mints nothing and mails nothing for an address with no booking here', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');

    app(MyBookingsFactory::class)->requestLink(mybTenant())->request('nobody@example.test', 'en');

    expect(seamSent())->toBe([])
        ->and(mybLinks())->toBe([]);
});

it('mints nothing and mails nothing while the tenant has the bookings link switched off, and mints again once it is back on', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    seamSwitchOff([MessageKey::MyBookingsLink->value]);

    app(MyBookingsFactory::class)->requestLink(mybTenant())->request(MYB_CASEY, 'en');

    expect(seamSent())->toBe([])
        ->and(mybLinks())->toBe([]);

    seamSwitchOff([MessageKey::Reminder->value]);
    app(MyBookingsFactory::class)->requestLink(mybTenant())->request(MYB_CASEY, 'en');

    expect(seamSent())->toHaveCount(1)
        ->and(mybLinks())->toHaveCount(1);
});

it('keeps the link when the transport refuses, and logs one error naming the tenant and the key, no booking, and neither the address nor the token', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $log = new SeamLog;
    Log::swap($log);
    seamMailer()->setSymfonyTransport(new RefusingTransport);

    app(MyBookingsFactory::class)->requestLink(mybTenant())->request(MYB_CASEY, 'en');

    $errors = $log->errors();

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['context'])->toBe([
            'tenant' => 'luna',
            'key' => 'my_bookings_link',
            'booking' => null,
            'exception' => TransportException::class,
        ])
        ->and($log->text())->not->toContain(MYB_CASEY)
        ->and(mybLinks())->toHaveCount(1);
});

it('keeps the token out of every log line under a transport that works too', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $log = new SeamLog;
    Log::swap($log);

    app(MyBookingsFactory::class)->requestLink(mybTenant())->request(MYB_CASEY, 'en');

    $token = mybTokenIn((string) seamOnly()->getTextBody());

    expect($log->text())->not->toContain($token)
        ->and($log->text())->not->toContain(MYB_CASEY);
});

/*
 * The link's POST: whatever was found, the answer is the way back to the form with the one
 * sentence as a flash, and a link is mailed only where the address has a booking here and the key
 * is on. A malformed address is judged at the boundary and reads the same.
 */

it('answers {0} with the one sentence on the way back to the form, and mails {3}', function (string $case, mixed $email, bool $on, int $mailed) {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');

    if (! $on) {
        seamSwitchOff([MessageKey::MyBookingsLink->value]);
    }

    $this->post(tenantUrl('luna', '/my-bookings/link'), $email === null ? [] : ['email' => $email])
        ->assertRedirect(tenantUrl('luna', '/my-bookings'))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSessionHas(MyBookingsController::FLASH, ['kind' => 'success', 'message' => MyBookingsController::SENT]);

    expect(seamSent())->toHaveCount($mailed)
        ->and(mybLinks())->toHaveCount($mailed);
})->with([
    ['a known address', MYB_CASEY, true, 1],
    ['a known address in another case', 'CASEY@example.test', true, 1],
    ['an unknown address', 'nobody@example.test', true, 0],
    ['a known address while the key is off', MYB_CASEY, false, 0],
    ['a malformed address', 'casey at example', true, 0],
    ['a blank address', '', true, 0],
    ['no address at all', null, true, 0],
    ['a list of addresses', [MYB_CASEY], true, 0],
]);

it('counts the link POST under a limiter of its own, five a minute per address as typed in any case, and answers the form at 429 with its refusal past it, never the envelope', function () {
    for ($ask = 0; $ask < AppServiceProvider::MY_BOOKINGS_LINK_REQUESTS_PER_MINUTE; $ask++) {
        $this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => $ask % 2 === 0 ? 'someone@example.test' : 'SomeOne@Example.TEST'])
            ->assertRedirect(tenantUrl('luna', '/my-bookings'));
    }

    $refused = $this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => ' someone@example.test '])
        ->assertStatus(429)
        ->assertHeader('X-RateLimit-Limit', (string) AppServiceProvider::MY_BOOKINGS_LINK_REQUESTS_PER_MINUTE)
        ->assertHeader('Retry-After')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSessionMissing(MyBookingsController::FLASH);
    $document = documentOf($refused);
    $inlined = mybInlined($document);

    expect(mountOf($document)->getAttribute('data-mode'))->toBe('list')
        ->and($inlined)->toHaveKeys(['bookings', 'notice', 'csrf'])
        ->and($inlined['notice'])->toBe(pageSentence(MyBookingsController::TOO_MANY))
        ->and($inlined['bookings'])->toBeNull()
        ->and((string) $refused->getContent())->not->toContain('rate_limited');

    // Another address is counted apart, on the same host.
    $this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => 'other@example.test'])
        ->assertRedirect(tenantUrl('luna', '/my-bookings'));
});

/*
 * A suspended tenant: every route of My bookings answers the suspended page at 503, the one
 * answer the resolver gives on every route, and so does the link POST past its limit, whose
 * answer the limiter gives before resolution.
 */

it('answers a suspended tenant\'s My bookings routes with the suspended page at 503, the link POST past its limit included', function () {
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => MYB_CASEY])->assertRedirect(tenantUrl('luna', '/my-bookings'));
    $token = mybTokenIn((string) seamAt(MYB_CASEY)->getTextBody());
    seamTransport()->flush();
    DB::table('tenants')->where('slug', 'luna')->update(['status' => 'suspended']);

    $suspended = $this->get(tenantUrl('luna', '/'))->assertStatus(503);
    $page = (string) $suspended->getContent();

    expect($page)->toContain(pageSentence('pages.suspended.heading'));

    foreach ([
        $this->get(tenantUrl('luna', '/my-bookings')),
        $this->getJson(tenantUrl('luna', '/my-bookings')),
        $this->get(tenantUrl('luna', '/my-bookings/'.$token)),
        $this->post(tenantUrl('luna', '/my-bookings/'.$token)),
        $this->post(tenantUrl('luna', '/my-bookings/sign-out')),
    ] as $answer) {
        $answer->assertStatus(503);

        expect((string) $answer->getContent())->toBe($page);
    }

    for ($ask = 0; $ask < AppServiceProvider::MY_BOOKINGS_LINK_REQUESTS_PER_MINUTE; $ask++) {
        $this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => MYB_CASEY])->assertStatus(503);
    }

    $limited = $this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => MYB_CASEY])->assertStatus(503);

    expect((string) $limited->getContent())->toBe($page)
        ->and(seamSent())->toBe([])
        ->and(mybLinks()[0]['used_at'] ?? null)->toBeNull()
        ->and(DB::table('tenant_luna.customer_sessions')->count())->toBe(0);
});
