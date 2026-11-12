<?php

use App\Application\Clock;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Notification\Messengers;
use App\Jobs\MessageNotSent;
use App\Jobs\SendAcknowledgement;
use App\Jobs\SendApprovalNag;
use App\Jobs\SendApprovalRequest;
use App\Jobs\SendAutoApprovedNotice;
use App\Jobs\SendBookingsLink;
use App\Jobs\SendCancellation;
use App\Jobs\SendCancellationNotice;
use App\Jobs\SendConfirmation;
use App\Jobs\SendLatePaymentNotice;
use App\Jobs\SendMove;
use App\Jobs\SendMoveNotice;
use App\Jobs\SendNewBookingNotice;
use App\Jobs\SendReminder;
use App\Jobs\SendRequestApproved;
use App\Jobs\SendRequestExpired;
use App\Jobs\SendRequestRejected;
use App\Jobs\SendThankYou;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\Support\RefusingLines;
use Tests\Support\SeamLog;

pest()->use(DatabaseTruncation::class);

/*
 * The render inside the seam's promise: every tenant message is handed to the seam unwritten, its
 * key, its booking and the closure that writes it, and the seam asks the switch first and writes
 * the words inside its own catch. Words that cannot be written are one logged error of their own,
 * naming the tenant, the key, the booking and the exception's class and never the address nor the
 * exception's message, nothing is sent and the job ends as it would have; a key the tenant
 * switched off is never written at all; a failure before the words, the re-read, still fails the
 * job with MessageNotSent; and of two recipients, the one whose words cannot be written gets
 * nothing while the other gets theirs.
 *
 * Over luna's real rows. A message's words fail to be written by a translator that cannot read
 * customer-mail.php's, business-mail.php's and decision.php's lines, RefusingLines, its exception
 * quoting the values a line would have been filled with; the seventeen senders are each run
 * against it the way a worker runs them, the sixteen that name a booking and the bookings link,
 * which names none and whose words are still its own, written for a link row whose address is
 * blank.
 *
 * Not wrapped in a transaction: the holds and the confirm enter the transaction runner. luna is
 * in Europe/Athens; 2026-11-03 is a Tuesday, Elena working 09:00 to 13:00 and 14:00 to 18:00, and
 * the clock stands three days before, so the reminder's own question is answered yes.
 */

const RITS_NOW = '2026-10-31 08:00:00';

/** The fixture's start in Athens as a job spells the start it was queued for: 08:00 UTC. */
const RITS_START = '2026-11-03T08:00:00.000000+00:00';

const RITS_UUID = '7b7f0e5a-0000-4000-8000-000000000008';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at(RITS_NOW));
    seamTransport()->flush();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A message of the key given about the booking given, to luna's customer.
 */
function ritsMessage(MessageKey $key = MessageKey::Confirmed, ?string $uuid = RITS_UUID): Message
{
    return new Message($key, new Recipient(LunaFixture::CUSTOMER_EMAIL), 'A subject', 'A body.', $uuid);
}

/**
 * The words of customer-mail.php, business-mail.php and decision.php made unreadable, as the line
 * readers ask the container for them.
 */
function ritsRefuseTheWords(string ...$prefixes): void
{
    app()->instance(Translator::class, new RefusingLines(app('translator'), $prefixes === [] ? ['customer-mail.', 'business-mail.', 'decision.'] : array_values($prefixes)));
}

/**
 * The error lines a log was handed, each as its message and its context.
 *
 * @return list<array{string, array<mixed>}>
 */
function ritsErrors(SeamLog $log): array
{
    return array_map(fn (array $record): array => [$record['message'], $record['context']], $log->errors());
}

/**
 * Every sender of a tenant message, by its name, the job it runs as built from the confirmed
 * booking's tenant, uuid, token and revision, the key it sends under, and whether that message
 * names its booking.
 *
 * @return array<string, array{string, Closure, MessageKey, bool}>
 */
