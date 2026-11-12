<?php

use App\Application\Clock;
use App\Application\MyBookings\BookingsLinkRequested;
use App\Application\MyBookings\RequestBookingsLink;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\BookingReader;
use App\Jobs\SendAcknowledgement;
use App\Jobs\SendBookingsLink;
use App\Jobs\SendCancellation;
use App\Jobs\SendConfirmation;
use App\Jobs\SendMove;
use App\Jobs\SendReminder;
use App\Jobs\SendRequestApproved;
use App\Jobs\SendRequestExpired;
use App\Jobs\SendRequestRejected;
use App\Jobs\SendThankYou;
use App\Notifications\TenantClock;
use App\Notifications\TenantTime;
use App\Site\SiteLanguage;
use App\Tenancy\PayLinks;
use App\Tenancy\Slug;
use App\Tenancy\TenantPages;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The customer's messages in the booking's language: every form and every subject, and the
 * calendar file's summary and description, is written in bookings.language, the language the hold
 * was made in, and never in the tenant's default, which may change after the booking, nor in the
 * application's locale, which a queued job never set. A booking made in Greek is written to in
 * Greek after its tenant switched its default to English, and one made in English in English
 * after the switch the other way: the first six forms, then the cancellation, the move, the
 * reminder and the thank-you with Book again and the line to My bookings, and the gate's four
 * mails, the confirmation, the reminder, the move and the cancellation of one booking. The
 * bookings link, about no booking, is in the language of the request that asked for it, carried
 * on its record and its job.
 *
 * customer-mail.php and decision.php hold their Greek, and each form is walked by their keys
 * (linesShownIn): every key a Greek form should show shown in Greek, its subject and its calendar
 * file's summary and description included, no English line of either file left but the shared, no
 * :word unfilled; an English form shows no Greek line; the last case holds the walks together to
 * customer-mail.php's keys. The mail as sent, read back from the transport, carries the Greek
 * intact, and the calendar file folds at character boundaries. Every sentence is read through the
 * translator and the instants are ICU's in the booking's language, compared with the formatter's
 * answer and never with a Greek literal.
 *
 * And the links these messages carry: the manage link and the awaiting-payment form's payment
 * link are minted under the prefix of the booking's language, and so are Book again, the My
 * bookings page and the bookings link, so the page each opens speaks the mail's language whatever
 * the tenant changes later, and a bare link minted before still opens the same pages.
 *
 * Not wrapped in a transaction: the holds enter the transaction runner. luna is in
 * Europe/Athens; 2026-11-03 is a Tuesday, when Elena works 09:00 to 13:00, and the clock stands
 * on the Monday before.
 */

