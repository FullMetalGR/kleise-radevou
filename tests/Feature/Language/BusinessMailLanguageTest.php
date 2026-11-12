<?php

use App\Application\Dto\Intake;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Domain\Audit\Actor;
use App\Domain\Enum\Decision;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SqlText;
use App\Jobs\SendApprovalNag;
use App\Jobs\SendApprovalRequest;
use App\Jobs\SendAutoApprovedNotice;
use App\Jobs\SendCancellationNotice;
use App\Jobs\SendLatePaymentNotice;
use App\Jobs\SendMoveNotice;
use App\Jobs\SendNewBookingNotice;
use App\Notifications\ApprovalNag;
use App\Notifications\TenantClock;
use App\Site\SiteLanguage;
use App\Tenancy\DecisionLinks;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;

pest()->use(DatabaseTruncation::class);

/*
 * The business's messages in their recipient's language: a message to a user of the business is
 * written in the language that user chose, users.locale, else the tenant default, and a message
 * to a staff row's own address, which no user holds, in the tenant default; one message per
 * recipient, so the recipients of one booking read their languages side by side. The decision
 * links an approval request carries are minted under the prefix of the language it is written in,
 * always explicit, the default's included, so each opens its page in the mail's language and
 * decides on the POST; a bare link minted before still decides, in the tenant default.
 *
 * One booking, a request for Elena's Bridal trial, and its recipients: luna's owner, who chose
 * Greek; Marina, a manager who chose English; Elena, a staff user linked to the row the booking
 * is on, who chose nothing; and the row's own address. The tenant default is each language in
 * turn, so the last two are seen to follow it. Every sender of the six keys a booking's party
 * hears is run against the booking, and the late payment's to two owners in two languages.
 *
 * business-mail.php and decision.php hold their Greek, and each form of each message is walked by
 * their keys (linesShownIn): every key a Greek form should show shown in Greek, its subject
 * included, no English line of either file left but the shared, no :word unfilled, the instants
 * ICU's in Greek in the tenant's clock; the same form's English copy shows no Greek line; the
 * time left's three forms are read in Greek; one message is read back from the transport with its
 * Greek intact; and the last case holds the walks to business-mail.php's 48 keys. Every sentence
 * is read through the translator and every instant is compared with the formatter's answer, never
 * with a Greek literal.
 *
 * Not wrapped in a transaction: the holds and the decisions enter the transaction runner. On the
 * system clock, every date relative to today: the links are minted and read against it, and the
 * request is placed on the first whole availability window.
 */

const BML_OWNER = 'owner@luna.example';

const BML_MANAGER = 'marina@luna.example';

const BML_STAFF = 'elena@luna.example';

const BML_ROW = 'chair@luna.example';

/** What the customer wrote on the request, so the note's heading is read where it prints. */
const BML_NOTE = 'note-token';

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
 * The booking's recipients with the languages they chose: the owner Greek, Marina English, Elena
 * none and the row's own address, the tenant default the one given.
 */
function bmlRecipients(SiteLanguage $default): void
{
    settingsOf('luna')->update(['language' => $default->value]);
    DB::table('users')->where('email', BML_OWNER)->update(['locale' => 'el']);
    DB::table('users')->where('id', addTenantUser(BML_MANAGER, 'manager'))->update(['locale' => 'en']);
    addTenantUser(BML_STAFF, 'staff', 'Elena Papadaki');
    DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->update(['email' => BML_ROW]);
}

function bmlStart(): DateTimeImmutable
{
    return firstWindowDay(2)->setTime(9, 0);
}

/**
 * The request the recipients hear about: Elena's Bridal trial, awaiting a decision, its approval
 * request sent under its own key alone.
 */
function bmlRequested(): string
{
    seamSwitchOff(seamEveryKeyBut(MessageKey::ApprovalRequest));

    return LunaFixture::hold(['Bridal trial'], bmlStart()->format('Y-m-d H:i'));
}

/**
 * What a walk by keys reads in a message: its subject and its text.
 */
function bmlShownIn(Email $mail): string
{
    return (string) $mail->getSubject()."\n".$mail->getTextBody();
}

