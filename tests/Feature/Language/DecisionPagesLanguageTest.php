<?php

use App\Domain\Enum\Decision;
use App\Infrastructure\Db\BookingReader;
use App\Notifications\TenantClock;
use App\Notifications\TenantTime;
use App\Site\SiteLanguage;
use App\Tenancy\DecisionLinks;
use App\Tenancy\Slug;
use App\Tenancy\TenantPages;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The decision pages in their link's language: a link minted under a prefix opens its page in that
 * language and a bare link in the tenant default; the signature covers the path, so a link
 * verifies under the prefix it was minted under and no other; the form posts back to a link minted
 * again under the page's prefix, so the outcome its POST shows is in the same language. The page's
 * words are decision.php's, read through the translator in the page's language and never spelled
 * here; the booking's instants are ICU's in the page's language, compared with the formatter's
 * answer and never with a Greek literal, in the tenant's date order and on its dial, a 12-hour
 * tenant's included. Every page is walked by the keys of decision.php under /el (linesShownIn):
 * the confirm page of each decision, the outcome of each status and of a closed window, the
 * refused page and the facts with an erased customer, each with lang el, no English line of the
 * file left but the shared and no :word unfilled, and the same pages under /en with no Greek line;
 * the walks together read 28 of the file's 29 keys, facts.email the one left, which the first case
 * checks among the facts' labels.
 *
 * And the pay hop a hold answers: its checkout_url is under the prefix of the language the hold
 * named, bare where it named none.
 *
 * Not wrapped in a transaction: the holds and the decisions enter the transaction runner. The
 * clock is the system's and every date is relative to today: the links are minted and read
 * against it, and the requests are placed on the first whole availability window.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    seamTransport()->flush();
    Http::preventStrayRequests();
});

