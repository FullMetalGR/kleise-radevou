<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Audit\Actor;
use DateTimeImmutable;

/**
 * What an approval writes onto the booking row: who said yes, when, and the end of the hold the
 * request was waiting on.
 *
 * It is a description rather than a step, because two unrelated things approve a request and
 * only one of them is a guarded transition. The owner pressing Approve goes through the guarded
 * sequence; the sweeper grants a request whose window closed under an auto-approving policy, in
 * a batch, with no lock on a staff-day and no guard to ask. Sharing the sequence between them
 * would be wrong and sharing nothing would leave two spellings of the same four columns, which
 * is how one of them comes to be missed.
 *
 * Where the approval lands is deliberately not here. It is ApproveBooking::landsOn, asked of
 * the booking's payment mode and the tenant's working connection: confirmed writes these four
 * columns as they are, and awaiting_payment writes the same stamp under a new payment hold,
 * whose class and expiry the use case sets over the two hold columns here.
 */
final readonly class Approval
{
    private function __construct(
        public DateTimeImmutable $at,
        public ?int $userId,
    ) {}

    /**
     * The decision as an actor made it. The user id is read from the actor rather than passed
     * beside it, so the trail's account of who acted and the row's cannot disagree, and a
     * decision no user of the tenant made records a null rather than an invented id: the
     * sweeper's, the engine's, and support's in a session, whose actor carries the operator's id,
     * which names no user.
     */
    public static function by(Actor $actor, DateTimeImmutable $at): self
    {
        return new self($at, $actor->userId());
    }

    /**
     * The columns, in the order a compare-and-set writes them. The instant stays an instant:
     * how one crosses to the store is the store's to spell, in the one place that spells it.
     *
     * The hold is ended with the decision because a request that has been answered is waiting
     * on nobody, and every transition out of a held state nulls both columns.
     *
     * @return array<string, scalar|DateTimeImmutable|null> keyed by TransitionColumn values
     */
    public function columns(): array
    {
        return [
            TransitionColumn::HoldClass->value => null,
            TransitionColumn::HoldExpiresAt->value => null,
            TransitionColumn::ApprovedAt->value => $this->at,
            TransitionColumn::ApprovedBy->value => $this->userId,
        ];
    }
}