/**
 * The language each message was written in, by its address: el where it shows lines of
 * business-mail.php in Greek and none in English, its start ICU's in Greek; en the other way
 * round; mixed where it shows both or neither.
 *
 * @return array<string, string>
 */
function bmlLanguages(): array
{
    $languages = [];

    foreach (seamSent() as $mail) {
        $text = bmlShownIn($mail);
        $greek = linesShownIn($text, 'business-mail', SiteLanguage::Greek);
        $english = linesShownIn($text, 'business-mail', SiteLanguage::English);
        $language = match (true) {
            $greek !== [] && $english === [] && str_contains($text, lunaInstant(bmlStart(), SiteLanguage::Greek)) => 'el',
            $english !== [] && $greek === [] && str_contains($text, lunaInstant(bmlStart())) => 'en',
            default => 'mixed',
        };
        $languages[$mail->getTo()[0]->getAddress()] = $language;
    }

    ksort($languages);

    return $languages;
}

/**
 * The language each recipient reads, the tenant default where they chose none.
 *
 * @return array<string, string>
 */
function bmlExpected(SiteLanguage $default): array
{
    $expected = [BML_ROW => $default->value, BML_STAFF => $default->value, BML_MANAGER => 'en', BML_OWNER => 'el'];
    ksort($expected);

    return $expected;
}

/**
 * Runs a job the way a worker does.
 */
function bmlRun(object $job): void
{
    app(Dispatcher::class)->dispatchNow($job);
}

/**
 * @param  TestResponse<Response>  $response
 */
function bmlLang(TestResponse $response): ?string
{
    return preg_match('/<html lang="([a-z]+)">/', (string) $response->getContent(), $found) === 1 ? $found[1] : null;
}

/**
 * The address the confirm page's form posts to.
 *
 * @param  TestResponse<Response>  $response
 */
function bmlAction(TestResponse $response): string
{
    preg_match('/ action="([^"]*)"/', (string) $response->getContent(), $action);

    return html_entity_decode($action[1] ?? '');
}

/**
 * The window the request's hold runs out at, as the nag is timed against it.
 */
function bmlWindow(string $uuid): DateTimeImmutable
{
    $booking = app(BookingReader::class)->snapshot(LunaFixture::tenant(), $uuid);
    assert($booking?->holdExpiresAt instanceof DateTimeImmutable);

    return $booking->holdExpiresAt;
}

/**
 * Every form of the business's messages about luna's request, each the job that sends it, built
 * from the tenant's id and the uuid; the address of the copy walked, the owner's, who holds the
 * links, or the staff row's, which holds none; and the keys of business-mail.php its subject and
 * body read, in the order the body reads them. A form whose message needs the row, the switches
 * or another state sets them in its closure. Named before anything is booked, so a dataset may
 * list them.
 *
 * @return array<string, array{Closure(int, string): object, string, string, list<string>}>
 */
