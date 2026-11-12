<?php

declare(strict_types=1);

namespace App\Application\Map;

use RuntimeException;
use Throwable;

/**
 * The business page's map was not made because something the map's job did past its re-read
 * threw before its settling write answered: the geocoder's call, the map image's, the store's
 * write or the settling write itself. Reported once by the job, after it has tried to settle the
 * row failed as a null settles it, and never thrown at a caller. Its sentence says which of
 * three things became of the row: settled failed, which map:backfill or a save changing the
 * address queues again; left as a later write moved it, nothing written; or, where the failed
 * settle threw too, as the last save left it, any image the job stored kept, since the row may
 * name it, and a row still pending waiting on no job until map:backfill queues one.
 *
 * The message names the tenant and the classes thrown, and carries neither a thrown message nor
 * the exception, which may hold the Google key in a request's URL, the address or a statement.
 */
final class MapNotMade extends RuntimeException
{
    /** The failed settle that followed the throw wrote failed. */
    public static function settledFailed(int $tenantId, Throwable $failure): self
    {
        return new self(sprintf('The map of tenant %d was not made, its job throwing %s; the row is settled failed, which map:backfill or a save changing the address queues again.', $tenantId, $failure::class));
    }

    /** The failed settle that followed the throw found the row moved on and wrote nothing. */
    public static function overtaken(int $tenantId, Throwable $failure): self
    {
        return new self(sprintf('The map of tenant %d was not made, its job throwing %s; nothing was written, a later write having moved the row on since the job read it.', $tenantId, $failure::class));
    }

    /** The failed settle that followed the throw threw too. */
    public static function unsettled(int $tenantId, Throwable $failure, Throwable $refused): self
    {
        return new self(sprintf('The map of tenant %d was not made, its job throwing %s and its failed settle %s; no write of the job\'s reached the row, so the row is as the last save left it, any image the job stored is kept, and map:backfill queues the row again where it still waits pending.', $tenantId, $failure::class, $refused::class));
    }
}
