<?php

use App\Application\ChainUnavailable;
use App\Application\Dto\DayWindow;
use App\Application\Dto\Intake;
use App\Application\ServiceNotBookable;
use App\Application\StaffNotEligible;
use App\Application\TransitionRefused;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Booking\StaffBlock;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Chain\Chain;
use App\Domain\Enum\BookingStatus;
use App\Http\ErrorEnvelope;
use App\Http\Requests\HoldBody;
use App\Http\Requests\Instant;
use App\Http\Requests\NewHoldRequest;
use App\Infrastructure\Db\Busy;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Site\SiteLanguage;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Support\FixtureDay;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The API's refusals in the request's language: the envelope's detail is read through the
 * translator when the envelope is built, on /api/v1 in the language the lang parameter names, else
 * the tenant default; under /admin/api in the user's; on the webhooks in English whatever the
 * request names. A limit's 429, which the framework answers before tenant resolution runs, reads
 * the request's language there, the lang parameter, else the default of the tenant the host names,
 * else English, and under /admin the signed-in user's. The transport cases below add a Greek line
 * of their own for the case alone, ASCII and marked GREEK, and read the English through the
 * translator, never spelled. The walk asks every key of api.php once in each language, the Greek
 * read through the translator too, and holds the walk to the file's keys, those of the admin's
 * move and of the blocked hold walked by AdminMoveLanguageTest on the demo
 * (apiKeysWalkedOnTheDemo). Each Greek detail shows no English line of api.php.
 *
 * The hold body's sentences fill every limit they name from the code's constant, read as a choice
 * by it, and name an item by its number, never by an ordinal: the first, the second, the third and
 * the last of the five the chain allows (Chain::MAX_SEGMENTS) through a request, and an eleventh,
 * which no request can name, through the translator in Greek. No hold reaches the transaction
 * runner here: each body is refused at the boundary. The clock is pinned, since the bodies spell
 * the fixture's day.
 */

beforeEach(function () {
    FixtureDay::pinClock();
    provisionTenant('acme');
});

/**
 * acme's default language as its row names it, no choice in Settings.
 */
function aplDefault(SiteLanguage $language): void
{
    DB::table('tenants')->where('slug', 'acme')->update(['locale' => $language->value]);
}

/**
 * acme's owner, their chosen language as given.
 */
function aplOwner(?SiteLanguage $chosen): User
{
    DB::table('users')->where('email', 'owner@example.com')->update(['locale' => $chosen?->value]);

    return User::query()->where('email', 'owner@example.com')->sole();
}

/**
 * The detail of an envelope at the status given.
 *
 * @param  TestResponse<Response>  $response
 */
function aplDetail(TestResponse $response, int $status): string
{
    $response->assertStatus($status)->assertJsonPath('data.status', $status);
    $detail = $response->json('data.detail');

    return is_string($detail) ? $detail : throw new LogicException('The envelope carries no detail.');
}

