<?php

use App\Application\BookingUnknown;
use App\Application\ChainUnavailable;
use App\Application\KeySetMoved;
use App\Application\ServiceNotBookable;
use App\Application\StaffNotEligible;
use App\Application\TransitionRefused;
use App\Application\WriteContended;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Booking\StaffBlock;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use App\Http\ErrorEnvelope;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\Db\ExclusionViolation;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

pest()->use(RefreshDatabase::class);

/*
 * The error envelope on every error under /api/v1, through the real router, the real middleware
 * and the real exception handler: a validation failure, a 404, the rate limit's 429 and a 500,
 * and every refusal the booking engine raises by type. The routes that fail on purpose are
 * registered beside the service list for the test, on the same host pattern and behind the same
 * middleware, because which sentence a refusal gets is the envelope's decision and not any one
 * route's: the hold endpoint's own tests drive these same types over real rows.
 */

/**
 * Registers a route under /api/v1 like the service list: same domain, same middleware, the
 * body the test's.
 */
function besideTheApi(string $uri, Closure $action): void
{
    $services = Route::getRoutes()->getByName('api.services');

    assert($services instanceof RegisteredRoute);

    Route::domain((string) $services->getDomain())->middleware($services->middleware())->prefix('api/v1')->get($uri, $action);
}

/**
 * @return array{code: string, message: string, data: array{status: int, detail: string}}
 */
function envelope(string $code, int $status, string $detail): array
{
    return ['code' => $code, 'message' => $code, 'data' => ['status' => $status, 'detail' => $detail]];
}

it('renders a validation failure as 400 invalid_request with the field sentence as its detail, not the framework\'s 422', function () {
    provisionTenant('acme');
    besideTheApi('/_validates', fn (Request $request): JsonResponse => new JsonResponse($request->validate(['name' => ['required', 'string'], 'count' => ['required', 'integer']])));

    $this->getJson(tenantUrl('acme', '/api/v1/_validates?count=many'))
        ->assertStatus(400)
        ->assertExactJson(envelope('invalid_request', 400, 'The name field is required.'));
});

it('renders a 404 as not_found for an unknown path, an unknown service and an unknown tenant alike', function () {
    provisionTenant('acme');

    $expected = envelope('not_found', 404, apiSentence('api.status.not_found'));

    $this->getJson(tenantUrl('acme', '/api/v1/nothing-here'))->assertNotFound()->assertExactJson($expected);
    $this->getJson(tenantUrl('acme', '/api/v1/services/1'))->assertNotFound()->assertExactJson($expected);
    $this->getJson(tenantUrl('nobody', '/api/v1/services'))->assertNotFound()->assertExactJson($expected);
    $this->getJson(centralUrl('/api/v1/services'))->assertNotFound()->assertExactJson($expected);
});

it('renders a method the route does not take as 405 in the envelope', function () {
    provisionTenant('acme');

    $this->postJson(tenantUrl('acme', '/api/v1/services'))
        ->assertStatus(405)
        ->assertExactJson(envelope('method_not_allowed', 405, apiSentence('api.status.method_not_allowed')));
});

it('accepts 300 requests a minute from one address and answers the 301st with 429 rate_limited and a worded detail, while another address still passes', function () {
    provisionTenant('acme');
    $url = tenantUrl('acme', '/api/v1/services');
    $statuses = [];

    for ($request = 1; $request <= AppServiceProvider::API_REQUESTS_PER_MINUTE; $request++) {
        $statuses[$this->getJson($url)->status()] = true;
    }

    expect(array_keys($statuses))->toBe([200]);

    $this->getJson($url)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '300')
        ->assertExactJson(envelope('rate_limited', 429, apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL)));

    $this->call('GET', $url, server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_ACCEPT' => 'application/json'])->assertOk();
});

