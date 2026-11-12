<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Availability\CalendarDay;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The range Home's figures read: the whole calendar period a word names, two of the tenant's
 * dates with the last inclusive, its days to come included, and the calendar period before it.
 *
 * Home names four words: today, yesterday, this week (from the tenant's week start, the week
 * the calendar opens on) and this month; Reports names those and the last 7 days, the last 30
 * days (each ending today), last month and this year. Any word the caller does not name, an empty
 * one or none reads as this week. The period before keeps the range's unit: a day steps back a
 * day, a week seven days, a month to the whole calendar month before it, a year to the year
 * before. A run of days and a custom pair, which Reports takes up to MOST_DAYS long, are spans
 * whose period before is the same number of days before them.
 *
 * window() hands the reader the first day's midnight in the tenant's zone and the midnight after
 * the last, so a day the clocks change on holds 23 or 25 hours.
 */
final readonly class FigureRange
{
    public const string TODAY = 'today';

    public const string YESTERDAY = 'yesterday';

    public const string WEEK = 'week';

    public const string MONTH = 'month';

    public const string LAST_7_DAYS = 'last_7_days';

    public const string LAST_30_DAYS = 'last_30_days';

    public const string LAST_MONTH = 'last_month';

    public const string YEAR = 'year';

    public const string CUSTOM = 'custom';

    /** The words Home names, in the order its range card lists them. */
    public const array HOME = [self::TODAY, self::YESTERDAY, self::WEEK, self::MONTH];

    /** The words Reports names, in the order its Range lists them; the custom range is apart. */
    public const array REPORTS = [self::TODAY, self::YESTERDAY, self::WEEK, self::LAST_7_DAYS, self::LAST_30_DAYS, self::MONTH, self::LAST_MONTH, self::YEAR];

    /**
     * The most days a custom range holds, a leap year's: the hours of every member are read for
     * the range and the period before, so the span is bounded.
     */
    public const int MOST_DAYS = 366;

    /** The most days Over time draws one bucket per day for. */
    public const int DAILY_DAYS = 31;

    /** The most days Over time draws one bucket per week for; a longer range is drawn per month. */
    public const int WEEKLY_DAYS = 183;

    private const string DAY = '/^\d{4}-\d{2}-\d{2}$/';

    private const string UNIT_DAY = 'day';

    private const string UNIT_WEEK = 'week';

    private const string UNIT_MONTH = 'month';

    private const string UNIT_YEAR = 'year';

    private const string UNIT_SPAN = 'span';

    private function __construct(
        public string $word,
        public string $from,
        public string $to,
        private string $unit,
    ) {}

    /**
     * The range one of the words given names on the tenant's today, this week for any other word.
     * Home names its four (HOME), Reports its eight (REPORTS). The last 7 and the last 30 days end
     * today and step back as many days; last month steps back as a month does, this year as a year.
     *
     * @param  string  $today  the tenant's today, Y-m-d
     * @param  int  $startOfWeek  ISO numbered, 1 for Monday
     * @param  list<string>  $words
     */
    public static function named(?string $word, string $today, int $startOfWeek, array $words = self::HOME): self
    {
        $lastMonth = CalendarDay::plusDays(substr($today, 0, 8).'01', -1);

        return match (in_array($word, $words, true) ? $word : self::WEEK) {
            self::TODAY => new self(self::TODAY, $today, $today, self::UNIT_DAY),
            self::YESTERDAY => new self(self::YESTERDAY, CalendarDay::plusDays($today, -1), CalendarDay::plusDays($today, -1), self::UNIT_DAY),
            self::MONTH => new self(self::MONTH, substr($today, 0, 8).'01', self::lastOfMonth($today), self::UNIT_MONTH),
            self::LAST_7_DAYS => new self(self::LAST_7_DAYS, CalendarDay::plusDays($today, -6), $today, self::UNIT_SPAN),
            self::LAST_30_DAYS => new self(self::LAST_30_DAYS, CalendarDay::plusDays($today, -29), $today, self::UNIT_SPAN),
            self::LAST_MONTH => new self(self::LAST_MONTH, substr($lastMonth, 0, 8).'01', $lastMonth, self::UNIT_MONTH),
            self::YEAR => new self(self::YEAR, substr($today, 0, 4).'-01-01', substr($today, 0, 4).'-12-31', self::UNIT_YEAR),
            default => new self(self::WEEK, CalendarDay::weekStart($today, $startOfWeek), CalendarDay::plusDays(CalendarDay::weekStart($today, $startOfWeek), 6), self::UNIT_WEEK),
        };
    }

    /**
     * A custom pair, both dates inclusive, or null where either is not a real date, the last is
     * before the first or the pair holds more than MOST_DAYS days.
     */
    public static function custom(?string $from, ?string $to): ?self
    {
        if (! self::isDay($from) || ! self::isDay($to) || $to < $from || CalendarDay::daysBetween($from, $to) >= self::MOST_DAYS) {
            return null;
        }

        return new self(self::CUSTOM, $from, $to, self::UNIT_SPAN);
    }

    /**
     * The calendar period before this one, in the same unit and under the same word.
     */
    public function before(): self
    {
        return match ($this->unit) {
            self::UNIT_DAY => new self($this->word, CalendarDay::plusDays($this->from, -1), CalendarDay::plusDays($this->to, -1), $this->unit),
            self::UNIT_WEEK => new self($this->word, CalendarDay::plusDays($this->from, -7), CalendarDay::plusDays($this->to, -7), $this->unit),
            self::UNIT_MONTH => new self($this->word, substr(CalendarDay::plusDays($this->from, -1), 0, 8).'01', CalendarDay::plusDays($this->from, -1), $this->unit),
            self::UNIT_YEAR => new self($this->word, sprintf('%04d-01-01', (int) substr($this->from, 0, 4) - 1), sprintf('%04d-12-31', (int) substr($this->from, 0, 4) - 1), $this->unit),
            default => new self($this->word, CalendarDay::plusDays($this->from, -$this->days()), CalendarDay::plusDays($this->from, -1), $this->unit),
        };
    }

    /** How many dates the range holds, both ends counted. */
    public function days(): int
    {
        return CalendarDay::daysBetween($this->from, $this->to) + 1;
    }

    /**
     * The range cut into Reports' Over time buckets: up to DAILY_DAYS days one bucket per day; up
     * to WEEKLY_DAYS one per week of the tenant's week start; longer one per calendar month. The
     * first and the last bucket are cut to the range, and each is two dates with the last
     * inclusive.
     *
     * @param  int  $startOfWeek  ISO numbered, 1 for Monday
     * @return array{unit: 'day'|'week'|'month', rows: non-empty-list<array{from: string, to: string}>}
     */
    public function buckets(int $startOfWeek): array
    {
        $days = $this->days();
        $unit = $days <= self::DAILY_DAYS ? self::UNIT_DAY : ($days <= self::WEEKLY_DAYS ? self::UNIT_WEEK : self::UNIT_MONTH);
        $rows = [];
        $from = $this->from;

        do {
            $last = match ($unit) {
                self::UNIT_DAY => $from,
                self::UNIT_WEEK => CalendarDay::plusDays(CalendarDay::weekStart($from, $startOfWeek), 6),
                default => self::lastOfMonth($from),
            };
            $to = min($last, $this->to);
            $rows[] = ['from' => $from, 'to' => $to];
            $from = CalendarDay::plusDays($to, 1);
        } while ($from <= $this->to);

        return ['unit' => $unit, 'rows' => $rows];
    }

    /**
     * The range as instants: the first day's midnight in the zone, inclusive, and the midnight
     * after the last day, exclusive. A first day whose midnight falls before FirstInstant starts
     * there instead, as the calendar's window does.
     *
     * @return array{from: DateTimeImmutable, until: DateTimeImmutable}
     */
    public function window(DateTimeZone $zone): array
    {
        $from = new DateTimeImmutable($this->from.' 00:00', $zone);

        return [
            'from' => FirstInstant::precedes($from) ? FirstInstant::at() : $from,
            'until' => (new DateTimeImmutable($this->to.' 00:00', $zone))->add(new DateInterval('P1D')),
        ];
    }

    /**
     * The range as the prop names it: its word and its two dates, the last inclusive.
     *
     * @return array{word: string, from: string, to: string}
     */
    public function toArray(): array
    {
        return ['word' => $this->word, 'from' => $this->from, 'to' => $this->to];
    }

    private static function lastOfMonth(string $day): string
    {
        return (new DateTimeImmutable(CalendarDay::spelled($day), new DateTimeZone('UTC')))->format('Y-m-t');
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function isDay(?string $value): bool
    {
        if ($value === null || preg_match(self::DAY, $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
