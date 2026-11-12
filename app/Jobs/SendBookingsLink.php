<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Infrastructure\Db\CustomerAccess;
use App\Infrastructure\Notification\Messengers;
use App\Notifications\BookingsLink;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The queued half of the My bookings link: the re-read, the words and the seam.
 *
 * **It carries the plaintext token, and that is why it is encrypted**, the reason the two jobs
 * carrying a manage token are: the row holds the hash, and the plaintext travels from the mint to
 * the send in here, and a queue row is a row.
 *
 * It starts with the tenant and the link rather than the tenant and a booking, the one job that
 * does: the message is about every booking of an address and no one of them. The re-read is the
 * link's row, for the address it was minted for; a link whose row is gone, or a tenant that is,
 * gets nothing. Whether the key is still on is the seam's answer, as it is for every message.
 *
 * It carries the language too, the request's, the code BookingsLinkRequested brought: the words
 * are written in it and the link is minted under its prefix, /en or /el, so the page the link
 * opens speaks the message's language, as a booking's links speak its.
 */
final class SendBookingsLink implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $language  the code of the language the request that asked for the link was answered in, en or el
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $linkId,
        public readonly string $linkToken,
        public readonly string $language,
    ) {}

    public function handle(CustomerAccess $access, Messengers $messengers, TenantUrls $urls, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $email = $access->linkAddress($tenant, $this->linkId);

            if ($email === null) {
                return;
            }

            // The request's language, a code the request's resolver answered; one no case names,
            // which no request can carry, reads as English.
            $language = SiteLanguage::tryFrom($this->language) ?? SiteLanguage::English;
            $link = $urls->myBookingsLink(Slug::of($tenant->slug), $this->linkToken, $language);

            // Written inside the seam's promise, after its switch; the link names no booking.
            $messengers->for($tenant)->send(MessageKey::MyBookingsLink, null, fn (): Message => BookingsLink::of($email, $tenant->name, $link, $language));
        } catch (Throwable $failure) {
            // Nothing from below this line leaves the job: the link is an argument of the call
            // that writes it into the words, and failed_jobs.exception is a stringified trace
            // in a column nothing encrypts. See MessageNotSent, made here, at this frame.
            throw MessageNotSent::after($failure, (string) $this->linkId, MessageNotSent::ABOUT_LINK);
        }
    }
}