function bmlForms(): array
{
    $mail = fn (string $key): string => 'business-mail.'.$key;
    $only = function (MessageKey ...$keys): void {
        seamSwitchOff(seamEveryKeyBut(...$keys));
    };
    $confirmed = fn (string $uuid) => DB::table('tenant_luna.bookings')->where('uuid', $uuid)->update(['status' => 'confirmed']);
    $cancelled = function (bool $request, string $actor) use ($only): Closure {
        return function (int $tenant, string $uuid) use ($only, $request, $actor): object {
            $only(MessageKey::CancellationNotice);

            return new SendCancellationNotice($tenant, $uuid, $request, $actor);
        };
    };
    $moved = function (bool $request, string $actor) use ($only): Closure {
        return function (int $tenant, string $uuid) use ($only, $request, $actor): object {
            $only(MessageKey::MoveNotice);

            return new SendMoveNotice($tenant, $uuid, $request, $actor, SqlText::instant(bmlStart()->add(new DateInterval('PT24H'))));
        };
    };
    $manager = fn (): string => Actor::user(tenantUserId(BML_MANAGER))->value;

    return [
        'the new booking' => [function (int $tenant, string $uuid) use ($only): object {
            $only(MessageKey::NewBooking);

            return new SendNewBookingNotice($tenant, $uuid);
        }, BML_OWNER, $mail('new_booking.checkout.subject'), array_map($mail, ['new_booking.checkout.opening', 'note.heading'])],
        'the new booking its payment confirmed' => [function (int $tenant, string $uuid) use ($only): object {
            $only(MessageKey::NewBooking);

            return new SendNewBookingNotice($tenant, $uuid, true);
        }, BML_OWNER, $mail('new_booking.paid.subject'), array_map($mail, ['new_booking.paid.opening', 'note.heading'])],
        'the approval request with its links' => [fn (int $tenant, string $uuid): object => new SendApprovalRequest($tenant, $uuid), BML_OWNER, $mail('approval_request.waiting.subject'), array_map($mail, ['approval_request.waiting.with_links', 'note.heading', 'links.approve', 'links.reject'])],
        'the approval request to a staff row\'s address' => [fn (int $tenant, string $uuid): object => new SendApprovalRequest($tenant, $uuid), BML_ROW, $mail('approval_request.waiting.subject'), array_map($mail, ['approval_request.waiting.without_links', 'note.heading', 'links.none'])],
        'the approval request settled before the queue reached it' => [function (int $tenant, string $uuid) use ($confirmed): object {
            $confirmed($uuid);

            return new SendApprovalRequest($tenant, $uuid);
        }, BML_OWNER, $mail('approval_request.settled.subject'), array_map($mail, ['approval_request.settled.opening', 'note.heading', 'approval_request.settled.nothing_left'])],
        'the nag with its links' => [function (int $tenant, string $uuid) use ($only): object {
            $only(MessageKey::ApprovalNag);

            return new SendApprovalNag($tenant, $uuid, SqlText::instant(bmlWindow($uuid)));
        }, BML_OWNER, $mail('approval_nag.subject'), array_map($mail, ['approval_nag.with_links', 'approval_nag.runs_out', 'links.approve', 'links.reject'])],
        'the nag to a staff row\'s address' => [function (int $tenant, string $uuid) use ($only): object {
            $only(MessageKey::ApprovalNag);

            return new SendApprovalNag($tenant, $uuid, SqlText::instant(bmlWindow($uuid)));
        }, BML_ROW, $mail('approval_nag.subject'), array_map($mail, ['approval_nag.without_links', 'approval_nag.runs_out', 'links.none'])],
        'the automatic approval, confirmed and told' => [function (int $tenant, string $uuid) use ($only): object {
            $only(MessageKey::AutoApproved, MessageKey::Approved);

            return new SendAutoApprovedNotice($tenant, $uuid);
        }, BML_OWNER, $mail('auto_approved.subject'), array_map($mail, ['auto_approved.opening', 'auto_approved.confirmed.told'])],
        'the automatic approval, confirmed and untold' => [function (int $tenant, string $uuid) use ($only): object {
            $only(MessageKey::AutoApproved);

            return new SendAutoApprovedNotice($tenant, $uuid);
        }, BML_OWNER, $mail('auto_approved.subject'), array_map($mail, ['auto_approved.opening', 'auto_approved.confirmed.untold'])],
        'the automatic approval, awaiting payment and told' => [function (int $tenant, string $uuid) use ($only): object {
            $only(MessageKey::AutoApproved, MessageKey::Approved);

            return new SendAutoApprovedNotice($tenant, $uuid, true);
        }, BML_OWNER, $mail('auto_approved.subject'), array_map($mail, ['auto_approved.opening', 'auto_approved.awaiting_payment.told'])],
        'the automatic approval, awaiting payment and untold' => [function (int $tenant, string $uuid) use ($only): object {
            $only(MessageKey::AutoApproved);

            return new SendAutoApprovedNotice($tenant, $uuid, true);
        }, BML_OWNER, $mail('auto_approved.subject'), array_map($mail, ['auto_approved.opening', 'auto_approved.awaiting_payment.untold'])],
        'the cancellation of the request by the customer' => [$cancelled(true, Actor::CUSTOMER), BML_OWNER, $mail('cancellation_notice.request.subject'), array_map($mail, ['cancellation_notice.request.opening', 'cancellation_notice.request.means', 'cancellation_notice.by.customer'])],
        'the cancellation of the booking by a user' => [fn (int $tenant, string $uuid): object => $cancelled(false, $manager())($tenant, $uuid), BML_OWNER, $mail('cancellation_notice.booking.subject'), array_map($mail, ['cancellation_notice.booking.opening', 'cancellation_notice.booking.means', 'cancellation_notice.by.user'])],
        'the cancellation of the request by a user since removed' => [$cancelled(true, Actor::user(PHP_INT_MAX)->value), BML_OWNER, $mail('cancellation_notice.request.subject'), array_map($mail, ['cancellation_notice.request.opening', 'cancellation_notice.request.means', 'cancellation_notice.by.removed'])],
        'the cancellation of the booking by the platform\'s support' => [$cancelled(false, Actor::support(1)->value), BML_OWNER, $mail('cancellation_notice.booking.subject'), array_map($mail, ['cancellation_notice.booking.opening', 'cancellation_notice.booking.means', 'cancellation_notice.by.support'])],
        'the cancellation of the request by the system' => [$cancelled(true, Actor::SYSTEM), BML_OWNER, $mail('cancellation_notice.request.subject'), array_map($mail, ['cancellation_notice.request.opening', 'cancellation_notice.request.means', 'cancellation_notice.by.system'])],
        'the move of the request by the customer' => [$moved(true, Actor::CUSTOMER), BML_OWNER, $mail('move_notice.request.subject'), array_map($mail, ['move_notice.request.opening', 'move_notice.by.customer'])],
        'the move of the booking by a user' => [fn (int $tenant, string $uuid): object => $moved(false, $manager())($tenant, $uuid), BML_OWNER, $mail('move_notice.booking.subject'), array_map($mail, ['move_notice.booking.opening', 'move_notice.by.user'])],
        'the move of the request by a user since removed' => [$moved(true, Actor::user(PHP_INT_MAX)->value), BML_OWNER, $mail('move_notice.request.subject'), array_map($mail, ['move_notice.request.opening', 'move_notice.by.removed'])],
        'the move of the booking by the platform\'s support' => [$moved(false, Actor::support(1)->value), BML_OWNER, $mail('move_notice.booking.subject'), array_map($mail, ['move_notice.booking.opening', 'move_notice.by.support'])],
        'the move of the request by the system' => [$moved(true, Actor::SYSTEM), BML_OWNER, $mail('move_notice.request.subject'), array_map($mail, ['move_notice.request.opening', 'move_notice.by.system'])],
        'the late payment' => [function (int $tenant, string $uuid) use ($only): object {
            $only(MessageKey::LatePayment);

            return new SendLatePaymentNotice($tenant, $uuid);
        }, BML_OWNER, $mail('late_payment.subject'), array_map($mail, ['late_payment.arrived', 'late_payment.reference', 'late_payment.stands'])],
    ];
}