/**
 * A hold body acme's boundary accepts, overridden as given: every refusal below is the one field
 * the case names.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aplHoldBody(array $overrides = []): array
{
    return [
        'items' => [['service_id' => 1, 'staff_id' => null]],
        'customer_name' => 'Ann Example',
        'customer_email' => 'ann@example.com',
        'start' => '2026-11-03T09:00:00+02:00',
        ...$overrides,
    ];
}

it('answers a refusal on /api/v1 in the language its lang names, else in the tenant default, a refusal by status, by type and by validation alike', function () {
    greekLines([
        'api.status.not_found' => 'not found',
        'api.envelope.missing_token' => 'no token',
        'api.validation.items.required' => 'no items',
    ]);
    $asked = [
        'not_found' => fn (string $query): TestResponse => $this->getJson(tenantUrl('acme', '/api/v1/services/1'.$query)),
        'missing_token' => fn (string $query): TestResponse => $this->postJson(tenantUrl('acme', '/api/v1/bookings/7b7f0e5a-0000-4000-8000-000000000001/cancel'.$query)),
        'items' => fn (string $query): TestResponse => $this->postJson(tenantUrl('acme', '/api/v1/holds'.$query), []),
    ];
    $read = fn (string $case, string $query): string => aplDetail($asked[$case]($query), $case === 'not_found' ? 404 : 400);

    expect($read('not_found', '?lang=el'))->toBe('GREEK not found')
        ->and($read('missing_token', '?lang=el'))->toBe('GREEK no token')
        ->and($read('items', '?lang=el'))->toBe('GREEK no items')
        ->and($read('not_found', ''))->toBe(apiSentence('api.status.not_found'))
        ->and($read('missing_token', '?lang=en'))->toBe(apiSentence(ErrorEnvelope::MISSING_TOKEN_DETAIL))
        ->and($read('items', '?lang=EL'))->toBe(apiSentence('api.validation.items.required'));

    aplDefault(SiteLanguage::Greek);

    expect($read('not_found', ''))->toBe('GREEK not found')
        ->and($read('missing_token', ''))->toBe('GREEK no token')
        ->and($read('items', ''))->toBe('GREEK no items')
        ->and($read('not_found', '?lang=en'))->toBe(apiSentence('api.status.not_found'))
        ->and($read('items', '?lang=en'))->toBe(apiSentence('api.validation.items.required'));
});

it('answers a limit\'s 429 on /api/v1 in the request\'s language although it is given before the tenant is resolved: {case}', function (string $path, int $limit, int $status) {
    greekLines(['api.envelope.rate_limited' => 'too many']);
    $ask = fn (string $query): TestResponse => $this->postJson(tenantUrl('acme', $path.$query), []);

    for ($request = 1; $request <= $limit; $request++) {
        $ask('?lang=el')->assertStatus($status);
    }

    $refused = $ask('?lang=el')->assertHeader('Retry-After')->assertHeader('X-RateLimit-Limit', (string) $limit);

    expect(aplDetail($refused, 429))->toBe('GREEK too many')
        ->and($refused->json('code'))->toBe('rate_limited')
        ->and(aplDetail($ask(''), 429))->toBe(apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL));

    aplDefault(SiteLanguage::Greek);

    expect(aplDetail($ask(''), 429))->toBe('GREEK too many')
        ->and(aplDetail($ask('?lang=en'), 429))->toBe(apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL));
})->with([
    'the holds\' limit' => ['/api/v1/holds', AppServiceProvider::HOLD_REQUESTS_PER_MINUTE, 400],
    'the booking writes\' limit' => ['/api/v1/bookings/7b7f0e5a-0000-4000-8000-000000000001/cancel', AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE, 400],
]);

it('answers a limit\'s 429 in English on a host that names no tenant, whatever lang names', function () {
    greekLines(['api.envelope.rate_limited' => 'too many']);

    for ($request = 1; $request <= AppServiceProvider::HOLD_REQUESTS_PER_MINUTE; $request++) {
        $this->postJson(tenantUrl('nobody', '/api/v1/holds?lang=el'), [])->assertNotFound();
    }

    expect(aplDetail($this->postJson(tenantUrl('nobody', '/api/v1/holds?lang=el'), []), 429))->toBe(apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL));
});

it('answers the admin\'s limit\'s 429 in the signed-in user\'s language, else the tenant default: {case}', function (string $path) {
    greekLines(['api.envelope.rate_limited' => 'too many']);
    $press = fn (): TestResponse => $this->post(tenantUrl('acme', $path));

    // The limit counts by the address and the host, before the permission is asked, so nobody
    // signed in spends it.
    for ($request = 1; $request <= 10; $request++) {
        $press()->assertRedirect();
    }

    expect(aplDetail($press(), 429))->toBe(apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL));

    aplDefault(SiteLanguage::Greek);

    expect(aplDetail($press(), 429))->toBe('GREEK too many');

    $this->actingAs(aplOwner(SiteLanguage::English));

    expect(aplDetail($press(), 429))->toBe(apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL));

    aplDefault(SiteLanguage::English);
    $this->actingAs(aplOwner(SiteLanguage::Greek));

    expect(aplDetail($press(), 429))->toBe('GREEK too many');
})->with([
    'the billing writes' => ['/admin/billing/plan'],
    'the payment checks' => ['/admin/settings/payments/test'],
]);

it('answers the admin\'s own API in the user\'s language, the tenant default for nobody signed in', function () {
    greekLines(['api.status.unauthenticated' => 'sign in', 'api.validation.items.required' => 'no items']);
    $ask = fn (): TestResponse => $this->getJson(tenantUrl('acme', '/admin/api/availability?from=2026-11-03&to=2026-11-04'));

    expect(aplDetail($ask(), 401))->toBe(apiSentence('api.status.unauthenticated'));

    aplDefault(SiteLanguage::Greek);

    expect(aplDetail($ask(), 401))->toBe('GREEK sign in');

    $this->actingAs(aplOwner(null));

    expect(aplDetail($ask(), 400))->toBe('GREEK no items');

    $this->actingAs(aplOwner(SiteLanguage::English));

    expect(aplDetail($ask(), 400))->toBe(apiSentence('api.validation.items.required'));

    aplDefault(SiteLanguage::English);
    $this->actingAs(aplOwner(SiteLanguage::Greek));

    // The lang of the public API is no door here: the admin answers its user.
    expect(aplDetail($this->getJson(tenantUrl('acme', '/admin/api/availability?from=2026-11-03&to=2026-11-04&lang=en')), 400))->toBe('GREEK no items');
});

it('answers the admin\'s manual booking with the hold body\'s own sentences, in the user\'s language', function () {
    greekLines(['api.validation.customer_email.email' => 'no address']);
    $create = fn (): TestResponse => $this->postJson(tenantUrl('acme', '/admin/bookings'), aplHoldBody(['customer_email' => 'not an address']));

    $this->actingAs(aplOwner(SiteLanguage::Greek));

    expect(aplDetail($create(), 400))->toBe('GREEK no address');

    $this->actingAs(aplOwner(SiteLanguage::English));

    expect(aplDetail($create(), 400))->toBe(apiSentence('api.validation.customer_email.email'));
});

it('answers the webhooks in English whatever the request names and whatever language the process holds: {case}', function (string $path) {
    greekLines(['api.status.method_not_allowed' => 'wrong verb']);
    app()->setLocale(SiteLanguage::Greek->value);

    try {
        $detail = aplDetail($this->getJson(centralUrl($path.'?lang=el')), 405);
    } finally {
        app()->setLocale(SiteLanguage::English->value);
    }

    expect($detail)->toBe(apiSentence('api.status.method_not_allowed'));
})->with([
    'the billing provider\'s' => ['/webhooks/billing'],
    'a tenant\'s own payment provider\'s' => ['/webhooks/stripe/a-connection-token'],
    'the platform\'s payment provider\'s' => ['/webhooks/stripe'],
]);

it('fills the limit a hold body sentence names from the code\'s own constant, read as a choice by it: {case}', function (array $overrides, string $key, int $limit) {
    /** @var array<string, mixed> $overrides */
    $sentence = aplDetail($this->postJson(tenantUrl('acme', '/api/v1/holds'), aplHoldBody($overrides)), 400);

    expect($sentence)->toBe(apiChoice($key, $limit))
        ->toContain((string) $limit);
})->with([
    'a name past its length' => [['customer_name' => str_repeat('a', HoldBody::NAME_MAX + 1)], 'api.validation.customer_name.max', HoldBody::NAME_MAX],
    'an address past its length' => [['customer_email' => str_repeat('a', HoldBody::EMAIL_MAX - 54).'@'.str_repeat('b', 50).'.com'], 'api.validation.customer_email.max', HoldBody::EMAIL_MAX],
    'a phone past its length' => [['customer_phone' => str_repeat('1', HoldBody::PHONE_MAX + 1)], 'api.validation.customer_phone.max', HoldBody::PHONE_MAX],
    'a note past its length' => [['note' => str_repeat('a', Intake::NOTE_MAX + 1)], NewHoldRequest::NOTE_TOO_LONG, Intake::NOTE_MAX],
    'more items than a chain holds' => [['items' => array_fill(0, Chain::MAX_SEGMENTS + 1, ['service_id' => 1, 'staff_id' => null])], 'api.validation.items.max', Chain::MAX_SEGMENTS],
]);

