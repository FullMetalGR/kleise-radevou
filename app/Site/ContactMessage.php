<?php

declare(strict_types=1);

namespace App\Site;

/**
 * One message the contact form let through: the person's name, their address and what they
 * wrote, each as the form judged it, and the language of the page it was sent from. Personal
 * data from end to end, so it is stored, mailed to the support mailbox and kept nowhere else:
 * never in a log line, an exception's message or a queue payload.
 */
final readonly class ContactMessage
{
    public function __construct(
        public string $name,
        public string $email,
        public string $message,
        public SiteLanguage $language,
    ) {}
}
