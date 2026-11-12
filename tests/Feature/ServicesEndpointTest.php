<?php

use App\Http\ServicePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

pest()->use(RefreshDatabase::class);

/*
 * GET /api/v1/services and GET /api/v1/services/{id} on the wire: the shape, the order, what is
 * withheld, who is never listed, the one 404, and the cross-origin and search-path properties
 * the config endpoint's tests check. Both routes open no transaction, so they are tested here
 * inside the one this suite wraps each test in.
 */

function servicesUrl(string $slug, string $path = ''): string
{
    return tenantUrl($slug, '/api/v1/services'.$path);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedStaff(string $schema, string $name, array $overrides = []): int
{
    return DB::table($schema.'.staff')->insertGetId(['name' => $name, 'status' => 'active', ...$overrides]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedService(string $schema, string $name, array $overrides = []): int
{
    return DB::table($schema.'.services')->insertGetId(serviceRow($name, $overrides));
}

function seedAssignment(string $schema, int $serviceId, int $staffId): void
{
    DB::table($schema.'.service_staff')->insert(['service_id' => $serviceId, 'staff_id' => $staffId]);
}

/**
 * Everything about a response a caller can compare, less what differs between any two
 * requests: the session cookie, the date, and the rate limit's counters, which a request that
 * matched no route never passed through.
 *
 * @param  TestResponse<Response>  $response
 * @return array{int, array<string, mixed>, string|false}
 */
function responseFingerprint(TestResponse $response): array
{
    $headers = array_filter(
        $response->headers->all(),
        fn (string $name): bool => ! in_array($name, ['set-cookie', 'date'], true) && ! str_starts_with($name, 'x-ratelimit-'),
        ARRAY_FILTER_USE_KEY,
    );

    return [$response->status(), $headers, $response->getContent()];
}

it('answers the active services in sort order, each with exactly the nine keys, and the cap of five; the inactive service is absent', function () {
    provisionTenant('acme');
    $sam = seedStaff('tenant_acme', 'Sam', ['sort' => 2]);
    $alex = seedStaff('tenant_acme', 'Alex', ['sort' => 1]);
    $colour = seedService('tenant_acme', 'Colour', ['description' => 'Full head.', 'duration_min' => 90, 'price_minor' => 8000, 'sort' => 2, 'payment_mode' => 'online', 'processing_gap_min' => 30, 'requires_approval' => true, 'approval_hold_hours' => 12, 'buffer_after_min' => 10]);
    $cut = seedService('tenant_acme', 'Cut', ['duration_min' => 45, 'price_minor' => 3500, 'sort' => 1]);
    $perm = seedService('tenant_acme', 'Perm', ['status' => 'inactive', 'sort' => 0]);
    seedAssignment('tenant_acme', $colour, $sam);
    seedAssignment('tenant_acme', $cut, $sam);
    seedAssignment('tenant_acme', $cut, $alex);
    seedAssignment('tenant_acme', $perm, $alex);

    $response = $this->getJson(servicesUrl('acme'))->assertOk()->assertExactJson([
        'services' => [
            [
                'id' => $cut,
                'name' => 'Cut',
                'description' => '',
                'duration_min' => 45,
                'price_minor' => 3500,
                'currency' => 'EUR',
                'category' => null,
                'online' => true,
                'staff' => [['id' => $alex, 'name' => 'Alex', 'photo_url' => null, 'title' => null, 'bio' => null], ['id' => $sam, 'name' => 'Sam', 'photo_url' => null, 'title' => null, 'bio' => null]],
            ],
            [
                'id' => $colour,
                'name' => 'Colour',
                'description' => 'Full head.',
                'duration_min' => 90,
                'price_minor' => 8000,
                'currency' => 'EUR',
                'category' => null,
                'online' => true,
                'staff' => [['id' => $sam, 'name' => 'Sam', 'photo_url' => null, 'title' => null, 'bio' => null]],
            ],
        ],
        'max_chain_length' => 5,
    ]);

    expect(array_keys((array) $response->json()))->toBe(['services', 'max_chain_length'])
        ->and(array_keys((array) $response->json('services.0')))->toBe(['id', 'name', 'description', 'duration_min', 'price_minor', 'currency', 'category', 'online', 'staff']);

    $response->assertDontSee('Perm')->assertDontSee('payment_mode')->assertDontSee('processing_gap')->assertDontSee('requires_approval')->assertDontSee('buffer');
});

it('carries each row\'s own currency as an integer of minor units, never a formatted string', function () {
    provisionTenant('acme', ['currency' => 'JPY']);
    seedService('tenant_acme', 'Cut', ['currency' => 'JPY', 'price_minor' => 5000]);
    seedService('tenant_acme', 'Colour', ['currency' => 'EUR', 'price_minor' => 5000, 'sort' => 1]);

    $this->getJson(servicesUrl('acme'))->assertOk()
        ->assertJsonPath('services.0.price_minor', 5000)
        ->assertJsonPath('services.0.currency', 'JPY')
        ->assertJsonPath('services.1.price_minor', 5000)
        ->assertJsonPath('services.1.currency', 'EUR');
});

it('lists a service\'s active assigned staff and never the implicit member nor an inactive one', function () {
    provisionTenant('acme', ['name' => 'Acme Salon']);
    $implicit = implicitStaffId('tenant_acme');
    $sam = seedStaff('tenant_acme', 'Sam');
    $gone = seedStaff('tenant_acme', 'Gone', ['status' => 'inactive']);
    $cut = seedService('tenant_acme', 'Cut');
    $floor = seedService('tenant_acme', 'Floor only', ['sort' => 1]);
    seedService('tenant_acme', 'Unassigned', ['sort' => 2]);
    seedAssignment('tenant_acme', $cut, $implicit);
    seedAssignment('tenant_acme', $cut, $sam);
    seedAssignment('tenant_acme', $cut, $gone);
    seedAssignment('tenant_acme', $floor, $implicit);

    $this->getJson(servicesUrl('acme'))->assertOk()
        ->assertJsonPath('services.0.staff', [['id' => $sam, 'name' => 'Sam', 'photo_url' => null, 'title' => null, 'bio' => null]])
        ->assertJsonPath('services.1.staff', [])
        ->assertJsonPath('services.2.staff', [])
        ->assertDontSee('Acme Salon')
        ->assertDontSee('Gone');

    $this->getJson(servicesUrl('acme', '/'.$cut))->assertOk()
        ->assertJsonPath('staff', [['id' => $sam, 'name' => 'Sam', 'photo_url' => null, 'title' => null, 'bio' => null]]);
});

it('classifies every column of the live services table as published or withheld, so a column added later is invisible until somebody decides', function () {
    provisionTenant('acme');

    $classified = [...ServicePayload::PUBLISHED, ...ServicePayload::WITHHELD];
    $live = liveColumns('tenant_acme', 'services');
    sort($classified);
    sort($live);

    expect($live)->not->toBeEmpty()
        ->and($classified)->toBe($live)
        ->and(ServicePayload::PUBLISHED)->toContain('category', 'online')
        ->and(array_intersect(ServicePayload::PUBLISHED, ServicePayload::WITHHELD))->toBe([]);
});

it('classifies every column of the live staff table as published or withheld, the photo published as its URL alone, so a column added later is invisible until somebody decides', function () {
    provisionTenant('acme');

    $classified = [...ServicePayload::STAFF_PUBLISHED, ...ServicePayload::STAFF_WITHHELD];
    $live = liveColumns('tenant_acme', 'staff');
    sort($classified);
    sort($live);

    expect($classified)->toBe($live)
        ->and(ServicePayload::STAFF_PUBLISHED)->toBe(['id', 'name', 'photo_path', 'title', 'bio'])
        ->and(array_intersect(ServicePayload::STAFF_PUBLISHED, ServicePayload::STAFF_WITHHELD))->toBe([]);
});

it('carries each listed member\'s photo as the URL it is served at on the tenant\'s origin, the title and the bio, and never the stored path, the address or the flags', function () {
    provisionTenant('acme');
    $sam = seedStaff('tenant_acme', 'Sam', ['photo_path' => 'tenants/acme/staff-0123456789abcdef.png', 'title' => 'Senior stylist', 'bio' => 'Ten years behind the chair.', 'email' => 'sam@acme.example']);
    $cut = seedService('tenant_acme', 'Cut');
    seedAssignment('tenant_acme', $cut, $sam);

    $this->getJson(servicesUrl('acme'))->assertOk()
        ->assertJsonPath('services.0.staff', [['id' => $sam, 'name' => 'Sam', 'photo_url' => tenantUrl('acme', '/images/staff-0123456789abcdef.png'), 'title' => 'Senior stylist', 'bio' => 'Ten years behind the chair.']])
        ->assertDontSee('tenants/acme')->assertDontSee('sam@acme.example');
    $this->getJson(servicesUrl('acme', '/'.$cut))->assertOk()->assertJsonPath('staff.0.photo_url', tenantUrl('acme', '/images/staff-0123456789abcdef.png'));
});

it('answers the preselected service in the same shape as a row of the list', function () {
    provisionTenant('acme');
    $sam = seedStaff('tenant_acme', 'Sam');
    $cut = seedService('tenant_acme', 'Cut', ['description' => 'Wash and cut.', 'duration_min' => 45, 'price_minor' => 3500, 'category' => 'Hair']);
    seedAssignment('tenant_acme', $cut, $sam);

    $single = $this->getJson(servicesUrl('acme', '/'.$cut))->assertOk()->assertExactJson([
        'id' => $cut,
        'name' => 'Cut',
        'description' => 'Wash and cut.',
        'duration_min' => 45,
        'price_minor' => 3500,
        'currency' => 'EUR',
        'category' => 'Hair',
        'online' => true,
        'staff' => [['id' => $sam, 'name' => 'Sam', 'photo_url' => null, 'title' => null, 'bio' => null]],
    ]);

    expect($single->json())->toBe($this->getJson(servicesUrl('acme'))->json('services.0'));
});

it('answers 404 for an inactive service and for an unknown id with byte-identical bodies, and for an id no row could have', function () {
    provisionTenant('acme');
    $perm = seedService('tenant_acme', 'Perm', ['status' => 'inactive']);

    $inactive = $this->getJson(servicesUrl('acme', '/'.$perm))->assertNotFound();
    $unknown = $this->getJson(servicesUrl('acme', '/'.($perm + 1)))->assertNotFound();
    $overflow = $this->getJson(servicesUrl('acme', '/99999999999999999999'))->assertNotFound();
    $leadingZero = $this->getJson(servicesUrl('acme', '/0'.$perm))->assertNotFound();
    $letters = $this->getJson(servicesUrl('acme', '/abc'))->assertNotFound();

    $inactive->assertExactJson(['code' => 'not_found', 'message' => 'not_found', 'data' => ['status' => 404, 'detail' => apiSentence('api.status.not_found')]]);

    expect(responseFingerprint($unknown))->toBe(responseFingerprint($inactive))
        ->and(responseFingerprint($overflow))->toBe(responseFingerprint($inactive))
        ->and(responseFingerprint($leadingZero))->toBe(responseFingerprint($inactive))
        ->and(responseFingerprint($letters))->toBe(responseFingerprint($inactive));
});

it('lists online services alone, each with its category, and per service its online performers alone; an offline service answers 404 as an inactive one does', function () {
    provisionTenant('acme');
    $sam = seedStaff('tenant_acme', 'Sam');
    $away = seedStaff('tenant_acme', 'Away', ['online' => false]);
    $cut = seedService('tenant_acme', 'Cut', ['category' => 'Hair']);
    $nails = seedService('tenant_acme', 'Nails', ['sort' => 1]);
    $private = seedService('tenant_acme', 'Private fitting', ['online' => false, 'sort' => 2, 'category' => 'Hair']);
    $perm = seedService('tenant_acme', 'Perm', ['status' => 'inactive', 'sort' => 3]);
    seedAssignment('tenant_acme', $cut, $sam);
    seedAssignment('tenant_acme', $cut, $away);
    seedAssignment('tenant_acme', $nails, $away);
    seedAssignment('tenant_acme', $private, $sam);

    $this->getJson(servicesUrl('acme'))->assertOk()
        ->assertJsonCount(2, 'services')
        ->assertJsonPath('services.0.id', $cut)
        ->assertJsonPath('services.0.category', 'Hair')
        ->assertJsonPath('services.0.staff', [['id' => $sam, 'name' => 'Sam', 'photo_url' => null, 'title' => null, 'bio' => null]])
        ->assertJsonPath('services.1.id', $nails)
        ->assertJsonPath('services.1.category', null)
        ->assertJsonPath('services.1.staff', [])
        ->assertDontSee('Private fitting')
        ->assertDontSee('Away');

    $offline = $this->getJson(servicesUrl('acme', '/'.$private))->assertNotFound();
    $inactive = $this->getJson(servicesUrl('acme', '/'.$perm))->assertNotFound();

    expect(responseFingerprint($offline))->toBe(responseFingerprint($inactive));
    $this->getJson(servicesUrl('acme', '/'.$cut))->assertOk()->assertJsonPath('staff', [['id' => $sam, 'name' => 'Sam', 'photo_url' => null, 'title' => null, 'bio' => null]]);
});

it('answers an empty catalog as an empty list with the cap beside it', function () {
    provisionTenant('acme');

    $this->getJson(servicesUrl('acme'))->assertOk()->assertExactJson(['services' => [], 'max_chain_length' => 5]);
});

it('reads the catalog of the tenant the host names and no other, though each schema numbers its rows from one', function () {
    provisionTenant('acme');
    provisionTenant('bravo', ['ownerEmail' => 'bravo@example.com']);
    $acmeCut = seedService('tenant_acme', 'Acme cut');
    $bravoCut = seedService('tenant_bravo', 'Bravo cut');

    expect($bravoCut)->toBe($acmeCut);

    $this->getJson(servicesUrl('acme'))->assertOk()->assertJsonPath('services.0.name', 'Acme cut')->assertJsonCount(1, 'services')->assertDontSee('Bravo');
    $this->getJson(servicesUrl('bravo'))->assertOk()->assertJsonPath('services.0.name', 'Bravo cut')->assertJsonCount(1, 'services')->assertDontSee('Acme');
    $this->getJson(servicesUrl('acme', '/'.$bravoCut))->assertOk()->assertJsonPath('name', 'Acme cut')->assertDontSee('Bravo');
});

it('answers a cross-origin read with permissive CORS on {route}', function (string $path) {
    provisionTenant('acme');
    seedService('tenant_acme', 'Cut');

    $this->getJson(servicesUrl('acme', $path), ['Origin' => 'https://www.salon-x.example'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*');
})->with(['the list' => [''], 'one service' => ['/1']]);

it('answers a cross-origin preflight with permissive CORS on {route}', function (string $path) {
    provisionTenant('acme');

    $this->call('OPTIONS', servicesUrl('acme', $path), server: [
        'HTTP_ORIGIN' => 'https://www.salon-x.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Access-Control-Allow-Methods', 'GET')
        ->assertHeader('Access-Control-Allow-Headers', 'content-type');
})->with(['the list' => [''], 'one service' => ['/1']]);

it('leaves the connection free of any search path change on {route}', function (string $path) {
    provisionTenant('acme');
    seedService('tenant_acme', 'Cut');

    $this->getJson(servicesUrl('acme', $path))->assertOk();

    expect(DB::scalar("select current_setting('search_path')"))->toBe('public')
        ->and(DB::scalar('select current_schema()'))->toBe('public');
})->with(['the list' => [''], 'one service' => ['/1']]);