it('renders a 500 as the envelope with a fixed sentence and nothing from the exception, with debug on', function () {
    config(['app.debug' => true]);
    provisionTenant('acme');
    besideTheApi('/_throws', function (): never {
        throw new RuntimeException('the connection string was postgres://secret');
    });

    $this->getJson(tenantUrl('acme', '/api/v1/_throws'))
        ->assertStatus(500)
        ->assertExactJson(envelope('server_error', 500, apiSentence(ErrorEnvelope::SERVER_ERROR_DETAIL)))
        ->assertDontSee('secret')
        ->assertDontSee('RuntimeException');
});

it('keeps the permissive CORS on an error, so the embed can read the envelope', function () {
    provisionTenant('acme');

    $this->getJson(tenantUrl('acme', '/api/v1/services/1'), ['Origin' => 'https://www.salon-x.example'])
        ->assertNotFound()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertJsonPath('code', 'not_found');
});

it('leaves an error outside the API to the framework: the envelope is the API\'s', function () {
    provisionTenant('acme');

    $this->get(tenantUrl('acme', '/nothing-here'))
        ->assertNotFound()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertDontSee('not_found');
});

it('renders each refusal the booking engine raises by its type: a service the catalog no longer offers as 404, a pick who does not perform it as 400, an unplaceable chain and a conflict under lock as the two 409s, each naming its segment', function () {
    provisionTenant('acme');
    $start = new DateTimeImmutable('2026-11-03 09:00', new DateTimeZone('Europe/Athens'));
    besideTheApi('/_gone', fn (): never => throw ServiceNotBookable::at(1, 41));
    besideTheApi('/_pick', fn (): never => throw StaffNotEligible::at(0, 7));
    besideTheApi('/_nobody', fn (): never => throw ChainUnavailable::nobodyAt(1, $start));
    besideTheApi('/_taken', fn (): never => throw SegmentConflict::at(2, new StaffBlock(7, $start, $start->add(new DateInterval('PT45M')))));

    $this->getJson(tenantUrl('acme', '/api/v1/_gone'))->assertNotFound()
        ->assertExactJson(envelope('not_found', 404, apiSentence(ErrorEnvelope::SERVICE_GONE_DETAIL)))
        ->assertDontSee('41');

    $this->getJson(tenantUrl('acme', '/api/v1/_pick'))->assertStatus(400)
        ->assertExactJson(envelope('invalid_request', 400, apiSentence(ErrorEnvelope::STAFF_NOT_ELIGIBLE_DETAIL)));

    $this->getJson(tenantUrl('acme', '/api/v1/_nobody'))->assertStatus(409)
        ->assertExactJson([...envelope('unavailable', 409, apiSentence(ErrorEnvelope::UNAVAILABLE_DETAIL)), 'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::UNAVAILABLE_DETAIL), 'segment' => 1]]);

    $this->getJson(tenantUrl('acme', '/api/v1/_taken'))->assertStatus(409)
        ->assertExactJson([...envelope('overlap', 409, apiSentence(ErrorEnvelope::OVERLAP_DETAIL)), 'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::OVERLAP_DETAIL), 'segment' => 2]]);
});

it('answers each refused transition with its own reason and status: a hold that ran out as 410, a state the guard will not move as 409, a closed policy window as 403, money owed as 402, and a booking nobody has as the 404 a failed credential gets', function () {
    provisionTenant('acme');
    besideTheApi('/_late', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::HoldExpired, BookingStatus::Pending));
    besideTheApi('/_settled', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::NotConfirmable, BookingStatus::Cancelled));
    besideTheApi('/_unpaid', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::PaymentRequired, BookingStatus::Pending));
    besideTheApi('/_nobody-has-it', fn (): never => throw BookingUnknown::named('7b7f0e5a-0000-4000-8000-000000000001'));
    besideTheApi('/_would-not-settle', fn (): never => throw WriteContended::keptMoving('7b7f0e5a-0000-4000-8000-000000000001', 2));
    besideTheApi('/_not-cancellable', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::NotCancellable, BookingStatus::Completed));
    besideTheApi('/_too-late', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::WindowClosed, BookingStatus::Confirmed));
    besideTheApi('/_not-releasable', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::NotReleasable, BookingStatus::AwaitingApproval));
    besideTheApi('/_not-reschedulable', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::NotReschedulable, BookingStatus::NoShow));
    besideTheApi('/_hold-lapsed', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::HoldLapsed, BookingStatus::Pending));
    besideTheApi('/_not-approvable', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::NotApprovable, BookingStatus::Expired));
    besideTheApi('/_not-rejectable', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::NotRejectable, BookingStatus::Confirmed));
    besideTheApi('/_not-confirmed', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::NotConfirmed, BookingStatus::AwaitingApproval));
    besideTheApi('/_too-early', fn (): never => throw TransitionRefused::because('7b7f0e5a-0000-4000-8000-000000000001', TransitionRefusal::TooEarly, BookingStatus::Confirmed));
    // Caught inside the shared implementation today and mapped all the same: an exception that
    // cannot reach a caller now must not be able to reach one as a 500 the day a guard closure
    // raises it.
    besideTheApi('/_moved', fn (): never => throw KeySetMoved::under('7b7f0e5a-0000-4000-8000-000000000001'));

    $this->getJson(tenantUrl('acme', '/api/v1/_late'))->assertStatus(410)
        ->assertExactJson(envelope('hold_expired', 410, apiSentence(ErrorEnvelope::HOLD_EXPIRED_DETAIL)))
        ->assertDontSee('pending');

    $this->getJson(tenantUrl('acme', '/api/v1/_settled'))->assertStatus(409)
        ->assertExactJson(envelope('not_confirmable', 409, apiSentence(ErrorEnvelope::NOT_CONFIRMABLE_DETAIL)))
        ->assertDontSee('cancelled');

    $this->getJson(tenantUrl('acme', '/api/v1/_unpaid'))->assertStatus(402)
        ->assertExactJson(envelope('payment_required', 402, apiSentence(ErrorEnvelope::PAYMENT_REQUIRED_DETAIL)));

    $this->getJson(tenantUrl('acme', '/api/v1/_nobody-has-it'))->assertNotFound()
        ->assertExactJson(envelope('not_found', 404, apiSentence('api.status.not_found')))
        ->assertDontSee('7b7f0e5a');

    $this->getJson(tenantUrl('acme', '/api/v1/_would-not-settle'))->assertStatus(503)
        ->assertHeader('Retry-After', '1')
        ->assertExactJson(envelope('busy', 503, apiSentence(ErrorEnvelope::BUSY_DETAIL)));

    $this->getJson(tenantUrl('acme', '/api/v1/_not-cancellable'))->assertStatus(409)
        ->assertExactJson(envelope('not_cancellable', 409, apiSentence(ErrorEnvelope::NOT_CANCELLABLE_DETAIL)))
        ->assertDontSee('completed');

    $this->getJson(tenantUrl('acme', '/api/v1/_too-late'))->assertStatus(403)
        ->assertExactJson(envelope('window_closed', 403, apiSentence(ErrorEnvelope::WINDOW_CLOSED_DETAIL)));

    $this->getJson(tenantUrl('acme', '/api/v1/_not-releasable'))->assertStatus(409)
        ->assertExactJson(envelope('not_releasable', 409, apiSentence(ErrorEnvelope::NOT_RELEASABLE_DETAIL)));

    $this->getJson(tenantUrl('acme', '/api/v1/_not-reschedulable'))->assertStatus(409)
        ->assertExactJson(envelope('not_reschedulable', 409, apiSentence(ErrorEnvelope::NOT_RESCHEDULABLE_DETAIL)));

    // The 410 above is the confirm's and belongs to that one reason. A hold that lapsed under
    // any other act is a guard refusing a transition and answers 409.
    $this->getJson(tenantUrl('acme', '/api/v1/_hold-lapsed'))->assertStatus(409)
        ->assertExactJson(envelope('hold_lapsed', 409, apiSentence(ErrorEnvelope::HOLD_LAPSED_DETAIL)));

    // The owner's three decisions refuse with reasons of their own, all of them 409: a request
    // the clock already took is not a confirm's 410, and an outcome asked for too early is not
    // the 403 of a policy window, since no window was configured and nobody lacks a right.
    $this->getJson(tenantUrl('acme', '/api/v1/_not-approvable'))->assertStatus(409)
        ->assertExactJson(envelope('not_approvable', 409, apiSentence(ErrorEnvelope::NOT_APPROVABLE_DETAIL)))
        ->assertDontSee('expired');

    $this->getJson(tenantUrl('acme', '/api/v1/_not-rejectable'))->assertStatus(409)
        ->assertExactJson(envelope('not_rejectable', 409, apiSentence(ErrorEnvelope::NOT_REJECTABLE_DETAIL)));

    $this->getJson(tenantUrl('acme', '/api/v1/_not-confirmed'))->assertStatus(409)
        ->assertExactJson(envelope('not_confirmed', 409, apiSentence(ErrorEnvelope::NOT_CONFIRMED_DETAIL)));

    $this->getJson(tenantUrl('acme', '/api/v1/_too-early'))->assertStatus(409)
        ->assertExactJson(envelope('too_early', 409, apiSentence(ErrorEnvelope::TOO_EARLY_DETAIL)));

    $this->getJson(tenantUrl('acme', '/api/v1/_moved'))->assertStatus(503)
        ->assertHeader('Retry-After', '1')
        ->assertExactJson(envelope('busy', 503, apiSentence(ErrorEnvelope::BUSY_DETAIL)))
        ->assertDontSee('7b7f0e5a');
});

