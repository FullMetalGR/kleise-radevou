<?php

declare(strict_types=1);

namespace App\Site;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * The contact form's table: a message inserted in one statement with the language of the page
 * it came from and the clock's now as UTC text with its offset, and nothing about the machine
 * that sent it. The statement commits on its own, so whatever the caller does next, the mail
 * among it, runs after the row stands.
 *
 * A message's content never reaches a log line. A failed statement's exception spells the
 * statement with its values bound in, the person's name, address and message among them, and
 * the exception handler logs that message; so a failure here is answered with an exception of
 * its own naming the SQLSTATE alone, the statement's exception left behind rather than
 * chained, since a chained one is logged too.
 */
final readonly class ContactMessages
{
    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function store(ContactMessage $message): void
    {
        try {
            $this->db->connection()->insert(
                'insert into contact_messages (name, email, message, locale, created_at) values (?, ?, ?, ?, ?::timestamptz)',
                [$message->name, $message->email, $message->message, $message->language->value, SqlText::instant($this->clock->now())],
            );
        } catch (QueryException $failure) {
            throw new RuntimeException(sprintf('A contact message could not be stored (SQLSTATE %s).', $failure->getCode()));
        }
    }
}
