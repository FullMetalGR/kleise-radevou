<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\MyBookings\BookingsLinkRequested;
use App\Jobs\SendBookingsLink;

/**
 * A minted My bookings link is mailed to the address it was minted for, through the queue as
 * every message is.
 *
 * **It runs after the row is written and it does not throw**, QueuedMessages' two promises, the
 * same as every listener on a transition. The message is sent from the job, because the plaintext
 * token is on the record and a job is where it can travel encrypted; see SendBookingsLink.
 *
 * A failure to queue is logged against the link, by its id, since the message is about no
 * booking. The language the request was answered in rides to the job with the link.
 */
final readonly class MailTheBookingsLink
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingsLinkRequested $requested): void
    {
        $this->messages->hand(
            SendBookingsLink::class,
            'link:'.$requested->linkId,
            function (int $tenant) use ($requested): void {
                SendBookingsLink::dispatch($tenant, $requested->linkId, $requested->token, $requested->language);
            },
        );
    }
}
