<?php

declare(strict_types=1);

namespace App\Help;

/**
 * One ticket as the business reads it: its uuid, subject and status, and its messages oldest
 * first.
 */
final readonly class HelpThread
{
    /**
     * @param  list<HelpThreadMessage>  $messages
     */
    public function __construct(
        public string $uuid,
        public string $subject,
        public HelpStatus $status,
        public array $messages,
    ) {}
}
