<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Models\User;
use App\Platform\PasswordLinks;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;

/**
 * The set-password link: the platform's own message, sent in its name through the platform's seam
 * to the one user the console sent a link to. It names the business and its sign-in page, carries
 * the link on the tenant's own host, and says the link works once within its lifetime,
 * PasswordLinks::hours() as a choice by those hours, and that nothing changes until it is used. It
 * is sent at once, after the transaction that minted the link has committed, and queued nowhere, so
 * the clear token travels from the mint to the seam in memory alone and no queue payload ever holds
 * it. The seam never throws: a transport that fails is a logged error, and the console can send
 * another link.
 *
 * It is a branded mail in the user's language: the words are mail.php's in their users.locale, else
 * the tenant default, a user who chose none, as every new one is, reading the tenant's; the same
 * words go out as HTML in the one layout beside the text. The reader is any user of the tenant,
 * named by the greeting's placeholder.
 *
 * It reads for a reset, the link the console's detail sends, saying any password the reader already
 * has keeps working until the link is used; or for a first password, the link a free tenant's new
 * owner is sent, whose password is a secret nobody knows, so the mail keeps the window and drops
 * the sentence about a password the reader has.
 */
final readonly class SetPasswordLink
{
    public function __construct(
        private PlatformMessenger $platform,
        private TenantUrls $urls,
        private BrandedMails $mails,
    ) {}

    public function send(Tenant $tenant, User $user, string $token, bool $firstPassword = false): void
    {
        $this->platform->send(PlatformMessageKind::SetPassword, $tenant->id, function () use ($tenant, $user, $token, $firstPassword): PlatformMessage {
            $slug = Slug::of($tenant->slug);
            $mail = $this->mails->to($tenant, $user->locale);
            $subject = $mail->line('set_password.subject');
            $paragraphs = [
                $mail->line('greeting', ['owner' => $user->name]),
                $mail->line('set_password.choose', ['business' => $tenant->name, 'address' => $this->urls->signIn($slug)]),
                new MailLink($this->urls->setPasswordLink($slug, $token)),
                $mail->choice($firstPassword ? 'set_password.window_first' : 'set_password.window', PasswordLinks::hours()),
                $mail->line('set_password.ignore'),
            ];

            return new PlatformMessage(
                PlatformMessageKind::SetPassword,
                $tenant->id,
                $user->email,
                $user->name,
                $subject,
                BrandedMail::text($paragraphs),
                html: $mail->html($subject, $paragraphs, $mail->line('set_password.reason', ['business' => $tenant->name])),
            );
        });
    }
}