/**
 * The walk's verdict on a message written in Greek: no English line of business-mail.php or of
 * decision.php left but the shared, no :word unfilled, the start ICU's in Greek and never in
 * English, and the keys named shown in Greek and no other key of business-mail.php.
 *
 * @param  list<string>  $keys  keys of business-mail.php, the file named
 */
function bmlWalkedGreek(Email $mail, array $keys): void
{
    $text = bmlShownIn($mail);
    $shown = array_map(fn (string $key): string => 'business-mail.'.$key, linesShownIn($text, 'business-mail', SiteLanguage::Greek));

    expect(linesShownIn($text, 'business-mail', SiteLanguage::English))->toBe([])
        ->and(linesShownIn($text, 'decision', SiteLanguage::English))->toBe([])
        ->and(unfilledIn($text))->toBe([])
        ->and($text)->toContain(lunaInstant(bmlStart(), SiteLanguage::Greek))
        ->and($text)->not->toContain(lunaInstant(bmlStart()))
        ->and($keys)->not->toBeEmpty()
        ->and(array_values(array_unique($shown)))->toEqualCanonicalizing($keys);
}

/**
 * The walk's verdict on a message written in English: no Greek line of either file in it.
 */
function bmlWalkedEnglish(Email $mail): void
{
    $text = bmlShownIn($mail);

    expect(linesShownIn($text, 'business-mail', SiteLanguage::Greek))->toBe([])
        ->and(linesShownIn($text, 'decision', SiteLanguage::Greek))->toBe([])
        ->and(linesShownIn($text, 'business-mail', SiteLanguage::English))->not->toBe([]);
}