afterEach(function () {
    Http::assertNothingSent();
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A request awaiting the owner's decision: a Bridal trial with Elena at Athens 09:00 on the
 * Tuesday of the first whole availability window.
 */
function dplRequested(): string
{
    return LunaFixture::hold(['Bridal trial'], dplStart()->format('Y-m-d H:i'));
}

function dplStart(): DateTimeImmutable
{
    return firstWindowDay(2)->setTime(9, 0);
}

/**
 * The approve or reject link the approval request would carry for luna's owner, under the
 * prefix given, bare for none.
 */
function dplLink(string $uuid, ?SiteLanguage $prefix, Decision $decision = Decision::Approve): string
{
    $booking = app(BookingReader::class)->snapshot(LunaFixture::tenant(), $uuid);
    assert($booking?->holdExpiresAt instanceof DateTimeImmutable);

    return app(DecisionLinks::class)->link(LunaFixture::tenant(), $uuid, $decision, tenantUserId(), $booking->holdExpiresAt, $prefix);
}

/**
 * An approve link with its path moved under the prefix given, bare for none, its query and so its
 * signature kept.
 */
function dplMoved(string $link, string $uuid, ?SiteLanguage $to): string
{
    return tenantUrl('luna', TenantPages::uri('/decide/'.$uuid.'/approve', $to)).'?'.parse_url($link, PHP_URL_QUERY);
}

/**
 * One line of decision.php in Greek, luna's names filled in where the key names them.
 */
function dplGreek(string $key): string
{
    return decisionLine($key, ['business' => 'Luna Hair Studio', 'service' => 'Bridal trial', 'staff' => 'Elena Papadaki', 'amount' => '150,00 EUR', 'zone' => 'Europe/Athens'], SiteLanguage::Greek);
}

/**
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function dplTexts(TestResponse $response, string $query): array
{
    $texts = [];

    foreach ((new DOMXPath(documentOf($response)))->query($query) ?: [] as $node) {
        if ($node instanceof DOMNode) {
            $texts[] = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
        }
    }

    return $texts;
}

/**
 * @param  TestResponse<Response>  $response
 */
function dplLang(TestResponse $response): ?string
{
    return preg_match('/<html lang="([a-z]+)">/', (string) $response->getContent(), $found) === 1 ? $found[1] : null;
}

/**
 * The address the confirm page's form posts to.
 *
 * @param  TestResponse<Response>  $response
 */
function dplAction(TestResponse $response): string
{
    preg_match('/ action="([^"]*)"/', (string) $response->getContent(), $action);

    return html_entity_decode($action[1] ?? '');
}

it('answers a link minted under /el in Greek with lang el: the page\'s words from decision.php, the instant in ICU\'s Greek, the form posting back under /el, and the outcome its POST shows in Greek', function () {
    $uuid = dplRequested();

    $page = $this->get(dplLink($uuid, SiteLanguage::Greek))->assertOk();
    $action = dplAction($page);

    expect(dplLang($page))->toBe('el')
        ->and(dplTexts($page, '//title'))->toBe([dplGreek('decision.title')])
        ->and(dplTexts($page, '//main/p[1]'))->toBe([dplGreek('decision.confirm.waiting').' '.dplGreek('decision.decisions.approve.consequence')])
        ->and(dplTexts($page, '//main/ul/li/div[1]'))->toBe([dplGreek('decision.facts.item')])
        // ICU's Greek medium date and short time, day first on luna's 24-hour clock, and not
        // the English.
        ->and(dplTexts($page, '//main/ul/li/div[@class="when"]'))->toBe([lunaInstant(dplStart(), SiteLanguage::Greek)])
        ->and(lunaInstant(dplStart(), SiteLanguage::Greek))->not->toBe(lunaInstant(dplStart()))
        ->and(lunaInstant(dplStart(), SiteLanguage::Greek))->toEndWith(', 09:00')
        ->and(dplTexts($page, '//dl/dt'))->toBe([dplGreek('decision.facts.customer'), dplGreek('decision.facts.email'), dplGreek('decision.facts.phone')])
        ->and(dplTexts($page, '//p[@class="total"]'))->toBe([dplGreek('decision.facts.total')])
        ->and(dplTexts($page, '//p[@class="zone"]'))->toBe([dplGreek('decision.facts.zone')])
        ->and(dplTexts($page, '//form/button'))->toBe([dplGreek('decision.decisions.approve.verb')])
        ->and($action)->toStartWith(tenantUrl('luna', '/el/decide/'.$uuid.'/approve').'?')
        ->and(app()->getLocale())->toBe('en');

    $outcome = $this->post($action)->assertOk();

    expect(dplLang($outcome))->toBe('el')
        ->and(dplTexts($outcome, '//main/p[1]'))->toBe([dplGreek('decision.outcome.confirmed')])
        ->and(dplTexts($outcome, '//title'))->toBe([dplGreek('decision.title')])
        ->and(LunaFixture::row($uuid)['status'])->toBe('confirmed');
});

it('answers a link minted under /en and a bare one in English, the bare one in the tenant default, Greek once luna chose it', function () {
    $uuid = dplRequested();
    settingsOf('luna')->update(['language' => 'el']);

    $bare = $this->get(dplLink($uuid, null))->assertOk();
    $english = $this->get(dplLink($uuid, SiteLanguage::English))->assertOk();

    expect(dplLang($bare))->toBe('el')
        ->and(dplTexts($bare, '//title'))->toBe([dplGreek('decision.title')])
        ->and(dplTexts($bare, '//main/ul/li/div[@class="when"]'))->toBe([lunaInstant(dplStart(), SiteLanguage::Greek)])
        ->and(dplAction($bare))->toStartWith(tenantUrl('luna', '/decide/'.$uuid.'/approve').'?')
        ->and(dplLang($english))->toBe('en')
        ->and(dplTexts($english, '//title'))->toBe([decisionLine('decision.title', ['business' => 'Luna Hair Studio'])])
        ->and(dplTexts($english, '//p[@class="zone"]'))->toBe([decisionLine('decision.facts.zone', ['zone' => 'Europe/Athens'])])
        ->and(dplTexts($english, '//main/ul/li/div[@class="when"]'))->toBe([lunaInstant(dplStart())])
        ->and(dplAction($english))->toStartWith(tenantUrl('luna', '/en/decide/'.$uuid.'/approve').'?');
});

it('verifies a link under the prefix it was minted under and refuses it under every other, both ways, the refusal in the language of the address it was opened at', function () {
    $uuid = dplRequested();
    $prefixes = ['bare' => null, 'en' => SiteLanguage::English, 'el' => SiteLanguage::Greek];

    foreach ($prefixes as $minted => $under) {
        $link = dplLink($uuid, $under);

        foreach ($prefixes as $opened => $at) {
            $address = dplMoved($link, $uuid, $at);
            $response = $this->get($address);

            expect(parse_url($address, PHP_URL_PATH))->toBe(TenantPages::uri('/decide/'.$uuid.'/approve', $at), $minted.' at '.$opened);

            if ($minted === $opened) {
                $response->assertOk();

                continue;
            }

            $response->assertForbidden();
            $this->post($address)->assertForbidden();

            $language = $at ?? SiteLanguage::English;

            expect(dplTexts($response, '//title'))->toBe([decisionLine('decision.refused.title', [], $language)], $minted.' at '.$opened)
                ->and(dplTexts($response, '//main/*'))->toBe([decisionLine('decision.refused.title', [], $language), decisionLine('decision.refused.changed', [], $language), decisionLine('decision.refused.again', [], $language)])
                ->and(dplLang($response))->toBe($at === SiteLanguage::Greek ? 'el' : 'en');
        }
    }

    expect(LunaFixture::row($uuid)['status'])->toBe('awaiting_approval');
});

/**
 * A link on luna's booking for its owner, or the user given, under the prefix given, its expiry a
 * week ahead or, where it lapsed, a week behind.
 */
function dplLinkFor(string $uuid, SiteLanguage $prefix, Decision $decision = Decision::Approve, ?int $user = null, bool $lapsed = false): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $week = new DateInterval('P7D');

    return app(DecisionLinks::class)->link(LunaFixture::tenant(), $uuid, $decision, $user ?? tenantUserId(), $lapsed ? $now->sub($week) : $now->add($week), $prefix);
}

/**
 * Every page a decision link answers, each by the address that opens it under the prefix given
 * and the keys of decision.php it shows: the confirm page of each decision, the outcome of each
 * status, a lapsed link's on a live request, the two policies and the bare closing of a closed
 * window, the facts of an erased customer and the refusal. The bookings are made once and each
 * page asked under both prefixes.
 *
 * @return array<string, Closure(SiteLanguage): array{string, list<string>}>
 */
function dplPages(): array
{
    $request = dplRequested();
    $facts = ['decision.title', 'decision.facts.customer', 'decision.facts.phone', 'decision.facts.item', 'decision.facts.total', 'decision.facts.zone'];
    // Written straight to the rows, none of them blocking, so they share the fixture's start.
    $made = [];

    foreach (['pending' => ['checkout', 'future'], 'awaiting_payment' => ['approval', 'future'], 'confirmed' => [null, 'none'], 'completed' => [null, 'none'], 'no_show' => [null, 'none'], 'cancelled' => [null, 'none'], 'rejected' => [null, 'none'], 'expired' => [null, 'none']] as $status => [$class, $expiry]) {
        $made[$status] = LunaFixture::booking($status, $class, $expiry, blocks: false);
    }

    $outcome = fn (string $status): Closure => fn (SiteLanguage $prefix): array => [dplLinkFor($made[$status] ?? throw new LogicException('No booking '.$status), $prefix), ['decision.outcome.'.$status, ...$facts]];
    $closed = fn (string $policy): string => LunaFixture::booking('awaiting_approval', 'approval', 'past', blocks: false, requiresApproval: true, onApprovalTimeout: $policy);
    $expire = $closed('expire');
    $auto = $closed('auto_approve');
    $erased = LunaFixture::booking('confirmed', blocks: false);
    DB::table('tenant_luna.bookings')->where('uuid', $erased)->update(['customer_name' => null, 'customer_email' => null, 'customer_phone' => null]);
    $staff = addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki');
    $chair = ['decision.title', 'decision.facts.customer', 'decision.facts.item', 'decision.facts.total', 'decision.facts.zone'];

    return [
        'the approve page' => fn (SiteLanguage $prefix): array => [dplLinkFor($request, $prefix), ['decision.confirm.waiting', 'decision.decisions.approve.verb', 'decision.decisions.approve.consequence', ...$facts]],
        'the reject page' => fn (SiteLanguage $prefix): array => [dplLinkFor($request, $prefix, Decision::Reject), ['decision.confirm.waiting', 'decision.confirm.reason', 'decision.decisions.reject.verb', 'decision.decisions.reject.consequence', ...$facts]],
        'the outcome of a pending booking' => $outcome('pending'),
        'the outcome of a lapsed link on a live request' => fn (SiteLanguage $prefix): array => [dplLinkFor($request, $prefix, lapsed: true), ['decision.outcome.awaiting_approval', ...$facts]],
        'the outcome of a booking awaiting its payment' => $outcome('awaiting_payment'),
        'the outcome of a confirmed booking' => $outcome('confirmed'),
        'the outcome of a completed booking' => $outcome('completed'),
        'the outcome of a no-show' => $outcome('no_show'),
        'the outcome of a cancelled booking' => $outcome('cancelled'),
        'the outcome of a rejected request' => $outcome('rejected'),
        'the outcome of an expired request' => $outcome('expired'),
        'a closed window that releases the slot' => fn (SiteLanguage $prefix): array => [dplLinkFor($expire, $prefix), ['decision.outcome.awaiting_approval', 'decision.window.expire', ...$facts]],
        'a closed window that approves' => fn (SiteLanguage $prefix): array => [dplLinkFor($auto, $prefix), ['decision.outcome.awaiting_approval', 'decision.window.auto_approve', ...$facts]],
        'a closed window, to a user who may not see the policy' => fn (SiteLanguage $prefix): array => [dplLinkFor($auto, $prefix, user: $staff), ['decision.outcome.awaiting_approval', 'decision.window.closed', ...$chair]],
        'the facts of an erased customer' => fn (SiteLanguage $prefix): array => [dplLinkFor($erased, $prefix), ['decision.outcome.confirmed', 'decision.facts.erased', 'decision.facts.customer', 'decision.facts.item', 'decision.facts.total', 'decision.facts.zone', 'decision.title']],
        'the refusal' => fn (SiteLanguage $prefix): array => [dplLinkFor($request, $prefix).'0', ['decision.refused.title', 'decision.refused.changed', 'decision.refused.again']],
    ];
}

/**
 * What a walk by the keys of decision.php reads off a page: its lang, the keys it shows in each
 * language, the shared left out of both, and every :word it leaves unfilled.
 *
 * @param  TestResponse<Response>  $response
 * @return array{lang: string|null, greek: list<string>, english: list<string>, unfilled: list<string>}
 */
function dplWalk(TestResponse $response): array
{
    $text = shownTextOf($response);
    $keys = fn (SiteLanguage $language): array => array_map(fn (string $key): string => 'decision.'.$key, linesShownIn($text, 'decision', $language));

    return ['lang' => dplLang($response), 'greek' => $keys(SiteLanguage::Greek), 'english' => $keys(SiteLanguage::English), 'unfilled' => unfilledIn($text)];
}

it('walks every decision page under /el by the keys of decision.php, each in Greek with lang el and no English line left, and under /en with no Greek line, the walks reading every key of the file', function () {
    $pages = dplPages();
    $walked = [];

    foreach ($pages as $page => $open) {
        [$link, $keys] = $open(SiteLanguage::Greek);
        $greek = $this->get($link);
        $walk = dplWalk($greek);

        expect($greek->getStatusCode())->toBe($page === 'the refusal' ? 403 : 200, $page)
            ->and($walk['lang'])->toBe('el', $page)
            ->and($walk['english'])->toBe([], $page)
            ->and($walk['unfilled'])->toBe([], $page)
            ->and(array_values(array_diff($keys, $walk['greek'])))->toBe([], $page);

        [$link] = $open(SiteLanguage::English);
        $english = dplWalk($this->get($link));

        expect($english['lang'])->toBe('en', $page)
            ->and($english['greek'])->toBe([], $page);

        array_push($walked, ...$keys);
    }

    $file = require lang_path('en/decision.php');
    assert(is_array($file));
    $all = array_map(fn (string $key): string => 'decision.'.$key, array_keys(Arr::dot($file)));
    $walked = array_values(array_unique($walked));
    sort($walked);
    sort($all);

    // facts.email is the one shared value, Email in both languages, which no walk can tell apart.
    expect($pages)->toHaveCount(16)
        ->and($walked)->toBe(array_values(array_diff($all, ['decision.facts.email'])))
        ->and($walked)->toHaveCount(28);
});

it('spells the page\'s instants on a 12-hour tenant on the twelve-hour dial, in English and in Greek, the date still day first, and the request\'s two mails on the same dial, their job reading the settings row', function () {
    settingsOf('luna')->update(['time_format' => 'g:i A']);
    $uuid = dplRequested();
    $twelve = new TenantClock(new DateTimeZone('Europe/Athens'), 'd/m/Y', 'g:i A');

    $english = $this->get(dplLink($uuid, null))->assertOk();
    $greek = $this->get(dplLink($uuid, SiteLanguage::Greek))->assertOk();
    [$spelledEnglish] = dplTexts($english, '//main/ul/li/div[@class="when"]');
    [$spelledGreek] = dplTexts($greek, '//main/ul/li/div[@class="when"]');

    expect($spelledEnglish)->toBe(TenantTime::instant(dplStart(), $twelve, SiteLanguage::English))
        ->and($spelledEnglish)->toStartWith(dplStart()->format('j').' ')
        ->and($spelledEnglish)->toContain(', 9:00')
        ->and($spelledEnglish)->not->toContain('09:00')
        ->and($spelledEnglish)->toEndWith('am')
        ->and($spelledGreek)->toBe(TenantTime::instant(dplStart(), $twelve, SiteLanguage::Greek))
        ->and($spelledGreek)->toContain(', 9:00')
        ->and($spelledGreek)->not->toBe(lunaInstant(dplStart(), SiteLanguage::Greek))
        // The acknowledgement to the customer and every message to the owner, the approval
        // request and the nags the sync queue ran, on the tenant's dial too.
        ->and(seamTextAt(LunaFixture::CUSTOMER_EMAIL))->toContain($spelledEnglish)
        ->and(seamTextAt(LunaFixture::CUSTOMER_EMAIL))->not->toContain(lunaInstant(dplStart()));

    $owner = array_values(array_filter(seamSent(), fn (Email $message): bool => ($message->getTo()[0] ?? null)?->getAddress() === 'owner@luna.example'));

    expect($owner)->not->toBeEmpty();

    foreach ($owner as $message) {
        expect((string) $message->getTextBody())->toContain($spelledEnglish)
            ->not->toContain(lunaInstant(dplStart()));
    }
});

/**
 * A hold of luna's online Colour with Elena taken through the API at the Athens hour given on the
 * request's Tuesday, the language named as the booking app names it, or none, and the answer.
 *
 * @return array{uuid: string, manage_token: string, checkout_url?: string}
 */
function dplHold(TestCase $test, int $hour, ?string $lang): array
{
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'.($lang === null ? '' : '?lang='.$lang)), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'items' => [['service_id' => DB::table('tenant_luna.services')->where('name', 'Colour')->value('id'), 'staff_id' => DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id')]],
        'start' => firstWindowDay(2)->setTime($hour, 0)->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));
    $checkout = $held['checkout_url'] ?? null;

    return ['uuid' => $held['uuid'], 'manage_token' => $held['manage_token'], ...(is_string($checkout) ? ['checkout_url' => $checkout] : [])];
}

