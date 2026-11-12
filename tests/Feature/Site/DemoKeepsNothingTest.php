<?php

use App\Application\Clock;
use App\Application\Dto\Intake;
use App\Application\Event\BookingCancelled;
use App\Application\Event\BookingHeld;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Domain\Enum\Decision;
use App\Http\Controllers\MyBookingsController;
use App\Http\MyBookingsCookie;
use App\Http\Requests\NewHoldRequest;
use App\Jobs\SendBookingsLink;
use App\Jobs\SendConfirmation;
use App\Jobs\SendNewBookingNotice;
use App\Jobs\SendReminder;
use App\Site\DemoSalon;
use App\Site\SiteLanguage;
use App\Tenancy\DecisionLinks;
use App\Tenancy\PayLinks;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\RecordingPlatformMessages;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The demo keeps nothing and sends nothing: no row on the demo holds what a visitor typed, and no
 * message of any kind leaves for it. The guard reads the configured label and nothing else, so
 * the walk is made twice, on the demo and on luna, and the second keeps its visitor and queues
 * its messages.
 *
 * Not wrapped in a transaction: the hold enters the transaction runner, which refuses to open
 * inside another. The clock is FixtureDay's pin, Monday 2026-11-02 at 08:00 in Athens, so the
 * demo's seed runs around that day and luna's Elena works FixtureDay's Tuesday. The queue is the
 * database's for every case, since under the suite's sync queue a dispatched job runs inside the
 * request and never reaches the jobs table, so "no job queued" would be read off an empty table
 * whatever happened.
 *
 * Every email switch of the demo is turned on first: its seed switches every key off (8.1), and a
 * proof under those switches would prove the switches. The drop is by the label.
 */

const KEEPS_VISITOR_NAME = 'Perpetua Vandersnoot';

const KEEPS_VISITOR_EMAIL = 'perpetua.vandersnoot@example.test';

const KEEPS_VISITOR_PHONE = '+30 210 1234567';

const KEEPS_VISITOR_NOTE = 'Please keep the fringe long.';

beforeEach(function () {
    FixtureDay::pinClock();
    config(['queue.default' => 'database']);
    Artisan::call('demo:reset');
    DB::statement("update tenant_demo.settings set emails_off = '{}' where id = 1");
    seamTransport()->flush();
});

afterEach(function () {
    DB::statement('drop schema if exists tenant_demo cascade');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
    // The jobs luna's walk queues would outlive this file: the truncation runs at each of its
    // own tests' start, and the next file, wrapped in a transaction, would read them.
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();
});

function keepsTenant(string $slug = 'demo'): Tenant
{
    return Tenant::query()->where('slug', $slug)->sole();
}

/**
 * The id of a row of a tenant's catalog by its name.
 */