it('writes the approval request to each recipient in the language they read, a staff user who chose none and a staff row\'s address in the tenant default, {0}', function (SiteLanguage $default) {
    bmlRecipients($default);
    bmlRequested();

    expect(bmlLanguages())->toBe(bmlExpected($default))
        ->and(seamAt(BML_OWNER)->getSubject())->toBe(businessMailLine('business-mail.approval_request.waiting.subject', ['business' => 'Luna Hair Studio'], SiteLanguage::Greek))
        ->and(seamAt(BML_MANAGER)->getSubject())->toBe(businessMailLine('business-mail.approval_request.waiting.subject', ['business' => 'Luna Hair Studio']));
})->with([
    'luna Greek' => [SiteLanguage::Greek],
    'luna English' => [SiteLanguage::English],
]);

it('writes every message of the booking\'s party to each recipient in the language they read, one message each, {0}', function (SiteLanguage $default) {
    bmlRecipients($default);
    $uuid = bmlRequested();
    $tenant = LunaFixture::tenant()->id;

    seamSwitchOff(seamEveryKeyBut(MessageKey::NewBooking, MessageKey::ApprovalNag, MessageKey::AutoApproved, MessageKey::CancellationNotice, MessageKey::MoveNotice));

    foreach ([
        'new_booking' => new SendNewBookingNotice($tenant, $uuid),
        'approval_nag' => new SendApprovalNag($tenant, $uuid, SqlText::instant(bmlWindow($uuid))),
        'auto_approved' => new SendAutoApprovedNotice($tenant, $uuid),
        'cancellation_notice' => new SendCancellationNotice($tenant, $uuid, true, Actor::CUSTOMER),
        'move_notice' => new SendMoveNotice($tenant, $uuid, true, Actor::CUSTOMER, SqlText::instant(bmlStart())),
    ] as $key => $job) {
        seamTransport()->flush();
        bmlRun($job);

        expect(bmlLanguages())->toBe(bmlExpected($default), $key)
            ->and(seamSent())->toHaveCount(4, $key)
            ->and(linesShownIn((string) seamAt(BML_OWNER)->getSubject(), 'business-mail', SiteLanguage::Greek))->not->toBeEmpty()->each->toStartWith($key.'.')
            ->and(linesShownIn((string) seamAt(BML_MANAGER)->getSubject(), 'business-mail', SiteLanguage::English))->not->toBeEmpty()->each->toStartWith($key.'.');
    }
})->with([
    'luna Greek' => [SiteLanguage::Greek],
    'luna English' => [SiteLanguage::English],
]);

it('writes the late payment\'s notice to each owner in the language that owner reads, two owners of one booking two languages', function () {
    bmlRecipients(SiteLanguage::Greek);
    DB::table('users')->where('id', addTenantUser('partner@luna.example', 'owner'))->update(['locale' => 'en']);
    $uuid = bmlRequested();

    seamSwitchOff(seamEveryKeyBut(MessageKey::LatePayment));
    seamTransport()->flush();
    bmlRun(new SendLatePaymentNotice(LunaFixture::tenant()->id, $uuid));

    expect(bmlLanguages())->toBe([BML_OWNER => 'el', 'partner@luna.example' => 'en'])
        ->and(seamAt(BML_OWNER)->getSubject())->toBe(businessMailLine('business-mail.late_payment.subject', ['business' => 'Luna Hair Studio'], SiteLanguage::Greek))
        ->and(seamAt('partner@luna.example')->getSubject())->toBe(businessMailLine('business-mail.late_payment.subject', ['business' => 'Luna Hair Studio']))
        ->and(seamTextAt(BML_OWNER))->toContain(businessMailLine('business-mail.late_payment.reference', [], SiteLanguage::Greek).': '.$uuid)
        ->and(seamTextAt('partner@luna.example'))->toContain(businessMailLine('business-mail.late_payment.reference').': '.$uuid);

    foreach ([BML_OWNER, 'partner@luna.example'] as $owner) {
        expect(seamLinksIn(seamTextAt($owner)))->toBe([]);
    }
});

