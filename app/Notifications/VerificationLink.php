<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Domain\Lifetime;
use App\Site\SignupLinks;
use App\Tenancy\SignupVerification;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantUrls;

/**
 * The signup's verification link: the platform's own message, sent in its name through the
 * platform's seam to the owner's address, carrying the link and nothing else the signup was told.
 * It is sent at once, after the reservation has committed, and queued nowhere, so the clear token
 * travels from the caller to the seam in memory alone and no queue payload ever holds it. The seam
 * never throws: a transport that fails leaves the row reserved and the person on the pending page,
 * where the resend sends again.
 *
 * It is a branded mail in the row's language: the link is the click's route in that language,
 * under /el for a signup made in Greek, the words around it are mail.php's in the same language,
 * and the same words go out as HTML in the one layout beside the text. The owner it goes to has
 * chosen no language, no sign-in having reached them, so they read the tenant default, which for a
 * row whose trial is not built is tenants.locale. The link's window and the trial's length are the
 * code's, SignupVerification::LINK_LIFETIME in hours as Lifetime reads it and
 * TenantProvisioner::trialDays(), each a choice by its count in a paragraph of its own.
 */
final readonly class VerificationLink
{
    public function __construct(
        private PlatformMessenger $platform,
        private TenantUrls $urls,
        private SignupLinks $links,
        private BrandedMails $mails,
    ) {}

    public function send(Tenant $tenant, string $toAddress, string $toName, string $token): void
    {
        $this->platform->send(PlatformMessageKind::Verification, $tenant->id, function () use ($tenant, $toAddress, $toName, $token): PlatformMessage {
            $mail = $this->mails->to($tenant, null);
            $subject = $mail->line('verification.subject');
            $paragraphs = [
                $mail->line('greeting', ['owner' => $toName]),
                $mail->line('verification.confirm', ['business' => $tenant->name, 'address' => $this->urls->origin(Slug::of($tenant->slug))]),
                new MailLink($this->links->verify($token, $mail->language)),
                $mail->choice('verification.window', Lifetime::hours(SignupVerification::LINK_LIFETIME)),
                $mail->choice('verification.trial', TenantProvisioner::trialDays()),
                $mail->line('verification.ignore'),
            ];

            return new PlatformMessage(
                PlatformMessageKind::Verification,
                $tenant->id,
                $toAddress,
                $toName,
                $subject,
                BrandedMail::text($paragraphs),
                html: $mail->html($subject, $paragraphs, $mail->line('verification.reason')),
            );
        });
    }
}
