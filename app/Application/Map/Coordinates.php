<?php

declare(strict_types=1);

namespace App\Application\Map;

use InvalidArgumentException;

/**
 * A point on the map, in degrees: the latitude from -90 to 90 and the longitude from -180 to
 * 180, as the geocoder answers them and the settings row stores them. A pair outside those
 * ranges is no point anywhere and is refused here, so nothing downstream draws one.
 */
final readonly class Coordinates
{
    public function __construct(public float $lat, public float $lng)
    {
        if (! is_finite($lat) || $lat < -90.0 || $lat > 90.0 || ! is_finite($lng) || $lng < -180.0 || $lng > 180.0) {
            throw new InvalidArgumentException(sprintf('A point is a latitude from -90 to 90 and a longitude from -180 to 180, not %F, %F.', $lat, $lng));
        }
    }

    /** The pair as a maps URL and a static map spell it, latitude first, a comma between. */
    public function spelled(): string
    {
        return self::degrees($this->lat).','.self::degrees($this->lng);
    }

    /** A degree value with no exponent and no trailing zeros, to seven places, about a centimetre. */
    private static function degrees(float $value): string
    {
        $fixed = rtrim(rtrim(sprintf('%.7F', $value), '0'), '.');

        return $fixed === '-0' ? '0' : $fixed;
    }
}