it('writes {0} to a Greek recipient in Greek at a Greek tenant, walked by keys: its subject, its lines and no other, its facts and its instants, and each English copy, the English owner\'s at least, with no Greek line', function (string $form) {
    bmlRecipients(SiteLanguage::Greek);
    DB::table('users')->where('id', addTenantUser('partner@luna.example', 'owner'))->update(['locale' => 'en']);
    $uuid = bmlRequested();
    DB::table('tenant_luna.booking_meta')->insert([
        'booking_id' => DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('id'),
        'key' => Intake::NOTE_KEY,
        'value' => BML_NOTE,
    ]);
    seamSwitchOff(seamEveryKeyBut(MessageKey::ApprovalRequest));

    [$build, $address, $subject, $keys] = bmlForms()[$form];
    $job = $build(LunaFixture::tenant()->id, $uuid);
    seamTransport()->flush();
    bmlRun($job);

    $mail = seamAt($address);

    expect(linesShownIn((string) $mail->getSubject(), 'business-mail', SiteLanguage::Greek))->toContain(substr($subject, strlen('business-mail.')))
        ->and((string) $mail->getTextBody())->toContain(decisionLine('decision.facts.item', ['service' => 'Bridal trial', 'staff' => 'Elena Papadaki'], SiteLanguage::Greek));

    bmlWalkedGreek($mail, [$subject, ...$keys]);

    $english = 0;

    foreach (seamSent() as $copy) {
        if (in_array($copy->getTo()[0]->getAddress(), [BML_MANAGER, 'partner@luna.example'], true)) {
            bmlWalkedEnglish($copy);
            $english++;
        }
    }

    expect($english)->toBeGreaterThan(0);
})->with(fn () => array_keys(bmlForms()));

it('words the time left before a request runs out in Greek, each of the three forms by the hours left', function (int $minutes, int $hours) {
    bmlRecipients(SiteLanguage::Greek);
    $uuid = bmlRequested();
    $booking = app(BookingReader::class)->snapshot(LunaFixture::tenant(), $uuid);
    assert($booking?->holdExpiresAt instanceof DateTimeImmutable);
    $clock = new TenantClock(new DateTimeZone(FixtureDay::ZONE));
    $now = $booking->holdExpiresAt->sub(new DateInterval('PT'.$minutes.'M'));

    $message = ApprovalNag::of($booking, 'Luna Hair Studio', $clock, new Recipient(BML_ROW, language: SiteLanguage::Greek->value), null, $booking->holdExpiresAt, $now);

    expect($message->body)->toContain(businessMailChoice('business-mail.approval_nag.runs_out', $hours, ['end' => lunaInstant($booking->holdExpiresAt, SiteLanguage::Greek)], SiteLanguage::Greek))
        ->and(linesShownIn($message->body, 'business-mail', SiteLanguage::Greek))->toContain('approval_nag.runs_out')
        ->and(linesShownIn($message->body, 'business-mail', SiteLanguage::English))->toBe([])
        ->and(unfilledIn($message->body))->toBe([]);
})->with([
    'under half an hour, none' => [20, 0],
    'about an hour' => [70, 1],
    'about five hours' => [300, 5],
]);

it('carries the Greek intact on the wire: the subject encoded by the mailer into an ASCII header that decodes to it, and the text part UTF-8', function () {
    bmlRecipients(SiteLanguage::Greek);
    $uuid = bmlRequested();

    seamSwitchOff(seamEveryKeyBut(MessageKey::LatePayment));
    seamTransport()->flush();
    bmlRun(new SendLatePaymentNotice(LunaFixture::tenant()->id, $uuid));

    $mail = seamAt(BML_OWNER);

    expect(seamSent())->toHaveCount(1)
        ->and(preg_match('/[^\x00-\x7f]/', (string) $mail->getSubject()))->toBe(1);

    mailSentIntact($mail);
});