beforeEach(function () {
    $this->withoutVite();
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
    // The customer's five keys alone: the business's messages and the nags are the business's.
    seamSwitchOff(seamEveryKeyBut(MessageKey::Acknowledged, MessageKey::Confirmed, MessageKey::Approved, MessageKey::Rejected, MessageKey::RequestExpired));
    seamTransport()->flush();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/** The start a moved booking left, as SendMove carries it: the Monday at 11:00 in Athens. */
const CML_MOVED_FROM = '2026-11-02 09:00:00.000000+00:00';

/**
 * What a walk by keys reads in a message: its subject, its text and, where it carries a calendar
 * file, the file's summary and description, unfolded.
 */
function cmlShownIn(Email $mail): string
{
    $shown = [(string) $mail->getSubject(), (string) $mail->getTextBody()];

    foreach ($mail->getAttachments() as $file) {
        preg_match_all('/^(?:SUMMARY|DESCRIPTION):([^\r\n]*)/m', str_replace("\r\n ", '', $file->getBody()), $values);
        array_push($shown, ...$values[1]);
    }

    return implode("\n", $shown);
}

/**
 * The walk's verdict on a message written in Greek: no English line of customer-mail.php or of
 * decision.php left but the shared, no :word unfilled, and each key named shown in Greek.
 *
 * @param  list<string>  $keys  keys of customer-mail.php, the file named
 */
function cmlWalkedGreek(Email $mail, array $keys): void
{
    $text = cmlShownIn($mail);
    $shown = array_map(fn (string $key): string => 'customer-mail.'.$key, linesShownIn($text, 'customer-mail', SiteLanguage::Greek));

    expect(linesShownIn($text, 'customer-mail', SiteLanguage::English))->toBe([])
        ->and(linesShownIn($text, 'decision', SiteLanguage::English))->toBe([])
        ->and(unfilledIn($text))->toBe([])
        ->and($keys)->not->toBeEmpty()
        ->and(array_values(array_diff($keys, $shown)))->toBe([]);
}

/**
 * The walk's verdict on a message written in English: no Greek line of either file in it.
 */
function cmlWalkedEnglish(Email $mail): void
{
    $text = cmlShownIn($mail);

    expect(linesShownIn($text, 'customer-mail', SiteLanguage::Greek))->toBe([])
        ->and(linesShownIn($text, 'decision', SiteLanguage::Greek))->toBe([]);
}

/**
 * Asserts every line of each calendar file a message carries is within the format's 75 octets
 * and whole UTF-8, a fold never cutting a character, and answers how many folds fell inside
 * text that is not ASCII.
 */
function cmlFoldedAtCharacters(Email $mail): int
{
    $inside = 0;

    foreach ($mail->getAttachments() as $file) {
        $lines = explode("\r\n", rtrim($file->getBody(), "\r\n"));

        foreach ($lines as $at => $line) {
            expect(strlen($line))->toBeLessThanOrEqual(75)
                ->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();

            $next = $lines[$at + 1] ?? '';

            if (str_starts_with($next, ' ') && preg_match('/[^\x00-\x7f]\z/', $line) === 1 && preg_match('/\A [^\x00-\x7f]/', $next) === 1) {
                $inside++;
            }
        }
    }

    return $inside;
}

/**
 * A hold for Bridal trial with Elena, the request a human decides, made with the lang given, none
 * for none, with the uuid and the manage token it answered.
 *
 * @return array{uuid: string, token: string}
 */
function cmlRequest(TestCase $test, ?string $lang): array
{
    [$url, $body] = seamHold('Bridal trial', FixtureDay::START);

    return seamHeld($test->postJson($url.($lang === null ? '' : '?lang='.$lang), $body));
}

/**
 * Every form of the messages a job writes about luna's request, each the job that sends it, built
 * from the tenant's id, the uuid and the manage token, and the keys of customer-mail.php its
 * subject and body read, in the order the body reads them: the forms of the acknowledgement, the
 * confirmation, the approval, the rejection and the expiry, and the forms of the cancellation and
 * the move only a request, or a booking awaiting its payment, takes. A form whose message needs
 * the row or the switches otherwise sets them in its closure. Named before anything is booked, so
 * a dataset may list them.
 *
 * @return array<string, array{Closure(int, string, string): object, string, list<string>}>
 */
function cmlForms(): array
{
    $mail = fn (string $key): string => 'customer-mail.'.$key;

    return [
        'the acknowledgement' => [fn (int $tenant, string $uuid, string $token): object => new SendAcknowledgement($tenant, $uuid, $token), $mail('acknowledged.subject'), array_map($mail, ['greeting', 'acknowledged.received', 'acknowledged.deciding', 'acknowledged.manage', 'zone'])],
        'the confirmation with its manage link' => [fn (int $tenant, string $uuid, string $token): object => new SendConfirmation($tenant, $uuid, $token), $mail('confirmed.subject'), array_map($mail, ['greeting', 'confirmed.confirmed', 'confirmed.calendar', 'confirmed.manage', 'zone'])],
        'the confirmation after a payment, the acknowledgement sent' => [fn (int $tenant, string $uuid, string $token): object => new SendConfirmation($tenant, $uuid, null, true, true), $mail('confirmed.subject'), array_map($mail, ['greeting', 'confirmed.confirmed', 'confirmed.calendar', 'confirmed.where_link.acknowledged', 'zone'])],
        'the confirmation after a payment, no request before it' => [fn (int $tenant, string $uuid, string $token): object => new SendConfirmation($tenant, $uuid, null, true, false), $mail('confirmed.subject'), array_map($mail, ['greeting', 'confirmed.confirmed', 'confirmed.calendar', 'confirmed.where_link.unacknowledged', 'zone'])],
        'the approval' => [fn (int $tenant, string $uuid, string $token): object => new SendRequestApproved($tenant, $uuid), $mail('approved.confirmed.subject'), array_map($mail, ['greeting', 'approved.confirmed.approved', 'approved.confirmed.where_link.acknowledged', 'zone'])],
        'the approval, the acknowledgement switched off' => [function (int $tenant, string $uuid, string $token): object {
            seamSwitchOff(seamEveryKeyBut(MessageKey::Approved));

            return new SendRequestApproved($tenant, $uuid);
        }, $mail('approved.confirmed.subject'), array_map($mail, ['greeting', 'approved.confirmed.approved', 'approved.confirmed.where_link.unacknowledged', 'zone'])],
        'the approval awaiting payment' => [fn (int $tenant, string $uuid, string $token): object => new SendRequestApproved($tenant, $uuid, true), $mail('approved.awaiting_payment.subject'), array_map($mail, ['greeting', 'approved.awaiting_payment.approved', 'approved.awaiting_payment.pay', 'approved.awaiting_payment.held', 'zone'])],
        'the rejection' => [fn (int $tenant, string $uuid, string $token): object => new SendRequestRejected($tenant, $uuid), $mail('rejected.subject'), array_map($mail, ['greeting', 'rejected.declined', 'rejected.free_again', 'zone'])],
        'the timeout' => [fn (int $tenant, string $uuid, string $token): object => new SendRequestExpired($tenant, $uuid), $mail('request_expired.timed_out.subject'), array_map($mail, ['greeting', 'request_expired.timed_out.expired', 'request_expired.timed_out.free_again', 'zone'])],
        'the unpaid lapse' => [fn (int $tenant, string $uuid, string $token): object => new SendRequestExpired($tenant, $uuid, true), $mail('request_expired.unpaid.subject'), array_map($mail, ['greeting', 'request_expired.unpaid.expired', 'request_expired.unpaid.free_again', 'zone'])],
        'the cancellation of the request by the business' => [fn (int $tenant, string $uuid, string $token): object => new SendCancellation($tenant, $uuid, true, false), $mail('cancelled.request.by_business.subject'), array_map($mail, ['greeting', 'cancelled.request.by_business.cancelled', 'cancelled.request.by_business.means', 'zone'])],
        'the move of the request by the customer' => [fn (int $tenant, string $uuid, string $token): object => new SendMove($tenant, $uuid, true, true, CML_MOVED_FROM), $mail('moved.request.subject'), array_map($mail, ['greeting', 'moved.request.by_customer', 'moved.unchanged', 'zone'])],
        'the move of the request by the business' => [fn (int $tenant, string $uuid, string $token): object => new SendMove($tenant, $uuid, true, false, CML_MOVED_FROM), $mail('moved.request.subject'), array_map($mail, ['greeting', 'moved.request.by_business', 'moved.unchanged', 'moved.unexpected', 'zone'])],
        'the move of a booking awaiting its payment' => [function (int $tenant, string $uuid, string $token): object {
            DB::table('tenant_luna.bookings')->where('uuid', $uuid)->update(['status' => 'awaiting_payment']);

            return new SendMove($tenant, $uuid, false, true, CML_MOVED_FROM, true);
        }, $mail('moved.booking.subject'), array_map($mail, ['greeting', 'moved.booking.by_customer', 'moved.awaiting_payment', 'zone'])],
    ];
}

/**
 * The keys a request's forms send under: the acknowledgement, the confirmation, the approval,
 * the rejection, the expiry, the cancellation and the move.
 */
function cmlRequestKeysOn(): void
{
    seamSwitchOff(seamEveryKeyBut(MessageKey::Acknowledged, MessageKey::Confirmed, MessageKey::Approved, MessageKey::Rejected, MessageKey::RequestExpired, MessageKey::Cancelled, MessageKey::Moved));
}

/**
 * The values each line of a request's forms is filled with for luna's request: the start the
 * move left and the start it has as ICU spells them in the language given.
 *
 * @return array<string, string>
 */
function cmlValues(SiteLanguage $language): array
{
    return [
        'customer' => LunaFixture::CUSTOMER_NAME,
        'business' => LunaFixture::tenant()->name,
        'service' => 'Bridal trial',
        'zone' => FixtureDay::ZONE,
        'from' => lunaInstant(new DateTimeImmutable(CML_MOVED_FROM, new DateTimeZone('UTC')), $language),
        'to' => lunaInstant(LunaFixture::at(FixtureDay::START), $language),
    ];
}

/**
 * Runs the job the way a worker runs it and answers the one message it sent the customer.
 */
function cmlSent(object $job): Email
{
    seamTransport()->flush();
    app(Dispatcher::class)->dispatchNow($job);

    return seamAt(LunaFixture::CUSTOMER_EMAIL);
}

/**
 * A line of the six in a language, filled with luna's values for the names it holds.
 */
function cmlLine(string $key, SiteLanguage $language): string
{
    return customerMailLine($key, cmlValues($language), $language);
}

it('writes {0} in the booking\'s Greek at a tenant whose default was Greek at the hold and is English by the send, walked by keys: its subject, its lines, its facts, its instants and its calendar file, sent intact', function (string $form) {
    settingsOf('luna')->update(['language' => 'el']);
    ['uuid' => $uuid, 'token' => $token] = cmlRequest($this, null);
    settingsOf('luna')->update(['language' => 'en']);
    cmlRequestKeysOn();

    [$build, $subject, $keys] = cmlForms()[$form];
    $mail = cmlSent($build(LunaFixture::tenant()->id, $uuid, $token));
    $text = (string) $mail->getTextBody();
    $booking = app(BookingReader::class)->snapshot(LunaFixture::tenant(), $uuid);
    assert($booking !== null);
    $files = $mail->getAttachments();

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('language'))->toBe('el')
        ->and($mail->getSubject())->toBe(cmlLine($subject, SiteLanguage::Greek))
        ->and($text)->toContain(TenantTime::instant($booking->startsAt(), new TenantClock(new DateTimeZone(FixtureDay::ZONE)), SiteLanguage::Greek));

    foreach ($keys as $key) {
        expect($text)->toContain(cmlLine($key, SiteLanguage::Greek));
    }

    cmlWalkedGreek($mail, [$subject, ...$keys, ...($files === [] ? [] : ['customer-mail.calendar.summary'])]);
    mailSentIntact($mail);
    cmlFoldedAtCharacters($mail);

    foreach ($files as $file) {
        $ics = str_replace("\r\n ", '', $file->getBody());

        expect($ics)->toContain('SUMMARY:'.cmlLine('customer-mail.calendar.summary', SiteLanguage::Greek))
            ->and($ics)->toContain('DESCRIPTION:'.decisionLine('decision.facts.item', ['service' => 'Bridal trial', 'staff' => 'Elena Papadaki'], SiteLanguage::Greek));
    }
})->with(fn () => array_keys(cmlForms()));

it('writes {0} in the booking\'s English at a tenant whose default was English at the hold and is Greek by the send, no Greek line in it', function (string $form) {
    settingsOf('luna')->update(['language' => 'en']);
    ['uuid' => $uuid, 'token' => $token] = cmlRequest($this, 'en');
    settingsOf('luna')->update(['language' => 'el']);
    cmlRequestKeysOn();

    [$build, $subject, $keys] = cmlForms()[$form];
    $mail = cmlSent($build(LunaFixture::tenant()->id, $uuid, $token));
    $text = (string) $mail->getTextBody();

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('language'))->toBe('en')
        ->and($mail->getSubject())->toBe(cmlLine($subject, SiteLanguage::English));

    foreach ($keys as $key) {
        expect($text)->toContain(cmlLine($key, SiteLanguage::English));
    }

    cmlWalkedEnglish($mail);

    foreach ($mail->getAttachments() as $file) {
        expect(str_replace("\r\n ", '', $file->getBody()))->toContain('SUMMARY:'.cmlLine('customer-mail.calendar.summary', SiteLanguage::English));
    }
})->with(fn () => array_keys(cmlForms()));

