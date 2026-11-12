<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Availability\CalendarDay;
use App\Infrastructure\Db\WeeklyPattern;
use Illuminate\Support\Facades\DB;

/**
 * The one way a test writes a member's repeating week straight into the schema: the week is the
 * member's shift_patterns row (migration 000032 dropped the weekday rules), and a window a test
 * adds, a week it clears or a weekday it reshapes is written to that row here.
 *
 * A week written by add() and week() is weekly and starts where every weekly pattern does,
 * WeeklyPattern::fromTheStart, the week holding 2000-01-01, so it holds on every date; add() keeps
 * the cadence and the end of a pattern the member already holds, and its start above a cadence
 * of one, since WeeklyPattern::put writes the one start for a weekly row. A pattern a case is
 * about, its start, end or cadence, is written whole with pattern().
 */
final class WeeklyHours
{
    /**
     * Writes one member's week whole as a weekly pattern of the windows given, each [weekday,
     * start, end] as HH:MM or HH:MM:SS, replacing the one they hold; an empty week leaves them none.
     *
     * @param  list<array{int, string, string}>  $windows
     */
    public static function week(string $schema, int $staffId, array $windows): void
    {
        WeeklyPattern::replace(DB::connection(), $schema.'.', $staffId, array_map(fn (array $window): array => ['weekday' => $window[0], 'start' => $window[1], 'end' => $window[2]], $windows));
    }

    /**
     * Adds one window on one weekday to one member's pattern, a weekly one from the one start
     * where they hold none.
     */
    public static function add(string $schema, int $staffId, int $weekday, string $start, string $end): void
    {
        $held = WeeklyPattern::stored(DB::connection(), $schema.'.', [$staffId])[$staffId] ?? null;
        $windows = [...($held['windows'] ?? []), ['weekday' => $weekday, 'start' => $start, 'end' => $end]];

        WeeklyPattern::put(DB::connection(), $schema.'.', $staffId, $windows, $held['cadence_weeks'] ?? 1, $held['starts_on'] ?? null, $held['ends_on'] ?? null);
    }

    /**
     * Removes the pattern of one member, or of every member for null.
     */
    public static function clear(string $schema, ?int $staffId = null): void
    {
        $patterns = DB::table($schema.'.shift_patterns');

        if ($staffId !== null) {
            $patterns->where('staff_id', $staffId);
        }

        $patterns->delete();
    }

    /**
     * Changes the windows of one weekday, in every member's pattern, to the window given.
     */
    public static function reshape(string $schema, int $weekday, string $start, string $end): void
    {
        foreach (WeeklyPattern::stored(DB::connection(), $schema.'.') as $staffId => $held) {
            $windows = array_map(fn (array $window): array => $window['weekday'] === $weekday ? ['weekday' => $weekday, 'start' => $start, 'end' => $end] : $window, $held['windows']);

            WeeklyPattern::put(DB::connection(), $schema.'.', $staffId, $windows, $held['cadence_weeks'], $held['starts_on'], $held['ends_on']);
        }
    }

    /**
     * Writes one member's pattern whole, replacing the one they hold: for a case about the
     * pattern's own dates or cadence, its start written as given whatever the cadence.
     *
     * @param  list<array{int, string, string}>  $windows  each [weekday, start, end] as HH:MM
     */
    public static function pattern(string $schema, int $staffId, string $startsOn, int $cadence, ?string $endsOn, array $windows): void
    {
        DB::table($schema.'.shift_patterns')->where('staff_id', $staffId)->delete();
        DB::table($schema.'.shift_patterns')->insert([
            'staff_id' => $staffId,
            'starts_on' => CalendarDay::spelled($startsOn),
            'cadence_weeks' => $cadence,
            'ends_on' => $endsOn,
            'windows' => json_encode(array_map(fn (array $window): array => ['weekday' => $window[0], 'start' => $window[1], 'end' => $window[2]], $windows), JSON_THROW_ON_ERROR),
        ]);
    }
}
