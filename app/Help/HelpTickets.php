<?php

declare(strict_types=1);

namespace App\Help;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use stdClass;
use UnexpectedValueException;

/**
 * A business's help tickets as its own users read and write them: open a ticket, reply, mark a
 * thread read, the list, one thread, and whether any listed ticket is unread for a user.
 *
 * The business is always the caller's, taken from the signed-in session and never from the
 * request, and a ticket is named by its uuid alone: every read and write here finds it by uuid and
 * business together, so another business's ticket answers exactly as one that does not exist, and
 * a value that is no uuid answers so before it reaches the server.
 *
 * The two limits, ten new tickets and sixty messages by one user in any hour, are counted here,
 * where the write happens and the user is known, over the rows themselves: a ticket counts as the
 * user's when its first message is theirs, and every message they wrote counts toward the sixty,
 * a ticket's first included. Each write takes the user's row lock first, then the ticket's, so two
 * of one user's writes count one after the other and never both pass at the limit's edge. It reads
 * the clock only once it holds them, so a write that waited for a row counts its hour, and asks for
 * its instant, as of when it got the row and not as of when it began to wait.
 *
 * A ticket is unread for a user when its newest platform message is later than that user's mark
 * on the thread, or the user has no mark on it: a colleague's message makes nothing unread.
 * Opening a thread, writing a ticket and replying each mark the thread read for the user, up to
 * the newest message stored by then. A mark is always a stored message's instant and never one a
 * clock gave: a writer's is their own message's, an opening's the newest message's. Each message
 * is stored later than the newest before it, so a platform message stored after a mark is later
 * than the mark whatever its writer's clock says.
 *
 * The user's row is locked as a row whose key stays as it is, which a read mark's reference to the
 * user does not wait on. A mark and a reply of one user at once therefore never wait on each other
 * in a ring: the mark takes the ticket's row and then needs nothing a reply holds.
 *
 * Each message is handed to the hook that follows it once its transaction has committed.
 */
