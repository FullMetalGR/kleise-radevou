<?php

declare(strict_types=1);

namespace App\Help;

use App\Infrastructure\Db\SqlText;
use App\Platform\Operator;
use App\Tenancy\Role;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;
use UnexpectedValueException;

/**
 * The help tables' writes and reads that both sides share: the business's side and the console's.
 *
 * A ticket is opened with its first message, and every later message, by either side, is appended
 * under the ticket's row lock, the message's row and the ticket's newest-message columns written
 * in the caller's transaction: last_message_at and last_side on every message, last_platform_at on
 * the platform's. A business message reopens a closed ticket; a platform message leaves the status
 * as it is.
 *
 * A thread's instants rise in the order its messages were stored. The caller hands the instant it
 * read off the clock, and the ticket's row, which the write holds, decides the instant stored: the
 * one handed where it is later than the ticket's newest message, and otherwise the next
 * microsecond after that message. Two writers that read the clock in one order and reached the row
 * in the other are stored in the row's order, so the newest-message columns always describe the
 * message stored last, and no message shares the instant of the one before it, which is where that
 * one's writer has their read mark. The instant stored is the one the message's row, the ticket's
 * columns and the hook's payload carry.
 *
 * Once the caller's transaction has committed, it hands each stored message to announce(), which
 * builds the hook that follows a message, calls it, and keeps whatever either throws from the
 * write: the failure is logged by the ticket and the message and nothing else, never the message's
 * text, and the write's answer stands. The hook is built there and nowhere else, so building the
 * ledger, which every admin page's props do, builds nothing of what follows a message.
 */