function ritsSenders(): array
{
    return [
        'the acknowledgement' => ['SendAcknowledgement', fn (int $tenant, string $uuid, string $token): object => new SendAcknowledgement($tenant, $uuid, $token), MessageKey::Acknowledged, true],
        'the confirmation' => ['SendConfirmation', fn (int $tenant, string $uuid, string $token): object => new SendConfirmation($tenant, $uuid, $token), MessageKey::Confirmed, true],
        'the approval' => ['SendRequestApproved', fn (int $tenant, string $uuid): object => new SendRequestApproved($tenant, $uuid), MessageKey::Approved, true],
        'the approval awaiting payment' => ['SendRequestApproved', fn (int $tenant, string $uuid): object => new SendRequestApproved($tenant, $uuid, true), MessageKey::Approved, true],
        'the rejection' => ['SendRequestRejected', fn (int $tenant, string $uuid): object => new SendRequestRejected($tenant, $uuid), MessageKey::Rejected, true],
        'the timeout' => ['SendRequestExpired', fn (int $tenant, string $uuid): object => new SendRequestExpired($tenant, $uuid), MessageKey::RequestExpired, true],
        'the unpaid lapse' => ['SendRequestExpired', fn (int $tenant, string $uuid): object => new SendRequestExpired($tenant, $uuid, true), MessageKey::RequestExpired, true],
        'the cancellation' => ['SendCancellation', fn (int $tenant, string $uuid): object => new SendCancellation($tenant, $uuid, false, true), MessageKey::Cancelled, true],
        'the move' => ['SendMove', fn (int $tenant, string $uuid): object => new SendMove($tenant, $uuid, false, true, RITS_START), MessageKey::Moved, true],
        'the reminder' => ['SendReminder', fn (int $tenant, string $uuid, string $token, int $revision): object => new SendReminder($tenant, $uuid, RITS_START, $revision), MessageKey::Reminder, true],
        'the thank-you' => ['SendThankYou', function (int $tenant, string $uuid): object {
            DB::table('tenant_luna.bookings')->where('uuid', $uuid)->update(['status' => 'completed']);

            return new SendThankYou($tenant, $uuid);
        }, MessageKey::ThankYou, true],
        'the new booking notice' => ['SendNewBookingNotice', fn (int $tenant, string $uuid): object => new SendNewBookingNotice($tenant, $uuid), MessageKey::NewBooking, true],
        'the approval request' => ['SendApprovalRequest', fn (int $tenant, string $uuid): object => new SendApprovalRequest($tenant, $uuid), MessageKey::ApprovalRequest, true],
        'the approval nag' => ['SendApprovalNag', function (int $tenant): object {
            // A request still waiting on its decision, inside its window, the one state a nag
            // writes for.
            $request = LunaFixture::hold(['Bridal trial'], FixtureDay::DATE.' 14:00');
            $expiry = app(BookingReader::class)->snapshot(LunaFixture::tenant(), $request)?->holdExpiresAt;
            assert($expiry instanceof DateTimeImmutable);

            return new SendApprovalNag($tenant, $request, SqlText::instant($expiry));
        }, MessageKey::ApprovalNag, true],
        'the automatic approval notice' => ['SendAutoApprovedNotice', fn (int $tenant, string $uuid): object => new SendAutoApprovedNotice($tenant, $uuid), MessageKey::AutoApproved, true],
        'the cancellation notice' => ['SendCancellationNotice', fn (int $tenant, string $uuid): object => new SendCancellationNotice($tenant, $uuid, false, 'customer'), MessageKey::CancellationNotice, true],
        'the move notice' => ['SendMoveNotice', fn (int $tenant, string $uuid): object => new SendMoveNotice($tenant, $uuid, false, 'customer', RITS_START), MessageKey::MoveNotice, true],
        'the late payment notice' => ['SendLatePaymentNotice', fn (int $tenant, string $uuid): object => new SendLatePaymentNotice($tenant, $uuid), MessageKey::LatePayment, true],
        'the bookings link' => ['SendBookingsLink', function (int $tenant): object {
            // A link row for the customer's address: the one message about no booking, whose words
            // read customer-mail.php's lines like every other's.
            $link = DB::table('tenant_luna.customer_links')->insertGetId([
                'email' => LunaFixture::CUSTOMER_EMAIL,
                'token_hash' => str_repeat('a', 64),
                'created_at' => DB::raw('now()'),
                'expires_at' => DB::raw("now() + interval '15 minutes'"),
            ]);

            return new SendBookingsLink($tenant, $link, 'a-link-token', 'en');
        }, MessageKey::MyBookingsLink, false],
    ];
}

it('writes the words inside its promise, so words that cannot be written are one line of their own naming the tenant, the key, the booking and the class, never the address nor the exception\'s message, and nothing is sent', function () {
    $log = new SeamLog;
    Log::swap($log);

    app(Messengers::class)->for(LunaFixture::tenant())->send(MessageKey::Confirmed, RITS_UUID, fn (): Message => throw new RuntimeException(LunaFixture::CUSTOMER_EMAIL.' could not be written to'));

    expect(seamSent())->toBe([])
        ->and(ritsErrors($log))->toBe([['A message could not be rendered', ['tenant' => 'luna', 'key' => 'confirmed', 'booking' => RITS_UUID, 'exception' => RuntimeException::class]]])
        ->and($log->text())->not->toContain(LunaFixture::CUSTOMER_EMAIL);
});

