<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Enum\ApprovalTimeout;
use InvalidArgumentException;

/**
 * Where a request stands against its own window, for the page about to answer a decision link:
 * whether there is still a decision to make, whether the window has closed with nobody having
 * made one, and what the policy will make of it if nobody does.
 *
 * It is one value because the two pages have to agree. The page that offers a button and the
 * page that refuses to are one question with one answer, so a GET cannot show Approve on a
 * request the POST behind it would answer with the outcome page. The deciding POST asks it
 * twice, once of the row before the transition to gate it and once of the row after, because
 * the outcome page describes the row as it now stands and never the intention that wrote it,
 * DecideController.
 *
 * **Three states and not two.** A request whose window has closed still reads as one waiting
 * for a decision until the sweeper reaches it, so "not open" covers both the booking somebody
 * settled and the request the clock is about to settle, and only the second has anything to say
 * about what happens next.
 */
final readonly class ApprovalWindow
{
    /**
     * @param  bool  $isOpen  there is still a decision to make and this link may still make it
     * @param  bool  $hasClosed  the request is still waiting and its window has gone, which the
     *                           sweeper has not reached yet
     * @param  ApprovalTimeout|null  $afterTheWindow  the policy that will settle it, where the
     *                                                reader may be shown it: on_approval_timeout
     *                                                is a manage-only column
     */
    public function __construct(
        public bool $isOpen,
        public bool $hasClosed = false,
        public ?ApprovalTimeout $afterTheWindow = null,
    ) {
        if (($isOpen && $hasClosed) || ($afterTheWindow !== null && ! $hasClosed)) {
            throw new InvalidArgumentException('A window is open or closed and never both, and a timeout policy is what becomes of one that has closed.');
        }
    }
}
