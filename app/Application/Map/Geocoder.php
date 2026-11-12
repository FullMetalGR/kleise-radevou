<?php

declare(strict_types=1);

namespace App\Application\Map;

/**
 * The business page's geocoder: the address lines the settings row holds, in the order the page
 * prints them, to the one point they name, or null where none was found or nothing could be
 * asked. A null is the map's failed state and never an exception, so a bad afternoon at the
 * provider fails no save. The HTTP client behind it lives under app/Infrastructure, and a test
 * binds a geocoder of its own scripted to answer.
 */
interface Geocoder
{
    /** @param  list<string>  $lines */
    public function locate(array $lines): ?Coordinates;
}
