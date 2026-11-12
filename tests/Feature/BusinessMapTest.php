<?php

declare(strict_types=1);

use App\Application\Map\Coordinates;
use App\Application\Map\Geocoder;
use App\Application\Map\MapFileNotDeleted;
use App\Application\Map\MapNotMade;
use App\Application\Map\StaticMap;
use App\Console\Commands\BackfillMapsCommand;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\ImagePathRefused;
use App\Infrastructure\Storage\TenantImages;
use App\Jobs\PrepareMap;
use App\Models\User;
use App\Tenancy\MapState;
use App\Tenancy\Profile;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantStatus;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeDisks;
use Tests\Support\FakeGeocoder;
use Tests\Support\FakeStaticMap;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The business page's map: the settings save that changes an address column writes pending and
 * queues one job after its commit, carrying the tenant's id alone; the job re-reads, geocodes the
 * address as stored, fetches the map at the point, stores it through the image store as the map's
 * kind and settles the row in one write, or writes failed with the reason logged, dropping the
 * map the row held. Nothing thrown past the re-read leaves the job: the throw is met by a failed
 * settle and reported once as a map not made, saying whether the row settled failed, had moved
 * on, or, the failed settle refused too, stands as the save left it; an image that will not
 * delete is reported apart and left. The two ports are met through their scripted fakes and stray
 * requests are refused, so nothing here opens a connection. None of these writes enters the
 * transaction runner, so the suite's transaction wraps each test.
 */

const BUSINESS_MAP_KEY = 'AIzaSyMapsKeyOfTheSaveTests';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->withoutVite();
    seedDemo();
    FakeDisks::fake(TenantImages::DISK);
    config(['services.google.maps_key' => BUSINESS_MAP_KEY]);
    $this->geocoder = new FakeGeocoder(new Coordinates(37.9763295, 23.7323814));
    $this->map = FakeStaticMap::drawing();
    app()->instance(Geocoder::class, $this->geocoder);
    app()->instance(StaticMap::class, $this->map);
    $this->log = new SeamLog;
    app()->instance(LoggerInterface::class, $this->log);
});

afterEach(function () {
    expect($this->log->text())->not->toContain(BUSINESS_MAP_KEY);
});

function mapTenant(): Tenant
{
    return Tenant::query()->where('slug', 'luna')->sole();
}

/**
 * The whole settings patch with the address given and every other field as the page sends it.
 *
 * @param  array<mixed>  $address
 * @return TestResponse<Response>
 */
function mapSave(TestCase $test, array $address = []): TestResponse
{
    return $test->actingAs(User::query()->where('email', 'owner@luna.example')->sole())
        ->from(tenantUrl('luna', '/admin/settings'))
        ->json('PUT', tenantUrl('luna', '/admin/settings'), [
            'currency' => 'EUR', 'theme' => 'violet', 'checkout_ttl_min' => 15, 'approval_ttl_hours' => 48, 'payment_ttl_hours' => 24, 'reminder_lead_hours' => 24, 'start_interval_min' => 15, 'emails_off' => [],
            ...array_fill_keys(Profile::FIELDS, ''), 'country' => 'GR', 'about' => 'Hair, nails and quiet.',
            ...$address,
        ], ['X-Inertia' => 'true']);
}

/**
 * The settings row's map columns as the database holds them.
 *
 * @return array{lat: float|null, lng: float|null, map_path: string|null, map_state: string}
 */
function mapRow(): array
{
    $row = settingsOf('luna')->first(['lat', 'lng', 'map_path', 'map_state']);
    assert($row !== null && ($row->lat === null || is_float($row->lat)) && ($row->lng === null || is_float($row->lng))
        && ($row->map_path === null || is_string($row->map_path)) && is_string($row->map_state));

    return ['lat' => $row->lat, 'lng' => $row->lng, 'map_path' => $row->map_path, 'map_state' => $row->map_state];
}

