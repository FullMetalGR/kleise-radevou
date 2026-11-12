<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Infrastructure\Db\Pool;

/**
 * The query string of GET /admin/api/availability: the public route's query with its shape,
 * its sentences and its envelope, checked against the whole catalogue rather than the part
 * offered online, since the admin's wizard books every active service on every active member
 * whatever the flag says. The one place the two availability reads part before the catalog is
 * read.
 */
final class WizardAvailabilityRequest extends AvailabilityRequest
{
    protected function pool(): Pool
    {
        return Pool::Whole;
    }
}
