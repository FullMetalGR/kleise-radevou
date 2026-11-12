<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Availability\CalendarDay;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The calendar's fetch window as the server reads it: the visible range as two of the operator's
 * dates in the tenant's zone, the end exclusive, and the server's parameter beside them, Who: the
 * mode and the staff ids it narrows to. For Picked the list is the ids asked for; for Scheduled,
 * All and Me it is empty as asked, and the page fills it once it has resolved the members
 * (narrowedTo), the bookings read cutting on it in every mode but All. The ids are kept sorted and
 * unique, so the list the window echoes is the one the screen compares.
 *
 * The end is exclusive on the wire as it is on the screen: a week is Monday to the next Monday,
 * a day is that day to the next and a month is the six weeks its grid shows, so the client sends
 * the day after the last one shown and this class turns nothing. range() hands the reader the two
 * midnights in the tenant's zone.
 */
final readonly class CalendarWindow
{
    private const string DAY = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * The most days one read may span: the Month view's six-week grid, the widest view, is read
     * whole in one, and a caller asking for more is asking for something else.
     */
    public const int MAX_DAYS = 42;

    /** @var list<int> */
    public array $staff;

    /**
     * @param  list<int>  $staff
     */
    public function __construct(
        public string $from,
        public string $to,
        public CalendarWho $who = CalendarWho::Scheduled,
        array $staff = [],
    ) {
        if (preg_match(self::DAY, $from) !== 1 || preg_match(self::DAY, $to) !== 1 || $to <= $from) {
            throw new InvalidArgumentException(sprintf('A calendar window runs from one date to a later one, not "%s" to "%s".', $from, $to));
        }

        $ids = array_values(array_unique($staff));
        sort($ids);
        $this->staff = $ids;
    }

    /**
     * The same range and mode narrowed to the members resolved for it: what the bookings read
     * filters on, every mode but All.
     *
     * @param  list<int>  $staff
     */
    public function narrowedTo(array $staff): self
    {
        return new self($this->from, $this->to, $this->who, $staff);
    }

    /** Whether the read is cut to the staff list: every mode but All. */
    public function narrows(): bool
    {
        return $this->who !== CalendarWho::All;
    }

    /**
     * Every date of the window in order, the end excluded.
     *
     * @return list<string>
     */
    public function days(): array
    {
        $utc = new DateTimeZone('UTC');
        $day = new DateTimeImmutable($this->from, $utc);
        $days = [];

        while ($day->format('Y-m-d') < $this->to) {
            $days[] = $day->format('Y-m-d');
            $day = $day->add(new DateInterval('P1D'));
        }

        return $days;
    }

    /**
     * Whether two dates make a window this class accepts: both dates, the second after the first
     * and at most MAX_DAYS on.
     */
    public static function accepts(?string $from, ?string $to): bool
    {
        if ($from === null || $to === null || preg_match(self::DAY, $from) !== 1 || preg_match(self::DAY, $to) !== 1 || $to <= $from) {
            return false;
        }

        $utc = new DateTimeZone('UTC');

        return (new DateTimeImmutable($from, $utc))->diff(new DateTimeImmutable($to, $utc))->days <= self::MAX_DAYS;
    }

    /**
     * The week holding a date, from the tenant's week start: the window the calendar opens on.
     *
     * @param  string  $date  the tenant's today, Y-m-d
     * @param  int  $startOfWeek  ISO numbered, 1 for Monday
     */
    public static function weekOf(string $date, int $startOfWeek): self
    {
        $from = CalendarDay::weekStart($date, $startOfWeek);

        return new self($from, CalendarDay::plusDays($from, 7));
    }

    /**
     * The window as instants: from's midnight in the tenant's zone, inclusive, to to's, exclusive.
     * A from on the calendar's own first day, whose midnight east of UTC falls before
     * FirstInstant, starts there instead: nothing precedes it, so the answer is the same.
     *
     * @return array{from: DateTimeImmutable, until: DateTimeImmutable}
     */
    public function range(DateTimeZone $zone): array
    {
        $from = new DateTimeImmutable($this->from.' 00:00', $zone);

        return [
            'from' => FirstInstant::precedes($from) ? FirstInstant::at() : $from,
            'until' => new DateTimeImmutable($this->to.' 00:00', $zone),
        ];
    }
}
