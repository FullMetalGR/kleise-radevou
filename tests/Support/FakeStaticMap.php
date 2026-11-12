<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Map\Coordinates;
use App\Application\Map\StaticMap;
use Illuminate\Http\UploadedFile;

/**
 * The business page's map image in a test: scripted to answer the bytes of a real PNG at the
 * map's size, its own bytes, or null, for every point it is asked about, keeping each point, so a
 * test can say it ran once, on what, or not at all. It opens no connection to anywhere.
 */
final class FakeStaticMap implements StaticMap
{
    /** @var list<Coordinates> */
    public array $asked = [];

    public function __construct(public ?string $answer) {}

    /** A map answering a drawn PNG of 640 by 320, the size the Google adapter asks for. */
    public static function drawing(): self
    {
        return new self(UploadedFile::fake()->image('map.png', 640, 320)->getContent());
    }

    public function image(Coordinates $point): ?string
    {
        $this->asked[] = $point;

        return $this->answer;
    }
}
