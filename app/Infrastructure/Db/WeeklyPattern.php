<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\WallClockTime;
use Illuminate\Database\Connection;
use LogicException;

/**
 * A member's shift rows as their screens read and write them (replace(), put() and override() the
 * writes; stored() the patterns as stored, decoded() a windows column read back, windows() their
 * order, fromTheStart() the one start and storedStart() the start a write stores): a member's week
 * is their shift_patterns row alone, and one date edited alone is a shift_overrides row. A
 * pattern's windows are {weekday, start, end} as HH:MM ordered by weekday and start. A week with
 * no window is no pattern: replace() deletes the row for one, and Shifts' pattern write does the
 * same.
 *
 * Every weekly pattern starts on the first day of the week holding 2000-01-01 in the tenant's week
 * start (fromTheStart), whoever writes it, and this class alone spells that start: put() writes it
 * for a cadence of one whatever start it is handed (storedStart), so Shifts' pattern editor,
 * Opening hours, the provisioner's default week, the seeds and the sample salon all land on it,
 * and Shifts' pattern request judges an end against the same storedStart(). The Calendar draws
 * past weeks from the pattern, so a weekly pattern holds on every date, the past included. A
 * cadence above one counts its weeks from its own start, written as the caller names it: the
 * pattern editor's Starts on and the sample salon's stylist every two weeks, whose start is the
 * sample week's first day. override() writes one date's windows in place of the pattern's.
 *
 * Every table is named through the prefix the caller gives: a schema and a dot for a write that
 * names its schema, empty inside a schema switch.
 */
