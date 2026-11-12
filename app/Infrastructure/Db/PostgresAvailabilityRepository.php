<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Repository\AvailabilityLeavingOut;
use App\Application\Repository\AvailabilityRepository;
use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\StaffDayGeometry;
use App\Domain\Availability\StaffDayMasks;
use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Availability\WindowGeometry;
use App\Domain\Booking\LockKeySet;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;

/**
 * The availability repository of one tenant, for a read that opens no transaction: the window's
 * geometry in the tenant's zone at the granularity its settings row names, then the one mapping
 * from rows to masks with every table named by schema, the not-booked half through the mask cache.
 * Built per tenant rather than resolved from the container, so a use case holding one can never
 * read another tenant's rows.
 *
 * One read costs the settings statement, the patterns, overrides and blackouts statements for the
 * open half, which no revision covers and which is rebuilt every time, and one revisions statement.
 * The items statement runs only for the staff members with a staff-day the store could not answer:
 * a warm read never touches booking_items, a cold read reads it once for the whole window. A hit is
 * read back as stored; a miss is built from rows and stored under its key, so the next read is warm
 * until a hold on that staff-day bumps its revision, or the earliest live hold it counted lapses,
 * or a day passes.
 *
 * The clock the misses are judged by is the database's now from the revisions statement, the
 * clock the blocking predicate reads, and not the clock the use case reads for today and the
 * lead time. The two are kept apart on purpose: what a slot is called is the tenant's business,
 * whether a hold still blocks is the server's.
 *
 * The open half keeps its two factors beside it, the member's hours and what no closure covers,
 * since both come from the same three statements at no further cost: an advisory crossing is then
 * told apart as the write under the lock tells it, hours or a closure.
 *
 * The per-booking read goes through the same path with one booking left out: the staff-days that
 * booking stands on skip the store both ways and are rebuilt from rows without its items, and every
 * other staff-day is served exactly as above. One path, so the cold and warm answers of the two
 * reads cannot come apart.
 */
