<?php

declare(strict_types=1);

use App\Application\Map\Coordinates;
use App\Application\Map\Geocoder;
use App\Application\Map\StaticMap;
use App\Infrastructure\Map\GoogleGeocoder;
use App\Infrastructure\Map\GoogleStaticMap;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Tests\Support\SeamLog;

/*
 * The map's two adapters: one GET each to Google's Geocoding and Static Maps APIs carrying the
 * key, read from configuration at the call, given up after five seconds. The answers are the
 * documented shapes: a geocode's status word beside its results, a map's PNG body or Google's
 * refusal sentence. The framework's client is faked with stray requests refused, so no case
 * opens a connection, and no line any case logs carries the key.
 */

const GOOGLE_MAPS_TEST_KEY = 'AIzaSyMapsKeyNeverShownInALogLine';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.google.maps_key' => GOOGLE_MAPS_TEST_KEY]);
    $this->log = new SeamLog;
    app()->instance(LoggerInterface::class, $this->log);
});

afterEach(function () {
    expect($this->log->text())->not->toContain(GOOGLE_MAPS_TEST_KEY);
});

/**
 * Fakes one endpoint, any query string, with one answer, keeping the options the request was
 * sent with.
 *
 * @param  array<string, mixed>  $options
 * @param  array<array-key, mixed>|string  $body
 * @param  array<string, string>  $headers
 */
function googleMapsAnswers(string $endpoint, array|string $body, array &$options, int $status = 200, array $headers = []): void
{
    Http::fake([$endpoint.'*' => function (Request $request, array $sent) use ($body, $status, $headers, &$options): PromiseInterface {
        $options = $sent;

        return Http::response($body, $status, $headers);
    }]);
}

/**
 * The query string a faked request carried, by name.
 *
 * @return array<array-key, mixed>
 */
function googleMapsQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

const GOOGLE_MAPS_LINES = ['Odos Ermou 12', '10563 Athens', 'GR'];

it('answers the geocoder and the map with the Google adapters', function () {
    expect(get_class(app()->make(Geocoder::class)))->toBe(GoogleGeocoder::class)
        ->and(get_class(app()->make(StaticMap::class)))->toBe(GoogleStaticMap::class);
});

it('geocodes an address to the first result\'s location, asking once with the lines and the key inside five seconds', function () {
    $options = [];
    googleMapsAnswers(GoogleGeocoder::ENDPOINT, [
        'results' => [
            ['formatted_address' => 'Ermou 12, Athina 105 63, Greece', 'geometry' => ['location' => ['lat' => 37.9763295, 'lng' => 23.7323814], 'location_type' => 'ROOFTOP'], 'place_id' => 'ChIJ-placeholder', 'types' => ['street_address']],
            ['formatted_address' => 'Ermou, Athina, Greece', 'geometry' => ['location' => ['lat' => 37.9, 'lng' => 23.7]], 'types' => ['route']],
        ],
        'status' => 'OK',
    ], $options);

    $point = app(GoogleGeocoder::class)->locate(GOOGLE_MAPS_LINES);

    expect($point)->toEqual(new Coordinates(37.9763295, 23.7323814));
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json?')
        && googleMapsQuery($request) === ['address' => 'Odos Ermou 12, 10563 Athens, GR', 'key' => GOOGLE_MAPS_TEST_KEY]);
    expect($options['timeout'] ?? null)->toBe(5)
        ->and($options['connect_timeout'] ?? null)->toBe(5)
        ->and($this->log->records)->toBe([]);
});

it('answers null for an address Google finds nothing at, logging nothing', function () {
    $options = [];
    googleMapsAnswers(GoogleGeocoder::ENDPOINT, ['results' => [], 'status' => 'ZERO_RESULTS'], $options);

    expect(app(GoogleGeocoder::class)->locate(GOOGLE_MAPS_LINES))->toBeNull()
        ->and($this->log->records)->toBe([]);
    Http::assertSentCount(1);
});

it('answers null for a geocode Google refuses or cannot use, logging one line with the status and the status word and nothing of the message', function (int $status, array $body, ?string $word) {
    $options = [];
    googleMapsAnswers(GoogleGeocoder::ENDPOINT, $body, $options, $status);

    expect(app(GoogleGeocoder::class)->locate(GOOGLE_MAPS_LINES))->toBeNull()
        ->and($this->log->records)->toBe([['level' => 'warning', 'message' => GoogleGeocoder::REFUSED, 'context' => ['status' => $status, 'answer' => $word]]])
        ->and($this->log->text())->not->toContain('API key');
})->with([
    'a refused key' => [200, ['error_message' => 'The provided API key is invalid. key='.GOOGLE_MAPS_TEST_KEY, 'results' => [], 'status' => 'REQUEST_DENIED'], 'REQUEST_DENIED'],
    'a spent quota' => [200, ['error_message' => 'You have exceeded your daily request quota for this API.', 'results' => [], 'status' => 'OVER_QUERY_LIMIT'], 'OVER_QUERY_LIMIT'],
    'a server error' => [500, [], null],
    'an OK with no location' => [200, ['results' => [['geometry' => []]], 'status' => 'OK'], 'OK'],
    'an OK off the globe' => [200, ['results' => [['geometry' => ['location' => ['lat' => 91.0, 'lng' => 23.7]]]], 'status' => 'OK'], 'OK'],
    'a status Google never documents' => [200, ['results' => [], 'status' => GOOGLE_MAPS_TEST_KEY], null],
]);

