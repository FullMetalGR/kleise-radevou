<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\ConfirmPaidBooking;
use App\Application\Event\BookingConfirmed;
use App\Domain\Audit\Actor;
use App\Domain\Enum\HoldClass;
use App\Jobs\SendNewBookingNotice;

/**
 * The business hears about a booking nobody at the business was asked about, and about a
 * payment that landed on a booking they approved.
 *
 * The decision is the hold class the booking was settled from and, for a payment hold, the
 * record's actor, and it is made on the record because the row can no longer say: confirming
 * nulls the class.
 *
 * - **checkout**: a customer took a slot and settled it themselves. Nobody at the business has
 *   heard of it, so this is the message that tells them.
 * - **approval**: the business decided this booking themselves, and being told about the
 *   outcome of their own click is noise. The case where the clock decided for them is a
 *   different fact under a different key, auto_approved, and is AnnounceAutoApproval's.
 * - **none at all**: an admin entered the booking, so the business is the one who
 *   made it. Telling them would be mailing somebody their own typing.
 * - **payment**: the business approved the request into a hold for its payment, so the booking
 *   is theirs already, but the payment is news: until it landed the time was only held. The
 *   paid transition's record, the payment's job its actor, is told under this key in its paid
 *   form. The pay hop's settlement on site carries the same class and says afterPayment too,
 *   yet moved no money, and its actor is the customer: nothing is said.
 *
 * **It runs after the commit and it does not throw**, and it runs whether or not the customer's
 * confirmation on the same record did. Both promises are QueuedMessages'. What happens here is
 * the decision and the handover; the recipient rule and the sending happen in the job, after a
 * re-read, because who at the business is party to a booking is a question for the moment the
 * message goes out.
 */
final readonly class AnnounceNewBooking
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingConfirmed $confirmed): void
    {
        $paid = $confirmed->holdClass === HoldClass::Payment && $confirmed->actor->value === Actor::job(ConfirmPaidBooking::ACTOR)->value;

        if ($confirmed->holdClass !== HoldClass::Checkout && ! $paid) {
            return;
        }

        $this->messages->hand(
            SendNewBookingNotice::class,
            $confirmed->uuid,
            function (int $tenant) use ($confirmed, $paid): void {
                SendNewBookingNotice::dispatch($tenant, $confirmed->uuid, $paid);
            },
        );
    }
}
