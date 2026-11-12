<?php

declare(strict_types=1);

namespace App\Infrastructure\Map;

use App\Application\Map\Coordinates;
use App\Application\Map\StaticMap;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Psr\Log\LoggerInterface;

/**
 * The map image over Google's Static Maps API and the framework's HTTP client: one GET for a
 * PNG of SIZE at ZOOM centred on the point with the point marked, the key in the query string as
 * Google takes it, given up after TIMEOUT seconds, connecting included. The image is the body of
 * a successful answer typed image/png.
 *
 * Every other ending is null. An endpoint that cannot be reached, a timeout included, logs
 * UNREACHABLE and nothing of the exception, whose text carries the URL and so the key; an
 * answer refused, a 403 for a key Google will not serve or a 400 for a request it cannot
 * draw, or one that is no PNG, logs NO_IMAGE with the HTTP status alone, never the body, which
 * is Google's own sentence. An unset key asks nothing.
 */
final readonly class GoogleStaticMap implements StaticMap
{
    public const string ENDPOINT = 'https://maps.googleapis.com/maps/api/staticmap';

    public const int TIMEOUT = 5;

    public const string SIZE = '640x320';

    public const int ZOOM = 15;

    public const string UNREACHABLE = 'The map image could not reach Google; the map was not made.';

    public const string NO_IMAGE = 'The map image had no PNG from Google; the map was not made.';

    public function __construct(private Factory $http, private GoogleMapsKey $key, private LoggerInterface $log) {}

    public function image(Coordinates $point): ?string
    {
        $key = $this->key->value();

        if ($key === null) {
            return null;
        }

        try {
            $response = $this->http->accept('image/png')
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::TIMEOUT)
                ->get(self::ENDPOINT, ['center' => $point->spelled(), 'zoom' => self::ZOOM, 'size' => self::SIZE, 'markers' => $point->spelled(), 'key' => $key]);
        } catch (ConnectionException|TransferException) {
            $this->log->warning(self::UNREACHABLE);

            return null;
        }

        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        $body = $response->body();

        if (! $response->successful() || $type !== 'image/png' || $body === '') {
            $this->log->warning(self::NO_IMAGE, ['status' => $response->status()]);

            return null;
        }

        return $body;
    }
}