it('never writes a message the tenant switched off, the switch asked before the words', function () {
    seamSwitchOff([MessageKey::Confirmed->value]);
    $log = new SeamLog;
    Log::swap($log);
    $written = 0;

    app(Messengers::class)->for(LunaFixture::tenant())->send(MessageKey::Confirmed, RITS_UUID, function () use (&$written): Message {
        $written++;

        return ritsMessage();
    });

    expect($written)->toBe(0)
        ->and(seamSent())->toBe([])
        ->and($log->errors())->toBe([]);
});

it('writes and sends a message the tenant has on, once', function () {
    $written = 0;

    app(Messengers::class)->for(LunaFixture::tenant())->send(MessageKey::Confirmed, RITS_UUID, function () use (&$written): Message {
        $written++;

        return ritsMessage();
    });

    expect($written)->toBe(1)
        ->and(seamAddresses())->toBe([LunaFixture::CUSTOMER_EMAIL]);
});

it('refuses a message written {0} than it was handed under, since the switch asked was the one for the key named', function (Message $written) {
    $log = new SeamLog;
    Log::swap($log);

    app(Messengers::class)->for(LunaFixture::tenant())->send(MessageKey::Confirmed, RITS_UUID, fn (): Message => $written);

    expect(seamSent())->toBe([])
        ->and(ritsErrors($log))->toBe([['A message could not be rendered', ['tenant' => 'luna', 'key' => 'confirmed', 'booking' => RITS_UUID, 'exception' => LogicException::class]]]);
})->with([
    'under another key' => [fn (): Message => ritsMessage(MessageKey::Rejected)],
    'about another booking' => [fn (): Message => ritsMessage(uuid: '7b7f0e5a-0000-4000-8000-000000000009')],
]);

it('sends nothing from {0} whose words cannot be written, logs one line per message naming the tenant, the key and the booking and never the address, the token or the words\' values, and ends as it would have', function (string $sender, Closure $job, MessageKey $key, bool $aboutTheBooking) {
    Queue::fake();
    [$url, $body] = seamHold('Haircut', FixtureDay::START);
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);
    $tenant = LunaFixture::tenant();
    $built = $job($tenant->id, $held['uuid'], $held['token'], app(BookingReader::class)->revision($tenant, $held['uuid']));
    seamTransport()->flush();

    ritsRefuseTheWords();
    $log = new SeamLog;
    Log::swap($log);

    app(Dispatcher::class)->dispatchNow($built);

    $errors = ritsErrors($log);
    $booking = $aboutTheBooking ? (is_object($built) && property_exists($built, 'uuid') && is_string($built->uuid) ? $built->uuid : 'no uuid') : null;
    $context = ['tenant' => 'luna', 'key' => $key->value, 'booking' => $booking, 'exception' => RuntimeException::class];

    // One line per message, each of its own: luna's owner is the one business recipient.
    expect(seamSent())->toBe([])
        ->and($errors)->not->toBe([])
        ->and($errors)->toBe(array_fill(0, count($errors), ['A message could not be rendered', $context]))
        ->and($log->text())->not->toContain(LunaFixture::CUSTOMER_EMAIL)
        ->and($log->text())->not->toContain(LunaFixture::CUSTOMER_NAME)
        ->and($log->text())->not->toContain('owner@luna.example')
        ->and($log->text())->not->toContain($held['token'])
        ->and($log->text())->not->toContain('could not be read');
})->with(ritsSenders(...));

it('names in its dataset every sender of a tenant message, so a sender added later is run against unreadable words by being there, and every send hands the seam its key, its booking and the closure that writes the words', function () {
    $senders = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Jobs/Send*.php') ?: [] as $path) {
        $senders[] = basename($path, '.php');
    }

    sort($senders);

    $named = array_values(array_unique(array_map(fn (array $sender): string => $sender[0], ritsSenders())));
    sort($named);

    expect($senders)->toBe($named)
        ->and($senders)->toHaveCount(17);

    foreach ($senders as $sender) {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Jobs/'.$sender.'.php');

        expect(substr_count($source, '->send('))->toBeGreaterThan(0)
            ->and(preg_match_all('/->send\(MessageKey::[A-Za-z]+, (\$booking->uuid|null), fn \(\): Message => /', $source))->toBe(substr_count($source, '->send('));
    }
});