it('carries the manage link under the prefix of the booking\'s language in the acknowledgement and the confirmation, and the link opens the manage page in that language', function (?string $lang, SiteLanguage $language) {
    ['uuid' => $uuid, 'token' => $token] = cmlRequest($this, $lang);
    $link = app(TenantUrls::class)->manageLink(Slug::of('luna'), $uuid, $token, $language);

    $acknowledgement = (string) seamAt(LunaFixture::CUSTOMER_EMAIL)->getTextBody();
    $confirmation = (string) cmlSent(new SendConfirmation(LunaFixture::tenant()->id, $uuid, $token))->getTextBody();

    expect(str_starts_with($link, tenantUrl('luna', '/'.$language->value.'/manage/'.$uuid.'?')))->toBeTrue()
        ->and(seamLinksIn($acknowledgement))->toContain($link)
        ->and(seamLinksIn($confirmation))->toContain($link);

    $page = $this->get($link)->assertOk();

    expect(documentOf($page)->documentElement?->getAttribute('lang'))->toBe($language->value);
})->with([
    'a Greek booking' => ['el', SiteLanguage::Greek],
    'an English booking, its prefix named too' => [null, SiteLanguage::English],
]);

it('carries the payment link under the prefix of the booking\'s language in the awaiting-payment form, the link opening its page there', function (?string $lang, SiteLanguage $language) {
    ['uuid' => $uuid] = cmlRequest($this, $lang);

    $text = (string) cmlSent(new SendRequestApproved(LunaFixture::tenant()->id, $uuid, true))->getTextBody();
    $links = array_values(array_filter(seamLinksIn($text), fn (string $link): bool => str_contains($link, '/link?')));

    expect($links)->toHaveCount(1)
        ->and(str_starts_with($links[0], tenantUrl('luna', '/'.$language->value.'/pay/'.$uuid.'/link?')))->toBeTrue();

    $page = $this->get($links[0])->assertOk();

    expect(documentOf($page)->documentElement?->getAttribute('lang'))->toBe($language->value);
})->with([
    'a Greek booking' => ['el', SiteLanguage::Greek],
    'an English booking, its prefix named too' => [null, SiteLanguage::English],
]);

