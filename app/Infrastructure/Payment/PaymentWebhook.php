<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Application\Checkpoint;
use App\Application\ConfirmPaidBooking;
use App\Application\Event\BookingPaidLate;
use App\Application\PaidOutcome;
use App\Application\Payment\Credentials;
use App\Application\Payment\NotificationRefused;
use App\Application\Payment\PaymentNotice;
use App\Application\Payment\ProviderRegistry;
use App\Domain\Audit\Actor;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Events\TransitionPublishers;
use App\Infrastructure\PaymentFactory;
use App\Jobs\FlagPayment;
use App\Tenancy\Tenant;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The own-mode payment webhook: POST /webhooks/{provider}/{token} on the central host. The
 * notification is the authority; nothing about a booking waits for the customer to come back.
 *
 * verify() is the route's access check, run by its middleware: the provider must be one this
 * build registers and the token one of its connections' routing rows, or the answer is 404; the
 * adapter of that provider verifies the signature with the connection's own secret, lent for
 * that call, before any booking transaction opens, or the answer is 400. Each refusal is
 * recorded with why and nothing of the body. A verified notice is recorded by its event id,
 * handled once, and marked; a handling failure leaves it unmarked, is logged and reported with
 * its trace, and answers 500 for the provider's retry. Billing's webhook is the pattern and
 * shares nothing with this.
 *
 * Nothing here reads the subscription: a lapsed tenant's customers still pay.
 */
