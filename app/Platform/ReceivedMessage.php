<?php

declare(strict_types=1);

namespace App\Platform;

use App\Site\SiteLanguage;
use DateTimeImmutable;

/**
 * One contact form message as the console's messages screen reads it: the sender's name and
 * address, what they wrote as it was stored, the language of the page it was sent from and the
 * instant it arrived. A prospect's personal data, so it is shown to the operator and kept nowhere
 * else: never in a log line, an exception's message or an audit row.
 */
final readonly class ReceivedMessage
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $message,
        public SiteLanguage $language,
        public DateTimeImmutable $receivedAt,
    ) {}
}