final readonly class HelpLedger
{
    /** The log line of a hook that failed after its message was stored. */
    public const string HOOK_FAILED = 'What follows a stored help message failed; the message stands';

    /**
     * The instant a message on a ticket is stored at, read off the ticket's row as its update
     * holds it: the instant bound, or the next microsecond after the newest message where the
     * instant bound is not later than it.
     */
    private const string NEXT = "greatest(?::timestamptz, last_message_at + interval '1 microsecond')";

    public function __construct(private Container $container, private LoggerInterface $log) {}

    /**
     * A new ticket of the business with its first message, a user's, the ticket open and the
     * user's message its newest.
     */
    public function open(Connection $db, int $tenantId, string $subject, HelpAuthor $author, string $body, DateTimeImmutable $at): HelpMessageStored
    {
        $now = SqlText::instant($at);
        $row = $db->selectOne(
            'insert into help_tickets (uuid, tenant_id, subject, status, last_message_at, last_side, created_at, updated_at)
             values (gen_random_uuid(), ?, ?, ?, ?::timestamptz, ?, ?::timestamptz, ?::timestamptz)
             returning id, uuid::text as uuid',
            [$tenantId, $subject, HelpStatus::Open->value, $now, HelpSide::Business->value, $now, $now],
        );

        if (! $row instanceof stdClass || ! is_int($row->id) || ! is_string($row->uuid)) {
            throw new UnexpectedValueException('A new help ticket came back in a shape this writer does not know.');
        }

        $ticket = new HelpTicketRef($row->id, $row->uuid, $tenantId, $subject, HelpStatus::Open);

        return $this->stored($ticket, true, HelpSide::Business, $author->userId, null, $author->name, $author->role, $body, $at, $this->insert($db, $ticket->id, HelpSide::Business, $author->userId, null, $author->name, $body, $now));
    }

    /**
     * The ticket's row under a lock for the caller's transaction, picked by the condition given;
     * null where no row answers it.
     *
     * @param  list<int|string>  $bindings
     */
    public function lock(Connection $db, string $where, array $bindings): ?HelpTicketRef
    {
        $row = $db->selectOne('select id, uuid::text as uuid, tenant_id, subject, status from help_tickets where '.$where.' for update', $bindings);

        if ($row === null) {
            return null;
        }

        if (! $row instanceof stdClass
            || ! is_int($row->id)
            || ! is_string($row->uuid)
            || ! is_int($row->tenant_id)
            || ! is_string($row->subject)
            || ! is_string($row->status)
            || ($status = HelpStatus::tryFrom($row->status)) === null) {
            throw new UnexpectedValueException('A help ticket came back in a shape this reader does not know.');
        }

        return new HelpTicketRef($row->id, $row->uuid, $row->tenant_id, $row->subject, $status);
    }

    /**
     * A business user's message on a locked ticket: its newest, and the ticket open again where it
     * was closed. The instant given is the one asked for; the one stored is in the answer.
     */
    public function appendFromBusiness(Connection $db, HelpTicketRef $ticket, HelpAuthor $author, string $body, DateTimeImmutable $at): HelpMessageStored
    {
        $at = $this->advance($db, $ticket, HelpSide::Business, $at);

        return $this->stored($ticket, false, HelpSide::Business, $author->userId, null, $author->name, $author->role, $body, $at, $this->insert($db, $ticket->id, HelpSide::Business, $author->userId, null, $author->name, $body, SqlText::instant($at)));
    }

    /**
     * An operator's message on a locked ticket, kept under the operator's id and name: its newest
     * and its newest from the platform, the status left as it is. The instant given is the one
     * asked for; the one stored is in the answer.
     */
    public function appendFromPlatform(Connection $db, HelpTicketRef $ticket, Operator $operator, string $body, DateTimeImmutable $at): HelpMessageStored
    {
        $at = $this->advance($db, $ticket, HelpSide::Platform, $at);

        return $this->stored($ticket, false, HelpSide::Platform, null, $operator->id, $operator->name, null, $body, $at, $this->insert($db, $ticket->id, HelpSide::Platform, null, $operator->id, $operator->name, $body, SqlText::instant($at)));
    }

    /**
     * A ticket's messages, oldest first, the earlier row first within one instant.
     *
     * @return list<HelpThreadMessage>
     */
    public function messages(Connection $db, int $ticketId): array
    {
        $rows = $db->select(sprintf(
            'select id, side, user_id, operator_id, author_name, body, %s as created_at
             from help_messages where ticket_id = ? order by created_at, id',
            SqlText::instantColumn('created_at'),
        ), [$ticketId]);

        return array_map(self::message(...), array_values($rows));
    }

    /**
     * Hands a stored message to the hook that follows it, once its transaction has committed, and
     * keeps the hook's failure from the write: one that cannot be built as one that throws.
     */
    public function announce(HelpMessageStored $stored): void
    {
        try {
            $this->container->make(AfterHelpMessage::class)->handle($stored);
        } catch (Throwable $failure) {
            $this->log->error(self::HOOK_FAILED, [
                'ticket' => $stored->ticketId,
                'message' => $stored->messageId,
                'exception' => $failure::class,
            ]);
        }
    }

    /**
     * Moves a ticket's newest-message columns to a message of the side given, about to be stored,
     * and answers the instant it is stored at. The update holds the row and reads its newest
     * message off it, so the answer is right whatever the caller read before it reached the row.
     */
    private function advance(Connection $db, HelpTicketRef $ticket, HelpSide $side, DateTimeImmutable $at): DateTimeImmutable
    {
        $asked = SqlText::instant($at);
        [$also, $with] = $side === HelpSide::Business
            ? ['status = ?', HelpStatus::Open->value]
            : ['last_platform_at = '.self::NEXT, $asked];

        $stored = $db->scalar(sprintf(
            'update help_tickets set last_message_at = %1$s, updated_at = %1$s, last_side = ?, %2$s where id = ? returning %3$s',
            self::NEXT,
            $also,
            SqlText::instantColumn('last_message_at'),
        ), [$asked, $asked, $side->value, $with, $ticket->id], false);

        return is_string($stored) ? SqlText::readInstant($stored) : throw new UnexpectedValueException('A help ticket took a message and answered no instant for it.');
    }

    private function insert(Connection $db, int $ticketId, HelpSide $side, ?int $userId, ?int $operatorId, string $name, string $body, string $now): int
    {
        $id = $db->scalar(
            'insert into help_messages (ticket_id, side, user_id, operator_id, author_name, body, created_at)
             values (?, ?, ?, ?, ?, ?, ?::timestamptz) returning id',
            [$ticketId, $side->value, $userId, $operatorId, $name, $body, $now],
            false,
        );

        return is_int($id) ? $id : throw new UnexpectedValueException('A new help message came back with no id.');
    }

    private function stored(HelpTicketRef $ticket, bool $opened, HelpSide $side, ?int $userId, ?int $operatorId, string $name, ?Role $role, string $body, DateTimeImmutable $at, int $messageId): HelpMessageStored
    {
        return new HelpMessageStored($messageId, $ticket->id, $ticket->uuid, $ticket->tenantId, $ticket->subject, $opened, $side, $userId, $operatorId, $name, $role, $body, $at);
    }

    private static function message(mixed $row): HelpThreadMessage
    {
        if (! $row instanceof stdClass
            || ! is_int($row->id)
            || ! is_string($row->side)
            || ($side = HelpSide::tryFrom($row->side)) === null
            || ! ($row->user_id === null || is_int($row->user_id))
            || ! ($row->operator_id === null || is_int($row->operator_id))
            || ! is_string($row->author_name)
            || ! is_string($row->body)
            || ! is_string($row->created_at)) {
            throw new UnexpectedValueException('A help message came back in a shape this reader does not know.');
        }

        return new HelpThreadMessage($row->id, $side, $row->user_id, $row->operator_id, $row->author_name, $row->body, SqlText::readInstant($row->created_at));
    }
}
