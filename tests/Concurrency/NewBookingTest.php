<?php

use App\Application\Clock;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Domain\Audit\Actor;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Enum\BookingSource;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresBookingWriter;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Concurrency\Observer;
use Tests\Support\FixedClock;

/*
 * The manual booking under the write protocol: two operators booking the same member and the
 * same start at once both plan from a catalog that holds neither booking, so neither is refused
 * by the planner's masks, both reach the lock, one lands confirmed with no hold and the other is
 * refused by the re-validation as the overlap naming its segment, with nothing of the loser's
 * written. The tenant is provisioned for real and dropped afterwards, with a Cut of sixty
 * minutes on its implicit member, who works Monday to Friday 09:00 to 17:00 in Athens;
 * 2026-10-01 is a Thursday, so 09:00 UTC is noon there. The clock stands on the day before.
 *
 * Worker closures run in another process and call only autoloaded code and the framework, so
 * the clock they stand on is spelled inside each closure.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_manual cascade');
    provisionTenant('manual');
    $this->cut = DB::table('tenant_manual.services')->insertGetId(serviceRow('Cut', ['duration_min' => 60, 'price_minor' => 2500]));
    DB::table('tenant_manual.service_staff')->insert(['service_id' => $this->cut, 'staff_id' => 1]);
    app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
});

afterEach(function () {
    ConcurrentRun::abandonAll();
    DB::statement('drop schema if exists tenant_manual cascade');
});

/**
 * A worker booking one Cut on the implicit member for a customer who called, as the signed-in
 * user 7, reporting the booking or the segment that conflicted and every statement it ran.
 */
function manualWorker(int $cut, string $start): Closure
{
    return function () use ($cut, $start): array {
        app()->instance(Clock::class, FixedClock::at('2026-09-30 12:00:00'));
        $tenant = Tenant::query()->where('slug', 'manual')->sole();
        $request = new HoldRequest(new Customer('Casey Customer', 'casey@example.com'), [new SegmentChoice($cut, 1)], new DateTimeImmutable($start, new DateTimeZone('UTC')), new Intake(BookingSource::Admin));
        $observer = Observer::attach();

        try {
            $booked = app(HoldBookingFactory::class)->for($tenant, Pool::Whole)->book($request, Actor::user(7));
            $report = ['booked' => $booked->uuid, 'total' => $booked->total->minor];
        } catch (SegmentConflict $conflict) {
            $report = ['conflict' => $conflict->segment];
        }

        return [...$report, 'statements' => $observer->statements];
    };
}

it('lets exactly one of two operators released together book the same member at the same start: both reach the lock, the winner lands confirmed with no hold, the loser gets the overlap naming segment 0 from the re-validation and writes nothing', function () {
    $outcomes = ConcurrentRun::of([
        'a' => manualWorker($this->cut, '2026-10-01 09:00'),
        'b' => manualWorker($this->cut, '2026-10-01 09:00'),
    ])->run();

    $a = returnedArray($outcomes['a']);
    $b = returnedArray($outcomes['b']);
    $winner = isset($a['booked']) ? $a : $b;
    $loser = $winner === $a ? $b : $a;
    $rows = DB::table('tenant_manual.bookings')->get()->all();
    $trail = DB::table('tenant_manual.audit_log')->get()->all();

    expect($outcomes['a']->threw())->toBeFalse()
        ->and($outcomes['b']->threw())->toBeFalse()
        ->and($winner['booked'] ?? null)->toBeString()
        ->and($winner['total'] ?? null)->toBe(2500)
        ->and($loser['conflict'] ?? null)->toBe(0)
        ->and($loser['statements'] ?? null)->toContain(PostgresBookingWriter::overlapSql())
        ->and($loser['statements'] ?? null)->not->toContain(PostgresBookingWriter::insertBookingSql())
        ->and($rows)->toHaveCount(1)
        ->and((array) $rows[0])->toMatchArray(['status' => 'confirmed', 'hold_class' => null, 'hold_expires_at' => null])
        ->and(DB::table('tenant_manual.booking_items')->where('blocks', true)->count())->toBe(1)
        ->and(array_map(fn (object $row): array => [$row->action, $row->actor], $trail))->toBe([['confirmed', 'user:7']]);
});
