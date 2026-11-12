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
 * The reopen's notice: the platform's own message, sent in its name through the platform's seam to
 * every owner user of a closing tenant the console reopened, the kind reopened. It names the
 * business and the instant in the tenant's clock with the zone named once, says the account is no
 * longer closing and nothing in it will be deleted, that changes to the setup work again while the
 * bookings and the booking page carry on, and gives the admin's sign-in page. It is sent at once,
 * after the transaction that reopened the account has committed, and queued nowhere; the seam
 * never throws, so an owner the transport failed for is a logged error and the others are still
 * sent theirs.
 *
 * It is a branded mail in its recipient's language: the words are mail.php's in the owner's
 * users.locale, else the tenant default, the instant spelled by the locale-aware formatter with
 * the weekday and the month in that language, and the same words go out as HTML in the one layout
 * beside the text. What every owner reads alike is composed once per language among them.
 */
final readonly class TenantReopened
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
            $this->platform->send(PlatformMessageKind::Reopened, $account->id, function () use ($account, $owner, $at, &$composed): PlatformMessage {
                $mail = $this->mails->to($account->tenant, $owner['locale']);
                [$subject, $reopenedAt, $reason] = $composed[$mail->language->value] ??= [
                    $mail->line('reopened.subject'),
                    $mail->instant($at, $account->zone),
                    $mail->line('reopened.reason', ['business' => $account->name]),
                ];
                $paragraphs = [
                    $mail->line('greeting', ['owner' => $owner['name']]),
                    $mail->line('reopened.kept', ['business' => $account->name, 'at' => $reopenedAt]),
                    $mail->line('reopened.what_returns'),
                    new MailLink($this->urls->signIn($account->slug)),
                    $mail->line('reopened.zone', ['zone' => $account->zone->getName()]),
                ];

                return new PlatformMessage(
                    PlatformMessageKind::Reopened,
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
