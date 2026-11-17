<?php

declare(strict_types=1);

namespace App\Help;

/**
 * One ticket as the console reads it: the ticket as its list shows it, and its messages oldest
 * first, each under its writer's name as written, an operator's included.
 */
final readonly class DeskThread
{
    /**
     * @param  list<HelpThreadMessage>  $messages
     */
    public function __construct(
        public DeskTicket $ticket,
        public array $messages,
    ) {}
}
