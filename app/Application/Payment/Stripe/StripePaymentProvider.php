<?php

declare(strict_types=1);

namespace App\Application\Payment\Stripe;

use App\Application\Clock;
use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Application\Payment\CheckoutRequest;
use App\Application\Payment\CheckoutSession;
use App\Application\Payment\Credentials;
use App\Application\Payment\PaymentLog;
use App\Application\Payment\PaymentProvider;
use App\Application\Payment\PlatformFee;
use App\Application\Payment\SessionWithheld;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameter;

/**
 * The port over one Stripe connection: a hosted Checkout Session per booking, priced inline from
 * the booking's own total, never a mirrored product.
 *
 * Own mode borrows the tenant's key. A platform connection is lent the platform's key and names
 * the tenant's connected account on every request, as Stripe-Account, so the payment is the
 * tenant's own sale on its own account, a direct charge, with the platform's fee attached as the
 * session's payment_intent_data[application_fee_amount]. The fee is computed from the booking's
 * total when the session is created and only then: a session read back keeps the fee it was
 * created with. The customer is asked for the total and nothing more, the fee coming out of the
 * tenant's side, and a fee of nothing sends no fee at all. A platform connection whose fee is
 * unset in configuration still opens its sessions, with no fee, and says so on one error line
 * per session opened.
 *
 * Building it, and asking it isAvailable, reads nothing and calls nothing: both run inside the
 * booking transaction under the staff-day lock. The connection's credentials are borrowed
 * through the store's one door for each call that leaves the process and for that call alone, so
 * a missing or refused key fails at first use, worded, and never at boot.
 *
 * isAvailable answers true: the tenant's provider hands this out only for a connection that
 * works, and Stripe offers nothing cheaper than a call that would say otherwise. So the one
 * state that leaves a recorded session to its own end, a working connection whose provider
 * answers unavailable, is unreachable through Stripe. On an async queue a connection repaired
 * between a settlement's commit and its expiry job is reached by that job, which is harmless:
 * the session it names is this booking's.
 */
