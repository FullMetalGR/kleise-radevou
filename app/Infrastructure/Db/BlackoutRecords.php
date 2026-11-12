<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Tenancy\Tenant;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * The dated closures, as the Staff screen reads and writes them: tenant-wide with no staff
 * member, personal with one; the whole day with no window, that window with one. Dates and
 * times here carry no instant and cross the wire as the column spells them, a machine date and
 * HH:MM, never converted.
 *
 * The removal is the one place a closure is matched, and it matches what the operator saw: the
 * date and, with a window given, that window's own start and end, so two partial closures on
 * one date with different windows go independently; with no window given, the all-day closure
 * on that date. Never a row id, because the card shows dates and a server matching by id would
 * need an id the screen never showed.
 *
 * Nothing here touches the mask cache: the open mask is rebuilt from patterns and blackouts on
 * every read and only a hold bumps a revision, so a closure written here is what the very next
 * availability read sees.
 */
final readonly class BlackoutRecords
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * The closures of one member, or the tenant-wide ones for null, in date order.
     *
     * @return list<array{date: string, start: string|null, end: string|null, reason: string|null}>
     */
    public function of(Tenant $tenant, ?int $staffId): array
    {
        return array_values(array_map(
            fn (object $row): array => self::row($row),
            $this->scoped($tenant, $staffId)->orderBy('date')->orderBy('start_time')->orderBy('id')->get(['date', 'start_time', 'end_time', 'reason'])->all(),
        ));
    }

    /**
     * Every personal closure, grouped by the member it belongs to.
     *
     * @return array<int, list<array{date: string, start: string|null, end: string|null, reason: string|null}>>
     */
    public function perMember(Tenant $tenant): array
    {
        $grouped = [];

        foreach ($this->table($tenant)->whereNotNull('staff_id')->orderBy('date')->orderBy('start_time')->orderBy('id')->get(['staff_id', 'date', 'start_time', 'end_time', 'reason']) as $row) {
            $grouped[self::int($row->staff_id)][] = self::row($row);
        }

        return $grouped;
    }

    /**
     * Writes a closure on every date from $date to $until inclusive, one row each, in one
     * transaction: closures stay one row per date, so a closure of several days is several rows,
     * which upcoming() folds back into one line. A null $until is the one date.
     */
    public function add(Tenant $tenant, ?int $staffId, string $date, ?string $until, ?string $start, ?string $end, ?string $reason): void
    {
        $rows = array_map(fn (string $day): array => [
            'staff_id' => $staffId,
            'date' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'reason' => $reason,
        ], self::dates($date, $until));

        $this->db->connection()->transaction(fn () => $this->table($tenant)->insert($rows));
    }

    /**
     * Removes the closures matching what was seen and answers how many went: every date from
     * $date to $until inclusive (the one date for a null $until), and the window given or, with
     * none given, the all-day closures of those dates. A folded run of the screen leaves whole in
     * this one statement.
     */
    public function remove(Tenant $tenant, ?int $staffId, string $date, ?string $until, ?string $start, ?string $end): int
    {
        $matching = $this->scoped($tenant, $staffId)->where('date', '>=', $date)->where('date', '<=', $until ?? $date);

        if ($start === null || $end === null) {
            return $matching->whereNull('start_time')->delete();
        }

        return $matching->where('start_time', $start)->where('end_time', $end)->delete();
    }

    /**
     * The closures from $today on, every scope's, soonest first, as the Staff screen lists them: a
     * run of consecutive dates with the same member, the same window and the same reason folded
     * into one line from its first date to its last, $until null for a line of one date. Past dates
     * are not read, so a run under way is listed from today. Lines of one date come in the order of
     * the business's first, then by member, then all-day first.
     *
     * @return list<array{staff_id: int|null, date: string, until: string|null, start: string|null, end: string|null, reason: string|null}>
     */
    public function upcoming(Tenant $tenant, string $today): array
    {
        $rows = $this->table($tenant)->where('date', '>=', $today)
            ->orderByRaw('staff_id nulls first')->orderByRaw('start_time nulls first')->orderBy('end_time')->orderBy('reason')->orderBy('date')->orderBy('id')
            ->get(['staff_id', 'date', 'start_time', 'end_time', 'reason'])->all();
        $lines = [];

        foreach ($rows as $row) {
            $line = ['staff_id' => $row->staff_id === null ? null : self::int($row->staff_id), ...self::row($row)];
            $last = array_key_last($lines);

            if ($last !== null && self::continues($lines[$last], $line)) {
                $lines[$last]['until'] = $line['date'];

                continue;
            }

            $lines[] = ['staff_id' => $line['staff_id'], 'date' => $line['date'], 'until' => null, 'start' => $line['start'], 'end' => $line['end'], 'reason' => $line['reason']];
        }

        usort($lines, fn (array $a, array $b): int => [$a['date'], $a['staff_id'] === null ? 0 : 1, $a['staff_id'] ?? 0, $a['start'] === null ? 0 : 1, $a['start'] ?? ''] <=> [$b['date'], $b['staff_id'] === null ? 0 : 1, $b['staff_id'] ?? 0, $b['start'] === null ? 0 : 1, $b['start'] ?? '']);

        return $lines;
    }

    /**
     * Whether a closure's row runs on from a line: the same member, window and reason, on the
     * day after the line's last date.
     *
     * @param  array{staff_id: int|null, date: string, until: string|null, start: string|null, end: string|null, reason: string|null}  $line
     * @param  array{staff_id: int|null, date: string, start: string|null, end: string|null, reason: string|null}  $row
     */
    private static function continues(array $line, array $row): bool
    {
        return $line['staff_id'] === $row['staff_id'] && $line['start'] === $row['start'] && $line['end'] === $row['end'] && $line['reason'] === $row['reason']
            && ($line['until'] ?? $line['date']) === self::dayBefore($row['date']);
    }

    /** The date before a machine date, by the calendar and never by elapsed time. */
    private static function dayBefore(string $date): string
    {
        return (new DateTimeImmutable($date.' 12:00', new DateTimeZone('UTC')))->sub(new DateInterval('P1D'))->format('Y-m-d');
    }

    /**
     * The machine dates from $from to $until inclusive, the one date for a null $until.
     *
     * @return list<string>
     */
    public static function dates(string $from, ?string $until): array
    {
        $zone = new DateTimeZone('UTC');
        $day = new DateTimeImmutable($from.' 12:00', $zone);
        $last = new DateTimeImmutable(($until ?? $from).' 12:00', $zone);
        $dates = [];

        while ($day <= $last) {
            $dates[] = $day->format('Y-m-d');
            $day = $day->add(new DateInterval('P1D'));
        }

        return $dates;
    }

    private function scoped(Tenant $tenant, ?int $staffId): Builder
    {
        $table = $this->table($tenant);

        return $staffId === null ? $table->whereNull('staff_id') : $table->where('staff_id', $staffId);
    }

    private function table(Tenant $tenant): Builder
    {
        return $this->db->connection()->table($tenant->schema_name.'.blackouts');
    }

    /**
     * @return array{date: string, start: string|null, end: string|null, reason: string|null}
     */
    private static function row(object $row): array
    {
        return [
            'date' => self::string($row->date ?? null),
            'start' => self::time($row->start_time ?? null),
            'end' => self::time($row->end_time ?? null),
            'reason' => is_string($row->reason ?? null) ? $row->reason : null,
        ];
    }

    /** A time column as the wire spells it, HH:MM, or null where the column is. */
    public static function time(mixed $value): ?string
    {
        return $value === null ? null : substr(self::string($value), 0, 5);
    }

    public static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }

        throw new LogicException('A staff column read as something other than the integer the schema declares.');
    }

    public static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('A staff column read as something other than the text the schema declares.');
    }
}
