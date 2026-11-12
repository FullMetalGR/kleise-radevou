<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Repository\AvailabilityRepository;
use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\BlockRange;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\MaskBuilder;
use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Availability\WallClockTime;
use App\Domain\Availability\WindowGeometry;
use DateTimeZone;

/**
 * An availability repository over fixtures, for a use case tested with no database: masks
 * built through the real MaskBuilder over whatever window is asked for, from hours that hold
 * every weekday and block ranges per member, and loads from an array. Every ask is recorded,
 * so a test can prove what was read and what never was.
 */
final class FixtureAvailability implements AvailabilityRepository
{
    /** @var list<array{string, mixed}> */
    public array $asked = [];

    /**
     * @param  array<int, list<array{string, string}>>  $hours  open and close per staff member, every weekday
     * @param  array<int, list<BlockRange>>  $blocks  per staff member
     * @param  array<int, int>  $loads  per staff member
     */
    public function __construct(
        private readonly DateTimeZone $zone,
        private readonly array $hours = [],
        private readonly array $blocks = [],
        private readonly array $loads = [],
        private readonly ?Granularity $granularity = null,
    ) {}

    public function masks(array $staffIds, string $firstDay, int $dayCount): StaffDayMaskSet
    {
        $this->asked[] = ['masks', [$staffIds, $firstDay, $dayCount]];
        $window = WindowGeometry::spanning($firstDay, $dayCount, $this->zone, $this->granularity ?? Granularity::default());
        $byStaff = [];

        foreach ($staffIds as $staffId) {
            $rules = [];

            foreach ($this->hours[$staffId] ?? [] as [$open, $close]) {
                foreach (range(1, 7) as $weekday) {
                    $rules[] = new AvailabilityRule($weekday, WallClockTime::of($open), WallClockTime::of($close));
                }
            }

            $days = [];

            foreach ($window->days as $day) {
                $days[] = (new MaskBuilder)->build($day, $staffId, $rules, [], $this->blocks[$staffId] ?? []);
            }

            $byStaff[$staffId] = $days;
        }

        return new StaffDayMaskSet($window, $byStaff);
    }

    public function loads(array $staffIds, string $day): array
    {
        $this->asked[] = ['loads', [$staffIds, $day]];

        return array_intersect_key($this->loads, array_flip($staffIds));
    }
}