it('fills a limit into the Greek line as into the English one', function () {
    app('translator')->addLines(['api.validation.customer_name.max' => '{1} GREEK one|[2,*] GREEK at most :count'], 'el');

    expect(aplDetail($this->postJson(tenantUrl('acme', '/api/v1/holds?lang=el'), aplHoldBody(['customer_name' => str_repeat('a', HoldBody::NAME_MAX + 1)])), 400))
        ->toBe('GREEK at most '.HoldBody::NAME_MAX);
});

it('names an item by its number in a sentence that holds no ordinal: the item at {0}', function (int $position) {
    $items = array_fill(0, $position, ['service_id' => 1, 'staff_id' => null]);
    $items[$position - 1] = ['staff_id' => null];

    $sentence = aplDetail($this->postJson(tenantUrl('acme', '/api/v1/holds'), aplHoldBody(['items' => $items])), 400);

    expect($sentence)->toBe(apiSentence('api.validation.item.no_service_id', ['n' => (string) $position]))
        ->toContain((string) $position)
        ->not->toMatch('/\d(?:st|nd|rd|th)\b/');
})->with([1, 2, 3, Chain::MAX_SEGMENTS]);

it('fills the item\'s number into the Greek line, each sentence about an item a whole line with :n', function () {
    greekLines(['api.validation.item.staff_id_integer' => 'staff of item :n']);
    $items = [['service_id' => 1, 'staff_id' => null], ['service_id' => 1, 'staff_id' => 'ann']];

    expect(aplDetail($this->postJson(tenantUrl('acme', '/api/v1/holds?lang=el'), aplHoldBody(['items' => $items])), 400))->toBe('GREEK staff of item 2');

    foreach (['object', 'no_service_id', 'no_staff_id', 'service_id_integer', 'staff_id_integer', 'service_unavailable', 'staff_unqualified'] as $key) {
        expect(__('api.validation.item.'.$key))->toBeString()->toContain(':n');
    }
});