it('ends the job quietly where the words cannot be written, so a worker leaves no failed job behind', function () {
    config(['queue.default' => 'database']);
    seamSwitchOff(seamEveryKeyBut(MessageKey::Acknowledged));
    ritsRefuseTheWords();
    $log = new SeamLog;
    Log::swap($log);

    [$url, $body] = seamHold('Bridal trial', FixtureDay::START);
    $held = seamHeld($this->postJson($url, $body));

    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1, '--memory' => 0, '--no-interaction' => true]);

    // The rows left, if any, are the nags, whose instants the worker has not reached; neither the
    // acknowledgement nor the business's request is among them, and nothing failed.
    $left = DB::table('jobs')->pluck('payload')->implode("\n");

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and($left)->not->toContain('SendAcknowledgement')
        ->and($left)->not->toContain('SendApprovalRequest')
        ->and(seamSent())->toBe([])
        ->and(ritsErrors($log))->toBe([['A message could not be rendered', ['tenant' => 'luna', 'key' => 'acknowledged', 'booking' => $held['uuid'], 'exception' => RuntimeException::class]]]);
});

it('still fails {0} with MessageNotSent where the re-read fails before any words are written, and sends nothing', function (string $sender, Closure $job) {
    Queue::fake();
    [$url, $body] = seamHold('Haircut', FixtureDay::START);
    $held = seamHeld($this->postJson($url, $body));
    seamTransport()->flush();

    DB::statement('alter table tenant_luna.bookings rename to bookings_away');

    try {
        expect(fn () => app(Dispatcher::class)->dispatchNow($job(LunaFixture::tenant()->id, $held['uuid'], $held['token'])))
            ->toThrow(MessageNotSent::class);
    } finally {
        DB::statement('alter table tenant_luna.bookings_away rename to bookings');
    }

    expect(seamSent())->toBe([]);
})->with([
    'the acknowledgement' => ['SendAcknowledgement', fn (int $tenant, string $uuid, string $token): object => new SendAcknowledgement($tenant, $uuid, $token)],
    'the new booking notice' => ['SendNewBookingNotice', fn (int $tenant, string $uuid): object => new SendNewBookingNotice($tenant, $uuid)],
]);

it('sends the recipient whose words were written their message where another recipient\'s cannot be written, the failed one logged alone', function () {
    // A second recipient who does not manage bookings, Elena's own mailbox, beside the owner, who
    // does: the owner's words name the customer's address under its label, the one line refused.
    DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->update(['email' => 'elena@luna.example']);
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
    ritsRefuseTheWords('decision.facts.email');
    $log = new SeamLog;
    Log::swap($log);

    [$url, $body] = seamHold('Haircut', FixtureDay::START);
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);

    expect(seamAddressSet())->toBe(['elena@luna.example', LunaFixture::CUSTOMER_EMAIL])
        ->and((string) seamAt('elena@luna.example')->getTextBody())->toContain(LunaFixture::CUSTOMER_NAME)
        ->and(ritsErrors($log))->toBe([['A message could not be rendered', ['tenant' => 'luna', 'key' => 'new_booking', 'booking' => $held['uuid'], 'exception' => RuntimeException::class]]])
        ->and($log->text())->not->toContain('owner@luna.example');
});

it('sends a staff row\'s address its copy of the approval request where the copy with the links cannot be written, the owner\'s failed render logged alone', function () {
    // Elena's row has an address and no user, so its copy carries no links and is worded for
    // that; the owner's copy opens on the sentence for a recipient holding the links, the one
    // line refused.
    DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->update(['email' => 'chair@luna.example']);
    seamSwitchOff(['approval_nag', 'acknowledged']);
    ritsRefuseTheWords('business-mail.approval_request.waiting.with_links');
    $log = new SeamLog;
    Log::swap($log);

    [$url, $body] = seamHold('Bridal trial', FixtureDay::START);
    $held = seamHeld($this->postJson($url, $body));
    $chair = (string) seamAt('chair@luna.example')->getTextBody();

    expect(seamAddressSet())->toBe(['chair@luna.example'])
        ->and($chair)->toContain(businessMailLine('business-mail.links.none'))
        ->and(seamLinksIn($chair))->toBe([])
        ->and(ritsErrors($log))->toBe([['A message could not be rendered', ['tenant' => 'luna', 'key' => 'approval_request', 'booking' => $held['uuid'], 'exception' => RuntimeException::class]]])
        ->and($log->text())->not->toContain('owner@luna.example')
        ->and($log->text())->not->toContain('/decide/');
});
