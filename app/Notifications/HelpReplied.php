<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Help\HelpMessageStored;
use App\Models\User;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Database\Query\Builder;

/**
 * An operator's reply on a help ticket, told to the business: the platform's own message, sent in
 * its name through the platform's seam to each user who wrote in the thread and is still a user of
 * the business when the reply is sent, once each however often they wrote. The operator who replied
 * is no user and is sent nothing, and the business never reads an operator's name or address: the
 * reply is the platform's support's, named by the public name.
 *
 * It is a branded mail in each reader's language, their users.locale, else the business's. It names
 * the business and the ticket's subject, the subject folded to one line wherever it stands, since
 * a colleague typed it and a line break in it would set lines of its own into the mail; it gives
 * the reply as it was written, the one paragraph that asks the layout to keep its line breaks,
 * escaped as every paragraph is; and it links to the thread on the business's own host, where the
 * admin opens on it, through sign-in where needed.
 */
final readonly class HelpReplied
{
    public function __construct(
        private PlatformMessenger $platform,
        private TenantUrls $urls,
        private BrandedMails $mails,
    ) {}

    /**
     * Hands the mail to the seam once for each user of the business who wrote in the thread, read
     * now rather than when they wrote, so a user removed since is sent nothing.
     */
    public function send(HelpMessageStored $stored, Tenant $tenant): void
    {
        $writers = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', fn (Builder $messages): Builder => $messages->select('user_id')->from('help_messages')
                ->where('ticket_id', $stored->ticketId)
                ->whereNotNull('user_id'))
            ->orderBy('id')
            ->get();

        foreach ($writers as $writer) {
            $this->platform->send(PlatformMessageKind::HelpReply, $tenant->id, function () use ($stored, $tenant, $writer): PlatformMessage {
                $mail = $this->mails->to($tenant, $writer->locale);
                $about = BrandedMail::oneLine($stored->subject);
                $subject = $mail->line('help_reply.subject', ['subject' => $about]);
                $paragraphs = [
                    $mail->line('greeting', ['owner' => $writer->name]),
                    $mail->line('help_reply.replied', ['business' => $tenant->name]),
                    $mail->line('help_reply.about', ['subject' => $about]),
                    new MailLines($stored->body),
                    $mail->line('help_reply.open'),
                    new MailLink($this->urls->origin(Slug::of($tenant->slug)).'/admin/help/'.rawurlencode($stored->ticketUuid)),
                ];

                return new PlatformMessage(
                    PlatformMessageKind::HelpReply,
                    $tenant->id,
                    $writer->email,
                    $writer->name,
                    $subject,
                    BrandedMail::text($paragraphs),
                    html: $mail->html($subject, $paragraphs, $mail->line('help_reply.reason', ['business' => $tenant->name])),
                );
            });
        }
    }
}
