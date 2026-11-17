<?php

declare(strict_types=1);

namespace App\Http;

use App\Billing\EntitlementResolver;
use App\Billing\Feature;
use App\Domain\Availability\CalendarDay;
use App\Infrastructure\Db\StaffRecords;
use App\Tenancy\Tenant;

/**
 * The business's usual opening week, read off the staff rows' weekly windows: the one reading the
 * business page's hours table and the Calendar's closed days share, so the two never disagree on
 * when the business opens.
 *
 * The windows of the implicit member's pattern count where the staff feature is absent or the
 * roster holds no other active row, else the active members' do, each day's windows merged where
 * they overlap or touch. The overrides and the cadence are not read: this is the week a customer
 * can expect, the times offered the resolver's. A pattern whose end is before the tenant's today
 * is left out, since nothing is offered from it from today on; one that starts later is kept.
 */
final readonly class OpeningWeek
{
    public function __construct(private StaffRecords $staff, private EntitlementResolver $entitlements) {}

    /**
     * The week from the roster as it stands, read here.
     *
     * @return array<int, list<array{0: string, 1: string}>>
     */
    public function read(Tenant $tenant, string $today): array
    {
        return $this->of($tenant, $this->staff->patterned($tenant), $today);
    }

    /**
     * The week by ISO weekday from members already read, a weekday with no window absent.
     *
     * @param  list<array{status: string, is_implicit: bool, pattern: array{ends_on: string|null, windows: list<array{weekday: int, start: string, end: string}>}|null}>  $members
     * @return array<int, list<array{0: string, 1: string}>>
     */
    public function of(Tenant $tenant, array $members, string $today): array
    {
        $active = array_filter($members, fn (array $member): bool => ! $member['is_implicit'] && $member['status'] === 'active');
        $floor = $active === [] || ! $this->entitlements->resolve($tenant)->allows(Feature::Staff);
        $week = [];

        foreach ($members as $member) {
            $pattern = $member['pattern'];

            if ($pattern !== null && ($pattern['ends_on'] === null || $pattern['ends_on'] >= $today) && ($floor ? $member['is_implicit'] : $member['status'] === 'active')) {
                foreach ($pattern['windows'] as $window) {
                    $week[$window['weekday']][] = [$window['start'], $window['end']];
                }
            }
        }

        ksort($week);

        return array_map(self::joined(...), $week);
    }

    /**
     * The dates the business is closed all day: a weekday the week holds no window on, or a date
     * whose business closures leave no minute of that weekday's windows open. The closures of one
     * date count together, joined where they overlap or touch, and one with no times takes the
     * whole day. A member's own closure closes nothing here.
     *
     * @param  array<int, list<array{0: string, 1: string}>>  $week
     * @param  list<string>  $days
     * @param  list<array{date: string, start: string|null, end: string|null}>  $closures  the business's, the tenant-wide rows alone
     * @return list<string>
     */
    public static function closedDays(array $week, array $days, array $closures): array
    {
        return array_values(array_filter($days, function (string $day) use ($week, $closures): bool {
            $taken = [];

            foreach ($closures as $closure) {
                if ($closure['date'] !== $day) {
                    continue;
                }

                if ($closure['start'] === null) {
                    return true;
                }

                if ($closure['end'] !== null) {
                    $taken[] = [$closure['start'], $closure['end']];
                }
            }

            $taken = self::joined($taken);

            foreach ($week[CalendarDay::weekday($day)] ?? [] as [$start, $end]) {
                if (! self::covered($start, $end, $taken)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * The spans of one day in start order, joined where they overlap or touch: a day's opening
     * windows, or the times its closures take.
     *
     * @param  list<array{0: string, 1: string}>  $spans
     * @return list<array{0: string, 1: string}>
     */
    private static function joined(array $spans): array
    {
        usort($spans, fn (array $a, array $b): int => $a <=> $b);
        $joined = [];

        foreach ($spans as [$start, $end]) {
            $last = array_key_last($joined);

            if ($last !== null && $start <= $joined[$last][1]) {
                $joined[$last][1] = max($joined[$last][1], $end);
            } else {
                $joined[] = [$start, $end];
            }
        }

        return $joined;
    }

    /**
     * Whether one of the joined spans holds the window whole. Joined spans stand apart, so a
     * window with no minute outside them lies inside one.
     *
     * @param  list<array{0: string, 1: string}>  $taken
     */
    private static function covered(string $start, string $end, array $taken): bool
    {
        foreach ($taken as [$from, $until]) {
            if ($from <= $start && $until >= $end) {
                return true;
            }
        }

        return false;
    }
}
