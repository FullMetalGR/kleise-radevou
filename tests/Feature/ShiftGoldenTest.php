<?php

use App\Application\Clock;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Tenancy\Slug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The public availability endpoint answers the same bytes before and after the weekday rules
 * gave way to shift patterns. The bodies were captured once, before the migration and the
 * resolver swap, into tests/Fixtures, one file per tenant: the two seeded tenants, luna and
 * pier, and the sample salon demo:reset plants. The sample salon's file was captured again once
 * its sample week was seeded with every state Shifts draws: a stylist every two weeks, a day
 * changed and a day off, so it differs from the first capture in the answers those states change
 * and in no other; luna's and pier's files are the first capture byte for byte. Each file holds,
 * for every active service offered online, the answer for any member and for each member offered
 * online who performs it, over 56 days from the pinned today in the tenant's zone.
 *
 * The clock stands on 2026-10-24, a Saturday, so the window crosses the clock change of
 * 2026-10-25 and luna's closures of 2026-12-04 and its dentist's afternoon; the sample salon's
 * pending request lapsed long before any run of this file, the predicate reading the database's
 * own now, so every run reads the rows alike.
 *
 * Once for each tenant, as the writers left the rows. The copy's own proof stands in
 * ShiftContractTest. The three files hold through the contract byte for byte: every writer now
 * writes the pattern alone and every reader reads it, so no answer moves.
 *
 * GOLDEN_CAPTURE=1 writes the sample salon's file instead of comparing; it never writes luna's
 * or pier's.
 */

const GOLDEN_TODAY = '2026-10-24 09:00';

const GOLDEN_DAYS = 56;

const GOLDEN_TENANTS = ['luna', 'pier', 'demo'];

beforeEach(function () {
    app()->instance(Clock::class, FixedClock::at(GOLDEN_TODAY, 'Europe/Athens'));
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    $this->withoutMiddleware(ThrottleRequests::class);

    seedDemo();
    Artisan::call('demo:reset');
});

function goldenFixture(string $slug): string
{
    return base_path(sprintf('tests/Fixtures/availability-%s.txt', $slug));
}

/**
 * A fixture's requests and answers, its header line left out.
 */
function goldenCaptured(string $slug): string
{
    [$header, $bodies] = explode("\n", (string) file_get_contents(goldenFixture($slug)), 2) + ['', ''];

    expect($header)->toStartWith('# '.$slug.': GET /api/v1/availability, '.GOLDEN_DAYS.' days from ');

    return $bodies;
}

/**
 * Every request of one tenant and its answer, as the fixture spells them below its header line:
 * per request its query string on one line and its status and body on the next. The header is the
 * fixture's own record of when it was captured, so it is written at a capture and never compared.
 */
function goldenBodies(TestCase $test, string $slug): string
{
    $schema = Slug::of($slug)->schemaName();
    $tenant = DB::table('tenants')->where('slug', $slug)->sole();

    assert(is_string($tenant->timezone));

    $zone = new DateTimeZone($tenant->timezone);
    $from = app(Clock::class)->now()->setTimezone($zone);
    $to = $from->add(new DateInterval(sprintf('P%dD', GOLDEN_DAYS - 1)));
    $lines = [];

    $services = DB::table($schema.'.services')->where('status', 'active')->where('online', true)->orderBy('sort')->orderBy('id')->pluck('id')->all();

    foreach ($services as $serviceId) {
        $members = DB::table($schema.'.service_staff', 'link')
            ->join($schema.'.staff as staff', 'staff.id', '=', 'link.staff_id')
            ->where('link.service_id', $serviceId)
            ->where('staff.status', 'active')
            ->where('staff.online', true)
            ->orderBy('staff.id')
            ->pluck('staff.id')
            ->all();

        foreach ([null, ...$members] as $staffId) {
            $query = http_build_query([
                'items' => (string) json_encode([['service_id' => $serviceId, 'staff_id' => $staffId]]),
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ]);
            $response = $test->get(tenantUrl($slug, '/api/v1/availability?'.$query));

            $lines[] = $query;
            $lines[] = $response->status().' '.$response->getContent();
        }
    }

    return implode("\n", $lines)."\n";
}

it('answers every service and member of the seeded tenants byte for byte as their first capture did, and the sample salon as its sample week was captured, over 56 days', function () {
    if (getenv('GOLDEN_CAPTURE') === '1') {
        $header = sprintf('# demo: GET /api/v1/availability, %d days from %s; captured by GOLDEN_CAPTURE=1 vendor/bin/pest tests/Feature/ShiftGoldenTest.php', GOLDEN_DAYS, substr(GOLDEN_TODAY, 0, 10));
        file_put_contents(goldenFixture('demo'), $header."\n".goldenBodies($this, 'demo'));

        expect(file_exists(goldenFixture('demo')))->toBeTrue();

        return;
    }

    foreach (GOLDEN_TENANTS as $slug) {
        expect(goldenBodies($this, $slug))->toBe(goldenCaptured($slug), $slug);
    }
});
