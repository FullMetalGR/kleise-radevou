<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Booking\ServiceWindows;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A booking as the re-read under its own row lock found it: the only account of the booking a
 * guard is allowed to judge. Nothing a request carried and nothing the pre-read saw reaches it,
 * so a guard cannot accidentally decide on what the caller believed.
 *
 * The expiry arrives as the server's own verdict rather than as an instant to compare. The
 * blocking predicate asks whether hold_expires_at is still ahead of now() inside the query, so a
 * guard asking the same question anywhere else would be asking a different clock: the
 * customer's, if the instant travelled through a request, or the web server's, which drifts from
 * the database's. One boolean, decided where the predicate decides it, and no clock of ours is
 * in the answer.
 *
 * It is two of the predicate's three limbs and not all three. The third, an approval request
 * whose service auto-approves and therefore blocks until it is decided, is not read here by
 * anything: the reap's exclusion and the availability read both spell it in SQL, where the
 * predicate belongs, and no guard asks it. The two transitions that meet awaiting_approval head
 * on are approve and reject, and both read the expiry alone: a request whose window has closed
 * is the sweeper's to settle whatever its policy, so the policy decides nothing a guard could
 * act on. Carrying it for a guard that does not exist is how a re-read grows a column nobody
 * reads.
 *
 * **One column rides here that no guard reads**, the pay hop's seal of the manage token, and it
 * is the one exception to the rule above, made on purpose. Its reader is the paid transition's
 * event closure, ConfirmPaidBooking's, which opens the seal onto the confirmed record,
 * BookingConfirmed; the confirmation's send builds the manage link from that record, outside the
 * application. The compare-and-set that lands the payment clears the seal in the same statement,
 * so the only snapshot that still holds it, and that agrees with the transition being published,
 * is this re-read under the lock. A move keeps it, because a move is no compare-and-set.
 *
 * **A second rides for the move alone**, payment_ref. No guard reads it either: a move of a
 * booking still waiting for its payment clears the session it names under this lock and carries
 * it on the move's record, so the provider expires it after the commit and the next visit of the
 * pay hop opens a session of its own, for the total the hold fixed, which a move does not
 * change.
 *
 * The items carry the contended ranges, because step 5 recomputes the key set from them, and
 * the two facts a policy window is made of.
 */
final readonly class LockedBooking
{
    /** @var non-empty-list<LockedItem> in chain order */
    public array $items;

    /**
     * @param  bool  $holdIsLive  the server's answer to hold_expires_at > now(), false where there is no expiry at all
     * @param  list<LockedItem>  $items  in chain order
     * @param  string|null  $sealedToken  the manage token's ciphertext the pay hop stored, bookings.manage_token_sealed; never the plaintext
     * @param  string|null  $paymentRef  the session the pay hop recorded or the payment a notice named, bookings.payment_ref
     */
    public function __construct(
        public string $uuid,
        public BookingStatus $status,
        public ?HoldClass $holdClass,
        public bool $holdIsLive,
        public PaymentMode $paymentMode,
        array $items,
        public ?string $sealedToken = null,
        public ?string $paymentRef = null,
    ) {
        if ($items === []) {
            throw new InvalidArgumentException(sprintf('Booking %s was read back with no items; a booking has at least one.', $uuid));
        }

        $this->items = $items;
    }

    /**
     * The contended range of every item, in chain order: what the staff-day mutex rows stand
     * for, and what step 5 recomputes the locked key set from.
     *
     * @return non-empty-list<StaffBlock>
     */
    public function blocks(): array
    {
        return array_map(fn (LockedItem $item): StaffBlock => $item->block, $this->items);
    }

    /**
     * The first segment's start: the instant a policy window is measured back from. The items
     * come back in chain order, which is the order the segments run in.
     */
    public function firstStart(): DateTimeImmutable
    {
        return $this->items[0]->start;
    }

    /**
     * Every segment's service windows, in chain order. The policy takes the largest of each
     * across them, so the strictest service in a chain sets the limit for the whole booking.
     *
     * @return non-empty-list<ServiceWindows>
     */
    public function windows(): array
    {
        return array_map(fn (LockedItem $item): ServiceWindows => $item->windows, $this->items);
    }
}
