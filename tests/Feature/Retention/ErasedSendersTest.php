<?php

use App\Application\Clock;
use App\Application\Notification\MessageKey;
use App\Jobs\ErasedBooking;
use App\Jobs\SendAcknowledgement;
use App\Jobs\SendApprovalNag;
use App\Jobs\SendApprovalRequest;
use App\Jobs\SendAutoApprovedNotice;
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
use App\Retention\Erasure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\Support\SeamLog;

pest()->use(DatabaseTruncation::class);

/*
 * No mail is ever addressed to a null address: every sender that re-reads a booking to mail the
 * customer or the business about it ends on a booking whose customer was erased, sends nothing,
 * and says so once at info, naming the tenant, the message and the booking and never an address.
 *
 * Over luna's real rows: a confirmed booking taken the way the booking page takes one, its
 * listeners' jobs held on the queue fake, then erased through erasure's primitive, and every
 * sender run against it the way a worker runs it. Not wrapped in a transaction: the hold and the
 * confirm enter the transaction runner, which refuses to open inside another. The clock stands
 * three days before the booking's start, so the reminder's own question, whether the start it
 * was queued for is still ahead, is answered yes and the erased booking is what stops it.
 */

const ERASED_SENDERS_NOW = '2026-10-31 08:00:00';

/** The fixture's start in Athens as a job spells the start it was queued for: 08:00 UTC. */
const ERASED_SENDERS_START = '2026-11-03T08:00:00.000000+00:00';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at(ERASED_SENDERS_NOW));
    seamTransport()->flush();
    Queue::fake();
    [$url, $body] = seamHold('Haircut', FixtureDay::START);
    $held = seamHeld($this->postJson($url, $body));
    seamConfirm($this, $held['uuid'], $held['token']);
    $this->uuid = $held['uuid'];
    $this->token = $held['token'];
    $id = DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->value('id');
    assert(is_int($id));
    app(Erasure::class)->bookings(LunaFixture::tenant(), [$id]);
    seamTransport()->flush();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

it('sends nothing from {job} about a booking whose customer was erased, and ends quietly with one line at info naming the tenant, the message and the booking and no address', function (Closure $job, MessageKey $key) {
    $log = new SeamLog;
    Log::swap($log);

    app(Dispatcher::class)->dispatchNow($job(LunaFixture::tenant()->id, $this->uuid, $this->token));

    expect(seamSent())->toBe([])
        ->and($log->records)->toBe([
            ['level' => 'info', 'message' => ErasedBooking::NOT_SENT, 'context' => ['tenant' => 'luna', 'key' => $key->value, 'booking' => $this->uuid]],
        ])
        ->and($log->text())->not->toContain('perpetua')
        ->and($log->text())->not->toContain('Vandersnoot')
        ->and($log->text())->not->toContain($this->token)
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $this->uuid)->value('status'))->toBe('confirmed');
})->with([
    'SendAcknowledgement' => [fn (int $tenant, string $uuid, string $token) => new SendAcknowledgement($tenant, $uuid, $token), MessageKey::Acknowledged],
    'SendConfirmation' => [fn (int $tenant, string $uuid, string $token) => new SendConfirmation($tenant, $uuid, $token), MessageKey::Confirmed],
    'SendRequestApproved' => [fn (int $tenant, string $uuid) => new SendRequestApproved($tenant, $uuid), MessageKey::Approved],
    'SendRequestRejected' => [fn (int $tenant, string $uuid) => new SendRequestRejected($tenant, $uuid), MessageKey::Rejected],
    'SendCancellation' => [fn (int $tenant, string $uuid) => new SendCancellation($tenant, $uuid, false, true), MessageKey::Cancelled],
    'SendMove' => [fn (int $tenant, string $uuid) => new SendMove($tenant, $uuid, false, true, ERASED_SENDERS_START), MessageKey::Moved],
    'SendReminder' => [fn (int $tenant, string $uuid) => new SendReminder($tenant, $uuid, ERASED_SENDERS_START, 1), MessageKey::Reminder],
    'SendRequestExpired' => [fn (int $tenant, string $uuid) => new SendRequestExpired($tenant, $uuid), MessageKey::RequestExpired],
    'SendThankYou' => [fn (int $tenant, string $uuid) => new SendThankYou($tenant, $uuid), MessageKey::ThankYou],
    'SendNewBookingNotice' => [fn (int $tenant, string $uuid) => new SendNewBookingNotice($tenant, $uuid), MessageKey::NewBooking],
    'SendApprovalRequest' => [fn (int $tenant, string $uuid) => new SendApprovalRequest($tenant, $uuid), MessageKey::ApprovalRequest],
    'SendApprovalNag' => [fn (int $tenant, string $uuid) => new SendApprovalNag($tenant, $uuid, ERASED_SENDERS_START), MessageKey::ApprovalNag],
    'SendAutoApprovedNotice' => [fn (int $tenant, string $uuid) => new SendAutoApprovedNotice($tenant, $uuid), MessageKey::AutoApproved],
    'SendCancellationNotice' => [fn (int $tenant, string $uuid) => new SendCancellationNotice($tenant, $uuid, false, 'customer'), MessageKey::CancellationNotice],
    'SendMoveNotice' => [fn (int $tenant, string $uuid) => new SendMoveNotice($tenant, $uuid, false, 'customer', ERASED_SENDERS_START), MessageKey::MoveNotice],
    'SendLatePaymentNotice' => [fn (int $tenant, string $uuid) => new SendLatePaymentNotice($tenant, $uuid), MessageKey::LatePayment],
]);

it('names in its dataset every job that re-reads a booking to mail it, so a sender added later without the guard fails here by its absence', function () {
    $senders = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Jobs/Send*.php') ?: [] as $path) {
        if (str_contains((string) file_get_contents($path), '$bookings->snapshot($tenant, $this->uuid)')) {
            $senders[] = basename($path, '.php');
        }
    }

    sort($senders);

    expect($senders)->toBe(['SendAcknowledgement', 'SendApprovalNag', 'SendApprovalRequest', 'SendAutoApprovedNotice', 'SendCancellation', 'SendCancellationNotice', 'SendConfirmation', 'SendLatePaymentNotice', 'SendMove', 'SendMoveNotice', 'SendNewBookingNotice', 'SendReminder', 'SendRequestApproved', 'SendRequestExpired', 'SendRequestRejected', 'SendThankYou']);

    foreach ($senders as $sender) {
        expect((string) file_get_contents(dirname(__DIR__, 3).'/app/Jobs/'.$sender.'.php'))->toContain('ErasedBooking::endsHere($booking, $tenant, MessageKey::');
    }
});