it('mints each recipient\'s decision links under the prefix of the language their message is written in, the default\'s explicit, and gives a staff row\'s address none', function () {
    bmlRecipients(SiteLanguage::Greek);
    $uuid = bmlRequested();
    $prefixOf = fn (string $address): array => array_values(array_unique(array_map(fn (string $link): string => explode('/', (string) parse_url($link, PHP_URL_PATH))[1], seamLinksIn(seamTextAt($address)))));

    expect($prefixOf(BML_OWNER))->toBe(['el'])
        ->and($prefixOf(BML_MANAGER))->toBe(['en'])
        // Elena chose none and reads luna's default, Greek, under its explicit prefix.
        ->and($prefixOf(BML_STAFF))->toBe(['el'])
        ->and(seamLinksIn(seamTextAt(BML_OWNER)))->toHaveCount(2)
        ->and(seamLinksIn(seamTextAt(BML_OWNER))[0])->toContain('/el/decide/'.$uuid.'/approve?')
        ->and(seamLinksIn(seamTextAt(BML_OWNER))[1])->toContain('/el/decide/'.$uuid.'/reject?')
        ->and(seamLinksIn(seamTextAt(BML_MANAGER))[0])->toContain('/en/decide/'.$uuid.'/approve?')
        ->and(seamLinksIn(seamTextAt(BML_ROW)))->toBe([]);
});

it('opens {0}\'s approve link at its page in the language the mail was written in and decides on the POST, the outcome in the same language', function (string $address, SiteLanguage $language) {
    bmlRecipients(SiteLanguage::English);
    $uuid = bmlRequested();
    [$approve] = seamLinksIn(seamTextAt($address));

    $page = $this->get($approve)->assertOk();
    $action = bmlAction($page);

    expect(bmlLang($page))->toBe($language->value)
        ->and((string) $page->getContent())->toContain(e(decisionLine('decision.title', ['business' => 'Luna Hair Studio'], $language)))
        ->and(parse_url($action, PHP_URL_PATH))->toBe('/'.$language->value.'/decide/'.$uuid.'/approve');

    $outcome = $this->post($action)->assertOk();

    expect(bmlLang($outcome))->toBe($language->value)
        ->and((string) $outcome->getContent())->toContain(e(decisionLine('decision.outcome.confirmed', [], $language)))
        ->and(LunaFixture::row($uuid)['status'])->toBe('confirmed')
        ->and(LunaFixture::row($uuid)['approved_by'])->toBe(tenantUserId($address));
})->with([
    'the Greek owner' => [BML_OWNER, SiteLanguage::Greek],
    'the English manager' => [BML_MANAGER, SiteLanguage::English],
    'the staff user under the English default' => [BML_STAFF, SiteLanguage::English],
]);

it('still decides on a bare link minted before the links carried a prefix, its page in the tenant default', function () {
    bmlRecipients(SiteLanguage::Greek);
    $uuid = bmlRequested();
    $bare = app(DecisionLinks::class)->link(LunaFixture::tenant(), $uuid, Decision::Approve, tenantUserId(BML_MANAGER), bmlWindow($uuid));

    $page = $this->get($bare)->assertOk();
    $action = bmlAction($page);

    expect(parse_url($bare, PHP_URL_PATH))->toBe('/decide/'.$uuid.'/approve')
        ->and(bmlLang($page))->toBe('el')
        ->and(parse_url($action, PHP_URL_PATH))->toBe('/decide/'.$uuid.'/approve');

    $this->post($action)->assertOk();

    expect(LunaFixture::row($uuid)['status'])->toBe('confirmed')
        ->and(LunaFixture::row($uuid)['approved_by'])->toBe(tenantUserId(BML_MANAGER));
});

/**
 * Every key of business-mail.php the forms above send their messages under, derived from the
 * forms: the subject and the body's keys of each.
 *
 * @return list<string>
 */
function bmlKeysWalked(): array
{
    $keys = [];

    foreach (bmlForms() as [, , $subject, $lines]) {
        array_push($keys, $subject, ...$lines);
    }

    $keys = array_values(array_unique($keys));
    sort($keys);

    return $keys;
}

it('walks every key of business-mail.php across the forms above, so no line of the file goes unread in Greek', function () {
    $file = require lang_path('en/business-mail.php');
    assert(is_array($file));
    $keys = array_map(fn (string $key): string => 'business-mail.'.$key, array_keys(Arr::dot($file)));
    sort($keys);

    expect(bmlKeysWalked())->toBe($keys)
        ->and($keys)->toHaveCount(48);
});