it('still opens the manage page and the payment page from a bare link, as every link minted before the language prefix was', function () {
    ['uuid' => $uuid, 'token' => $token] = cmlRequest($this, 'el');
    $booking = app(BookingReader::class)->snapshot(LunaFixture::tenant(), $uuid);
    assert($booking !== null);

    $manage = app(TenantUrls::class)->manageLink(Slug::of('luna'), $uuid, $token);
    $pay = app(PayLinks::class)->link(LunaFixture::tenant(), $uuid, $booking->endsAt());

    expect(str_starts_with($manage, tenantUrl('luna', '/manage/')))->toBeTrue()
        ->and(str_starts_with($pay, tenantUrl('luna', '/pay/')))->toBeTrue();

    $this->get($manage)->assertOk();
    $this->get($pay)->assertOk();
});

/*
 * The rest of the customer's messages, over a confirmed Haircut with Elena at 10:00 on the
 * Tuesday, and the bookings link.
 */

const CML_REVIEWS = 'https://g.page/r/luna-hair-studio/review';

/**
 * A confirmed Haircut with Elena at 10:00 on the Tuesday, held while luna's default was the
 * language given and confirmed, luna's default then switched to the other language and every
 * message the hold and the confirm sent cleared away: the uuid and the manage token.
 *
 * @return array{uuid: string, token: string}
 */
function cmlConfirmedThenSwitched(TestCase $test, SiteLanguage $madeIn): array
{
    settingsOf('luna')->update(['language' => $madeIn->value]);
    [$url, $body] = seamHold('Haircut', FixtureDay::START);
    $held = seamHeld($test->postJson($url, $body));
    seamConfirm($test, $held['uuid'], $held['token']);
    settingsOf('luna')->update(['language' => $madeIn->other()->value]);
    seamTransport()->flush();

    return $held;
}

/**
 * The reminder SendReminder would be queued as for the booking as it stands.
 */
function cmlReminder(int $tenant, string $uuid): SendReminder
{
    $reader = app(BookingReader::class);
    $booking = $reader->snapshot(LunaFixture::tenant(), $uuid) ?? throw new LogicException('No booking '.$uuid);

    return new SendReminder($tenant, $uuid, SendReminder::instant($booking->startsAt()), $reader->revision(LunaFixture::tenant(), $uuid));
}

/**
 * The messages about a confirmed booking of luna's, each the job that sends it, the key of its
 * subject and the keys of its own paragraphs in the order the body reads them, whether it carries
 * Book again, and whether it carries a calendar file; the lines ending on a link, Book again, the
 * reviews line and the line to My bookings, are each case's own. Built from ids alone, so a
 * dataset may name the forms before anything is booked.
 *
 * @return array<string, array{Closure(int, string): object, string, list<string>, bool, bool}>
 */
