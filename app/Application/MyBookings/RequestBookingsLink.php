<?php

declare(strict_types=1);

namespace App\Application\MyBookings;

use App\Application\Clock;
use App\Domain\Customer\CustomerSecret;
use Closure;
use DateInterval;

/**
 * POST /my-bookings/link's decision: a link is minted and announced only where the tenant sends
 * the bookings link and the address has at least one booking here. Every other request does
 * nothing at all, and the form answers it with the same sentence as the one that sent, so the
 * form says nothing about which addresses have bookings.
 *
 * The token is thirty-two random bytes in hex, the row holds its sha256, and the window is
 * fifteen minutes from the Clock port's now. The plaintext goes out on the record, once, to the
 * job that mails it, with the language the request was answered in, which the message is written
 * in.
 */
final readonly class RequestBookingsLink
{
    /** How long a link opens for: fifteen minutes. */
    public const string LIFETIME = 'PT15M';

    /**
     * @param  Closure(): bool  $sendsLinks  whether the tenant has the bookings link switched on, read when asked
     */
    public function __construct(
        private CustomerLinks $links,
        private Clock $clock,
        private LinkAnnouncer $announcer,
        private Closure $sendsLinks,
    ) {}

    /**
     * @param  string  $language  the code of the request's language, en or el, which the message is written in
     */
    public function request(string $email, string $language): void
    {
        if (! ($this->sendsLinks)() || ! $this->links->hasBookings($email)) {
            return;
        }

        $token = CustomerSecret::generate();
        $now = $this->clock->now();
        $id = $this->links->mint($email, $token->hash(), $now, $now->add(new DateInterval(self::LIFETIME)));

        $this->announcer->announce(new BookingsLinkRequested($id, $token->plain, $language));
    }
}