const BUSINESS_MAP_ADDRESS = ['address_line1' => 'Ermou 12', 'city' => 'Athens', 'postal_code' => '10563'];

/**
 * A map standing on the row as a settled job leaves one, at the address above: a drawn map image
 * stored through the store as the map's kind, its point, its path and ready. Answers the path.
 */
function standingMap(): string
{
    $path = app(TenantImages::class)->store(mapTenant(), ImageKind::Map, UploadedFile::fake()->image('map.png', 640, 320));
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'lat' => 37.9, 'lng' => 23.7, 'map_path' => $path, 'map_state' => 'ready']);

    return $path;
}

it('writes pending and queues one job carrying the tenant\'s id alone when a save changes an address column', function (array $address) {
    Queue::fake();

    mapSave($this, $address)->assertRedirect(tenantUrl('luna', '/admin/settings'));

    expect(mapRow())->toBe(['lat' => null, 'lng' => null, 'map_path' => null, 'map_state' => 'pending']);
    Queue::assertPushed(PrepareMap::class, 1);
    Queue::assertPushed(PrepareMap::class, fn (PrepareMap $job): bool => $job->tenantId === mapTenant()->id);
    expect(array_map(fn (ReflectionParameter $carried): string => $carried->getName(), (new ReflectionMethod(PrepareMap::class, '__construct'))->getParameters()))->toBe(['tenantId']);
})->with([
    'a whole address' => [BUSINESS_MAP_ADDRESS],
    'a city alone' => [['city' => 'Athens']],
    'a second street line beside a postal code' => [['postal_code' => '10563', 'address_line2' => 'Floor 2']],
]);

it('writes pending when only the country of a standing address changes', function () {
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'map_state' => 'ready']);
    Queue::fake();

    mapSave($this, [...BUSINESS_MAP_ADDRESS, 'country' => 'CY']);

    expect(mapRow()['map_state'])->toBe('pending');
    Queue::assertPushed(PrepareMap::class, 1);
});

it('touches none of the map and queues nothing when a save changes no address column', function () {
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'lat' => 37.9, 'lng' => 23.7, 'map_path' => 'tenants/luna/map-0123456789abcdef.png', 'map_state' => 'ready']);
    Queue::fake();

    mapSave($this, BUSINESS_MAP_ADDRESS)->assertRedirect(tenantUrl('luna', '/admin/settings'));

    expect(mapRow())->toBe(['lat' => 37.9, 'lng' => 23.7, 'map_path' => 'tenants/luna/map-0123456789abcdef.png', 'map_state' => 'ready']);
    Queue::assertNotPushed(PrepareMap::class);
});

it('writes none and queues nothing when a save changes the address while the key is unset', function (?string $key) {
    config(['services.google.maps_key' => $key]);
    Queue::fake();

    mapSave($this, BUSINESS_MAP_ADDRESS);

    expect(mapRow()['map_state'])->toBe('none');
    Queue::assertNotPushed(PrepareMap::class);
})->with(['null' => [null], 'empty' => ['']]);

it('writes none and queues nothing when a save empties the address, a country alone being no address', function () {
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'map_state' => 'ready']);
    Queue::fake();

    mapSave($this);

    expect(mapRow()['map_state'])->toBe('none');
    Queue::assertNotPushed(PrepareMap::class);
});

