<?php

declare(strict_types=1);

namespace App\Application\Map;

use RuntimeException;
use Throwable;

/**
 * An image the map's job meant to delete, once its settling write had answered, threw on its
 * delete: the one the row held before or the one the job stored, which the row no longer names.
 * Reported once per image by the job, which goes on to its other delete and never throws it at a
 * caller, so the map made, or the row's failed state, stands; the image is left on the disk. A
 * path the row held that is not a file of the tenant's own directory, which only a hand's write
 * leaves, throws ImagePathRefused here.
 *
 * The message names the tenant and the class thrown, and carries neither the thrown message nor
 * the exception, which may hold the path or the disk's own words.
 */
final class MapFileNotDeleted extends RuntimeException
{
    public static function for(int $tenantId, Throwable $failure): self
    {
        return new self(sprintf('The map job of tenant %d could not delete an image its row no longer names, failing with %s; the image is left on the disk and the row is as the job\'s settle left it.', $tenantId, $failure::class));
    }
}
