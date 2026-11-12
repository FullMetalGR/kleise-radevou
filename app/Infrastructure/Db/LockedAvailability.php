<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Repository\AvailabilityRepository;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\StaffDayGeometry;
use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Availability\WindowGeometry;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use UnexpectedValueException;

/**
 * The availability reads of a booking transaction, on the connection the runner handed its
 * closure, with the mutex rows held: the same mapping from rows to masks the advisory read
 * uses, a connection and a table-name prefix away, and no cache anywhere near it. The search
 * path is already set and asserted, so every table is named bare, and the granularity is read
 * here on the same connection rather than carried in from outside the transaction.
 *
 * Every read is cold. That is the point: the masks the re-validation step checks are the rows
 * as they stand under the lock, and the masks the hold keeps for the cache are the rows as it
 * left them.
 */
final class LockedAvailability implements AvailabilityRepository
{
    private ?Granularity $granularity = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Tenant $tenant,
    ) {}

    public function masks(array $staffIds, string $firstDay, int $dayCount): StaffDayMaskSet
    {
        return MasksFromRows::onSearchPath($this->connection)->build($this->window($firstDay, $dayCount), $staffIds);
    }

    public function loads(array $staffIds, string $day): array
    {
        return MasksFromRows::onSearchPath($this->connection)->loads(StaffDayGeometry::of($day, $this->tenant->zone(), $this->granularity()), $staffIds);
    }

    /**
     * The window of a run of days in the tenant's zone at the granularity read on this
     * transaction.
     */
    public function window(string $firstDay, int $dayCount): WindowGeometry
    {
        return WindowGeometry::spanning($firstDay, $dayCount, $this->tenant->zone(), $this->granularity());
    }

    /**
     * The granularity of the settings row, read once per transaction.
     */
    public function granularity(): Granularity
    {
        if ($this->granularity === null) {
            $minutes = $this->connection->scalar(self::granularitySql());

            if (! is_int($minutes)) {
                throw new UnexpectedValueException(sprintf('The settings row answered a %s for its granularity where an integer was expected.', get_debug_type($minutes)));
            }

            $this->granularity = Granularity::minutes($minutes);
        }

        return $this->granularity;
    }

    public static function granularitySql(): string
    {
        return 'select granularity_min from settings where id = 1';
    }
}