final readonly class PaymentWebhook
{
    public const string REFUSED = 'The payment webhook refused a notification';

    public const string IGNORED = 'The payment webhook heard of a payment for a booking it cannot confirm; the event is ignored';

    public const string LATE = 'The payment webhook heard of a payment for a booking no longer waiting for it; it confirms nothing and the booking stands';

    public const string LATE_UNQUEUED = 'A late payment\'s note could not be queued';

    public const string UNHANDLED = 'The payment webhook could not finish handling an event; it is left unprocessed for the provider to deliver again';

    /**
     * The checkpoint between a notice handled and its event marked: a test standing here is a
     * delivery whose concurrent twin can still mark the event first.
     */
    public const string HANDLED = 'payment-webhook.handled';

    public function __construct(
        private ProviderRegistry $registry,
        private PaymentRoutes $routes,
        private PaymentEvents $events,
        private PaymentFactory $payments,
        private BookingTransitionFactory $transitions,
        private TransitionPublishers $publishers,
        private LoggerInterface $log,
    ) {}

    /**
     * The webhook's access check: the provider registered, the token routed, the signature
     * verified with the connection's own secret, before any booking transaction opens. Answers
     * the verified notice, or the refusal to answer with, recorded and logged with why.
     *
     * @param  Closure(string): string  $header  the value of a request header by name, empty
     *                                           when absent
     */
    public function verify(string $provider, string $token, string $body, Closure $header): VerifiedNotice|WebhookAnswer
    {
        $adapter = $this->registry->adapter($provider);

        if ($adapter === null) {
            return $this->refuse($provider, null, PaymentEvents::UNKNOWN_PROVIDER, WebhookAnswer::Unknown);
        }

        $tenantId = $this->routes->byToken($provider, $token);
        $tenant = $tenantId === null ? null : Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            return $this->refuse($provider, null, PaymentEvents::UNKNOWN_ROUTE, WebhookAnswer::Unknown);
        }

        $signature = $header($adapter->descriptor()->webhookSignature->header);

        try {
            $notice = $this->payments->connections($tenant)->withCredentials(
                $adapter,
                fn (Credentials $credentials): PaymentNotice => $adapter->verify($body, $signature, $credentials),
            );
        } catch (NotificationRefused) {
            $notice = null;
        }

        if ($notice === null) {
            return $this->refuse($provider, $tenant->id, PaymentEvents::SIGNATURE, WebhookAnswer::Refused);
        }

        return new VerifiedNotice($provider, $tenant, $notice);
    }

    /**
     * A verified notice recorded once, handled once and marked; a repeat of a marked one is the
     * no-op. A late payment's two follow-ups, the note on the payment and the owner's message,
     * run after the write that marks its event and only where that write marked it, so each is
     * at most once: a concurrent delivery of the same event that did not mark it queues and
     * announces nothing, and a process that dies between the mark and the follow-ups loses them
     * with no line logged, the provider's retry stopping at the record. The trade is deliberate,
     * since the other order sends a note and a message twice. Nothing here is inside a
     * transaction: the paid transition's has returned before the mark.
     *
     * @throws NotificationUnhandled when the notice could not be handled, for the provider's retry
     */
    public function receive(VerifiedNotice $verified): void
    {
        $this->land($verified->provider, PaymentEvents::OWN, $verified->tenant, $verified->notice);
    }

    /**
     * The same handling for a payment notice whichever endpoint it arrived on: own mode's, and the
     * platform's for a payment taken on a connected account (PlatformWebhook), the event recorded
     * under the route it came by. One paid transition, one late road.
     *
     * @throws NotificationUnhandled when the notice could not be handled, for the provider's retry
     */
    public function land(string $provider, string $route, Tenant $tenant, PaymentNotice $notice): void
    {
        if (! $this->events->stillToHandle($provider, $route, $tenant->id, $notice->eventId, $notice->type)) {
            return;
        }

        try {
            $late = $this->handle($tenant, $notice);
        } catch (Throwable $cause) {
            $this->log->error(self::UNHANDLED, ['provider' => $provider, 'event' => $notice->eventId, 'exception' => $cause::class]);

            throw NotificationUnhandled::after($provider, $notice->eventId, $cause);
        }

        Checkpoint::reach(self::HANDLED);

        if ($this->events->mark($provider, $notice->eventId) && $late !== null) {
            $this->flagLate($tenant, $notice);
            $this->publishers->for($tenant)->publish($late);
        }
    }

    /**
     * A notice saying a payment succeeded lands its booking through the paid transition, read
     * again under the lock; every other notice is recorded and acted on by nobody. A booking we
     * do not have is logged and confirms nothing. Answers the late payment's record where the
     * notice is one, null otherwise: a payment the paid transition did not confirm, for a booking
     * no payment confirmed or one another payment did. Three kinds of notice are that confirming
     * payment's repeat and are logged by nobody, a provider sending several events for one
     * payment: one naming the payment that confirmed the booking; one naming none on a booking a
     * payment confirmed; and one naming any id on a booking whose confirming notice named none,
     * since nothing tells that id from the confirming payment's (CheckoutState::confirmedBy).
     */
    private function handle(Tenant $tenant, PaymentNotice $notice): ?BookingPaidLate
    {
        if (! $notice->paid) {
            return null;
        }

        $booking = $notice->booking;
        $outcome = $booking === null ? PaidOutcome::Unknown : $this->transitions->paid($tenant)->confirm($booking, $notice->paymentRef, $notice->amount);

        if ($outcome === PaidOutcome::Confirmed) {
            return null;
        }

        $state = $booking === null || $outcome === PaidOutcome::Unknown ? null : $this->payments->hop($tenant)->standing($booking);

        if ($state?->confirmedBy($notice->paymentRef) === true) {
            return null;
        }

        $this->log->warning($state === null ? self::IGNORED : self::LATE, [
            'tenant' => $tenant->slug,
            'event' => $notice->eventId,
            'booking' => $notice->booking,
            'payment' => $notice->paymentRef,
            'outcome' => $state === null ? PaidOutcome::Unknown->name : $outcome->name,
        ]);

        if ($state === null) {
            return null;
        }

        return new BookingPaidLate($booking, $state->status, $state->status, null, Actor::job(ConfirmPaidBooking::ACTOR));
    }

    /**
     * The late payment's note, queued once per event after the write that marked it: the job
     * flags the payment the notice named, where it named one, and asks the provider only where
     * its descriptor declares supportsRefundFlag. A dispatch that throws is logged as
     * LATE_UNQUEUED and never thrown, since the event is marked and a throw would answer the
     * provider's retry with a record that stops it.
     */
    private function flagLate(Tenant $tenant, PaymentNotice $notice): void
    {
        if ($notice->booking === null || $notice->paymentRef === null) {
            return;
        }

        try {
            FlagPayment::dispatch($tenant->id, $notice->booking, $notice->paymentRef);
        } catch (Throwable $failure) {
            $this->log->error(self::LATE_UNQUEUED, ['tenant' => $tenant->slug, 'event' => $notice->eventId, 'booking' => $notice->booking, 'exception' => $failure::class]);
        }
    }

    private function refuse(string $provider, ?int $tenant, string $refusal, WebhookAnswer $answer): WebhookAnswer
    {
        $this->events->refused($provider, PaymentEvents::OWN, $tenant, $refusal);
        $this->log->warning(self::REFUSED, ['provider' => $provider, 'route' => PaymentEvents::OWN, 'tenant' => $tenant, 'refusal' => $refusal]);

        return $answer;
    }
}
