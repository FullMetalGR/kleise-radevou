<?php

declare(strict_types=1);

namespace App\Application\Map;

/**
 * The business page's map image: a point to the bytes of one image of the streets around it
 * with the point marked, or null where none came back. The bytes are stored and served from the
 * tenant's own origin, so the customer's browser never asks the provider for anything. The HTTP
 * client behind it lives under app/Infrastructure, and a test binds a map of its own scripted
 * to answer.
 */
interface StaticMap
{
    public function image(Coordinates $point): ?string;
}