final readonly class HelpTickets
{
    /** How many tickets the list holds: the business's newest by their last message. */
    public const int LISTED = 50;

    /** The longest subject, in characters. */
    public const int LONGEST_SUBJECT = 150;

    /** The longest message, in characters. */
    public const int LONGEST_MESSAGE = 5000;

    private const string HOUR = 'PT1H';

    /** Unread for the reader joined as r: a platform message later than the newest one their last opening held, or a ticket never opened. */
    private const string UNREAD = '(t.last_platform_at is not null and (r.read_at is null or r.read_at < t.last_platform_at))';

    public function __construct(private DatabaseManager $db, private Clock $clock, private HelpLedger $ledger) {}

    /**
     * A new ticket of the business with the user's first message, read by its writer; answers the
     * ticket's uuid.
     *
     * @throws HelpLimitReached where the user opened ten tickets or wrote sixty messages in the last hour
     */
    public function open(int $tenantId, HelpAuthor $author, string $subject, string $body): string
    {
        $stored = $this->connection()->transaction(function (Connection $db) use ($tenantId, $author, $subject, $body): HelpMessageStored {
            $this->lockUser($db, $author->userId);
            $at = $this->clock->now();
            $this->guard($db, $author->userId, $at, true);
            $stored = $this->ledger->open($db, $tenantId, $subject, $author, $body, $at);
            self::read($db, $stored->ticketId, $author->userId, $stored->at);

            return $stored;
        });

        $this->ledger->announce($stored);

        return $stored->ticketUuid;
    }

    /**
     * The user's reply on a ticket of the business, which reopens it where it was closed and is
     * read by its writer; false where the business holds no such ticket, nothing written.
     *
     * @throws HelpLimitReached where the user wrote sixty messages in the last hour
     */
    public function reply(int $tenantId, HelpAuthor $author, string $uuid, string $body): bool
    {
        if (! SqlText::isUuid($uuid)) {
            return false;
        }

        $stored = $this->connection()->transaction(function (Connection $db) use ($tenantId, $author, $uuid, $body): ?HelpMessageStored {
            $this->lockUser($db, $author->userId);
            $ticket = $this->ledger->lock($db, 'uuid = ? and tenant_id = ?', [$uuid, $tenantId]);

            if ($ticket === null) {
                return null;
            }

            $at = $this->clock->now();
            $this->guard($db, $author->userId, $at, false);
            $stored = $this->ledger->appendFromBusiness($db, $ticket, $author, $body, $at);
            self::read($db, $ticket->id, $author->userId, $stored->at);

            return $stored;
        });

        if ($stored === null) {
            return false;
        }

        $this->ledger->announce($stored);

        return true;
    }

    /**
     * The user's opening of a thread of the business: the mark put at the newest message's stored
     * instant, so every message stored by then is read, and never earlier than a mark already
     * held; false where the business holds no such ticket.
     *
     * It is one statement, and it reads no clock. It finds the ticket by uuid and business and
     * holds its row for the statement against a delete, so a ticket gone by then, or another
     * business's, writes nothing and answers false. A message whose write holds the row is waited
     * for and read by the mark; one stored afterwards is stored later than the newest message the
     * mark read, and so later than the mark.
     */
    public function markRead(int $tenantId, int $userId, string $uuid): bool
    {
        if (! SqlText::isUuid($uuid)) {
            return false;
        }

        return $this->connection()->scalar(
            'with held as (
                 select id, last_message_at from help_tickets where uuid = ? and tenant_id = ? for key share
             )
             insert into help_reads (ticket_id, user_id, read_at)
             select id, ?, last_message_at from held
             on conflict (ticket_id, user_id) do update set read_at = greatest(help_reads.read_at, excluded.read_at)
             returning ticket_id',
            [$uuid, $tenantId, $userId],
            false,
        ) !== null;
    }

    /**
     * Whether the business holds a ticket by this uuid.
     */
    public function holds(int $tenantId, string $uuid): bool
    {
        return SqlText::isUuid($uuid)
            && $this->connection()->scalar('select exists (select 1 from help_tickets where uuid = ? and tenant_id = ?)', [$uuid, $tenantId]) === true;
    }

    /**
     * The business's fifty tickets with the newest activity, the newest first, each unread or not
     * for the user.
     *
     * @return list<HelpTicketSummary>
     */
    public function list(int $tenantId, int $userId): array
    {
        $rows = $this->connection()->select(sprintf(
            'select t.uuid::text as uuid, t.subject, t.status, %s as last_message_at, %s as unread
             from help_tickets t
             left join help_reads r on r.ticket_id = t.id and r.user_id = ?
             where t.tenant_id = ?
             order by t.last_message_at desc, t.id desc
             limit %d',
            SqlText::instantColumn('t.last_message_at'),
            self::UNREAD,
            self::LISTED,
        ), [$userId, $tenantId]);

        return array_map(self::summary(...), array_values($rows));
    }

    /**
     * Whether any of the fifty listed tickets is unread for the user: the bubble's dot.
     */
    public function unread(int $tenantId, int $userId): bool
    {
        return $this->connection()->scalar(sprintf(
            'select exists (
                select 1 from (
                    select id, last_platform_at from help_tickets where tenant_id = ?
                    order by last_message_at desc, id desc limit %d
                ) t
                left join help_reads r on r.ticket_id = t.id and r.user_id = ?
                where %s
            )',
            self::LISTED,
            self::UNREAD,
        ), [$tenantId, $userId]) === true;
    }

    /**
     * A thread of the business, its messages oldest first; null where the business holds no such
     * ticket.
     */
    public function thread(int $tenantId, string $uuid): ?HelpThread
    {
        if (! SqlText::isUuid($uuid)) {
            return null;
        }

        $row = $this->connection()->selectOne('select id, uuid::text as uuid, subject, status from help_tickets where uuid = ? and tenant_id = ?', [$uuid, $tenantId]);

        if ($row === null) {
            return null;
        }

        if (! $row instanceof stdClass
            || ! is_int($row->id)
            || ! is_string($row->uuid)
            || ! is_string($row->subject)
            || ! is_string($row->status)
            || ($status = HelpStatus::tryFrom($row->status)) === null) {
            throw new UnexpectedValueException('A help ticket came back in a shape this reader does not know.');
        }

        return new HelpThread($row->uuid, $row->subject, $status, $this->ledger->messages($this->connection(), $row->id));
    }

    /**
     * Refuses the write where the user is at a limit: ten tickets opened, where this write opens
     * one, or sixty messages written, in the hour before the instant given.
     */
    private function guard(Connection $db, int $userId, DateTimeImmutable $at, bool $opens): void
    {
        $since = SqlText::instant($at->sub(new DateInterval(self::HOUR)));

        if ($opens) {
            $opened = $db->scalar(
                'select count(*) from help_messages m
                 where m.user_id = ? and m.created_at > ?::timestamptz
                 and not exists (select 1 from help_messages e where e.ticket_id = m.ticket_id and e.id < m.id)',
                [$userId, $since],
            );

            if (is_int($opened) && $opened >= HelpLimit::Tickets->most()) {
                throw new HelpLimitReached(HelpLimit::Tickets);
            }
        }

        $written = $db->scalar('select count(*) from help_messages where user_id = ? and created_at > ?::timestamptz', [$userId, $since]);

        if (is_int($written) && $written >= HelpLimit::Messages->most()) {
            throw new HelpLimitReached(HelpLimit::Messages);
        }
    }

    /**
     * The user's row held for the write, so one user's writes pass the limits one at a time: as a
     * row whose key stays, which a reference to the user from a row being written does not wait on.
     */
    private function lockUser(Connection $db, int $userId): void
    {
        $db->select('select id from users where id = ? for no key update', [$userId]);
    }

    /**
     * The user's read mark on a ticket moved to the instant given, never back.
     */
    private static function read(Connection $db, int $ticketId, int $userId, DateTimeImmutable $at): void
    {
        $db->insert(
            'insert into help_reads (ticket_id, user_id, read_at) values (?, ?, ?::timestamptz)
             on conflict (ticket_id, user_id) do update set read_at = greatest(help_reads.read_at, excluded.read_at)',
            [$ticketId, $userId, SqlText::instant($at)],
        );
    }

    private static function summary(mixed $row): HelpTicketSummary
    {
        if (! $row instanceof stdClass
            || ! is_string($row->uuid)
            || ! is_string($row->subject)
            || ! is_string($row->status)
            || ($status = HelpStatus::tryFrom($row->status)) === null
            || ! is_string($row->last_message_at)
            || ! is_bool($row->unread)) {
            throw new UnexpectedValueException('A help ticket came back in a shape this reader does not know.');
        }

        return new HelpTicketSummary($row->uuid, $row->subject, $status, SqlText::readInstant($row->last_message_at), $row->unread);
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