it('gives a plain 503 a code of its own, since unavailable is the 409 of a chain the tenant is not open to and a code is one machine reason', function () {
    provisionTenant('acme');
    $start = new DateTimeImmutable('2026-11-03 09:00', new DateTimeZone('Europe/Athens'));
    besideTheApi('/_down', fn (): never => throw new ServiceUnavailableHttpException);
    besideTheApi('/_shut', fn (): never => throw ChainUnavailable::underLock(0, new StaffBlock(7, $start, $start->add(new DateInterval('PT45M')))));

    $down = $this->getJson(tenantUrl('acme', '/api/v1/_down'))->assertStatus(503);
    $shut = $this->getJson(tenantUrl('acme', '/api/v1/_shut'))->assertStatus(409);

    expect($down->json('code'))->toBe('service_unavailable')
        ->and($shut->json('code'))->toBe('unavailable')
        ->and($shut->json('data.segment'))->toBe(0)
        ->and($shut->json('data.detail'))->toBe(apiSentence(ErrorEnvelope::UNAVAILABLE_DETAIL))
        ->and($down->json('code'))->not->toBe($shut->json('code'));
});

it('answers a lock timeout 503 busy with a Retry-After of one second, and a protocol hole the 500 envelope with nothing from the exception', function () {
    provisionTenant('acme');
    besideTheApi('/_busy', fn (): never => throw Busy::on('tenant_acme', new PDOException('lock timeout on staff_days')));
    besideTheApi('/_hole', fn (): never => throw ExclusionViolation::on('tenant_acme', new PDOException('conflicting key value violates exclusion constraint')));

    $this->getJson(tenantUrl('acme', '/api/v1/_busy'))->assertStatus(503)
        ->assertHeader('Retry-After', '1')
        ->assertExactJson(envelope('busy', 503, apiSentence(ErrorEnvelope::BUSY_DETAIL)))
        ->assertDontSee('staff_days');

    $this->getJson(tenantUrl('acme', '/api/v1/_hole'))->assertStatus(500)
        ->assertExactJson(envelope('server_error', 500, apiSentence(ErrorEnvelope::SERVER_ERROR_DETAIL)))
        ->assertDontSee('exclusion')
        ->assertDontSee('tenant_acme');
});
