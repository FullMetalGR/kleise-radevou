<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Site\Brand;
use App\Site\ContactMessage;
use App\Site\SiteLanguage;
use Illuminate\Container\Attributes\Config;

/**
 * The contact form's mail: the platform's own message, sent in its name through the platform's
 * seam to the support mailbox, site.contact_address, once the message's row has been written. It
 * is about no tenant, so it names none and a failed send is logged against the kind alone; and
 * it is an operator's mail, so it is plain English whatever the page's language, which it names.
 * Its reply-to is the person who wrote, the one message on the seam that carries one, so the
 * mailbox answers them by replying.
 *
 * The person's name stays out of the subject, a header, and is in the body alone. Sent at once
 * and queued nowhere, so the message is in no queue payload; the seam never throws, so a
 * transport that fails leaves the row standing for the console to list. The page reaches the
 * seam through this class and never through the port.
 */
final readonly class ContactMessageReceived
{
    /** The subject, the public name filled in. */
    public const string SUBJECT = 'A message from the contact form of %s';

    public function __construct(
        private PlatformMessenger $platform,
        private Brand $brand,
        #[Config('site.contact_address')] private string $mailbox,
    ) {}

    public function send(ContactMessage $message): void
    {
        $this->platform->send(PlatformMessageKind::ContactMessage, null, fn (): PlatformMessage => new PlatformMessage(
            PlatformMessageKind::ContactMessage,
            null,
            $this->mailbox,
            '',
            sprintf(self::SUBJECT, $this->brand->name),
            implode("\n", [
                sprintf('A message came in through the contact form of %s.', $this->brand->name),
                '',
                'Name: '.$message->name,
                'Email: '.$message->email,
                'Language: '.self::languageOf($message->language),
                '',
                $message->message,
                '',
                sprintf('Reply to this email to answer %s.', $message->name),
            ]),
            replyTo: $message->email,
        ));
    }

    private static function languageOf(SiteLanguage $language): string
    {
        return match ($language) {
            SiteLanguage::English => 'English',
            SiteLanguage::Greek => 'Greek',
        };
    }
}