it('makes the map on the queue after the save: the address as stored geocoded, the map fetched at the point, the image stored as the map\'s kind, the pair, the path and ready in one write', function () {
    mapSave($this, BUSINESS_MAP_ADDRESS)->assertRedirect(tenantUrl('luna', '/admin/settings'));

    $row = mapRow();

    expect($this->geocoder->asked)->toBe([['Ermou 12', '10563 Athens', 'GR']])
        ->and($this->map->asked)->toEqual([new Coordinates(37.9763295, 23.7323814)])
        ->and($row['lat'])->toBe(37.9763295)
        ->and($row['lng'])->toBe(23.7323814)
        ->and($row['map_state'])->toBe('ready')
        ->and($row['map_path'])->toMatch('/\Atenants\/luna\/map-[0-9a-f]{16}\.png\z/')
        ->and(getimagesizefromstring((string) Storage::disk(TenantImages::DISK)->get($row['map_path'] ?? ''))[0] ?? null)->toBe(640)
        ->and($this->log->records)->toBe([]);

    $settings = app(SettingsReader::class)->read(mapTenant());

    expect([$settings->lat, $settings->lng, $settings->mapPath, $settings->mapState])->toBe([37.9763295, 23.7323814, $row['map_path'], MapState::Ready]);
});

it('replaces the map on the next address change, deleting the image it replaced', function () {
    mapSave($this, BUSINESS_MAP_ADDRESS);
    $first = mapRow()['map_path'];
    $this->geocoder->answer = new Coordinates(40.6401, 22.9444);
    $this->map->answer = UploadedFile::fake()->image('map.png', 640, 321)->getContent();

    mapSave($this, [...BUSINESS_MAP_ADDRESS, 'city' => 'Thessaloniki']);

    $row = mapRow();

    expect($row['map_state'])->toBe('ready')
        ->and($row['lat'])->toBe(40.6401)
        ->and($row['map_path'])->not->toBe($first)
        ->and(Storage::disk(TenantImages::DISK)->exists($row['map_path'] ?? ''))->toBeTrue()
        ->and(Storage::disk(TenantImages::DISK)->exists($first ?? ''))->toBeFalse();
});

it('keeps the image when the next address draws the same map, the same bytes naming the same file', function () {
    mapSave($this, BUSINESS_MAP_ADDRESS);
    $first = mapRow()['map_path'];

    mapSave($this, [...BUSINESS_MAP_ADDRESS, 'address_line2' => 'Floor 2']);

    expect(mapRow()['map_path'])->toBe($first)
        ->and(Storage::disk(TenantImages::DISK)->exists($first ?? ''))->toBeTrue();
});

it('writes failed with no reason stored and the reason logged against the tenant when the geocoder finds no point, asking the map nothing, and drops the standing map with its file and its point', function () {
    $standing = standingMap();
    $this->geocoder->answer = null;

    mapSave($this, [...BUSINESS_MAP_ADDRESS, 'city' => 'Piraeus']);

    expect(mapRow())->toBe(['lat' => null, 'lng' => null, 'map_path' => null, 'map_state' => 'failed'])
        ->and($this->map->asked)->toBe([])
        ->and($this->log->records)->toBe([['level' => 'warning', 'message' => PrepareMap::NO_POINT, 'context' => ['tenant' => mapTenant()->id]]])
        ->and(Storage::disk(TenantImages::DISK)->exists($standing))->toBeFalse()
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
});

it('writes failed with the reason logged when no map image comes for the point, or what comes is no image the store takes, dropping the standing map with its file', function (?string $bytes, string $reason) {
    standingMap();
    $this->map->answer = $bytes;

    mapSave($this, [...BUSINESS_MAP_ADDRESS, 'city' => 'Piraeus']);

    expect(mapRow())->toBe(['lat' => null, 'lng' => null, 'map_path' => null, 'map_state' => 'failed'])
        ->and($this->log->records)->toBe([['level' => 'warning', 'message' => $reason, 'context' => ['tenant' => mapTenant()->id]]])
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
})->with([
    'no image' => [null, PrepareMap::NO_IMAGE],
    'bytes that are no image' => ['<html>not a map</html>', PrepareMap::REFUSED],
]);