it('answers a start the API cannot read with the one start sentence', function () {
    expect(aplDetail($this->postJson(tenantUrl('acme', '/api/v1/holds'), aplHoldBody(['start' => 'tomorrow'])), 400))->toBe(apiSentence(Instant::SENTENCE));
});

/**
 * A hold on acme's API under the lang given, its body aplHoldBody's overridden as given: one of
 * the walk's asks.
 *
 * @param  array<string, mixed>  $overrides
 * @return Closure(string): TestResponse<Response>
 */
function aplHoldAsk(TestCase $test, array $overrides): Closure
{
    return fn (string $lang): TestResponse => $test->postJson(tenantUrl('acme', '/api/v1/holds?lang='.$lang), aplHoldBody($overrides));
}

/**
 * A route beside acme's API that throws what it is given, under the API's own middleware, so the
 * envelope answers it as it answers the engine (as ApiErrorEnvelopeTest's besideTheApi does).
 */
function aplBeside(string $uri, Closure $action): void
{
    $services = Route::getRoutes()->getByName('api.services');

    assert($services instanceof RegisteredRoute);

    Route::domain((string) $services->getDomain())->middleware($services->middleware())->prefix('api/v1')->get($uri, $action);
}

it('walks every key of api.php in Greek under lang=el, each detail the Greek line filled as the English one, the code unchanged, and lang=en answering the English', function () {
    $uuid = '7b7f0e5a-0000-4000-8000-000000000001';
    $start = new DateTimeImmutable('2026-11-03 09:00', new DateTimeZone(FixtureDay::ZONE));
    $refusal = fn (TransitionRefusal $reason): Closure => fn (): never => throw TransitionRefused::because($uuid, $reason, BookingStatus::Pending);
    $thrown = [
        'envelope.server_error' => fn (): never => throw new RuntimeException('a fault'),
        'envelope.rate_limited' => fn (): never => throw new TooManyRequestsHttpException,
        'envelope.overlap' => fn (): never => throw SegmentConflict::at(0, new StaffBlock(7, $start, $start->add(new DateInterval('PT45M')))),
        'envelope.unavailable' => fn (): never => throw ChainUnavailable::nobodyAt(0, $start),
        'envelope.service_gone' => fn (): never => throw ServiceNotBookable::at(0, 41),
        'envelope.staff_not_eligible' => fn (): never => throw StaffNotEligible::at(0, 7),
        'envelope.busy' => fn (): never => throw Busy::on('tenant_acme', new PDOException('lock timeout')),
        'envelope.hold_expired' => $refusal(TransitionRefusal::HoldExpired),
        'envelope.not_confirmable' => $refusal(TransitionRefusal::NotConfirmable),
        'envelope.payment_required' => $refusal(TransitionRefusal::PaymentRequired),
        'envelope.not_cancellable' => $refusal(TransitionRefusal::NotCancellable),
        'envelope.not_reschedulable' => $refusal(TransitionRefusal::NotReschedulable),
        'envelope.not_releasable' => $refusal(TransitionRefusal::NotReleasable),
        'envelope.hold_lapsed' => $refusal(TransitionRefusal::HoldLapsed),
        'envelope.window_closed' => $refusal(TransitionRefusal::WindowClosed),
        'envelope.not_approvable' => $refusal(TransitionRefusal::NotApprovable),
        'envelope.not_rejectable' => $refusal(TransitionRefusal::NotRejectable),
        'envelope.not_confirmed' => $refusal(TransitionRefusal::NotConfirmed),
        'envelope.too_early' => $refusal(TransitionRefusal::TooEarly),
        'status.bad_request' => fn (): never => throw new BadRequestHttpException,
        'status.unauthenticated' => fn (): never => throw new AuthenticationException,
        'status.forbidden' => fn (): never => throw new AccessDeniedHttpException,
        'status.method_not_allowed' => fn (): never => throw new MethodNotAllowedHttpException(['GET']),
        'status.service_unavailable' => fn (): never => throw new ServiceUnavailableHttpException,
        'status.refused' => fn (): never => throw new ConflictHttpException,
    ];
    $asked = [];

    foreach ($thrown as $key => $action) {
        aplBeside('/_walk/'.$key, $action);
        $asked[$key] = [fn (string $lang): TestResponse => $this->getJson(tenantUrl('acme', '/api/v1/_walk/'.$key.'?lang='.$lang)), []];
    }

    // The ones a real request meets: a token missing, a service unknown, and each field of the
    // hold body and of the availability query refused alone.
    $staff = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Ann', 'status' => 'active']);
    $other = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Ben', 'status' => 'active']);
    $service = DB::table('tenant_acme.services')->insertGetId(serviceRow('Cut'));
    DB::table('tenant_acme.service_staff')->insert(['service_id' => $service, 'staff_id' => $staff]);
    $without = fn (string $field): Closure => function (string $lang) use ($field): TestResponse {
        $body = aplHoldBody();
        unset($body[$field]);

        return $this->postJson(tenantUrl('acme', '/api/v1/holds?lang='.$lang), $body);
    };
    $window = fn (array $query): Closure => fn (string $lang): TestResponse => $this->getJson(tenantUrl('acme', '/api/v1/availability?'.http_build_query([
        'items' => (string) json_encode([['service_id' => $service, 'staff_id' => null]]), 'from' => '2026-11-03', 'to' => '2026-11-04', ...$query, 'lang' => $lang,
    ])));
    $first = fn (array $item): array => ['items' => [$item]];
    $asked += [
        'envelope.missing_token' => [fn (string $lang): TestResponse => $this->postJson(tenantUrl('acme', '/api/v1/bookings/'.$uuid.'/cancel?lang='.$lang)), []],
        'status.not_found' => [fn (string $lang): TestResponse => $this->getJson(tenantUrl('acme', '/api/v1/services/999?lang='.$lang)), []],
        'validation.customer_name.required' => [$without('customer_name'), []],
        'validation.customer_name.max' => [aplHoldAsk($this, ['customer_name' => str_repeat('a', HoldBody::NAME_MAX + 1)]), ['count' => HoldBody::NAME_MAX]],
        'validation.customer_email.required' => [$without('customer_email'), []],
        'validation.customer_email.email' => [aplHoldAsk($this, ['customer_email' => 'not an address']), []],
        'validation.customer_email.max' => [aplHoldAsk($this, ['customer_email' => str_repeat('a', HoldBody::EMAIL_MAX - 54).'@'.str_repeat('b', 50).'.com']), ['count' => HoldBody::EMAIL_MAX]],
        'validation.customer_phone.string' => [aplHoldAsk($this, ['customer_phone' => 2101234567]), []],
        'validation.customer_phone.max' => [aplHoldAsk($this, ['customer_phone' => str_repeat('1', HoldBody::PHONE_MAX + 1)]), ['count' => HoldBody::PHONE_MAX]],
        'validation.start' => [aplHoldAsk($this, ['start' => 'tomorrow']), []],
        'validation.same_staff' => [$window(['same_staff' => 'maybe']), []],
        'validation.note.string' => [aplHoldAsk($this, ['note' => 5]), []],
        'validation.note.max' => [aplHoldAsk($this, ['note' => str_repeat('a', Intake::NOTE_MAX + 1)]), ['count' => Intake::NOTE_MAX]],
        'validation.items.required' => [$without('items'), []],
        'validation.items.list' => [aplHoldAsk($this, ['items' => 'haircut']), []],
        'validation.items.max' => [aplHoldAsk($this, ['items' => array_fill(0, Chain::MAX_SEGMENTS + 1, ['service_id' => 1, 'staff_id' => null])]), ['count' => Chain::MAX_SEGMENTS]],
        'validation.item.object' => [aplHoldAsk($this, ['items' => ['haircut']]), ['n' => 1]],
        'validation.item.no_service_id' => [aplHoldAsk($this, $first(['staff_id' => null])), ['n' => 1]],
        'validation.item.no_staff_id' => [aplHoldAsk($this, $first(['service_id' => 1])), ['n' => 1]],
        'validation.item.service_id_integer' => [aplHoldAsk($this, $first(['service_id' => 'cut', 'staff_id' => null])), ['n' => 1]],
        'validation.item.staff_id_integer' => [aplHoldAsk($this, $first(['service_id' => 1, 'staff_id' => 'ann'])), ['n' => 1]],
        'validation.item.service_unavailable' => [$window(['items' => (string) json_encode([['service_id' => 999, 'staff_id' => null]])]), ['n' => 1]],
        'validation.item.staff_unqualified' => [$window(['items' => (string) json_encode([['service_id' => $service, 'staff_id' => $other]])]), ['n' => 1]],
        'validation.from.day' => [$window(['from' => 'monday']), []],
        'validation.to.day' => [$window(['to' => 'tuesday']), []],
        'validation.to.after' => [$window(['to' => '2026-11-02']), []],
        'validation.to.max' => [$window(['to' => '2027-03-01']), ['count' => DayWindow::MAX_DAYS]],
    ];

    // The admin's move and the blocked hold are AdminMoveLanguageTest's, walked on the demo.
    expect([...array_keys($asked), ...apiKeysWalkedOnTheDemo()])->toEqualCanonicalizing(array_keys(Arr::dot((array) __('api', [], 'en'))));

    foreach ($asked as $key => [$ask, $fill]) {
        $line = fn (SiteLanguage $language): string => isset($fill['count'])
            ? apiChoice('api.'.$key, $fill['count'], $language)
            : apiSentence('api.'.$key, array_map(strval(...), $fill), $language);
        // Each key asks twice; the limits count per minute, and the walk is no flood.
        Cache::flush();
        $greek = $ask('el');
        $english = $ask('en');

        expect($greek->json('data.detail'))->toBe($line(SiteLanguage::Greek), $key)
            ->and($english->json('data.detail'))->toBe($line(SiteLanguage::English), $key)
            ->and($line(SiteLanguage::Greek))->not->toBe($line(SiteLanguage::English), $key)
            ->and(unfilledIn($line(SiteLanguage::Greek)))->toBe([], $key)
            ->and(linesShownIn(aplDetail($greek, $greek->getStatusCode()), 'api', SiteLanguage::English))->toBe([], $key)
            ->and($greek->getStatusCode())->toBe($english->getStatusCode(), $key)
            ->and($greek->json('code'))->toBe($english->json('code'), $key)
            ->and($greek->json('code'))->toBeString();
    }
});