function cmlLaterForms(): array
{
    $mail = fn (string $key): string => 'customer-mail.'.$key;

    return [
        'the cancellation by the customer' => [fn (int $tenant, string $uuid): object => new SendCancellation($tenant, $uuid, false, true), $mail('cancelled.booking.by_customer.subject'), array_map($mail, ['greeting', 'cancelled.booking.by_customer.cancelled', 'cancelled.booking.by_customer.means', 'zone']), false, false],
        'the cancellation by the business' => [fn (int $tenant, string $uuid): object => new SendCancellation($tenant, $uuid, false, false), $mail('cancelled.booking.by_business.subject'), array_map($mail, ['greeting', 'cancelled.booking.by_business.cancelled', 'cancelled.booking.by_business.means', 'zone']), false, false],
        'the withdrawal of a request' => [fn (int $tenant, string $uuid): object => new SendCancellation($tenant, $uuid, true, true), $mail('cancelled.request.by_customer.subject'), array_map($mail, ['greeting', 'cancelled.request.by_customer.cancelled', 'cancelled.request.by_customer.means', 'zone']), false, false],
        'the move by the customer' => [fn (int $tenant, string $uuid): object => new SendMove($tenant, $uuid, false, true, CML_MOVED_FROM), $mail('moved.booking.subject'), array_map($mail, ['greeting', 'moved.booking.by_customer', 'moved.calendar', 'zone']), false, true],
        'the move by the business' => [fn (int $tenant, string $uuid): object => new SendMove($tenant, $uuid, false, false, CML_MOVED_FROM), $mail('moved.booking.subject'), array_map($mail, ['greeting', 'moved.booking.by_business', 'moved.calendar', 'moved.unexpected', 'zone']), false, true],
        'the reminder' => [fn (int $tenant, string $uuid): object => cmlReminder($tenant, $uuid), $mail('reminder.subject'), array_map($mail, ['greeting', 'reminder.reminder', 'reminder.earlier', 'zone']), true, false],
        'the thank-you' => [function (int $tenant, string $uuid): object {
            DB::table('tenant_luna.bookings')->where('uuid', $uuid)->update(['status' => 'completed']);
            settingsOf('luna')->update(['google_reviews_url' => CML_REVIEWS]);

            return new SendThankYou($tenant, $uuid);
        }, $mail('thank_you.subject'), array_map($mail, ['greeting', 'thank_you.thanks', 'thank_you.again']), true, false],
    ];
}

/**
 * A line of the confirmed booking's messages in a language, filled with luna's values for the
 * Haircut: the customer, the business, the zone, and the start the move left and the start it has
 * as ICU spells them in that language; and the link given for a line ending on one.
 */
function cmlLaterLine(string $key, SiteLanguage $language, string $link = ''): string
{
    return customerMailLine($key, [
        'customer' => LunaFixture::CUSTOMER_NAME,
        'business' => LunaFixture::tenant()->name,
        'service' => 'Haircut',
        'zone' => FixtureDay::ZONE,
        'from' => lunaInstant(new DateTimeImmutable(CML_MOVED_FROM, new DateTimeZone('UTC')), $language),
        'to' => lunaInstant(LunaFixture::at(FixtureDay::START), $language),
        'link' => $link,
    ], $language);
}

/**
 * Book again for the Haircut on Elena, under the prefix of a language.
 */
function cmlAgain(SiteLanguage $language): string
{
    return app(TenantUrls::class)->bookingLink(Slug::of('luna'), ['service' => seamId('services', 'Haircut'), 'staff' => seamId('staff', 'Elena Papadaki')], $language);
}

/**
 * How long the bookings link opens for in a language: the request's lifetime in minutes, chosen
 * by and spelled out by ICU in the language the line is read in, the soft hyphen ICU puts inside
 * some Greek spellings taken out as the mail takes it out.
 */
function cmlLifetime(SiteLanguage $language): string
{
    $minutes = (new DateInterval(RequestBookingsLink::LIFETIME))->i;
    $spelled = (new NumberFormatter($language->value, NumberFormatter::SPELLOUT))->format($minutes);
    assert(is_string($spelled));

    return trans_choice('customer-mail.my_bookings_link.once', $minutes, ['count' => str_replace("\u{00AD}", '', $spelled)], $language->value);
}

/**
 * Asserts one of the confirmed booking's messages is written in the language given: its
 * subject, its own paragraphs, its instant where it carries one, the line to My bookings and
 * Book again where it carries it, every link under the language's prefix, and its calendar
 * file's summary and description where it carries one.
 *
 * @param  list<string>  $keys
 */