it('drops the standing map with its file and its point when a save writes none: the key unset, or the address emptied', function (?string $key, array $address) {
    $standing = standingMap();
    config(['services.google.maps_key' => $key]);
    Queue::fake();

    mapSave($this, $address)->assertRedirect(tenantUrl('luna', '/admin/settings'));

    expect(mapRow())->toBe(['lat' => null, 'lng' => null, 'map_path' => null, 'map_state' => 'none'])
        ->and(Storage::disk(TenantImages::DISK)->exists($standing))->toBeFalse()
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
    Queue::assertNotPushed(PrepareMap::class);
})->with([
    'the key unset and the city changed' => [null, [...BUSINESS_MAP_ADDRESS, 'city' => 'Piraeus']],
    'the key set and the address emptied' => [BUSINESS_MAP_KEY, []],
]);

it('settles the row failed, drops the standing map and reports the throw as a map not made, naming the tenant and the class and never the message, when either port throws', function (string $port) {
    Exceptions::fake();
    $standing = standingMap();
    $throwing = new class implements Geocoder, StaticMap
    {
        public function locate(array $lines): never
        {
            throw new RuntimeException('Google answered for key='.BUSINESS_MAP_KEY);
        }

        public function image(Coordinates $point): never
        {
            throw new RuntimeException('Google answered for key='.BUSINESS_MAP_KEY);
        }
    };
    app()->instance($port, $throwing);

    mapSave($this, [...BUSINESS_MAP_ADDRESS, 'city' => 'Piraeus'])->assertRedirect(tenantUrl('luna', '/admin/settings'));

    expect(mapRow())->toBe(['lat' => null, 'lng' => null, 'map_path' => null, 'map_state' => 'failed'])
        ->and(Storage::disk(TenantImages::DISK)->exists($standing))->toBeFalse()
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (MapNotMade $report): bool => $report->getMessage() === sprintf('The map of tenant %d was not made, its job throwing %s; the row is settled failed, which map:backfill or a save changing the address queues again.', mapTenant()->id, RuntimeException::class)
        && $report->getPrevious() === null);
})->with(['the geocoder' => [Geocoder::class], 'the map image' => [StaticMap::class]]);

/**
 * Refuses the map's settling statements, the ready write's and the failed write's, with each throw
 * given in turn, and lets every later statement through; the throws not yet met are the answer's.
 *
 * @return SplQueue<Throwable>
 */
function refuseMapSettles(Throwable ...$refusals): SplQueue
{
    /** @var SplQueue<Throwable> $left */
    $left = new SplQueue;

    foreach ($refusals as $refusal) {
        $left->enqueue($refusal);
    }

    DB::connection()->beforeExecuting(function (string $query, array $bindings) use ($left): void {
        if (! $left->isEmpty() && str_starts_with($query, 'update') && (in_array(MapState::Ready->value, $bindings, true) || in_array(MapState::Failed->value, $bindings, true))) {
            throw $left->dequeue();
        }
    });

    return $left;
}

it('deletes the image it stored and settles the row failed when the settling write throws, reporting the throw once', function () {
    Exceptions::fake();
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'map_state' => 'pending']);
    $left = refuseMapSettles(new RuntimeException('The settling statement failed.'));

    app()->call([new PrepareMap(mapTenant()->id), 'handle']);

    expect($left->isEmpty())->toBeTrue()
        ->and(mapRow())->toBe(['lat' => null, 'lng' => null, 'map_path' => null, 'map_state' => 'failed'])
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(MapNotMade::class);
});

it('lets nothing leave the job when the failed settle is refused too, reporting one map not made naming both classes; the row stays pending as the save left it, the image it stored kept, and map:backfill then makes the map', function () {
    Exceptions::fake();
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'map_state' => 'pending']);
    $left = refuseMapSettles(new RuntimeException('The ready statement failed.'), new UnexpectedValueException('The failed statement failed.'));

    app()->call([new PrepareMap(mapTenant()->id), 'handle']);

    $kept = Storage::disk(TenantImages::DISK)->allFiles();

    expect($left->isEmpty())->toBeTrue()
        ->and(mapRow())->toBe(['lat' => null, 'lng' => null, 'map_path' => null, 'map_state' => 'pending'])
        ->and($kept)->toHaveCount(1);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (MapNotMade $report): bool => $report->getMessage() === sprintf('The map of tenant %d was not made, its job throwing %s and its failed settle %s; no write of the job\'s reached the row, so the row is as the last save left it, any image the job stored is kept, and map:backfill queues the row again where it still waits pending.', mapTenant()->id, RuntimeException::class, UnexpectedValueException::class)
        && $report->getPrevious() === null);

    backfillMaps();

    expect(mapRow()['map_state'])->toBe('ready')
        ->and(mapRow()['map_path'])->toBe($kept[0] ?? null)
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe($kept);
});