/**
 * The pay hop of a hold under the prefix given, bare for none, as TenantUrls spells it.
 *
 * @param  array{uuid: string, manage_token: string, checkout_url?: string}  $held
 */
function dplHop(array $held, ?SiteLanguage $prefix): string
{
    return app(TenantUrls::class)->payLink(Slug::of('luna'), $held['uuid'], $held['manage_token'], $prefix);
}

it('names the pay hop a hold answers under the prefix of the language the hold named, bare where it named none, and the hop under /el leads back under /el', function () {
    connectPaymentFake();
    $urls = app(TenantUrls::class);

    $greek = dplHold($this, 11, 'el');
    $english = dplHold($this, 14, 'en');
    $unnamed = dplHold($this, 16, null);

    expect($greek['checkout_url'] ?? null)->toBe(dplHop($greek, SiteLanguage::Greek))
        ->and($greek['checkout_url'] ?? null)->toStartWith(tenantUrl('luna', '/el/pay/'.$greek['uuid']).'?')
        ->and($english['checkout_url'] ?? null)->toBe(dplHop($english, SiteLanguage::English))
        ->and($unnamed['checkout_url'] ?? null)->toBe(dplHop($unnamed, null))
        ->and(DB::table('tenant_luna.bookings')->whereIn('uuid', [$greek['uuid'], $english['uuid'], $unnamed['uuid']])->orderBy('id')->pluck('language')->all())->toBe(['el', 'en', 'en']);

    $back = $urls->manageLink(Slug::of('luna'), $greek['uuid'], $greek['manage_token'], SiteLanguage::Greek);

    $this->get($greek['checkout_url'] ?? '')->assertStatus(303)->assertRedirect($back.'&paydesk_session=pd_1');

    expect(paymentFake()->sessions['pd_1']['returnUrl'] ?? null)->toBe($back);
});
