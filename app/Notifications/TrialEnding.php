<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Clock;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Billing\TrialAccount;
use App\Billing\TrialNotice;
use App\Tenancy\TenantUrls;

/**
 * The trial's two notices: the platform's own messages, sent in its name through the platform's
 * seam to every owner user of a tenant in trial, three days before the end and on its last day.
 * Each names the business, the end in the tenant's clock with the zone named once, what the end
 * pauses and what it leaves running, and the Billing screen, where a plan is chosen. It is sent
 * at once, after the statement that claimed the notice has committed, and queued nowhere; the
 * seam never throws, so an owner the transport failed for is a logged error and the others are
 * still sent theirs.
 *
 * Each is a branded mail in its recipient's language: the words are mail.php's in the owner's
 * users.locale, else the tenant default; the end is spelled by the locale-aware formatter with
 * the weekday and the month in that language, and the same words go out as HTML in the one layout
 * beside the text. What every owner reads alike, the subject, the end and the footer's reason, is
 * composed once per language among them.
 *
 * The subjects hold whatever is left of the trial: a trial already shorter than three days when
 * the first run sees it is sent both, and "soon" is still true of it. A trial whose end is at or
 * behind the clock's now when "ends today" is sent, the lapse not having moved it yet (P9F-51a),
 * reads two lines of its own in place of the end and what the end pauses, both in the past, under
 * a subject of its own saying the trial has ended, in the text and the HTML part alike: the end
 * the lapse counts as passed, at or before now, is the one the body says has passed.
 */
final readonly class TrialEnding
{
    public function __construct(
        private PlatformMessenger $platform,
        private TenantUrls $urls,
        private BrandedMails $mails,
        private Clock $clock,
    ) {}

    /**
     * Hands the notice to the seam once for each owner.
     */
    public function send(TrialAccount $account, TrialNotice $notice): void
    {
        $section = $notice === TrialNotice::ThreeDaysLeft ? 'trial.three_days' : 'trial.ends_today';
        $ended = $notice->reachesAPastEnd() && $account->trialEndsAt <= $this->clock->now();
        $composed = [];

        foreach ($account->owners as $owner) {
            $this->platform->send(PlatformMessageKind::TrialNotice, $account->id, function () use ($account, $owner, $section, $ended, &$composed): PlatformMessage {
                $mail = $this->mails->to($account->tenant, $owner['locale']);
                [$subject, $end, $reason] = $composed[$mail->language->value] ??= [
                    $mail->line($ended ? 'trial.ends_today.ended_subject' : $section.'.subject'),
                    $mail->instant($account->trialEndsAt, $account->zone),
                    $mail->line('trial.reason', ['business' => $account->name]),
                ];
                $paragraphs = [
                    $mail->line('greeting', ['owner' => $owner['name']]),
                    $mail->line($section.($ended ? '.ended' : '.ends'), ['business' => $account->name, 'end' => $end]),
                    $mail->line($ended ? 'trial.what_ended' : 'trial.what_ends'),
                    $mail->line('trial.billing'),
                    new MailLink($this->urls->billing($account->slug)),
                    $mail->line('trial.zone', ['zone' => $account->zone->getName()]),
                ];

                return new PlatformMessage(
                    PlatformMessageKind::TrialNotice,
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