it('writes nothing and says so when a save moves the address while the job is asking Google and the port then throws', function () {
    Exceptions::fake();
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'map_state' => 'pending']);
    app()->instance(Geocoder::class, new class implements Geocoder
    {
        public function locate(array $lines): never
        {
            settingsOf('luna')->update(['city' => 'Piraeus']);

            throw new RuntimeException('Google answered for key='.BUSINESS_MAP_KEY);
        }
    });

    app()->call([new PrepareMap(mapTenant()->id), 'handle']);

    expect(mapRow())->toBe(['lat' => null, 'lng' => null, 'map_path' => null, 'map_state' => 'pending']);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (MapNotMade $report): bool => $report->getMessage() === sprintf('The map of tenant %d was not made, its job throwing %s; nothing was written, a later write having moved the row on since the job read it.', mapTenant()->id, RuntimeException::class));
});

it('reports an image the row no longer names that will not delete as a map image not deleted, the map made and settled ready and nothing leaving the job', function () {
    Exceptions::fake();
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'map_path' => 'tenants/pier/map-0123456789abcdef.png', 'map_state' => 'pending']);

    app()->call([new PrepareMap(mapTenant()->id), 'handle']);

    $row = mapRow();

    expect($row['map_state'])->toBe('ready')
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([$row['map_path']]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertNotReported(MapNotMade::class);
    Exceptions::assertReported(fn (MapFileNotDeleted $report): bool => $report->getMessage() === sprintf('The map job of tenant %d could not delete an image its row no longer names, failing with %s; the image is left on the disk and the row is as the job\'s settle left it.', mapTenant()->id, ImagePathRefused::class)
        && $report->getPrevious() === null);
});

it('still deletes the image it stored when the image the row held will not delete, reporting each throw once', function () {
    Exceptions::fake();
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'map_path' => 'tenants/pier/map-0123456789abcdef.png', 'map_state' => 'pending']);
    refuseMapSettles(new RuntimeException('The settling statement failed.'));

    app()->call([new PrepareMap(mapTenant()->id), 'handle']);

    expect(mapRow())->toBe(['lat' => null, 'lng' => null, 'map_path' => null, 'map_state' => 'failed'])
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(MapNotMade::class);
    Exceptions::assertReported(MapFileNotDeleted::class);
});

it('exits without writing or asking anything when the row is no longer pending, a later save having moved it', function (string $state) {
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'map_state' => $state]);

    app()->call([new PrepareMap(mapTenant()->id), 'handle']);

    expect(mapRow()['map_state'])->toBe($state)
        ->and($this->geocoder->asked)->toBe([])
        ->and($this->map->asked)->toBe([]);
})->with(['none', 'ready', 'failed']);

it('writes nothing and keeps no file when a save moves the address while the job is asking Google', function () {
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'map_state' => 'pending']);
    $moving = new class(new Coordinates(37.9763295, 23.7323814)) implements Geocoder
    {
        public function __construct(private Coordinates $point) {}

        public function locate(array $lines): Coordinates
        {
            settingsOf('luna')->update(['city' => 'Piraeus']);

            return $this->point;
        }
    };
    app()->instance(Geocoder::class, $moving);

    app()->call([new PrepareMap(mapTenant()->id), 'handle']);

    expect(mapRow())->toBe(['lat' => null, 'lng' => null, 'map_path' => null, 'map_state' => 'pending'])
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([]);
});