function cmlExpectLater(Email $mail, string $subject, array $keys, bool $again, bool $file, SiteLanguage $language): void
{
    $text = (string) $mail->getTextBody();

    expect($mail->getSubject())->toBe(cmlLaterLine($subject, $language))
        ->and($text)->toContain(cmlLaterLine('customer-mail.my_bookings', $language, app(TenantUrls::class)->myBookings(Slug::of('luna'), $language)));

    foreach ($keys as $key) {
        expect($text)->toContain(cmlLaterLine($key, $language));
    }

    foreach (seamLinksIn($text) as $link) {
        expect(str_starts_with($link, tenantUrl('luna', '/'.$language->value)) || $link === CML_REVIEWS)->toBeTrue();
    }

    if ($again) {
        expect($text)->toContain(cmlLaterLine('customer-mail.book_again', $language, cmlAgain($language)));
    }

    if ($subject === 'customer-mail.thank_you.subject') {
        expect($text)->toContain(cmlLaterLine('customer-mail.thank_you.review', $language, CML_REVIEWS))
            ->and($text)->toContain('    '.decisionLine('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki'], $language));
    } else {
        expect($text)->toContain(lunaInstant(LunaFixture::at(FixtureDay::START), $language));
    }

    expect($mail->getAttachments())->toHaveCount($file ? 1 : 0);

    foreach ($mail->getAttachments() as $attachment) {
        $ics = str_replace("\r\n ", '', $attachment->getBody());

        expect($ics)->toContain('SUMMARY:'.cmlLaterLine('customer-mail.calendar.summary', $language))
            ->and($ics)->toContain('DESCRIPTION:'.decisionLine('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki'], $language));
    }
}

/**
 * The keys of customer-mail.php one of the confirmed booking's messages shows: its subject and
 * its own paragraphs, the line to My bookings, Book again where it carries it, the reviews
 * line on the thank-you, and the calendar file's summary where it carries one.
 *
 * @param  list<string>  $keys
 * @return list<string>
 */
function cmlLaterKeysOf(string $subject, array $keys, bool $again, bool $file): array
{
    return [
        $subject,
        ...$keys,
        'customer-mail.my_bookings',
        ...($again ? ['customer-mail.book_again'] : []),
        ...($subject === 'customer-mail.thank_you.subject' ? ['customer-mail.thank_you.review'] : []),
        ...($file ? ['customer-mail.calendar.summary'] : []),
    ];
}

it('writes {0} in the booking\'s Greek at a tenant whose default was Greek at the hold and is English by the send, its subject, its lines, its instants, Book again and the line to My bookings under /el, and its calendar file', function (string $form) {
    ['uuid' => $uuid] = cmlConfirmedThenSwitched($this, SiteLanguage::Greek);
    seamSwitchOff(seamEveryKeyBut(MessageKey::Cancelled, MessageKey::Moved, MessageKey::Reminder, MessageKey::ThankYou, MessageKey::MyBookingsLink));

    [$job, $subject, $keys, $again, $file] = cmlLaterForms()[$form];
    $mail = cmlSent($job(LunaFixture::tenant()->id, $uuid));

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('language'))->toBe('el')
        ->and(settingsOf('luna')->value('language'))->toBe('en');

    cmlExpectLater($mail, $subject, $keys, $again, $file, SiteLanguage::Greek);
    cmlWalkedGreek($mail, cmlLaterKeysOf($subject, $keys, $again, $file));
    mailSentIntact($mail);
    cmlFoldedAtCharacters($mail);
})->with(fn () => array_keys(cmlLaterForms()));

it('writes {0} in the booking\'s English at a tenant whose default was English at the hold and is Greek by the send, its links under /en', function (string $form) {
    ['uuid' => $uuid] = cmlConfirmedThenSwitched($this, SiteLanguage::English);
    seamSwitchOff(seamEveryKeyBut(MessageKey::Cancelled, MessageKey::Moved, MessageKey::Reminder, MessageKey::ThankYou, MessageKey::MyBookingsLink));

    [$job, $subject, $keys, $again, $file] = cmlLaterForms()[$form];
    $mail = cmlSent($job(LunaFixture::tenant()->id, $uuid));

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('language'))->toBe('en');

    cmlExpectLater($mail, $subject, $keys, $again, $file, SiteLanguage::English);
    cmlWalkedEnglish($mail);
})->with(fn () => array_keys(cmlLaterForms()));

