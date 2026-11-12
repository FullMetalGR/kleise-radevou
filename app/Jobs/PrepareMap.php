<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Map\Geocoder;
use App\Application\Map\MapFileNotDeleted;
use App\Application\Map\MapNotMade;
use App\Application\Map\StaticMap;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\SettingsRecords;
use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\ImageRefused;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\MapState;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;
use Throwable;

/**
 * The business page's map, made after the save that changed the address: the address geocoded, the
 * map image fetched at the point and stored through the tenant image store as its own kind, the
 * point, the path and ready written in one write, and the image the row held before deleted. It
 * carries the tenant's id and nothing else, so a queue row holds no address and no key.
 * BackfillMapsCommand queues it too, for a row holding an address and no ready map.
 *
 * **It re-reads before it acts**. A row no longer pending, because a later save moved it, is left
 * as it is; the settling write checks again under its lock, since the two Google calls run with no
 * lock held and a save may land between, and writes nothing where the address is no longer the one
 * geocoded, that save's own job settling the row.
 *
 * **A null from either port is the failed state**, written with no reason stored and the reason
 * logged against the tenant's id, never the address. Nothing Google does fails the save, which
 * committed before this was queued. The failed state drops the map the row held, its file, its
 * path and its point, so the page never shows the map of a former address.
 *
 * **Nothing thrown past the re-read leaves it**. The re-read stands outside, as SweepExpiredHolds
 * keeps its tenant read out: a row that cannot be read cannot be settled against its lines, so
 * that throw leaves the job as any job's does, to the queue's own report and retry, the row
 * staying pending until a later run of the job, a save changing the address or map:backfill
 * settles it. Everything after it is guarded, each report a class of its own naming the class
 * thrown and never its message, as ExpireCheckout's CheckoutNotExpired is. A throw from either
 * port, the store or the settling write is met by settling the row failed as a null settles it,
 * then reported once as MapNotMade, whose sentence says what became of the row: settled failed;
 * left as a later write moved it; or, where the failed settle throws too, as the last save left
 * it, a row still pending then waiting on no job until map:backfill queues one, and any image the
 * job stored kept, since the row may name it. A delete of an image the row no longer names that
 * throws, on any road, is reported as MapFileNotDeleted and the image left, the other delete still
 * made.
 */
final class PrepareMap implements ShouldQueue
{
    use Queueable;

    public const string NO_POINT = 'The map was not made: the geocoder found no point for the address.';

    public const string NO_IMAGE = 'The map was not made: no map image came for the point.';

    public const string REFUSED = 'The map was not made: the map image is not one the store takes.';

    public function __construct(public readonly int $tenantId) {}

    public function handle(SettingsReader $reader, SettingsRecords $records, Geocoder $geocoder, StaticMap $map, TenantImages $images, LoggerInterface $log, ExceptionHandler $handler): void
    {
        $tenant = JobTenant::find($this->tenantId, self::class, $log);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $settings = $reader->read($tenant);

        if ($settings->mapState !== MapState::Pending) {
            return;
        }

        $lines = $settings->profile->addressLines();
        $stored = null;
        $made = null;

        try {
            $point = $geocoder->locate($lines);
            $bytes = $point === null ? null : $map->image($point);

            if ($point === null || $bytes === null) {
                $log->warning($point === null ? self::NO_POINT : self::NO_IMAGE, ['tenant' => $tenant->id]);
            } else {
                try {
                    $stored = self::store($images, $tenant, $bytes);
                    $made = ['point' => $point, 'path' => $stored];
                } catch (ImageRefused) {
                    $log->warning(self::REFUSED, ['tenant' => $tenant->id]);
                }
            }

            $settled = $records->settleMap($tenant, $lines, $made);
        } catch (Throwable $failure) {
            $made = null;
            $settled = self::settleThrown($records, $handler, $tenant, $lines, $failure);

            if ($settled === null) {
                return;
            }
        }

        self::forget($images, $handler, $tenant, $settled, $stored, $made === null ? null : $made['path']);
    }

    /**
     * Meets a throw from before the settling write answered: settles the row failed as a null
     * settles it and reports the throw as MapNotMade, saying what became of the row, and answers
     * that settle's answer for forget(). Where the failed settle throws too, both classes are
     * reported in the one MapNotMade and the answer is null: nothing more is done, so any image
     * the job stored is kept, the row as the last save left it possibly naming it.
     *
     * @param  list<string>  $lines  the address lines the job geocoded
     * @return array{written: bool, previous: string|null}|null
     */
    private static function settleThrown(SettingsRecords $records, ExceptionHandler $handler, Tenant $tenant, array $lines, Throwable $failure): ?array
    {
        try {
            $settled = $records->settleMap($tenant, $lines, null);
        } catch (Throwable $refused) {
            $handler->report(MapNotMade::unsettled($tenant->id, $failure, $refused));

            return null;
        }

        $handler->report($settled['written'] ? MapNotMade::settledFailed($tenant->id, $failure) : MapNotMade::overtaken($tenant->id, $failure));

        return $settled;
    }

    /**
     * Deletes every image the row does not name once the settling write has answered: the one the
     * row held before and the one this job stored, each unless it is the one the row now names,
     * the path written where the write landed, else the path the lock found. The same point draws
     * the same bytes under the same name, so a file the row still names is never deleted. A delete
     * that throws is reported as MapFileNotDeleted and its image left, the other still deleted.
     *
     * @param  array{written: bool, previous: string|null}  $settled
     * @param  string|null  $stored  the file this job stored, if it got that far
     * @param  string|null  $written  the path the settling write named, null for failed
     */
    private static function forget(TenantImages $images, ExceptionHandler $handler, Tenant $tenant, array $settled, ?string $stored, ?string $written): void
    {
        $named = $settled['written'] ? $written : $settled['previous'];

        foreach (array_unique(array_filter([$settled['previous'], $stored], is_string(...))) as $path) {
            if ($path === $named) {
                continue;
            }

            try {
                $images->delete($tenant, $path);
            } catch (Throwable $failure) {
                $handler->report(MapFileNotDeleted::for($tenant->id, $failure));
            }
        }
    }

    /** Stores the fetched bytes through the store's one road, a file, written and removed here. */
    private static function store(TenantImages $images, Tenant $tenant, string $bytes): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'kr-map');

        if ($temporary === false || file_put_contents($temporary, $bytes) === false) {
            throw new RuntimeException('The map image could not be written to a temporary file.');
        }

        try {
            return $images->store($tenant, ImageKind::Map, new File($temporary));
        } finally {
            unlink($temporary);
        }
    }
}