it('exits without writing for a tenant that has gone', function () {
    app()->call([new PrepareMap(999999), 'handle']);

    expect($this->geocoder->asked)->toBe([]);
});

/*
 * The command that writes the maps a row with an address lacks: every built tenant whose row
 * holds an address and no ready map, in none, failed, pending with no job coming or ready naming
 * no image, is moved to pending and its job queued, as a save that changed the address would. A
 * second run leaves every row as the first left it: a map made is skipped, a row failed again is
 * asked again and fails the same, and a second job of a row still pending exits on its re-read
 * once the first has settled it. A row still provisioning has no schema and is never read; a
 * suspended and a closing tenant are taken like an active one; and the command is scheduled
 * nowhere.
 */

/**
 * Runs the command and answers its exit code and everything it printed.
 *
 * @return array{int, string}
 */
function backfillMaps(): array
{
    $code = Artisan::call('map:backfill');

    return [$code, Artisan::output()];
}

it('queues the map of a row holding an address and no ready map, whatever its state, the job making it on the fake ports, and a second run queueing nothing', function (string $state) {
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'map_state' => $state]);

    [$code, $output] = backfillMaps();

    $row = mapRow();

    expect($code)->toBe(0)
        ->and($output)->toBe('Queued the map for 1 tenant.'."\n")
        ->and($this->geocoder->asked)->toBe([['Ermou 12', '10563 Athens', 'GR']])
        ->and($row['map_state'])->toBe('ready')
        ->and($row['lat'])->toBe(37.9763295)
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toBe([$row['map_path']]);

    [$code, $output] = backfillMaps();

    expect($code)->toBe(0)
        ->and($output)->toBe('Queued the map for 0 tenants.'."\n")
        ->and($this->geocoder->asked)->toHaveCount(1)
        ->and(mapRow())->toBe($row);
})->with([
    'none' => ['none'],
    'failed' => ['failed'],
    'pending, its job gone' => ['pending'],
    'ready naming no image' => ['ready'],
]);

it('leaves a row whose map stands ready and a row holding no address, a country alone being none, and queues nothing', function () {
    $standing = standingMap();
    settingsOf('pier')->update(['map_state' => 'failed']);
    provisionTenant('cove');
    Queue::fake();

    [$code, $output] = backfillMaps();

    expect($code)->toBe(0)
        ->and($output)->toBe(sprintf(BackfillMapsCommand::QUEUED, 0, 'tenants')."\n")
        ->and(mapRow())->toBe(['lat' => 37.9, 'lng' => 23.7, 'map_path' => $standing, 'map_state' => 'ready'])
        ->and(settingsOf('pier')->value('map_state'))->toBe('failed')
        ->and(settingsOf('cove')->value('map_state'))->toBe('none');
    Queue::assertNothingPushed();
});

it('asks again on a second run for a row its job left failed, the row standing failed as the first run left it', function () {
    settingsOf('luna')->update([...BUSINESS_MAP_ADDRESS, 'map_state' => 'failed']);
    $this->geocoder->answer = null;

    [, $first] = backfillMaps();
    $row = mapRow();
    [, $second] = backfillMaps();

    expect($first)->toBe(sprintf(BackfillMapsCommand::QUEUED, 1, 'tenant')."\n")
        ->and($second)->toBe($first)
        ->and($row)->toBe(['lat' => null, 'lng' => null, 'map_path' => null, 'map_state' => 'failed'])
        ->and(mapRow())->toBe($row)
        ->and($this->geocoder->asked)->toHaveCount(2);
});

