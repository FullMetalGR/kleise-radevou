<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Infrastructure\Db\Pool;

/**
 * The query string of GET /api/v1/availability: the shared query of AvailabilityRequest,
 * checked against the services and members offered online, since the booking app offers
 * those alone.
 */
final class PublicAvailabilityRequest extends AvailabilityRequest
{
    protected function pool(): Pool
    {
        return Pool::Online;
    }
}
