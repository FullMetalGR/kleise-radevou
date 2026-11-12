<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Booking\LockKey;
use App\Domain\Booking\Revisions;
use Illuminate\Database\Connection;
use LogicException;
use stdClass;

/**
 * The one statement the cache reader runs before it looks anything up: the revisions of the
 * mutex rows that exist among the staff-days of a read, in one statement for the whole window
 * across every staff member asked for, with the server's now beside them. A staff-day with no
 * row is simply not returned, and the reader keys it as absent.
 *
 * The statement starts from a one-row subquery and left-joins the mutex table so that it
 * answers one row at least, now included, when no mutex row exists at all: a second statement
 * for the clock would be a round trip spent on nothing.
 *
 * A read outside any transaction, every table named by schema, nothing set on the connection,
 * as the settings reader honours the pooling rule. The writer never needs this: the lock
 * statement hands the post-bump revisions back itself.
 */
final readonly class RevisionsFromRows
{
    private function __construct(
        private Connection $connection,
        private string $prefix,
    ) {}

    public static function forSchema(Connection $connection, string $schemaName): self
    {
        return new self($connection, '"'.$schemaName.'".');
    }

    /**
     * @param  non-empty-list<int>  $staffIds
     * @param  string  $firstDay  a tenant-local calendar day, YYYY-MM-DD
     * @param  string  $lastDay  the last day of the window, inclusive
     */
    public function read(array $staffIds, string $firstDay, string $lastDay): StaffDayRevisions
    {
        $entries = [];
        $now = null;

        foreach ($this->connection->select(self::sql($this->prefix), [SqlText::bigintArray($staffIds), $firstDay, $lastDay], false) as $row) {
            if (! $row instanceof stdClass) {
                throw new LogicException(sprintf('The revisions statement returned a %s where a row was expected.', get_debug_type($row)));
            }

            $at = $row->server_now ?? null;

            if (! is_string($at)) {
                throw new LogicException('The revisions statement returned a row without the server clock.');
            }

            $now ??= SqlText::readInstant($at);
            $staffId = $row->staff_id ?? null;
            $day = $row->day ?? null;
            $revision = $row->rev ?? null;

            if ($staffId === null && $day === null && $revision === null) {
                continue; // the padding row the left join answers when no mutex row exists
            }

            if (! is_int($staffId) || ! is_string($day) || ! is_int($revision)) {
                throw new LogicException('A staff_days row is not shaped as the schema declares.');
            }

            $entries[] = [new LockKey($staffId, $day), $revision];
        }

        if ($now === null) {
            throw new LogicException('The revisions statement returned no row; it answers at least one.');
        }

        return new StaffDayRevisions(new Revisions($entries), $now);
    }

    public static function sql(string $prefix): string
    {
        return 'select s.staff_id, s.day, s.rev,'
            .' '.SqlText::instantColumn('now()').' as server_now'
            .' from (select 1) as t'
            .' left join '.$prefix.'staff_days s on s.staff_id = any(?::bigint[]) and s.day between ?::date and ?::date'
            .' order by s.staff_id, s.day';
    }
}
