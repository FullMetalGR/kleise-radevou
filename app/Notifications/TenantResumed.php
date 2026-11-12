<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Platform\AccountOwners;
use App\Tenancy\TenantUrls;
use DateTimeImmutable;

/**
 * The resumption's notice: the platform's own message, sent in its name through the platform's
 * seam to every owner user of a tenant whose suspension the console ended. It names the business
 * and the instant in the tenant's clock with the zone named once, and says the booking page and
 * the admin are open again, with the booking page's address. It is sent at once, after the
 * transaction that resumed the account has committed, and queued nowhere; the seam never throws.
 *
 * It is a branded mail in its recipient's language: the words are mail.php's in the owner's
 * users.locale, else the tenant default, the instant spelled by the locale-aware formatter with
 * the weekday and the month in that language, and the same words go out as HTML in the one
 * layout beside the text. What every owner reads alike is composed once per language among them.
 */
final readonly class TenantResumed
{
    public function __construct(
        private PlatformMessenger $platform,
        private TenantUrls $urls,
        private BrandedMails $mails,
    ) {}

    /**
     * Hands the notice to the seam once for each owner.
     */
    public function send(AccountOwners $account, DateTimeImmutable $at): void
    {
        $composed = [];

        foreach ($account->owners as $owner) {
            $this->platform->send(PlatformMessageKind::Resumption, $account->id, function () use ($account, $owner, $at, &$composed): PlatformMessage {
                $mail = $this->mails->to($account->tenant, $owner['locale']);
                [$subject, $endedAt, $reason] = $composed[$mail->language->value] ??= [
                    $mail->line('resumption.subject'),
                    $mail->instant($at, $account->zone),
                    $mail->line('resumption.reason', ['business' => $account->name]),
                ];
                $paragraphs = [
                    $mail->line('greeting', ['owner' => $owner['name']]),
                    $mail->line('resumption.ended', ['business' => $account->name, 'end' => $endedAt]),
                    new MailLink($this->urls->hostedPage($account->slug)),
                    $mail->line('resumption.zone', ['zone' => $account->zone->getName()]),
                ];

                return new PlatformMessage(
                    PlatformMessageKind::Resumption,
                    $account->id,
                    $owner['address'],
                    $owner['name'],
                    $subject,
                    BrandedMail::text($paragraphs),
                    html: $mail->html($subject, $paragraphs, $reason),
                );
            });
        }
    }
}
