<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Booking\LockKey;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\Revisions;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use stdClass;
use UnexpectedValueException;

/**
 * Step 3 of the write protocol: one statement that creates any missing mutex row, takes a real
 * row lock on every row of the key set, bumps each revision, and hands the post-bump values
 * back. INSERT ... ON CONFLICT DO UPDATE locks every row it touches, which is what makes the one
 * statement a lock; DO NOTHING would not, and a separate bootstrap followed by a separate bump
 * would be two statements with a gap between them.
 *
 * Runs on the connection the transaction runner hands its closure, inside that transaction, on
 * the tenant schema the runner has already set and asserted. It opens no connection and no
 * transaction of its own, and it lets every driver error through unwrapped, because the runner's
 * retry and its error map read the raw exception.
 *
 * The returned row count is asserted against the key set's size: a lock that came back short
 * would otherwise be trusted. The one case that is not a driver error is raised as MutexNotHeld,
 * from inside the transaction, so the runner rolls it back.
 */
final readonly class LockManager
{
    public function lock(Connection $connection, LockKeySet $keys): Revisions
    {
        $expected = count($keys);
        $bindings = [];

        foreach ($keys as $key) {
            $bindings[] = $key->staffId;
            $bindings[] = $key->day;
        }

        $entries = array_values(array_map(self::entry(...), $connection->select(self::sql($expected), $bindings, false)));

        if (count($entries) !== $expected) {
            throw MutexNotHeld::short($expected, count($entries), self::missingFrom($keys, $entries));
        }

        return new Revisions($entries);
    }

    /**
     * The lock statement for a key set of the given size: one VALUES row per pair, in the
     * set's order, the composite key as the conflict target, the revision bumped on conflict,
     * and every pair's post-bump revision returned. The key set has already sorted and
     * de-duplicated the pairs, so no two VALUES rows name one key and no 21000 can arise.
     */
    public static function sql(int $count): string
    {
        if ($count < 1) {
            throw new InvalidArgumentException('The lock statement locks at least one staff-day.');
        }

        return 'insert into staff_days (staff_id, day, rev) values '
            .implode(', ', array_fill(0, $count, '(?, ?, 0)'))
            .' on conflict (staff_id, day) do update set rev = staff_days.rev + 1'
            .' returning staff_id, day, rev';
    }

    /**
     * One returned row as the pair it names and its revision. The driver returns bigint as int
     * and date as text; anything else is a driver or configuration change worth stopping on.
     *
     * @return array{LockKey, int}
     */
    private static function entry(mixed $row): array
    {
        if (! $row instanceof stdClass) {
            throw new UnexpectedValueException(sprintf('The lock statement returned a %s where a row was expected.', get_debug_type($row)));
        }

        $staffId = $row->staff_id ?? null;
        $day = $row->day ?? null;
        $revision = $row->rev ?? null;

        if (! is_int($staffId) || ! is_string($day) || ! is_int($revision)) {
            throw new UnexpectedValueException(sprintf(
                'The lock statement returned staff_id %s, day %s and rev %s; expected int, string and int.',
                get_debug_type($staffId),
                get_debug_type($day),
                get_debug_type($revision),
            ));
        }

        return [new LockKey($staffId, $day), $revision];
    }

    /**
     * The pairs of the key set that no returned row named.
     *
     * @param  list<array{LockKey, int}>  $entries
     * @return list<LockKey>
     */
    private static function missingFrom(LockKeySet $keys, array $entries): array
    {
        $returned = array_map(fn (array $entry): string => $entry[0]->toString(), $entries);

        return array_values(array_filter(
            $keys->all(),
            fn (LockKey $key): bool => ! in_array($key->toString(), $returned, true),
        ));
    }
}