final readonly class StripePaymentProvider implements PaymentProvider
{
    /** The shortest session Stripe opens, in seconds after its creation. */
    public const int FLOOR = 1800;

    /** The longest session Stripe opens, in seconds after its creation. */
    public const int CEILING = 86400;

    /**
     * Room for the gap between this clock and Stripe's, and for the request in flight: under
     * the ceiling, and under the floor for a session after a move, which ends with the hold as
     * it stands and so is refused before Stripe is asked once the hold has less than the floor
     * and this margin left. A hold's first session is given none at the floor, since the end
     * sent there is fixed for the hold: a live hold's end plus the floor is past Stripe's floor
     * by as long as the hold has left, so only a hold in its last seconds can meet a refusal,
     * which fails worded and leaves the hold to its end, as any refused session does.
     */
    public const int MARGIN = 60;

    /** The step an end past the ceiling is shortened by, so a retry inside one sends the same end. */
    public const int STEP = 60;

    /**
     * @param  Closure(Closure(Credentials): mixed): mixed  $lend  the store's door, bound to this provider's adapter, or the platform's keys
     * @param  PaymentLog  $log  where a platform session opened with no fee configured is said
     * @param  string|null  $account  the connected account of a platform connection, null in own mode
     * @param  PlatformFee|null  $fee  the platform's fee in force, a platform connection's alone
     */
    public function __construct(
        private StripeTransport $transport,
        private Clock $clock,
        private Closure $lend,
        private PaymentLog $log,
        private ?string $account = null,
        private ?PlatformFee $fee = null,
    ) {}

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * A recorded session is read back and answered while it is open; one of this booking's that
     * Stripe has completed answers Completed, its payment made or still being processed and the
     * notification not landed, and one expired, or one that is not this booking's, answers null, so
     * the hop hands the customer no dead page and opens no second session. A hold already past its
     * end answers null before Stripe is asked. A new session carries an idempotency key derived
     * from the booking, its hold's end, its total and the end sent, and on a platform connection
     * from the connected account and the fee as well. The end sent is fixed for the hold, so every
     * retry for one hold, whenever it is made, carries one key and is answered Stripe's first
     * session again; only a hold whose end plus the floor passes the ceiling sends an end that
     * moves with the clock, by whole STEPs.
     *
     * A session after a move expired the hold's first ends with the hold as it stands, which the
     * first session already lengthened by the floor, so the hold is lengthened no further however
     * many moves and hops follow; the key counts the sessions the moves cleared, so a retry meets
     * the session of its own cycle and never one a move expired. Such a hold with less than the
     * floor and the margin left is answered TooShort before Stripe is asked, since Stripe would
     * refuse the end and the hold may not grow to take one: told apart from a null, so the customer
     * reads that the hold can no longer be paid online rather than a page that could not be opened.
     *
     * @throws StripeFailed where Stripe refused, could not be reached, or the connection lends no key
     */
    public function checkoutUrl(CheckoutRequest $request): CheckoutSession|SessionWithheld|null
    {
        $now = $this->clock->now();
        $holdFollowsSession = $request->sessionsCleared > 0;

        if ($request->endsBy <= $now) {
            return null;
        }

        if ($request->paymentRef === null && $holdFollowsSession && $request->endsBy->getTimestamp() < $now->getTimestamp() + self::FLOOR + self::MARGIN) {
            return SessionWithheld::TooShort;
        }

        return $this->borrowing('open a checkout', function (#[SensitiveParameter] string $key) use ($request, $now, $holdFollowsSession): CheckoutSession|SessionWithheld|null {
            if ($request->paymentRef !== null) {
                return $this->recorded($request, $key);
            }

            $endsAt = self::sessionEnd($request->endsBy, $now, $holdFollowsSession);
            $fee = $this->account === null ? null : $this->fee?->on($request->booking->total)->minor;

            if ($this->account !== null && $this->fee === null) {
                $this->log->feeUnset();
            }

            $answer = $this->ask('open a checkout', 'POST', '/v1/checkout/sessions', self::sessionForm($request, $endsAt, $fee), $key, $this->idempotencyKey($request, $endsAt, $fee));

            return self::session($answer->body, $request->booking->uuid) ?? throw StripeFailed::because('open a checkout', 'the answer is not an open Checkout Session.');
        });
    }

    /**
     * Stripe finds no session by booking without a search, and the booking row holds the
     * reference the hop recorded; nothing asks this of a live provider.
     */
    public function paymentRef(BookingSnapshot $booking): ?string
    {
        return null;
    }

    /**
     * The note goes on the PaymentIntent, as its description and its metadata: a Checkout
     * Session id, the kind the hop records and every paid notice names, is read back for its
     * payment first; a PaymentIntent id is noted directly.
     *
     * @throws StripeFailed where Stripe refused, could not be reached, or the session holds no payment
     */
    public function flagPayment(BookingSnapshot $booking, string $paymentRef, string $note): void
    {
        $this->borrowing('leave a note', function (#[SensitiveParameter] string $key) use ($paymentRef, $note): void {
            $intent = $paymentRef;

            if (str_starts_with($paymentRef, 'cs_')) {
                $session = $this->ask('leave a note', 'GET', '/v1/checkout/sessions/'.rawurlencode($paymentRef), [], $key)->body;
                $intent = $session['payment_intent'] ?? null;
            }

            if (! is_string($intent) || ! str_starts_with($intent, 'pi_')) {
                throw StripeFailed::because('leave a note', 'the reference names no payment.');
            }

            $this->ask('leave a note', 'POST', '/v1/payment_intents/'.rawurlencode($intent), ['description' => $note, 'metadata[note]' => $note], $key);
        });
    }

    /**
     * Expires an open session. Stripe refuses to expire one already completed or expired; that
     * refusal is read back against the session's status and answered as nothing to do, so a
     * payment landing between a lapse and its expiry job is no failure here.
     *
     * @throws StripeFailed where Stripe refused for another reason or could not be reached
     */
    public function expireCheckout(BookingSnapshot $booking, string $paymentRef): void
    {
        $this->borrowing('expire a checkout', function (#[SensitiveParameter] string $key) use ($paymentRef): void {
            $path = '/v1/checkout/sessions/'.rawurlencode($paymentRef);

            try {
                $this->ask('expire a checkout', 'POST', $path.'/expire', [], $key);
            } catch (StripeFailed $refused) {
                $status = $this->ask('expire a checkout', 'GET', $path, [], $key)->body['status'] ?? null;

                if (! in_array($status, ['complete', 'expired'], true)) {
                    throw $refused;
                }
            }
        });
    }

    /**
     * The end a session is opened with: the hold's end plus Stripe's floor, one session per hold,
     * which depends on the hold alone and so is the same for every request made for it, now or
     * minutes later. A live hold's end is after now, so that end is always past the floor. Where it
     * would pass the ceiling, as a payment link's twenty-four-hour hold's does in its first half
     * hour, it is shortened by whole STEPs to just inside the ceiling, the same for every request
     * inside one STEP; the hop then shortens the hold to it. Where the hold already follows a
     * session, one a move since expired, the end is the hold's own, the floor having been added
     * once; the caller keeps such an end off Stripe where the floor would refuse it.
     *
     * @param  bool  $holdFollowsSession  whether the hold's end is already a session's end, a move having cleared that session since
     */
    public static function sessionEnd(DateTimeImmutable $holdEnds, DateTimeImmutable $now, bool $holdFollowsSession = false): int
    {
        $fixed = $holdEnds->getTimestamp() + ($holdFollowsSession ? 0 : self::FLOOR);
        $latest = $now->getTimestamp() + self::CEILING - self::MARGIN;

        if ($fixed > $latest) {
            return $fixed - intdiv($fixed - $latest + self::STEP - 1, self::STEP) * self::STEP;
        }

        return $fixed;
    }

    /**
     * @param  int|null  $fee  the platform's fee in the total's minor units, a platform connection's alone
     * @return array<string, scalar>
     */
    private static function sessionForm(CheckoutRequest $request, int $endsAt, ?int $fee): array
    {
        $booking = $request->booking;
        $names = array_map(fn (BookingItemSnapshot $item): string => $item->serviceName, $booking->items);

        return [
            'mode' => 'payment',
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($booking->total->currency->code),
            'line_items[0][price_data][unit_amount]' => $booking->total->minor,
            'line_items[0][price_data][product_data][name]' => implode(', ', array_unique($names)),
            'client_reference_id' => $booking->uuid,
            'metadata[booking]' => $booking->uuid,
            'payment_intent_data[metadata][booking]' => $booking->uuid,
            'payment_intent_data[description]' => 'Booking '.$booking->uuid,
            'success_url' => $request->returnUrl,
            'cancel_url' => $request->returnUrl,
            'expires_at' => $endsAt,
            ...($fee !== null && $fee > 0 ? ['payment_intent_data[application_fee_amount]' => $fee] : []),
        ];
    }

    /**
     * Own mode's five inputs, and after a move cleared a session the count of those as a sixth,
     * since from then on neither the hold's end nor the end sent moves between one cycle of move
     * and hop and the next and the key would otherwise meet a session a move expired; a platform
     * connection's key adds the account and the fee, so a fee changed inside Stripe's
     * idempotency window opens a new session rather than meeting a key reused with other
     * parameters.
     */
    private function idempotencyKey(CheckoutRequest $request, int $endsAt, ?int $fee): string
    {
        $booking = $request->booking;

        return 'checkout-'.hash('sha256', implode('|', [
            $booking->uuid,
            $request->endsBy->getTimestamp(),
            $booking->total->minor,
            $booking->total->currency->code,
            $endsAt,
            ...($request->sessionsCleared === 0 ? [] : [$request->sessionsCleared]),
            ...($this->account === null ? [] : [$this->account, $fee ?? 0]),
        ]));
    }

    /**
     * The recorded session as Stripe reads it back: open, its page again; completed, whatever its
     * payment_status says, the payment on its way, since a completed session takes no second
     * payment and its notification confirms the booking; anything else, or a session that is not
     * this booking's, null.
     */
    private function recorded(CheckoutRequest $request, string $key): CheckoutSession|SessionWithheld|null
    {
        $ref = (string) $request->paymentRef;
        $body = $this->ask('read a checkout', 'GET', '/v1/checkout/sessions/'.rawurlencode($ref), [], $key)->body;
        $metadata = $body['metadata'] ?? null;

        if (($body['id'] ?? null) !== $ref || ! is_array($metadata) || ($metadata['booking'] ?? null) !== $request->booking->uuid) {
            return null;
        }

        return ($body['status'] ?? null) === 'complete' ? SessionWithheld::Completed : self::session($body, $request->booking->uuid);
    }

    /**
     * An open session of this booking's, read from Stripe's Checkout Session object, or null.
     *
     * @param  array<array-key, mixed>  $session
     */
    private static function session(array $session, string $uuid): ?CheckoutSession
    {
        $id = $session['id'] ?? null;
        $url = $session['url'] ?? null;
        $expires = $session['expires_at'] ?? null;
        $metadata = $session['metadata'] ?? null;

        if (! is_string($id) || ! str_starts_with($id, 'cs_') || ! is_string($url) || $url === '' || ! is_int($expires)
            || ($session['status'] ?? null) !== 'open' || ! is_array($metadata) || ($metadata['booking'] ?? null) !== $uuid) {
            return null;
        }

        return new CheckoutSession($url, $id, (new DateTimeImmutable('@'.$expires, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC')));
    }

    /**
     * One request, its error answer turned into a worded failure that names no secret.
     *
     * @param  'GET'|'POST'  $method
     * @param  array<string, scalar>  $form
     *
     * @throws StripeFailed
     */
    private function ask(string $operation, string $method, string $path, array $form, #[SensitiveParameter] string $key, ?string $idempotencyKey = null): StripeAnswer
    {
        try {
            $answer = $this->transport->send($method, $path, $form, $key, $idempotencyKey, $this->account);
        } catch (StripeUnreachable $unreachable) {
            throw StripeFailed::because($operation, $unreachable->getMessage());
        }

        if (! $answer->ok()) {
            throw StripeFailed::because($operation, $answer->reason(), [$key]);
        }

        return $answer;
    }

    /**
     * Runs the call with the connection's secret key, borrowed for it alone.
     *
     * @template T
     *
     * @param  Closure(string): T  $call  handed the key, which it marks as a sensitive parameter
     * @return T
     *
     * @throws StripeFailed where the connection lends no key
     */
    private function borrowing(string $operation, Closure $call): mixed
    {
        $lent = false;
        $result = ($this->lend)(function (Credentials $credentials) use ($call, &$lent): mixed {
            $lent = true;
            $key = $credentials->field(StripeAdapter::SECRET_KEY);

            return $key === '' ? throw StripeFailed::because('use the connection', 'it holds no secret key.') : $call($key);
        });

        return $lent ? $result : throw StripeFailed::because($operation, 'the connection lends no credentials.');
    }
}
