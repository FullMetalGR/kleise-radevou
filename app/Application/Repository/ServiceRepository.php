<?php

declare(strict_types=1);

namespace App\Application\Repository;

use App\Application\Dto\BookableService;

/**
 * The port a chain's services come through, bound to one tenant and one pool by whoever built
 * it: the engine's view of the active services among some ids, each with the staff members who
 * may perform it. An unknown id and an inactive service are alike absent, because a customer may
 * book neither and the difference is nobody's business on the read side; on the public road a
 * service or a member not offered online is absent the same way.
 */
interface ServiceRepository
{
    /**
     * @param  list<int>  $serviceIds
     * @return array<int, BookableService> keyed by service id, the active services only
     */
    public function bookable(array $serviceIds): array;
}