function keepsId(string $slug, string $table, string $name): int
{
    $id = DB::table(sprintf('tenant_%s.%s', $slug, $table))->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * A hold as a visitor sends one: their own details and a note, the service with the staff
 * member named, at the Athens wall-clock start given.
 *
 * @return array<string, mixed>
 */
function visitorHold(string $slug, string $service, string $staff, string $start): array
{
    return [
        'customer_name' => KEEPS_VISITOR_NAME,
        'customer_email' => KEEPS_VISITOR_EMAIL,
        'customer_phone' => KEEPS_VISITOR_PHONE,
        'note' => KEEPS_VISITOR_NOTE,
        'items' => [['service_id' => keepsId($slug, 'services', $service), 'staff_id' => keepsId($slug, 'staff', $staff)]],
        'start' => (new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')))->format(DateTimeImmutable::RFC3339),
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return array{uuid: string, token: string}
 */
function visitorHeld(TestCase $test, string $slug, array $body): array
{
    return seamHeld($test->postJson(tenantUrl($slug, '/api/v1/holds'), $body));
}

/**
 * What a booking holds of a person: the three customer columns and the note.
 *
 * @return array{name: string, email: string, phone: string|null, note: string|null}
 */
function keptCustomer(string $slug, string $uuid): array
{
    $row = DB::table(sprintf('tenant_%s.bookings', $slug))->where('uuid', $uuid)->sole();
    $note = DB::table(sprintf('tenant_%s.booking_meta', $slug))->where('booking_id', $row->id)->where('key', 'note')->value('value');

    assert(is_string($row->customer_name) && is_string($row->customer_email));

    return [
        'name' => $row->customer_name,
        'email' => $row->customer_email,
        'phone' => is_string($row->customer_phone) ? $row->customer_phone : null,
        'note' => is_string($note) ? $note : null,
    ];
}

/**
 * The sample customer as a booking row holds it, with no note.
 *
 * @return array{name: string, email: string, phone: string, note: null}
 */
function keptSample(): array
{
    return [...DemoSalon::SAMPLE_CUSTOMER, 'note' => null];
}

function keptStatus(string $slug, string $uuid): string
{
    $status = DB::table(sprintf('tenant_%s.bookings', $slug))->where('uuid', $uuid)->value('status');

    assert(is_string($status));

    return $status;
}

/**
 * The jobs the queue holds, by the class each names.
 *
 * @return list<string>
 */
function keptJobs(): array
{
    $names = [];

    foreach (DB::table('jobs')->orderBy('id')->pluck('payload') as $payload) {
        assert(is_string($payload));
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded) && is_string($decoded['displayName'] ?? null));
        $names[] = $decoded['displayName'];
    }

    return $names;
}

/**
 * The lines a logger was told about the demo's drop, by message, with their contexts.
 *
 * @return list<array<mixed>>
 */
function keptDropLines(SeamLog $log, string $message): array
{
    return array_values(array_map(
        fn (array $record): array => $record['context'],
        array_filter($log->records, fn (array $record): bool => $record['message'] === $message),
    ));
}

it('writes a visitor\'s hold under the sample customer with no note, after validation and before the write, answers the sample\'s, refuses a bad body as any tenant does, and announces nothing', function () {
    $log = new SeamLog;
    Log::swap($log);
    $seeded = DB::table('tenant_demo.bookings')->count();
    $body = visitorHold('demo', 'Women\'s haircut', 'Eleni Papadopoulou', '2026-11-04 09:00');

    $response = $this->postJson(tenantUrl('demo', '/api/v1/holds'), $body)->assertCreated();
    $held = seamHeld($response);

    expect(keptCustomer('demo', $held['uuid']))->toBe(keptSample())
        ->and([$response->json('customer_name'), $response->json('customer_email'), $response->json('customer_phone')])->toBe(array_values(DemoSalon::SAMPLE_CUSTOMER))
        ->and(keptStatus('demo', $held['uuid']))->toBe('pending')
        ->and(DB::table('tenant_demo.bookings')->where('customer_email', KEEPS_VISITOR_EMAIL)->count())->toBe(0)
        ->and(DB::table('tenant_demo.booking_meta')->count())->toBe(0)
        ->and(seamSent())->toBe([])
        ->and(keptJobs())->toBe([])
        ->and(keptDropLines($log, 'A transition on the demo was not announced'))->toBe([['tenant' => 'demo', 'event' => BookingHeld::class]])
        ->and($log->text())->not->toContain(KEEPS_VISITOR_EMAIL)->not->toContain(KEEPS_VISITOR_NAME)->not->toContain('@');

    // A refusal is the visitor's own: the sample replaces nothing until the body has passed. It
    // reads the demo's language, Greek, since the request names none.
    $this->postJson(tenantUrl('demo', '/api/v1/holds'), [...$body, 'customer_email' => 'not an address'])
        ->assertStatus(400)
        ->assertJson(['code' => 'invalid_request', 'data' => ['detail' => apiSentence('api.validation.customer_email.email', [], SiteLanguage::Greek)]]);
    $this->postJson(tenantUrl('demo', '/api/v1/holds'), [...$body, 'note' => str_repeat('a', 501)])
        ->assertStatus(400)
        ->assertJson(['code' => 'invalid_request', 'data' => ['detail' => apiChoice(NewHoldRequest::NOTE_TOO_LONG, Intake::NOTE_MAX, SiteLanguage::Greek)]]);

    expect(DB::table('tenant_demo.bookings')->count())->toBe($seeded + 1)
        ->and(auditOffences())->toBe([]);
});

it('walks every public write route of the tenant surface on the demo with a visitor\'s details: the rows hold the sample customer\'s and the recorders hold no message', function () {
    $platform = new RecordingPlatformMessages;
    app()->instance(PlatformMessenger::class, $platform);
    $tenant = keepsTenant();
    $held = visitorHeld($this, 'demo', visitorHold('demo', 'Women\'s haircut', 'Eleni Papadopoulou', '2026-11-04 09:00'));
    $uuid = $held['uuid'];

    // The confirm: the free and pay-on-site path, carrying the token alone.
    $this->postJson(tenantUrl('demo', '/api/v1/bookings/'.$uuid.'/confirm'), ['token' => $held['token']])->assertOk();

    expect(keptStatus('demo', $uuid))->toBe('confirmed');

    // The move: the token and a start, the chain read from the rows.
    $moved = (new DateTimeImmutable('2026-11-05 11:00', new DateTimeZone('Europe/Athens')))->format(DateTimeImmutable::RFC3339);
    $this->postJson(tenantUrl('demo', '/api/v1/bookings/'.$uuid.'/reschedule'), ['token' => $held['token'], 'start' => $moved])->assertOk();

    $item = DB::table('tenant_demo.booking_items', 'item')
        ->join('tenant_demo.bookings as booking', 'booking.id', '=', 'item.booking_id')
        ->where('booking.uuid', $uuid)
        ->selectRaw("(item.start_utc at time zone 'Europe/Athens')::text as start, item.end_utc")
        ->sole();
    assert(is_string($item->start) && is_string($item->end_utc));

    expect($item->start)->toBe('2026-11-05 11:00:00');

    // The payment link's status page and its act: a settled booking is sent back to the
    // page, the provider never asked, since the demo connects none.
    $link = app(PayLinks::class)->link($tenant, $uuid, new DateTimeImmutable($item->end_utc, new DateTimeZone('UTC')));
    $this->get($link)->assertOk();
    $this->post($link)->assertStatus(303);

    // The My bookings link, with the visitor's address and with a seeded customer's: the same
    // sentence either way, no row and no message, with every key on. Then the sign-out.
    foreach ([KEEPS_VISITOR_EMAIL, DemoSalon::CUSTOMERS[0][1], DemoSalon::SAMPLE_CUSTOMER['email']] as $address) {
        $this->post(tenantUrl('demo', '/my-bookings/link'), ['email' => $address])
            ->assertRedirect(tenantUrl('demo', '/my-bookings'))
            ->assertSessionHas(MyBookingsController::FLASH, ['kind' => 'success', 'message' => MyBookingsController::SENT]);
    }

    $this->withCookie(MyBookingsCookie::NAME, 'a cookie naming nothing')->post(tenantUrl('demo', '/my-bookings/sign-out'))
        ->assertRedirect(tenantUrl('demo', '/my-bookings'));

    // The cancel, policy checked, carrying the token alone.
    $this->postJson(tenantUrl('demo', '/api/v1/bookings/'.$uuid.'/cancel'), ['token' => $held['token']])->assertOk();

    expect(keptStatus('demo', $uuid))->toBe('cancelled')
        ->and(keptCustomer('demo', $uuid))->toBe(keptSample())
        ->and(DB::table('tenant_demo.bookings')->where('customer_email', KEEPS_VISITOR_EMAIL)->orWhere('customer_name', KEEPS_VISITOR_NAME)->count())->toBe(0)
        ->and(DB::table('tenant_demo.booking_meta')->count())->toBe(0)
        ->and(DB::table('tenant_demo.customer_links')->count())->toBe(0)
        ->and(DB::table('tenant_demo.customer_sessions')->count())->toBe(0)
        ->and(seamSent())->toBe([])
        ->and(keptJobs())->toBe([])
        ->and($platform->sent)->toBe([])
        ->and(auditOffences())->toBe([]);
});

it('lives a booking\'s whole life on the demo sending nothing: a request, its approval through the signed decision link, the reminder never queued and never due, and a cancellation', function () {
    $log = new SeamLog;
    Log::swap($log);
    $tenant = keepsTenant();
    $held = visitorHeld($this, 'demo', visitorHold('demo', DemoSalon::APPROVAL_SERVICE, DemoSalon::APPROVAL_STYLIST, '2026-11-06 14:00'));
    $uuid = $held['uuid'];
    $ownerId = DB::table('users')->where('tenant_id', $tenant->id)->where('role', 'owner')->value('id');
    assert(is_int($ownerId));

    expect(keptStatus('demo', $uuid))->toBe('awaiting_approval')
        ->and(keptJobs())->toBe([])
        ->and(seamSent())->toBe([]);

    // The owner's decision, through the link the approval request would have carried. The
    // link's own lapse is judged by the application's clock, the pinned one, while the hold's
    // window is the database's, so the link is minted the window's length from the pin.
    $link = app(DecisionLinks::class)->link($tenant, $uuid, Decision::Approve, $ownerId, app(Clock::class)->now()->add(new DateInterval('PT48H')));
    $this->post($link)->assertOk();

    expect(keptStatus('demo', $uuid))->toBe('confirmed')
        ->and(keptJobs())->toBe([])
        ->and(seamSent())->toBe([]);

    // The reminder falling due: the clock past the visit, the worker run over the queue, and
    // nothing to send, since nothing was ever queued.
    app()->instance(Clock::class, FixedClock::at('2026-11-07 09:00', DemoSalon::ZONE));
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 0, '--no-interaction' => true]);

    expect(seamSent())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    FixtureDay::pinClock();
    $this->postJson(tenantUrl('demo', '/api/v1/bookings/'.$uuid.'/cancel'), ['token' => $held['token']])->assertOk();

    $lines = keptDropLines($log, 'A transition on the demo was not announced');
    $announced = array_map(fn (array $line): mixed => $line['event'] ?? null, $lines);
    $labels = array_map(fn (array $line): mixed => $line['tenant'] ?? null, $lines);

    expect(keptStatus('demo', $uuid))->toBe('cancelled')
        ->and(keptCustomer('demo', $uuid))->toBe(keptSample())
        ->and(seamSent())->toBe([])
        ->and(keptJobs())->toBe([])
        ->and($announced)->toContain(BookingHeld::class)->toContain(BookingCancelled::class)
        ->and($labels)->not->toBeEmpty()->each->toBe('demo')
        ->and($log->text())->not->toContain('@')
        ->and(auditOffences())->toBe([]);
});