final readonly class WeeklyPattern
{
    /**
     * The date whose week, in the tenant's week start, every weekly pattern starts on, read here
     * alone (fromTheStart); a pattern repeating every two weeks or more names its own start
     * (storedStart).
     */
    private const string BEGINNING = '2000-01-01';

    /**
     * The first day of the week holding BEGINNING in the week start the settings row names: the
     * start of every weekly pattern, Monday 1999-12-27 on a Monday start, Sunday 1999-12-26 on a
     * Sunday one.
     */
    public static function fromTheStart(Connection $connection, string $prefix): string
    {
        return CalendarDay::weekStart(self::BEGINNING, self::startOfWeek($connection, $prefix));
    }

    /**
     * Replaces the member's pattern whole with a weekly one of the windows given, from the one
     * start and with no end, or leaves them none for an empty week. A time is HH:MM or HH:MM:SS,
     * as a request and a time column spell one; the pattern spells it HH:MM.
     *
     * @param  list<array{weekday: int, start: string, end: string}>  $windows
     */
    public static function replace(Connection $connection, string $prefix, int $staffId, array $windows): void
    {
        if ($windows === []) {
            self::drop($connection, $prefix, $staffId);

            return;
        }

        self::put($connection, $prefix, $staffId, $windows, 1, null);
    }

    /**
     * Deletes the member's pattern, leaving them none: what a week with no window is, for
     * replace() and for Shifts' pattern write alike.
     */
    public static function drop(Connection $connection, string $prefix, int $staffId): void
    {
        $connection->table($prefix.'shift_patterns')->where('staff_id', $staffId)->delete();
    }

    /**
     * Stamps the settings row's hours_confirmed_at with the database's now() where the write
     * holds a window, and does nothing otherwise, so the setup checklist's hours item stays open
     * until a week with hours is saved. Every write of a member's hours, the opening hours' week,
     * a pattern and one date's override, stamps through here.
     */
    public static function confirmHours(Connection $connection, string $prefix, bool $holdsAWindow): void
    {
        if ($holdsAWindow) {
            $connection->table($prefix.'settings')->where('id', 1)->update(['hours_confirmed_at' => $connection->raw('now()')]);
        }
    }

    /**
     * The start a pattern repeating every cadence weeks is stored with: the one start
     * (fromTheStart) at a cadence of one, the start given or none left unread; the start given
     * above it, which such a pattern needs. put() writes it, and Shifts' pattern request judges an
     * end against it (ShiftRecords::storedStart), so a save is judged against the start it stores
     * wherever the one start's rule goes.
     */
    public static function storedStart(Connection $connection, string $prefix, int $cadenceWeeks, ?string $startsOn): string
    {
        if ($cadenceWeeks > 1 && $startsOn === null) {
            throw new LogicException('A pattern repeating every two weeks or more names its own start.');
        }

        return $cadenceWeeks === 1 || $startsOn === null ? self::fromTheStart($connection, $prefix) : CalendarDay::spelled($startsOn);
    }

    /**
     * Writes the member's pattern whole, upserted on its one row per member: the windows given,
     * repeating every cadence weeks to the end given or for ever, from the start storedStart()
     * names. The caller gives a week holding a window, since a week with none is no pattern:
     * Shifts' pattern write deletes the row instead (ShiftRecords::replacePattern), and replace()
     * does for an empty week.
     *
     * @param  list<array{weekday: int, start: string, end: string}>  $windows
     */
    public static function put(Connection $connection, string $prefix, int $staffId, array $windows, int $cadenceWeeks, ?string $startsOn, ?string $endsOn = null): void
    {
        $connection->table($prefix.'shift_patterns')->upsert([[
            'staff_id' => $staffId,
            'starts_on' => self::storedStart($connection, $prefix, $cadenceWeeks, $startsOn),
            'cadence_weeks' => $cadenceWeeks,
            'ends_on' => $endsOn === null ? null : CalendarDay::spelled($endsOn),
            'windows' => json_encode(self::windows($windows), JSON_THROW_ON_ERROR),
            'updated_at' => $connection->raw('now()'),
        ]], ['staff_id'], ['starts_on', 'cadence_weeks', 'ends_on', 'windows', 'updated_at']);
    }

    /**
     * Writes the member's override of one date whole, upserted on its one row per member and
     * date: the windows given as {start, end} HH:MM in their order, an empty list meaning not
     * working that day.
     *
     * @param  list<array{start: string, end: string}>  $windows
     */
    public static function override(Connection $connection, string $prefix, int $staffId, string $date, array $windows): void
    {
        $spelled = array_map(fn (array $window): array => ['start' => self::minutes($window['start']), 'end' => self::minutes($window['end'])], $windows);
        usort($spelled, fn (array $left, array $right): int => [$left['start'], $left['end']] <=> [$right['start'], $right['end']]);

        $connection->table($prefix.'shift_overrides')->upsert([[
            'staff_id' => $staffId,
            'date' => CalendarDay::spelled($date),
            'windows' => json_encode($spelled, JSON_THROW_ON_ERROR),
            'updated_at' => $connection->raw('now()'),
        ]], ['staff_id', 'date'], ['windows', 'updated_at']);
    }

    /**
     * The patterns as stored, by member: every member's, or the members' given. A pattern's windows
     * are the member's usual week, which every screen showing a week without its dates reads
     * (Opening hours' roster, the Services roster and the booking page's hours table); a read of
     * one date goes through the resolver instead (StaffHours).
     *
     * @param  list<int>|null  $staffIds
     * @return array<int, array{cadence_weeks: int, starts_on: string, ends_on: string|null, windows: list<array{weekday: int, start: string, end: string}>}>
     */
    public static function stored(Connection $connection, string $prefix, ?array $staffIds = null): array
    {
        if ($staffIds === []) {
            return [];
        }

        $query = $connection->table($prefix.'shift_patterns')->orderBy('staff_id');

        if ($staffIds !== null) {
            $query->whereRaw('staff_id = any(?::bigint[])', [SqlText::bigintArray($staffIds)]);
        }

        $patterns = [];

        foreach ($query->get(['staff_id', 'starts_on', 'cadence_weeks', 'ends_on', 'windows']) as $row) {
            $patterns[BlackoutRecords::int($row->staff_id)] = [
                'cadence_weeks' => BlackoutRecords::int($row->cadence_weeks),
                'starts_on' => CalendarDay::spelled(BlackoutRecords::string($row->starts_on)),
                'ends_on' => $row->ends_on === null ? null : CalendarDay::spelled(BlackoutRecords::string($row->ends_on)),
                'windows' => self::decoded(BlackoutRecords::string($row->windows)),
            ];
        }

        return $patterns;
    }

    /**
     * A pattern's windows column read back in its own spelling, {weekday, start, end} HH:MM;
     * refused where the column is not shaped so, which no writer here produces.
     *
     * @return list<array{weekday: int, start: string, end: string}>
     */
    public static function decoded(string $json): array
    {
        $list = json_decode($json, true, 4, JSON_THROW_ON_ERROR);

        if (! is_array($list) || ! array_is_list($list)) {
            throw new LogicException('A shift_patterns windows column is not a list.');
        }

        return array_map(function (mixed $window): array {
            if (! is_array($window) || ! is_int($window['weekday'] ?? null) || ! is_string($window['start'] ?? null) || ! is_string($window['end'] ?? null)) {
                throw new LogicException('A shift_patterns window is not shaped as {weekday, start, end}.');
            }

            return ['weekday' => $window['weekday'], 'start' => $window['start'], 'end' => $window['end']];
        }, $list);
    }

    /**
     * The windows as the pattern spells them, ordered by weekday, start and end.
     *
     * @param  list<array{weekday: int, start: string, end: string}>  $windows
     * @return list<array{weekday: int, start: string, end: string}>
     */
    public static function windows(array $windows): array
    {
        $spelled = array_map(fn (array $window): array => [
            'weekday' => $window['weekday'],
            'start' => self::minutes($window['start']),
            'end' => self::minutes($window['end']),
        ], $windows);

        usort($spelled, fn (array $left, array $right): int => [$left['weekday'], $left['start'], $left['end']] <=> [$right['weekday'], $right['start'], $right['end']]);

        return $spelled;
    }

    private static function startOfWeek(Connection $connection, string $prefix): int
    {
        $startOfWeek = $connection->table($prefix.'settings')->where('id', 1)->value('start_of_week');

        if (! is_int($startOfWeek)) {
            throw new LogicException('A weekly pattern is written beside a settings row naming the week start.');
        }

        return $startOfWeek;
    }

    /**
     * The reading as HH:MM, refused where it names a second, which the pattern cannot carry and
     * no screen writes.
     */
    private static function minutes(string $time): string
    {
        $reading = WallClockTime::of($time);

        if ($reading->secondsIntoDay % 60 !== 0) {
            throw new LogicException(sprintf('A weekly pattern spells a window to the minute, not %s.', $reading->format()));
        }

        return substr($reading->format(), 0, 5);
    }
}
