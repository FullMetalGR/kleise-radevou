<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Where the business page's map stands: none where there is no map to make, the key being unset
 * or the address empty; pending from a save that changed the address until the queued job
 * settles it; ready with the point and the stored image; failed where either Google step
 * answered nothing, the reason logged and never stored. The cases are the CHECK's words in its
 * order, so the two cannot drift apart unseen.
 */
enum MapState: string
{
    case None = 'none';
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