it('writes the gate\'s four mails of a booking made in Greek, its confirmation, its reminder, its move and its cancellation, each in Greek after its tenant switched its default to English, the move\'s calendar file too', function () {
    ['uuid' => $uuid, 'token' => $token] = cmlConfirmedThenSwitched($this, SiteLanguage::Greek);
    seamSwitchOff(seamEveryKeyBut(MessageKey::Confirmed, MessageKey::Reminder, MessageKey::Moved, MessageKey::Cancelled, MessageKey::MyBookingsLink));
    $tenant = LunaFixture::tenant()->id;
    $greek = SiteLanguage::Greek;

    $confirmation = cmlSent(new SendConfirmation($tenant, $uuid, $token));
    $reminder = cmlSent(cmlReminder($tenant, $uuid));
    $move = cmlSent(new SendMove($tenant, $uuid, false, true, CML_MOVED_FROM));
    $cancellation = cmlSent(new SendCancellation($tenant, $uuid, false, true));

    $mail = fn (string $key): string => 'customer-mail.'.$key;
    $confirmed = (string) $confirmation->getTextBody();

    expect(settingsOf('luna')->value('language'))->toBe('en')
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('language'))->toBe('el')
        ->and($confirmation->getSubject())->toBe(cmlLaterLine($mail('confirmed.subject'), $greek))
        ->and($confirmed)->toContain(cmlLaterLine($mail('confirmed.confirmed'), $greek))
        ->and($confirmed)->toContain(app(TenantUrls::class)->manageLink(Slug::of('luna'), $uuid, $token, $greek))
        ->and($confirmed)->toContain(cmlLaterLine($mail('book_again'), $greek, cmlAgain($greek)))
        ->and($confirmed)->toContain(cmlLaterLine($mail('my_bookings'), $greek, app(TenantUrls::class)->myBookings(Slug::of('luna'), $greek)))
        ->and($confirmed)->toContain(lunaInstant(LunaFixture::at(FixtureDay::START), $greek))
        ->and(str_replace("\r\n ", '', $confirmation->getAttachments()[0]->getBody()))->toContain('DESCRIPTION:'.decisionLine('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki'], $greek));

    cmlExpectLater($reminder, $mail('reminder.subject'), array_map($mail, ['greeting', 'reminder.reminder', 'reminder.earlier', 'zone']), true, false, $greek);
    cmlExpectLater($move, $mail('moved.booking.subject'), array_map($mail, ['greeting', 'moved.booking.by_customer', 'moved.calendar', 'zone']), false, true, $greek);
    cmlExpectLater($cancellation, $mail('cancelled.booking.by_customer.subject'), array_map($mail, ['greeting', 'cancelled.booking.by_customer.cancelled', 'cancelled.booking.by_customer.means', 'zone']), false, false, $greek);

    // Each of the four walked by keys: no English line of either file in it, the words
    // read through the translator and never spelled here.
    cmlWalkedGreek($confirmation, array_map($mail, ['confirmed.subject', 'greeting', 'confirmed.confirmed', 'confirmed.calendar', 'confirmed.manage', 'book_again', 'my_bookings', 'zone', 'calendar.summary']));
    cmlWalkedGreek($reminder, cmlLaterKeysOf($mail('reminder.subject'), array_map($mail, ['greeting', 'reminder.reminder', 'reminder.earlier', 'zone']), true, false));
    cmlWalkedGreek($move, cmlLaterKeysOf($mail('moved.booking.subject'), array_map($mail, ['greeting', 'moved.booking.by_customer', 'moved.calendar', 'zone']), false, true));
    cmlWalkedGreek($cancellation, cmlLaterKeysOf($mail('cancelled.booking.by_customer.subject'), array_map($mail, ['greeting', 'cancelled.booking.by_customer.cancelled', 'cancelled.booking.by_customer.means', 'zone']), false, false));
});

/**
 * A service name of the tenant's that puts the 75th octet of the Greek calendar file's SUMMARY
 * line inside the Greek after it, so the fold has a character to keep whole.
 */
const CML_LONG_SERVICE = 'Haircut and blow dry with styling for a long event';

it('folds a Greek booking\'s calendar file at character boundaries, every line within 75 octets and a fold falling inside the Greek', function () {
    ['uuid' => $uuid, 'token' => $token] = cmlConfirmedThenSwitched($this, SiteLanguage::Greek);
    seamSwitchOff(seamEveryKeyBut(MessageKey::Confirmed));
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['name' => CML_LONG_SERVICE]);

    $mail = cmlSent(new SendConfirmation(LunaFixture::tenant()->id, $uuid, $token));
    $ics = str_replace("\r\n ", '', $mail->getAttachments()[0]->getBody());
    $summary = customerMailLine('customer-mail.calendar.summary', ['service' => CML_LONG_SERVICE, 'business' => LunaFixture::tenant()->name], SiteLanguage::Greek);

    expect(strlen('SUMMARY:'.CML_LONG_SERVICE))->toBeLessThan(75)
        ->and(strlen('SUMMARY:'.$summary))->toBeGreaterThan(75)
        ->and($ics)->toContain('SUMMARY:'.$summary)
        ->and($ics)->toContain('DESCRIPTION:'.decisionLine('decision.facts.item', ['service' => CML_LONG_SERVICE, 'staff' => 'Elena Papadaki'], SiteLanguage::Greek))
        ->and(cmlFoldedAtCharacters($mail))->toBeGreaterThanOrEqual(1);
});

it('opens the hosted page with its preselection and My bookings in Greek from the addresses a Greek booking\'s reminder carries, each under /el', function () {
    ['uuid' => $uuid] = cmlConfirmedThenSwitched($this, SiteLanguage::Greek);
    seamSwitchOff(seamEveryKeyBut(MessageKey::Reminder, MessageKey::MyBookingsLink));

    $links = seamLinksIn((string) cmlSent(cmlReminder(LunaFixture::tenant()->id, $uuid))->getTextBody());

    expect($links)->toBe([cmlAgain(SiteLanguage::Greek), app(TenantUrls::class)->myBookings(Slug::of('luna'), SiteLanguage::Greek)])
        ->and(str_starts_with($links[0], tenantUrl('luna', '/el?')))->toBeTrue()
        ->and($links[1])->toBe(tenantUrl('luna', '/el/my-bookings'));

    $hosted = documentOf($this->get($links[0])->assertOk());
    $mine = documentOf($this->get($links[1])->assertOk());

    expect($hosted->documentElement?->getAttribute('lang'))->toBe('el')
        ->and(mountOf($hosted)->getAttribute('data-service'))->toBe((string) seamId('services', 'Haircut'))
        ->and(mountOf($hosted)->getAttribute('data-staff'))->toBe((string) seamId('staff', 'Elena Papadaki'))
        ->and($mine->documentElement?->getAttribute('lang'))->toBe('el');
});