it('answers the My bookings link POST on the demo as for an address with no booking, with the key on and a booking under the address: no link row, nothing queued, nothing sent, the same sentence', function () {
    $address = DemoSalon::CUSTOMERS[0][1];

    expect(DB::table('tenant_demo.bookings')->where('customer_email', $address)->count())->toBeGreaterThan(0);

    $this->post(tenantUrl('demo', '/my-bookings/link'), ['email' => $address])
        ->assertRedirect(tenantUrl('demo', '/my-bookings'))
        ->assertSessionHas(MyBookingsController::FLASH, ['kind' => 'success', 'message' => MyBookingsController::SENT]);

    expect(DB::table('tenant_demo.customer_links')->count())->toBe(0)
        ->and(keptJobs())->toBe([])
        ->and(seamSent())->toBe([]);
});

it('drops every platform message about the demo at the seam by the label, logging the label and the kind and never an address, and sends one about another tenant or about none', function () {
    $log = new SeamLog;
    Log::swap($log);
    $luna = provisionTenant('luna')->tenant;
    $messenger = app(PlatformMessenger::class);
    $rendered = 0;
    $about = function (?int $tenantId, PlatformMessageKind $kind) use ($messenger, &$rendered): void {
        $messenger->send($kind, $tenantId, function () use ($tenantId, $kind, &$rendered): PlatformMessage {
            $rendered++;

            return new PlatformMessage(
                kind: $kind,
                tenantId: $tenantId,
                toAddress: KEEPS_VISITOR_EMAIL,
                toName: KEEPS_VISITOR_NAME,
                subject: 'A message about an account',
                body: "Something happened to the account.\n",
            );
        });
    };

    $about(keepsTenant()->id, PlatformMessageKind::Suspension);
    $about(keepsTenant()->id, PlatformMessageKind::SetPassword);

    expect(seamSent())->toBe([])
        ->and(keptDropLines($log, 'A platform message about the demo was not sent'))->toBe([
            ['tenant' => 'demo', 'kind' => PlatformMessageKind::Suspension->value],
            ['tenant' => 'demo', 'kind' => PlatformMessageKind::SetPassword->value],
        ])
        ->and($log->text())->not->toContain('@')
        ->and($rendered)->toBe(0);

    $about($luna->id, PlatformMessageKind::Suspension);
    $about(null, PlatformMessageKind::OperatorSignIn);

    expect(seamAddresses())->toBe([KEEPS_VISITOR_EMAIL, KEEPS_VISITOR_EMAIL])
        ->and($rendered)->toBe(2)
        ->and($log->errors())->toBe([]);
});

