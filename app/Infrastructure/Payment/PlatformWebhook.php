<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Application\Clock;
use App\Application\Payment\NotificationRefused;
use App\Application\Payment\PlatformMode;
use App\Application\Payment\ProviderRegistry;
use App\Infrastructure\PaymentFactory;
use App\Tenancy\Tenant;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The platform-mode payment webhook: POST /webhooks/{provider} on the central host, where a
 * provider tells the platform about its connected accounts.
 *
 * verify() is the route's access check: the provider registered and the platform's keys for it
 * set, or 404; the adapter verifies the signature with the platform's own signing secret, or
 * 400. It fails closed, and each refusal is recorded as the own shape's are, with why and
 * nothing of the body. receive() finds the tenant by the account the notice names, through the
 * routing row; an account we do not know is logged as UNKNOWN_ACCOUNT and ignored, and a notice
 * naming no account is logged on its own line, NO_ACCOUNT, and ignored. A notice is recorded by
 * its event id and handled once: an account update lands the connection's standing, and a
 * repeat delivery is the no-op; no booking is touched and no transaction is open while the
 * provider's word lands. A notice carrying a payment taken on the connected account is handed
 * to the own-mode webhook's handling, PaymentWebhook::land, under route platform: the same paid
 * transition confirms the booking and the same late road reads a payment that confirms nothing.
 * Nothing here reads the subscription, and nothing here is billing's.
 */
final readonly class PlatformWebhook
{
    public const string REFUSED = 'The platform payment webhook refused a notification';

    public const string UNKNOWN_ACCOUNT = 'The platform payment webhook heard of an account no connection holds; the event is ignored';

    public const string NO_ACCOUNT = 'The platform payment webhook heard an event naming no account; the event is ignored';

    public const string UNHANDLED = 'The platform payment webhook could not finish handling an event; it is left unprocessed for the provider to deliver again';

    public function __construct(
        private ProviderRegistry $registry,
        private PlatformMode $platform,
        private PaymentRoutes $routes,
        private PaymentEvents $events,
        private PaymentFactory $payments,
        private PaymentWebhook $paid,
        private Clock $clock,
        private LoggerInterface $log,
    ) {}

    /**
     * @param  Closure(string): string  $header  the value of a request header by name, empty when absent
     */
    public function verify(string $provider, string $body, Closure $header): VerifiedPlatformNotice|WebhookAnswer
    {
        $adapter = $this->registry->adapter($provider);
        if ($adapter === null) {
            return $this->refuse($provider, PaymentEvents::UNKNOWN_PROVIDER, WebhookAnswer::Unknown);
        }
        $keys = $this->platform->keys($provider);
        if ($keys === null) {
            return $this->refuse($provider, PaymentEvents::UNKNOWN_ROUTE, WebhookAnswer::Unknown);
        }
        try {
            $notice = $adapter->verifyPlatform($body, $header($adapter->descriptor()->webhookSignature->header), $keys);
        } catch (NotificationRefused) {
            return $this->refuse($provider, PaymentEvents::SIGNATURE, WebhookAnswer::Refused);
        }

        return new VerifiedPlatformNotice($provider, $notice);
    }

    /**
     * @throws NotificationUnhandled when the standing or the payment could not be landed, for the provider's retry
     */
    public function receive(VerifiedPlatformNotice $verified): void
    {
        $notice = $verified->notice;
        $tenantId = $notice->accountRef === null ? null : $this->routes->byAccount($verified->provider, $notice->accountRef);
        $tenant = $tenantId === null ? null : Tenant::query()->find($tenantId);
        if (! $tenant instanceof Tenant) {
            $this->log->warning($notice->accountRef === null ? self::NO_ACCOUNT : self::UNKNOWN_ACCOUNT, ['provider' => $verified->provider, 'event' => $notice->eventId, 'account' => $notice->accountRef]);

            return;
        }
        if ($notice->payment !== null) {
            $this->paid->land($verified->provider, PaymentEvents::PLATFORM, $tenant, $notice->payment);

            return;
        }

        if (! $this->events->stillToHandle($verified->provider, PaymentEvents::PLATFORM, $tenant->id, $notice->eventId, $notice->type)) {
            return;
        }
        try {
            if ($notice->standing !== null) {
                $this->payments->connections($tenant)->landStanding($notice->accountRef, $notice->standing, $this->clock->now());
            }
        } catch (Throwable $cause) {
            $this->log->error(self::UNHANDLED, ['provider' => $verified->provider, 'event' => $notice->eventId, 'exception' => $cause::class]);
            throw NotificationUnhandled::after($verified->provider, $notice->eventId, $cause);
        }
        $this->events->mark($verified->provider, $notice->eventId);
    }

    private function refuse(string $provider, string $refusal, WebhookAnswer $answer): WebhookAnswer
    {
        $this->events->refused($provider, PaymentEvents::PLATFORM, null, $refusal);
        $this->log->warning(self::REFUSED, ['provider' => $provider, 'route' => PaymentEvents::PLATFORM, 'tenant' => null, 'refusal' => $refusal]);

        return $answer;
    }
}