it('writes the bookings link asked from {0} in {3}, the language of the request that asked for it, carried on its record and its job, its link minted under that language\'s prefix and opening its page in it', function (string $where, ?SiteLanguage $prefix, SiteLanguage $default, SiteLanguage $expected) {
    settingsOf('luna')->update(['language' => $default->value]);
    seamSwitchOff(seamEveryKeyBut(MessageKey::MyBookingsLink));
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');
    $records = [];
    Event::listen(BookingsLinkRequested::class, function (BookingsLinkRequested $record) use (&$records): void {
        $records[] = $record;
    });
    $queue = Queue::fake();

    $this->post(tenantUrl('luna', TenantPages::uri('/my-bookings/link', $prefix)), ['email' => MYB_CASEY])->assertRedirect();

    $jobs = $queue->pushed(SendBookingsLink::class);
    $job = $jobs->first();
    assert($job instanceof SendBookingsLink);

    expect($records)->toHaveCount(1)
        ->and($records[0]->language)->toBe($expected->value)
        ->and($jobs)->toHaveCount(1)
        ->and($job->language)->toBe($expected->value);

    app(Dispatcher::class)->dispatchNow($job);
    $mail = seamAt(MYB_CASEY);
    $text = (string) $mail->getTextBody();
    $links = seamLinksIn($text);
    $name = ['business' => LunaFixture::tenant()->name];

    expect($mail->getSubject())->toBe(customerMailLine('customer-mail.my_bookings_link.subject', $name, $expected))
        ->and($text)->toContain(customerMailLine('customer-mail.my_bookings_link.greeting', [], $expected))
        ->and($text)->toContain(customerMailLine('customer-mail.my_bookings_link.here', $name, $expected))
        ->and($text)->toContain(cmlLifetime($expected))
        ->and($links)->toHaveCount(1)
        ->and(str_starts_with($links[0], tenantUrl('luna', '/'.$expected->value.'/my-bookings/')))->toBeTrue()
        ->and(documentOf($this->get($links[0])->assertOk())->documentElement?->getAttribute('lang'))->toBe($expected->value);

    if ($expected === SiteLanguage::English) {
        cmlWalkedEnglish($mail);
    } else {
        cmlWalkedGreek($mail, array_map(fn (string $key): string => 'customer-mail.my_bookings_link.'.$key, ['subject', 'greeting', 'here', 'once']));
        mailSentIntact($mail);
    }
})->with([
    'the /el page at a tenant whose default is English' => ['the /el page', SiteLanguage::Greek, SiteLanguage::English, SiteLanguage::Greek],
    'the /en page at a tenant whose default is Greek' => ['the /en page', SiteLanguage::English, SiteLanguage::Greek, SiteLanguage::English],
    'the bare page at a tenant whose default is Greek' => ['the bare page', null, SiteLanguage::Greek, SiteLanguage::Greek],
    'the bare page at a tenant whose default is English' => ['the bare page', null, SiteLanguage::English, SiteLanguage::English],
]);

it('writes the bookings link\'s Greek lifetime without the soft hyphen ICU puts inside the spelled word, in its subject and its text part alike', function () {
    settingsOf('luna')->update(['language' => SiteLanguage::English->value]);
    seamSwitchOff(seamEveryKeyBut(MessageKey::MyBookingsLink));
    mybBooking(MYB_CASEY, '2026-11-03 08:00:00');

    $this->post(tenantUrl('luna', TenantPages::uri('/my-bookings/link', SiteLanguage::Greek)), ['email' => MYB_CASEY])->assertRedirect();

    $mail = seamAt(MYB_CASEY);
    $text = (string) $mail->getTextBody();
    $minutes = (new DateInterval(RequestBookingsLink::LIFETIME))->i;
    $spelled = (new NumberFormatter(SiteLanguage::Greek->value, NumberFormatter::SPELLOUT))->format($minutes);
    assert(is_string($spelled));

    expect($spelled)->toContain("\u{00AD}")
        ->and($mail->getSubject())->not->toContain("\u{00AD}")
        ->and($text)->not->toContain("\u{00AD}")
        ->and($text)->toContain(trans_choice('customer-mail.my_bookings_link.once', $minutes, ['count' => str_replace("\u{00AD}", '', $spelled)], SiteLanguage::Greek->value));
});

/**
 * Every key of customer-mail.php the walks above show, by the forms they send: a request's,
 * a confirmed booking's, the gate's four and the bookings link's.
 *
 * @return list<string>
 */
function cmlKeysWalked(): array
{
    $keys = [];

    foreach (cmlForms() as [, $subject, $lines]) {
        array_push($keys, $subject, ...$lines);
    }

    foreach (cmlLaterForms() as [, $subject, $lines, $again, $file]) {
        array_push($keys, ...cmlLaterKeysOf($subject, $lines, $again, $file));
    }

    foreach (['subject', 'greeting', 'here', 'once'] as $key) {
        $keys[] = 'customer-mail.my_bookings_link.'.$key;
    }

    $keys = array_values(array_unique($keys));
    sort($keys);

    return $keys;
}

it('walks every key of customer-mail.php across the cases above, so no line of the file goes unread in Greek', function () {
    $file = require lang_path('en/customer-mail.php');
    assert(is_array($file));
    $keys = array_map(fn (string $key): string => 'customer-mail.'.$key, array_keys(Arr::dot($file)));
    sort($keys);

    expect(cmlKeysWalked())->toBe($keys)
        ->and($keys)->toHaveCount(65);
});