it('moves each such row to pending and queues one job carrying the tenant\'s id alone; a second run before the jobs queues them again, and the jobs leave each row as one would', function () {
    $queue = Queue::fake();
    settingsOf('luna')->update(BUSINESS_MAP_ADDRESS);
    settingsOf('pier')->update(['postal_code' => 'BS1 4DJ']);

    backfillMaps();

    expect(mapRow()['map_state'])->toBe('pending')
        ->and(settingsOf('pier')->value('map_state'))->toBe('pending');
    Queue::assertPushed(PrepareMap::class, 2);
    Queue::assertPushed(PrepareMap::class, fn (PrepareMap $job): bool => $job->tenantId === mapTenant()->id);

    [$code, $output] = backfillMaps();

    expect($code)->toBe(0)->and($output)->toBe(sprintf(BackfillMapsCommand::QUEUED, 2, 'tenants')."\n");
    Queue::assertPushed(PrepareMap::class, 4);

    foreach ($queue->pushed(PrepareMap::class) as $job) {
        assert($job instanceof PrepareMap);
        app()->call([$job, 'handle']);
    }

    expect(mapRow()['map_state'])->toBe('ready')
        ->and(settingsOf('pier')->value('map_state'))->toBe('ready')
        ->and($this->geocoder->asked)->toHaveCount(2)
        ->and(Storage::disk(TenantImages::DISK)->allFiles())->toHaveCount(2);
});

it('skips a row still provisioning, which has no schema to read, and takes a suspended and a closing tenant like an active one', function () {
    settingsOf('luna')->update(BUSINESS_MAP_ADDRESS);
    settingsOf('pier')->update(['postal_code' => 'BS1 4DJ']);
    Tenant::query()->where('slug', 'luna')->update(['status' => TenantStatus::Suspended->value]);
    Tenant::query()->where('slug', 'pier')->update(['status' => TenantStatus::Closing->value]);
    $reserved = app(TenantProvisioner::class)->reserve(signupFor('delta', ['ownerEmail' => 'owner@delta.example']));
    Queue::fake();

    [$code, $output] = backfillMaps();

    expect($reserved->status)->toBe(TenantStatus::Provisioning)
        ->and(DB::table('pg_namespace')->where('nspname', 'tenant_delta')->exists())->toBeFalse()
        ->and($code)->toBe(0)
        ->and($output)->toBe(sprintf(BackfillMapsCommand::QUEUED, 2, 'tenants')."\n")
        ->and(mapRow()['map_state'])->toBe('pending')
        ->and(settingsOf('pier')->value('map_state'))->toBe('pending');
    Queue::assertPushed(PrepareMap::class, 2);
});

it('skips a closed row, whose schema the drop removed, and queues the tenant beside it', function () {
    settingsOf('luna')->update(BUSINESS_MAP_ADDRESS);
    writeClosed('pier');
    Queue::fake();

    [$code, $output] = backfillMaps();

    expect($code)->toBe(0)
        ->and($output)->toBe(sprintf(BackfillMapsCommand::QUEUED, 1, 'tenant')."\n")
        ->and(mapRow()['map_state'])->toBe('pending');
    Queue::assertPushed(PrepareMap::class, 1);
});

it('refuses in one line and writes nothing while the map\'s key is unset', function () {
    Queue::fake();
    config(['services.google.maps_key' => null]);
    settingsOf('luna')->update(BUSINESS_MAP_ADDRESS);

    [$code, $output] = backfillMaps();

    expect($code)->toBe(2)
        ->and($output)->toBe(BackfillMapsCommand::NO_KEY."\n")
        ->and(mapRow()['map_state'])->toBe('none');
    Queue::assertNothingPushed();
});

it('is registered and listed, and scheduled nowhere', function () {
    expect(Artisan::all())->toHaveKey('map:backfill');

    Artisan::call('list');

    expect(Artisan::output())->toContain('map:backfill');

    $scheduled = array_filter(
        app(Schedule::class)->events(),
        fn (Event $event): bool => str_contains($event->command ?? '', 'map:backfill'),
    );

    expect($scheduled)->toBe([]);
});
