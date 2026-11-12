<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Clock;
use App\Application\Dto\BookingSnapshot;
use App\Domain\Booking\ManageToken;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\CustomerAccess;
use App\Tenancy\Tenant;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameter;

/**
 * The one credential a customer has, checked in one place. Given the booking a request names
 * and the token it presents, this answers the booking or refuses, and every guest route of the
 * booking lifecycle goes through it.
 *
 * Four things can be wrong and they are one refusal: the uuid names no booking of this tenant,
 * the token is not the one that booking was created with, the booking's hash has been nulled by
 * retention, and the token has outlived the booking it manages. The caller turns all four into
 * one answer, so the route is not an existence oracle: an expired token, a wrong token and an
 * unknown booking answer identically, and the booking app renders the one neutral panel for all
 * of them. Nothing here reports which it was, to anybody, including in the shape of the work it
 * does.
 *
 * The lifetime is derived and never stored: the token is good until thirty days after the last
 * segment of its booking ends. The lifecycle routes are self-limiting already, since a finished
 * booking cannot be cancelled; what the expiry protects is the contact details on the read,
 * because a magic link living in a mailbox forever would otherwise disclose a customer's email
 * and phone to whoever holds that mailbox years later. It is written here and nowhere else, so a
 * second route cannot acquire a second idea of when a link dies.
 *
 * Reading the hash before the booking is what keeps a refusal cheap and even: a uuid naming no
 * row costs the statement and the comparison that a wrong token costs, because it is compared
 * against a hash that no token hashes to rather than skipping the comparison. What is not
 * levelled is a string that is no uuid at all, which the reader refuses before it issues
 * anything; that says only that the caller mistyped an identifier, and the syntax of a uuid is
 * not a secret. The booking itself is read only once the credential is good for it.
 *
 * A request that presents no token may present the second credential instead, a live My bookings
 * session on its cookie whose address is the booking's: bookingOfSession is that check, and its
 * misses are this one's by another name. The derived expiry binds it as it binds the token,
 * through the same method, so a session cannot reach the contact details of a booking a mailbox
 * link no longer reaches.
 *
 * No transaction is opened and no search path is set. Every statement names its schema, the way
 * the readers behind the public routes do, because a search path set on a pooled connection
 * rides into the next request.
 */
final readonly class ManageTokenGuard
{
    /**
     * How long the credential outlives the booking; retention nulls the stored hash on the
     * same day for the same reason.
     */
    public const int LIFETIME_DAYS = 30;

    /**
     * What a presented token is compared against when no booking has that uuid. Sixty-four hex
     * characters, the shape of a real hash, so the comparison reads the same number of bytes
     * and no token hashes to it.
     */
    private const string ABSENT = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private BookingReader $bookings, private CustomerAccess $sessions, private Clock $clock) {}

    /**
     * The booking that uuid names when the presented token is the one it was created with and
     * has not outlived it, or null for every other case.
     */
    public function booking(Tenant $tenant, string $uuid, ManageToken $presented): ?BookingSnapshot
    {
        $stored = $this->bookings->manageTokenHash($tenant, $uuid);
        $matches = ManageToken::matches($presented->plain, $stored ?? self::ABSENT);

        if ($stored === null || ! $matches) {
            return null;
        }

        return $this->live($tenant, $uuid);
    }

    /**
     * The second credential: the booking that uuid names when the My bookings session a
     * presented secret names is live and was opened for the address the booking was made under,
     * compared as citext compares, and the booking has not outlived the lifetime a token for it
     * would have; null for every other case. The caller asks this only where the request
     * presented no token, so a request carrying both is judged by the token alone.
     *
     * The misses are the token's misses by another name and answer the same null: a session
     * unknown, revoked or past its end, a session for another address, an unknown booking, and a
     * booking past the derived expiry, which is the one written below and reached the same way.
     * Nothing is written: a read through a session leaves its row as it found it.
     */
    public function bookingOfSession(Tenant $tenant, string $uuid, #[SensitiveParameter] string $secret): ?BookingSnapshot
    {
        if (! $this->sessions->sessionReachesBooking($tenant, $secret, $uuid, $this->clock->now())) {
            return null;
        }

        return $this->live($tenant, $uuid);
    }

    /**
     * The booking once a credential is good for it, unless it has outlived the credential's
     * derived lifetime, which binds both credentials.
     */
    private function live(Tenant $tenant, string $uuid): ?BookingSnapshot
    {
        $booking = $this->bookings->snapshot($tenant, $uuid);

        if ($booking === null || $this->hasLapsed($booking)) {
            return null;
        }

        return $booking;
    }

    /**
     * Whether the credential has outlived its booking. The lifetime runs from when the booking
     * ended, which the snapshot answers and which is the latest end across the chain rather
     * than the end of its first segment, because the booking is not over until its last segment
     * is. A booking that ended exactly the lifetime ago is past it, the half-open convention
     * this codebase keeps everywhere a range has an end.
     */
    private function hasLapsed(BookingSnapshot $booking): bool
    {
        return $this->clock->now() >= self::deadline($booking->endsAt());
    }

    /**
     * The instant the credential stops working. The days are added in UTC, whatever zone the
     * instant arrived spelled in: UTC has no clock changes, so thirty days there is thirty
     * elapsed days, while thirty days on a zone that changes is thirty calendar days and an
     * hour either way, and a link would then die an hour early for half the year.
     */
    private static function deadline(DateTimeImmutable $lastEnd): DateTimeImmutable
    {
        return $lastEnd->setTimezone(new DateTimeZone('UTC'))->add(new DateInterval(sprintf('P%dD', self::LIFETIME_DAYS)));
    }
}
