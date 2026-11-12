<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Clock;
use App\Application\Dto\BookingSnapshot;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\Db\BookingReader;
use App\Tenancy\Tenant;

/**
 * Where a decision link's booking stands against its window, asked of a re-read by the page
 * that is about to answer it: once by the GET, and twice by the deciding POST, before its
 * transition to gate it and after, of the row the transition left.
 *
 * **Two expiries are in play and they are two questions with two clocks.**
 *
 * The booking's window is hold_expires_at, and whether it is still open is the database's
 * answer and nothing else: the column is written by now(), the sweep selects against now(), and
 * the re-read under the booking's own row lock carries the same predicate as a column. A page
 * comparing that column against the application's clock would offer a button the sweeper had
 * already voided, or hide one that was still live, by whatever the web server and the database
 * differ by. So the question goes to the server, through BookingReader, in the spelling SqlText
 * hands the write protocol too.
 *
 * The link's expiry is a credential's own lifetime: it is minted equal to the window, because a
 * link may not outlive the thing it decides, and it is compared against the application's
 * clock, which is the clock that minted it. That comparison is ours to make and says nothing
 * about the booking; a link past it answers the outcome page like any other link with nothing
 * left to decide, rather than a refusal.
 *
 * **What happens after the window is a manage-only sentence.** on_approval_timeout is a column
 * the API keeps behind manageBookings, which is where BookingPayload::MANAGE_ONLY classifies
 * it, and the outcome page's sentence about it is that column in words, so it is not read at
 * all for a link whose user may not be shown it. A staff-role approver is told the window
 * closed and no more.
 */
final readonly class ApprovalWindows
{
    public function __construct(private BookingReader $bookings, private Clock $clock) {}

    public function of(Tenant $tenant, BookingSnapshot $booking, SignedDecision $link): ApprovalWindow
    {
        // The status is asked of a re-read and never of the link, because a link says what was
        // true when it was minted. The transition under the POST asks again, under its own
        // lock, and refuses what it finds settled; this is the same question asked early enough
        // to answer a person in words.
        if ($booking->status !== BookingStatus::AwaitingApproval) {
            return new ApprovalWindow(false);
        }

        if ($this->bookings->holdIsLive($tenant, $booking->uuid)) {
            return new ApprovalWindow(! $link->hasLapsed($this->clock->now()));
        }

        return new ApprovalWindow(
            false,
            true,
            $link->managesBookings() ? $this->bookings->approvalTimeout($tenant, $booking->uuid) : null,
        );
    }
}
