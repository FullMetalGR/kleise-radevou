<?php

declare(strict_types=1);

namespace App\Infrastructure\Map;

use App\Application\Map\Coordinates;
use App\Application\Map\Geocoder;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * The geocoder over Google's Geocoding API and the framework's HTTP client: one GET with the
 * address lines joined by commas and the key, which Google takes in the query string, given up
 * after TIMEOUT seconds, connecting included. The point is the first result's location, read
 * from an answer whose status is OK.
 *
 * Every other ending is null. A ZERO_RESULTS answer is a miss and logs nothing here, the job that
 * asked logging its own line. An endpoint that cannot be reached, a timeout included, logs
 * UNREACHABLE and nothing of the exception, whose text carries the URL and so the key; any other
 * answer, a refusal such as REQUEST_DENIED or OVER_QUERY_LIMIT, a server error, or an OK with no
 * usable location, logs REFUSED with the HTTP status and Google's status word where it is one of
 * the documented words, and never the error message beside it. An unset key, or no lines, asks
 * nothing.
 */
final readonly class GoogleGeocoder implements Geocoder
{
    public const string ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    public const int TIMEOUT = 5;

    public const string UNREACHABLE = 'The geocoder could not reach Google; the map was not made.';

    public const string REFUSED = 'The geocoder had no usable answer from Google; the map was not made.';

    /** The status words Google's Geocoding API documents, the only ones a log line repeats. */
    private const array STATUSES = ['OK', 'ZERO_RESULTS', 'OVER_DAILY_LIMIT', 'OVER_QUERY_LIMIT', 'REQUEST_DENIED', 'INVALID_REQUEST', 'UNKNOWN_ERROR'];

    public function __construct(private Factory $http, private GoogleMapsKey $key, private LoggerInterface $log) {}

    public function locate(array $lines): ?Coordinates
    {
        $key = $this->key->value();

        if ($key === null || $lines === []) {
            return null;
        }

        try {
            $response = $this->http->acceptJson()
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::TIMEOUT)
                ->get(self::ENDPOINT, ['address' => implode(', ', $lines), 'key' => $key]);
        } catch (ConnectionException|TransferException) {
            $this->log->warning(self::UNREACHABLE);

            return null;
        }

        $status = $response->successful() ? $response->json('status') : null;

        if ($status === 'ZERO_RESULTS') {
            return null;
        }

        $lat = $response->json('results.0.geometry.location.lat');
        $lng = $response->json('results.0.geometry.location.lng');

        if ($status === 'OK' && (is_float($lat) || is_int($lat)) && (is_float($lng) || is_int($lng))) {
            try {
                return new Coordinates((float) $lat, (float) $lng);
            } catch (InvalidArgumentException) {
                // A pair off the globe is no answer, logged below as one.
            }
        }

        $this->log->warning(self::REFUSED, [
            'status' => $response->status(),
            'answer' => in_array($status, self::STATUSES, true) ? $status : null,
        ]);

        return null;
    }
}