it('answers null when the geocoder cannot reach Google, a timeout included, logging one line with no key and nothing of the exception', function () {
    Http::fake([GoogleGeocoder::ENDPOINT.'*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds for '.GoogleGeocoder::ENDPOINT.'?key='.GOOGLE_MAPS_TEST_KEY)]);

    expect(app(GoogleGeocoder::class)->locate(GOOGLE_MAPS_LINES))->toBeNull()
        ->and($this->log->records)->toBe([['level' => 'warning', 'message' => GoogleGeocoder::UNREACHABLE, 'context' => []]]);
});

it('fetches the map as a PNG of 640 by 320 at zoom 15 marking the point, asking once with the key inside five seconds', function () {
    $png = UploadedFile::fake()->image('map.png', 640, 320)->getContent();
    $options = [];
    googleMapsAnswers(GoogleStaticMap::ENDPOINT, $png, $options, 200, ['Content-Type' => 'image/png']);

    expect(app(GoogleStaticMap::class)->image(new Coordinates(37.9763295, 23.7323814)))->toBe($png);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/staticmap?')
        && googleMapsQuery($request) === ['center' => '37.9763295,23.7323814', 'zoom' => '15', 'size' => '640x320', 'markers' => '37.9763295,23.7323814', 'key' => GOOGLE_MAPS_TEST_KEY]);
    expect($options['timeout'] ?? null)->toBe(5)
        ->and($options['connect_timeout'] ?? null)->toBe(5)
        ->and($this->log->records)->toBe([]);
});

it('answers null for a map Google refuses or that is no PNG, logging one line with the status alone', function (int $status, string $body, string $type) {
    $options = [];
    googleMapsAnswers(GoogleStaticMap::ENDPOINT, $body, $options, $status, ['Content-Type' => $type]);

    expect(app(GoogleStaticMap::class)->image(new Coordinates(37.97, 23.73)))->toBeNull()
        ->and($this->log->records)->toBe([['level' => 'warning', 'message' => GoogleStaticMap::NO_IMAGE, 'context' => ['status' => $status]]]);
})->with([
    'a refused key' => [403, 'The Google Maps Platform server rejected your request. The provided API key is invalid. '.GOOGLE_MAPS_TEST_KEY, 'text/plain; charset=UTF-8'],
    'a request it cannot draw' => [400, 'The Google Maps Platform server rejected your request. Invalid request. Invalid \'center\' parameter.', 'text/plain; charset=UTF-8'],
    'an answer that is no image' => [200, '<html><body>Something else</body></html>', 'text/html'],
    'an empty PNG' => [200, '', 'image/png'],
]);

it('answers null when the map cannot reach Google, a timeout included, logging one line with no key and nothing of the exception', function () {
    Http::fake([GoogleStaticMap::ENDPOINT.'*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds for '.GoogleStaticMap::ENDPOINT.'?key='.GOOGLE_MAPS_TEST_KEY)]);

    expect(app(GoogleStaticMap::class)->image(new Coordinates(37.97, 23.73)))->toBeNull()
        ->and($this->log->records)->toBe([['level' => 'warning', 'message' => GoogleStaticMap::UNREACHABLE, 'context' => []]]);
});

it('asks Google nothing while the key is unset', function (?string $key) {
    config(['services.google.maps_key' => $key]);

    expect(app(GoogleGeocoder::class)->locate(GOOGLE_MAPS_LINES))->toBeNull()
        ->and(app(GoogleStaticMap::class)->image(new Coordinates(37.97, 23.73)))->toBeNull()
        ->and($this->log->records)->toBe([]);
    Http::assertNothingSent();
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('asks nothing for an address with no lines', function () {
    expect(app(GoogleGeocoder::class)->locate([]))->toBeNull();
    Http::assertNothingSent();
});

it('spells a point with no exponent and no trailing zeros, latitude first', function (float $lat, float $lng, string $spelled) {
    expect((new Coordinates($lat, $lng))->spelled())->toBe($spelled);
})->with([
    'Athens' => [37.9763295, 23.7323814, '37.9763295,23.7323814'],
    'round degrees' => [38.0, -9.0, '38,-9'],
    'near the meridian' => [51.4779, -0.0000001, '51.4779,-0.0000001'],
    'a tiny negative that rounds to zero' => [0.0, -0.00000001, '0,0'],
]);

it('refuses a point off the globe', function (float $lat, float $lng) {
    new Coordinates($lat, $lng);
})->with([
    'a latitude past the pole' => [90.5, 0.0],
    'a longitude past the antimeridian' => [0.0, -180.5],
    'not a number' => [NAN, 0.0],
])->throws(InvalidArgumentException::class);
