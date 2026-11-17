<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Help\HelpMessageStored;
use App\Tenancy\Role;
use App\Tenancy\TenantUrls;
use Illuminate\Container\Attributes\Config;

/**
 * A business's help message to us: the platform's own mail, sent through the platform's seam to the
 * support mailbox, site.contact_address, once for a new ticket and once for every later message the
 * business writes on it. It names the business, the writer with their role, the ticket's subject and
 * the message as written, and links to the thread on the console's host, where the answer is
 * written; so it carries no reply-to.
 *
 * It is an operator's mail, so it is plain English text with no HTML part, whatever the writer's
 * language. It is about the business, so a failed send is logged against the kind and the
 * business's id, and never with the message, which this mail alone carries.
 *
 * The business's name, the writer's name and the subject are one-line values someone typed, so each
 * is folded to one line wherever it stands, in the mail's subject and in its text: a line break in
 * one would read as a line of the mail's own. The message alone keeps its line breaks, under the
 * blank line that sets it apart.
 */
final readonly class HelpMessageReceived
{
    /** The subject of a new ticket's mail, the business's name filled in. */
    public const string OPENED = 'A new help ticket from %s';

    /** The subject of a later message's mail, the business's name filled in. */
    public const string ADDED = 'A new message on a help ticket from %s';

    public function __construct(
        private PlatformMessenger $platform,
        private TenantUrls $urls,
        #[Config('site.contact_address')] private string $mailbox,
    ) {}

    /**
     * Hands the mail for one stored business message to the seam, the business named as it is now.
     */
    public function send(HelpMessageStored $stored, string $business): void
    {
        $this->platform->send(PlatformMessageKind::HelpMessage, $stored->tenantId, function () use ($stored, $business): PlatformMessage {
            $business = BrandedMail::oneLine($business);

            return new PlatformMessage(
                PlatformMessageKind::HelpMessage,
                $stored->tenantId,
                $this->mailbox,
                '',
                sprintf($stored->opened ? self::OPENED : self::ADDED, $business),
                implode("\n", [
                    $stored->opened
                        ? sprintf('%s opened a help ticket.', $business)
                        : sprintf('%s wrote again on a help ticket.', $business),
                    '',
                    'Business: '.$business,
                    'From: '.BrandedMail::oneLine($stored->authorName).($stored->role === null ? '' : ', '.self::roleOf($stored->role)),
                    'Subject: '.BrandedMail::oneLine($stored->subject),
                    '',
                    $stored->body,
                    '',
                    'Answer it in the console:',
                    $this->urls->console('/tickets/'.$stored->ticketId),
                ]),
            );
        });
    }

    private static function roleOf(Role $role): string
    {
        return match ($role) {
            Role::Owner => 'Owner',
            Role::Manager => 'Manager',
            Role::Staff => 'Staff',
        };
    }
}
