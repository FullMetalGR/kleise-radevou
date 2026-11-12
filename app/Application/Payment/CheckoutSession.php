<?php

declare(strict_types=1);

namespace App\Application\Payment;

use DateTimeImmutable;

/**
 * The provider's answer to a checkout: the page to send the customer to, the id of the payment
 * object behind it, and the instant the provider says the session ends. The pay hop records the
 * id as the booking's payment_ref until the paid transition writes over it the id of the payment
 * the notification named (a notification with no payment id leaves the session). A booking the
 * hop settled on site keeps the session an earlier press recorded, so a reference alone does not
 * say money moved; the paid transition's audit row beside it does. The end is the provider's own
 * instant and may differ from the one asked for, a provider having a shortest and a longest
 * session of its own; the hold follows it.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $url,
        public string $ref,
        public DateTimeImmutable $endsAt,
    ) {}
}
