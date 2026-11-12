<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Infrastructure\Db\WeeklyPattern;
use App\Tenancy\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The demo hours on the two demo tenants, once DemoCatalogSeeder has given luna its named
 * staff.
 *
 * luna's two named staff members get different weeks: Elena works Monday to Friday with the
 * day split around lunch, Nikos Tuesday to Saturday through the day, so a Monday shows the
 * lunch gap and a Saturday shows one person. luna also gets one tenant-wide blackout on a date
 * and one personal blackout with a window. pier's implicit member keeps the default week
 * provisioning gave it, and nothing here touches it.
 *
 * A member's week is a weekly shift pattern from the week holding 2000-01-01, the one start of
 * every weekly pattern (WeeklyPattern::replace), written only when that member holds no pattern
 * at all, the rule provisioning follows, so hours edited since are kept; a blackout is inserted
 * only where no row of that member, date and window exists. A second run therefore changes
 * nothing, not even a timestamp. Every table is named by schema with no search path change, the
 * way the catalog is seeded.
 */
final class DemoHoursSeeder extends Seeder
{
    /**
     * Per tenant slug: each named staff member's windows as [weekday, start, end], and the
     * blackouts as [staff name or null for tenant-wide, date, start, end, reason].
     *
     * @var array<string, array{weeks: array<string, list<array{int, string, string}>>, blackouts: list<array{string|null, string, string|null, string|null, string}>}>
     */
    public const array HOURS = [
        'luna' => [
            'weeks' => [
                'Elena Papadaki' => [
                    [1, '09:00:00', '13:00:00'], [1, '14:00:00', '18:00:00'],
                    [2, '09:00:00', '13:00:00'], [2, '14:00:00', '18:00:00'],
                    [3, '09:00:00', '13:00:00'], [3, '14:00:00', '18:00:00'],
                    [4, '09:00:00', '13:00:00'], [4, '14:00:00', '18:00:00'],
                    [5, '09:00:00', '13:00:00'], [5, '14:00:00', '18:00:00'],
                ],
                'Nikos Georgiou' => [
                    [2, '10:00:00', '19:00:00'],
                    [3, '10:00:00', '19:00:00'],
                    [4, '10:00:00', '19:00:00'],
                    [5, '10:00:00', '19:00:00'],
                    [6, '10:00:00', '19:00:00'],
                ],
            ],
            'blackouts' => [
                ['Nikos Georgiou', '2026-12-04', '14:00:00', '16:00:00', 'Dentist'],
                [null, '2026-12-25', null, null, 'Christmas Day'],
            ],
        ],
        'pier' => [
            'weeks' => [],
            'blackouts' => [],
        ],
    ];

    public function run(): void
    {
        foreach (self::HOURS as $slug => $hours) {
            $schema = Slug::of($slug)->schemaName();
            $added = 0;
            $blackouts = 0;

            foreach ($hours['weeks'] as $name => $week) {
                $added += $this->week($schema, $this->staffId($schema, $slug, $name), $week);
            }

            foreach ($hours['blackouts'] as [$name, $date, $start, $end, $reason]) {
                $blackouts += $this->blackout($schema, $name === null ? null : $this->staffId($schema, $slug, $name), $date, $start, $end, $reason);
            }

            $this->command->line($added === 0 && $blackouts === 0
                ? sprintf('Hours of "%s" are already in place; nothing changed.', $slug)
                : sprintf('Hours of "%s": %d windows and %d blackouts added.', $slug, $added, $blackouts));
        }
    }

    /**
     * @param  list<array{int, string, string}>  $windows
     * @return int the windows added
     */
    private function week(string $schema, int $staffId, array $windows): int
    {
        if (DB::table($schema.'.shift_patterns')->where('staff_id', $staffId)->exists()) {
            return 0;
        }

        WeeklyPattern::replace(DB::connection(), $schema.'.', $staffId, array_map(
            fn (array $window): array => ['weekday' => $window[0], 'start' => $window[1], 'end' => $window[2]],
            $windows,
        ));

        return count($windows);
    }

    /**
     * @return int the blackouts added, one or none
     */
    private function blackout(string $schema, ?int $staffId, string $date, ?string $start, ?string $end, string $reason): int
    {
        $existing = DB::table($schema.'.blackouts')
            ->where('date', $date)
            ->whereRaw('staff_id is not distinct from ?', [$staffId])
            ->whereRaw('start_time is not distinct from ?::time', [$start])
            ->whereRaw('end_time is not distinct from ?::time', [$end])
            ->exists();

        if ($existing) {
            return 0;
        }

        DB::table($schema.'.blackouts')->insert([
            'staff_id' => $staffId,
            'date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'reason' => $reason,
        ]);

        return 1;
    }

    private function staffId(string $schema, string $slug, string $name): int
    {
        $id = DB::table($schema.'.staff')->where('name', $name)->value('id');

        if (! is_int($id)) {
            throw new LogicException(sprintf('The catalog of "%s" names no staff member "%s"; DemoCatalogSeeder runs first.', $slug, $name));
        }

        return $id;
    }
}
