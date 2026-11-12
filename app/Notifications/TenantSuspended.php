<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Platform\AccountOwners;
use DateTimeImmutable;

/**
 * The suspension's notice: the platform's own message, sent in its name through the platform's seam
 * to every owner user of a tenant the console suspended. It names the business and the instant in
 * the tenant's clock with the zone named once, gives the operator's reason as it was written, and
 * says what the pause stops and what it leaves running. It is sent at once, after the transaction
 * that suspended the account has committed, and queued nowhere; the seam never throws, so an owner
 * the transport failed for is a logged error and the others are still sent theirs.
 *
 * It is a branded mail in its recipient's language: the words are mail.php's in the owner's
 * users.locale, else the tenant default; what every owner reads alike is composed once per language
 * among them. The platform is named by its public name where the text once named the codebase, the
 * instant spelled by the locale-aware formatter with the weekday and the month in that language,
 * and the same words go out as HTML in the one layout beside the text. The operator's reason is a
 * paragraph of its own, as written; what pauses and what keeps running are one paragraph, as the
 * text has always read them, on adjacent lines.
 */
final readonly class TenantSuspended
{
    public function __construct(
        private PlatformMessenger $platform,
        private BrandedMails $mails,
    ) {}

    /**
     * Hands the notice to the seam once for each owner.
     */
    public function send(AccountOwners $account, DateTimeImmutable $at, string $reason): void
    {
        $composed = [];

        foreach ($account->owners as $owner) {
            $this->platform->send(PlatformMessageKind::Suspension, $account->id, function () use ($account, $owner, $at, $reason, &$composed): PlatformMessage {
                $mail = $this->mails->to($account->tenant, $owner['locale']);
                [$subject, $pausedAt, $why] = $composed[$mail->language->value] ??= [
                    $mail->line('suspension.subject'),
                    $mail->instant($at, $account->zone),
                    $mail->line('suspension.reason', ['business' => $account->name]),
                ];
                $paragraphs = [
                    $mail->line('greeting', ['owner' => $owner['name']]),
                    $mail->line('suspension.paused', ['business' => $account->name, 'end' => $pausedAt]),
                    $mail->line('suspension.because'),
                    $reason,
                    $mail->line('suspension.what_pauses')."\n".$mail->line('suspension.what_keeps'),
                    $mail->line('suspension.zone', ['zone' => $account->zone->getName()]),
                ];

                return new PlatformMessage(
                    PlatformMessageKind::Suspension,
                    $account->id,
                    $owner['address'],
                    $owner['name'],
                    $subject,
                    BrandedMail::text($paragraphs),
                    html: $mail->html($subject, $paragraphs, $why),
                );
            });
        }
    }
}
