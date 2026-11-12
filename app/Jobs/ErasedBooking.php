<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\MessageKey;
use App\Tenancy\Tenant;
use Psr\Log\LoggerInterface;

/**
 * The senders' one answer to a booking whose customer was erased: no message is ever addressed
 * to a null address, and none is written about a person who is gone, so every job that re-reads
 * a booking to mail it, the customer or the business, ends here on an erased one and sends
 * nothing.
 *
 * It ends quietly and says so once, at info: an erasure racing a queued message is an ordinary
 * day rather than a fault, and nothing retries. The line names the tenant, the message and the
 * booking, the handles the seam's own lines use (MailedMessages), and never an address, which
 * the booking no longer holds anyway.
 */
final readonly class ErasedBooking
{
    public const string NOT_SENT = 'A message about a booking whose customer was erased was not sent';

    /**
     * Whether the re-read booking's customer was erased, so that the sender ends here; where it
     * was, the line is written before the answer.
     */
    public static function endsHere(BookingSnapshot $booking, Tenant $tenant, MessageKey $key, LoggerInterface $log): bool
    {
        if ($booking->customer !== null) {
            return false;
        }

        $log->info(self::NOT_SENT, ['tenant' => $tenant->slug, 'key' => $key->value, 'booking' => $booking->uuid]);

        return true;
    }
}