final readonly class PostgresAvailabilityRepository implements AvailabilityLeavingOut, AvailabilityRepository
{
    public function __construct(
        private DatabaseManager $db,
        private SettingsReader $settings,
        private Tenant $tenant,
        private StaffDayMaskCache $cache,
    ) {}

    public function masks(array $staffIds, string $firstDay, int $dayCount): StaffDayMaskSet
    {
        return $this->read($staffIds, $firstDay, $dayCount, null, []);
    }

    /**
     * The same read with one booking left out: the staff-days it stands on inside the window
     * are rebuilt from rows by the items statement with its items excluded, narrowed to those
     * days and those members, and neither looked up in the store nor written to it, since they
     * are true for this booking's read alone. Every other staff-day is read exactly as masks()
     * reads it, so a warm read issues the items statement for the rebuilt staff-days and for
     * nothing else.
     */
    public function masksLeavingOut(string $uuid, LockKeySet $standsOn, array $staffIds, string $firstDay, int $dayCount): StaffDayMaskSet
    {
        $rebuilt = [];

        foreach ($standsOn as $key) {
            $rebuilt[$key->staffId][$key->day] = true;
        }

        return $this->read($staffIds, $firstDay, $dayCount, $uuid, $rebuilt);
    }

    /**
     * @param  non-empty-list<int>  $staffIds
     * @param  string|null  $leavingOut  the booking whose items the rebuilt staff-days leave out
     * @param  array<int, array<string, true>>  $rebuilt  by staff id, the days to rebuild without it
     */
    private function read(array $staffIds, string $firstDay, int $dayCount, ?string $leavingOut, array $rebuilt): StaffDayMaskSet
    {
        $granularity = Granularity::minutes($this->settings->read($this->tenant)->granularityMin);
        $window = WindowGeometry::spanning($firstDay, $dayCount, $this->tenant->zone(), $granularity);
        $connection = $this->db->connection();
        $schema = $this->tenant->schema_name;
        $rows = MasksFromRows::forSchema($connection, $schema);

        $open = $rows->openFactors($window, $staffIds);
        $staffIds = array_keys($open);
        $revisions = RevisionsFromRows::forSchema($connection, $schema)->read($staffIds, $window->days[0]->day, $window->days[count($window->days) - 1]->day);

        $keys = [];
        $widthByKey = [];
        $rebuiltDays = [];

        foreach ($staffIds as $staffId) {
            foreach ($window->days as $position => $day) {
                if (isset($rebuilt[$staffId][$day->day])) {
                    $rebuiltDays[$day->day] = true;

                    continue;
                }

                $key = StaffDayMaskCache::key($schema, $staffId, $day->day, $granularity, $revisions->of($staffId, $day->day));
                $keys[$staffId][$position] = $key;
                $widthByKey[$key] = $day->width;
            }
        }

        $hits = $this->cache->fetch($widthByKey, $revisions->now);
        $missed = [];

        foreach ($keys as $staffId => $byPosition) {
            foreach ($byPosition as $key) {
                if (! isset($hits[$key])) {
                    $missed[] = $staffId;

                    break;
                }
            }
        }

        $fresh = $missed === [] ? [] : $rows->notBookedMasks($window, $missed);
        [$firstRebuilt, $leftOut] = $this->leftOut($rows, $window, $rebuilt, $rebuiltDays, $leavingOut);
        $byStaff = [];

        foreach ($staffIds as $staffId) {
            $days = [];

            foreach ($window->days as $position => $day) {
                $key = $keys[$staffId][$position] ?? null;

                if ($key === null) {
                    $entry = $leftOut[$staffId][CalendarDay::daysBetween($firstRebuilt, $day->day)];
                } else {
                    $entry = $hits[$key] ?? null;

                    if ($entry === null) {
                        $entry = $fresh[$staffId][$position];
                        $this->cache->store($key, $entry, $revisions->now);
                    }
                }

                [$hours, $unclosed] = $open[$staffId][$position];
                $days[] = new StaffDayMasks($hours->and($unclosed), $entry->mask, $hours, $unclosed);
            }

            $byStaff[$staffId] = $days;
        }

        return new StaffDayMaskSet($window, $byStaff);
    }

    /**
     * The not-booked masks of the rebuilt staff-days, read with the booking left out over the
     * run of days from the first rebuilt day to the last, for the members with one, and the
     * first of those days, which the masks are positioned from. Nothing to rebuild inside the
     * window is no statement at all.
     *
     * @param  array<int, array<string, true>>  $rebuilt
     * @param  array<string, true>  $rebuiltDays  the rebuilt days inside the window
     * @return array{string, array<int, list<NotBookedMask>>}
     */
    private function leftOut(MasksFromRows $rows, WindowGeometry $window, array $rebuilt, array $rebuiltDays, ?string $leavingOut): array
    {
        if ($rebuiltDays === [] || $leavingOut === null) {
            return [$window->days[0]->day, []];
        }

        $days = array_keys($rebuiltDays);
        sort($days);
        $first = $days[0];
        $last = $days[count($days) - 1];
        $members = [];

        foreach ($rebuilt as $staffId => $byDay) {
            foreach (array_keys($byDay) as $day) {
                if (isset($rebuiltDays[$day])) {
                    $members[] = $staffId;

                    break;
                }
            }
        }

        $span = WindowGeometry::spanning($first, CalendarDay::daysBetween($first, $last) + 1, $window->zone, $window->granularity);

        return [$first, $members === [] ? [] : $rows->notBookedMasks($span, $members, $leavingOut)];
    }

    /**
     * Never cached: a count is not a mask, and the hold reads it once.
     */
    public function loads(array $staffIds, string $day): array
    {
        $granularity = Granularity::minutes($this->settings->read($this->tenant)->granularityMin);
        $geometry = StaffDayGeometry::of($day, $this->tenant->zone(), $granularity);

        return MasksFromRows::forSchema($this->db->connection(), $this->tenant->schema_name)->loads($geometry, $staffIds);
    }
}
