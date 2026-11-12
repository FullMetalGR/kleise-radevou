<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Map\Coordinates;
use App\Application\Map\Geocoder;

/**
 * The business page's geocoder in a test: scripted to answer one point, or null, for every
 * address it is asked about, keeping each address's lines, so a test can say it ran once, on
 * what, or not at all. It opens no connection to anywhere.
 */
final class FakeGeocoder implements Geocoder
{
    /** @var list<list<string>> */
    public array $asked = [];

    public function __construct(public ?Coordinates $answer) {}

    public function locate(array $lines): ?Coordinates
    {
        $this->asked[] = $lines;

        return $this->answer;
    }
}