it('inlines the sample customer on the demo\'s hosted page as the config\'s customer key, whatever cookie the request carries, not no-store, and keeps GET /config at the five keys', function () {
    $page = $this->withCookie(MyBookingsCookie::NAME, 'a cookie naming nothing')->get(tenantUrl('demo', '/'))->assertOk();
    $config = configOf(documentOf($page));

    expect($config)->toMatchArray([
        'tenant_name' => DemoSalon::NAME,
        'timezone' => DemoSalon::ZONE,
        'currency' => DemoSalon::CURRENCY,
        'customer' => DemoSalon::SAMPLE_CUSTOMER,
    ])
        ->and(array_keys($config))->toBe(['tenant_name', 'timezone', 'currency', 'granularity_min', 'important_info', 'default_language', 'language', 'customer'])
        ->and((string) $page->headers->get('Cache-Control'))->not->toContain('no-store');

    $fetched = $this->getJson(tenantUrl('demo', '/api/v1/config'))->assertOk()->json();
    assert(is_array($fetched));

    expect(array_keys($fetched))->toBe(['tenant_name', 'timezone', 'currency', 'granularity_min', 'important_info', 'default_language', 'language']);
});

it('keeps another tenant\'s visitor and its messages: luna\'s hold holds what was typed, its confirm queues the confirmation, the notice and the reminder, and its link POST mints a row and queues the link', function () {
    seedDemo();
    $held = visitorHeld($this, 'luna', visitorHold('luna', 'Haircut', 'Elena Papadaki', FixtureDay::START));

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/confirm'), ['token' => $held['token']])->assertOk();
    $this->post(tenantUrl('luna', '/my-bookings/link'), ['email' => KEEPS_VISITOR_EMAIL])
        ->assertRedirect(tenantUrl('luna', '/my-bookings'));

    expect(keptCustomer('luna', $held['uuid']))->toBe(['name' => KEEPS_VISITOR_NAME, 'email' => KEEPS_VISITOR_EMAIL, 'phone' => KEEPS_VISITOR_PHONE, 'note' => KEEPS_VISITOR_NOTE])
        ->and(keptJobs())->toEqualCanonicalizing([SendConfirmation::class, SendNewBookingNotice::class, SendReminder::class, SendBookingsLink::class])
        ->and(DB::table('tenant_luna.customer_links')->count())->toBe(1)
        ->and(configOf(documentOf($this->get(tenantUrl('luna', '/'))->assertOk())))->not->toHaveKey('customer');
});