it('names an item by its number in the Greek sentence at the first, second, third and last item the chain allows, and at an eleventh read through the translator, no English left', function (int $position) {
    $items = array_fill(0, min($position, Chain::MAX_SEGMENTS), ['service_id' => 1, 'staff_id' => null]);
    $greek = apiSentence('api.validation.item.no_service_id', ['n' => (string) $position], SiteLanguage::Greek);

    if ($position <= Chain::MAX_SEGMENTS) {
        $items[$position - 1] = ['staff_id' => null];

        expect(aplDetail($this->postJson(tenantUrl('acme', '/api/v1/holds?lang=el'), aplHoldBody(['items' => $items])), 400))->toBe($greek);
    }

    // Past the chain's five no request can name the item, so the eleventh is read as the
    // translator fills it.
    foreach (array_keys(Arr::dot((array) __('api.validation.item', [], 'en'))) as $key) {
        $line = apiSentence('api.validation.item.'.$key, ['n' => (string) $position], SiteLanguage::Greek);

        expect($line)->toContain((string) $position)
            ->and(linesShownIn($line, 'api', SiteLanguage::English))->toBe([])
            ->and(unfilledIn($line))->toBe([]);
    }
})->with([1, 2, 3, Chain::MAX_SEGMENTS, 11]);

it('answers the admin\'s own API and the manual booking under a Greek user with the Greek of api.php, as the booking app reads it', function () {
    $this->actingAs(aplOwner(SiteLanguage::Greek));

    expect(aplDetail($this->getJson(tenantUrl('acme', '/admin/api/availability?from=2026-11-03&to=2026-11-04')), 400))->toBe(apiSentence('api.validation.items.required', [], SiteLanguage::Greek))
        ->and(aplDetail($this->postJson(tenantUrl('acme', '/admin/bookings'), aplHoldBody(['customer_email' => 'not an address'])), 400))->toBe(apiSentence('api.validation.customer_email.email', [], SiteLanguage::Greek));

    $this->actingAs(aplOwner(SiteLanguage::English));

    expect(aplDetail($this->getJson(tenantUrl('acme', '/admin/api/availability?from=2026-11-03&to=2026-11-04')), 400))->toBe(apiSentence('api.validation.items.required'));
});
