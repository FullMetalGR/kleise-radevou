<?php

declare(strict_types=1);

namespace App\Platform;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use App\Site\SiteLanguage;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use stdClass;
use UnexpectedValueException;

/**
 * The console's side of the contact form's table: the messages read for the messages screen and
 * the one write after the form's insert, Archive.
 *
 * The read is one view's messages newest first by arrival, twenty-five a page, the later row first
 * within one instant, in two statements a page, the count and the rows, whatever the page holds.
 *
 * The archive is one statement writing archived_at as the clock's now, UTC text with its offset,
 * on the row the id names where it is null, and in the same transaction the platform audit row
 * contact_archived in the name of the operator given, about no tenant, its payload the message's
 * id under message and nothing of its content. A row already archived moves nothing and records
 * nothing. There is no unarchive and no delete; a retention sweep deletes a message twelve months
 * from its arrival.
 *
 * A message's content never reaches a log line, as the form's insert keeps it out: no statement
 * here binds a word of it, and a row this reader cannot read is refused naming its id at most.
 */
final readonly class ContactInbox
{
    public const int PAGE_SIZE = 25;

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    /**
     * One page of a view, counted from one, and how many messages the view holds in all.
     *
     * @return array{records: list<ReceivedMessage>, total: int}
     */
    public function page(InboxView $view, int $page): array
    {
        $where = self::where($view);
        $total = $this->total($view);

        $rows = $this->connection()->select(sprintf(
            'select id, name, email, message, locale, %s as created_at
             from contact_messages
             where %s
             order by created_at desc, id desc
             limit ? offset ?',
            SqlText::instantColumn('created_at'),
            $where,
        ), [self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE]);

        return [
            'records' => array_map(self::record(...), array_values($rows)),
            'total' => $total,
        ];
    }

    /** How many messages a view holds in all. */
    public function total(InboxView $view): int
    {
        $total = $this->connection()->scalar('select count(*) from contact_messages where '.self::where($view));

        return is_int($total) ? $total : throw new UnexpectedValueException('The contact messages counted no integer.');
    }

    /** The condition that picks a view's rows out of the table. */
    private static function where(InboxView $view): string
    {
        return $view === InboxView::Archived ? 'archived_at is not null' : 'archived_at is null';
    }

    /** Whether a row holds the id, archived or not. */
    public function holds(int $id): bool
    {
        return $this->connection()->scalar('select exists (select 1 from contact_messages where id = ?)', [$id]) === true;
    }

    /**
     * The archive: archived_at and its record in one transaction. Answers whether the row moved,
     * false for a message already archived or one no row holds, either writing and recording
     * nothing.
     */
    public function archive(int $id, int $operatorId): bool
    {
        $now = SqlText::instant($this->clock->now());

        return $this->connection()->transaction(function (Connection $db) use ($id, $operatorId, $now): bool {
            $moved = $db->scalar('update contact_messages set archived_at = ?::timestamptz where id = ? and archived_at is null returning id', [$now, $id], false);

            if (! is_int($moved)) {
                return false;
            }

            PlatformAudit::record($db, $operatorId, new PlatformAuditEntry(PlatformAuditAction::ContactArchived, [PlatformAuditKey::Message->value => $id]), null);

            return true;
        });
    }

    private static function record(mixed $row): ReceivedMessage
    {
        if (! $row instanceof stdClass
            || ! is_int($row->id)
            || ! is_string($row->name)
            || ! is_string($row->email)
            || ! is_string($row->message)
            || ! is_string($row->locale)
            || ! is_string($row->created_at)) {
            throw new UnexpectedValueException('A contact_messages row came back in a shape this reader does not know.');
        }

        $language = SiteLanguage::tryFrom($row->locale);
        $receivedAt = DateTimeImmutable::createFromFormat(SqlText::INSTANT, $row->created_at, new DateTimeZone('UTC'));

        if ($language === null || $receivedAt === false) {
            throw new UnexpectedValueException(sprintf('The contact message %d holds a language or an instant this reader cannot read.', $row->id));
        }

        return new ReceivedMessage($row->id, $row->name, $row->email, $row->message, $language, $receivedAt);
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
