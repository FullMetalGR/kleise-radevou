<?php

declare(strict_types=1);

namespace App\Help;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Platform\Operator;
use App\Platform\PlatformAudit;
use App\Platform\PlatformAuditAction;
use App\Platform\PlatformAuditEntry;
use App\Platform\PlatformAuditKey;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use stdClass;
use UnexpectedValueException;

/**
 * Every business's help tickets as the platform's console reads and answers them, by the ticket's
 * id: the paged list, one thread, the count of tickets awaiting a reply, and the operator's three
 * acts, reply, close and reopen.
 *
 * The list puts open tickets before closed ones, then the newest activity first. A ticket awaits a
 * reply while it is open and the business wrote last. An operator's reply is kept under the
 * operator's id and name and leaves the status as it is; closing and reopening move the status
 * alone, never the last message's instant the twelve months count from, and write no message.
 *
 * Each act is one transaction on the ticket's locked row, and answers false, writing nothing, for
 * an id no row holds or a status already where the act would put it. The same transaction writes
 * the act's platform audit row, the operator, the business and the ticket's id, never a word of a
 * message. A reply is handed to the hook that follows a message once its transaction has committed;
 * closing and reopening hand it nothing.
 */
final readonly class HelpDesk
{
    public const int PAGE_SIZE = 25;

    private const string AWAITING = "(t.status = 'open' and t.last_side = 'business')";

    public function __construct(private DatabaseManager $db, private Clock $clock, private HelpLedger $ledger) {}

    /**
     * One page of the list, counted from one, how many tickets there are in all, and the page that
     * was read: the one asked for, or the last one where the list ends before it, so a page past
     * the end is the list's last tickets and never an empty page of a list that holds some. The
     * tickets are counted first, for the count to say where the last page is.
     *
     * Open before closed, then the newest activity first: the order the index
     * help_tickets_status_activity_index keeps, so a page is read off it in order.
     *
     * @return array{records: list<DeskTicket>, total: int, page: int}
     */
    public function page(int $page): array
    {
        $total = $this->total();
        $page = max(1, min($page, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE)));

        $rows = $this->connection()->select(sprintf(
            "select t.id, t.uuid::text as uuid, t.tenant_id, n.name as tenant_name, n.slug as tenant_slug,
                    t.subject, t.status, %s as last_message_at, %s as awaiting
             from help_tickets t
             join tenants n on n.id = t.tenant_id
             order by (t.status = 'open') desc, t.last_message_at desc, t.id desc
             limit %d offset %d",
            SqlText::instantColumn('t.last_message_at'),
            self::AWAITING,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE,
        ));

        return ['records' => array_map(self::ticket(...), array_values($rows)), 'total' => $total, 'page' => $page];
    }

    /**
     * How many tickets there are, of every business and status.
     */
    public function total(): int
    {
        $total = $this->connection()->scalar('select count(*) from help_tickets');

        return is_int($total) ? $total : 0;
    }

    /**
     * How many tickets await a reply: open, the business having written last. The rail's count.
     */
    public function awaiting(): int
    {
        $count = $this->connection()->scalar('select count(*) from help_tickets t where '.self::AWAITING);

        return is_int($count) ? $count : 0;
    }

    /**
     * One ticket with its business and its messages oldest first, each under its writer's name as
     * written; null where no row holds the id.
     */
    public function thread(int $id): ?DeskThread
    {
        $row = $this->connection()->selectOne(sprintf(
            'select t.id, t.uuid::text as uuid, t.tenant_id, n.name as tenant_name, n.slug as tenant_slug,
                    t.subject, t.status, %s as last_message_at, %s as awaiting
             from help_tickets t
             join tenants n on n.id = t.tenant_id
             where t.id = ?',
            SqlText::instantColumn('t.last_message_at'),
            self::AWAITING,
        ), [$id]);

        if ($row === null) {
            return null;
        }

        $ticket = self::ticket($row);

        return new DeskThread($ticket, $this->ledger->messages($this->connection(), $ticket->id));
    }

    /**
     * The operator's reply on a ticket, the status left as it is; false where no row holds the id.
     */
    public function reply(int $id, Operator $operator, string $body): bool
    {
        $stored = $this->connection()->transaction(function (Connection $db) use ($id, $operator, $body): ?HelpMessageStored {
            $ticket = $this->ledger->lock($db, 'id = ?', [$id]);

            if ($ticket === null) {
                return null;
            }

            // Read once the row is locked, so a reply that waited on the lock is never stamped
            // earlier than the message stored before it.
            $stored = $this->ledger->appendFromPlatform($db, $ticket, $operator, $body, $this->clock->now());
            self::audit($db, $operator->id, PlatformAuditAction::HelpTicketReplied, $ticket);

            return $stored;
        });

        if ($stored === null) {
            return false;
        }

        $this->ledger->announce($stored);

        return true;
    }

    /**
     * Closes an open ticket; false where no row holds the id or the ticket is closed already.
     */
    public function close(int $id, int $operatorId): bool
    {
        return $this->move($id, $operatorId, HelpStatus::Open, HelpStatus::Closed, PlatformAuditAction::HelpTicketClosed);
    }

    /**
     * Reopens a closed ticket; false where no row holds the id or the ticket is open already.
     */
    public function reopen(int $id, int $operatorId): bool
    {
        return $this->move($id, $operatorId, HelpStatus::Closed, HelpStatus::Open, PlatformAuditAction::HelpTicketReopened);
    }

    private function move(int $id, int $operatorId, HelpStatus $from, HelpStatus $to, PlatformAuditAction $action): bool
    {
        return $this->connection()->transaction(function (Connection $db) use ($id, $operatorId, $from, $to, $action): bool {
            $ticket = $this->ledger->lock($db, 'id = ?', [$id]);

            if ($ticket === null || $ticket->status !== $from) {
                return false;
            }

            $db->update('update help_tickets set status = ?, updated_at = ?::timestamptz where id = ?', [$to->value, SqlText::instant($this->clock->now()), $ticket->id]);
            self::audit($db, $operatorId, $action, $ticket);

            return true;
        });
    }

    /**
     * The act's platform audit row on the act's own transaction: the business as the row's tenant
     * and the ticket by its id, nothing of a message.
     */
    private static function audit(Connection $db, int $operatorId, PlatformAuditAction $action, HelpTicketRef $ticket): void
    {
        PlatformAudit::record($db, $operatorId, new PlatformAuditEntry($action, [PlatformAuditKey::Ticket->value => $ticket->id]), $ticket->tenantId);
    }

    private static function ticket(mixed $row): DeskTicket
    {
        if (! $row instanceof stdClass
            || ! is_int($row->id)
            || ! is_string($row->uuid)
            || ! is_int($row->tenant_id)
            || ! is_string($row->tenant_name)
            || ! ($row->tenant_slug === null || is_string($row->tenant_slug))
            || ! is_string($row->subject)
            || ! is_string($row->status)
            || ($status = HelpStatus::tryFrom($row->status)) === null
            || ! is_string($row->last_message_at)
            || ! is_bool($row->awaiting)) {
            throw new UnexpectedValueException('A help ticket came back in a shape this reader does not know.');
        }

        return new DeskTicket($row->id, $row->uuid, $row->tenant_id, $row->tenant_name, $row->tenant_slug, $row->subject, $status, SqlText::readInstant($row->last_message_at), $row->awaiting);
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
