<?php

use App\Application\BookingUnknown;
use App\Application\Dto\AvailableStarts;
use App\Application\Dto\BookableService;
use App\Application\Dto\DayWindow;
use App\Application\Dto\SoldItem;
use App\Application\QueryMoveAvailability;
use App\Application\Repository\AvailabilityLeavingOut;
use App\Application\Repository\ServiceRepository;
use App\Application\Repository\SoldChain;
use App\Application\TenantContext;
use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\MaskBuilder;
use App\Domain\Availability\SegmentShape;
use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Availability\WallClockTime;
use App\Domain\Availability\WindowGeometry;
use App\Domain\Booking\LockKey;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Tests\Support\FixedClock;

/*
 * The per-booking read with no database: the booking as sold from an array, the catalog from
 * another, and masks built through the real MaskBuilder over whatever window the use case asks
 * for, every staff member open 09:00 to 13:00 each day and nothing busy, which is what the rows
 * read with the booking left out would say. The ask is recorded, so the tests can hold what the
 * use case asks for as well as what it answers.
 *
 * 2026-04-06 is a Monday and April in Athens is UTC+3.
 */

final class MoveAvailabilityTestSold implements SoldChain
{
    /**
     * @param  array<string, non-empty-list<SoldItem>>  $bookings
     */
    public function __construct(private array $bookings) {}

    public function of(string $uuid): ?array
    {
        return $this->bookings[$uuid] ?? null;
    }
}

final class MoveAvailabilityTestServices implements ServiceRepository
{
    /**
     * @param  array<int, BookableService>  $services
     */
    public function __construct(private array $services) {}

    public function bookable(array $serviceIds): array
    {
        return array_filter($this->services, fn (BookableService $service): bool => in_array($service->id, $serviceIds, true));
    }
}

final class MoveAvailabilityTestMasks implements AvailabilityLeavingOut
{
    /** @var list<array{string, list<string>, list<int>, string, int}> */
    public array $asked = [];

    public function masksLeavingOut(string $uuid, LockKeySet $standsOn, array $staffIds, string $firstDay, int $dayCount): StaffDayMaskSet
    {
        $this->asked[] = [$uuid, array_map(fn (LockKey $key): string => $key->toString(), $standsOn->all()), $staffIds, $firstDay, $dayCount];
        $window = WindowGeometry::spanning($firstDay, $dayCount, new DateTimeZone('Europe/Athens'), Granularity::default());
        $rules = array_map(fn (int $weekday): AvailabilityRule => new AvailabilityRule($weekday, WallClockTime::of('09:00'), WallClockTime::of('13:00')), range(1, 7));
        $byStaff = [];

        foreach ($staffIds as $staffId) {
            $byStaff[$staffId] = array_map(fn ($day) => (new MaskBuilder)->build($day, $staffId, $rules, [], []), $window->days);
        }

        return new StaffDayMaskSet($window, $byStaff);
    }
}

final readonly class MoveAvailabilityTestTenant implements TenantContext
{
    public function zone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Athens');
    }

    public function currencyCode(): string
    {
        return 'EUR';
    }
}

function moveAthens(string $text): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone('Europe/Athens'));
}

/**
 * @param  list<int>  $staffIds
 */
function moveService(int $id, int $durationMin, array $staffIds): BookableService
{
    return new BookableService($id, new SegmentShape($durationMin, 0, 0), 0, 0, 60, $staffIds, new Money(2500, Currency::of('EUR')), PaymentMode::Onsite, false, null, ApprovalTimeout::Expire);
}

/**
 * One item sold on a member at an Athens start for a number of minutes.
 */
function soldAt(int $serviceId, int $staffId, string $start, int $minutes): SoldItem
{
    $from = moveAthens($start);

    return new SoldItem($serviceId, $staffId, new StaffBlock($staffId, $from, $from->add(new DateInterval(sprintf('PT%dM', $minutes)))), new Money(2500, Currency::of('EUR')), $from);
}

/**
 * @param  array<string, non-empty-list<SoldItem>>  $bookings
 * @param  list<BookableService>  $services
 * @return array{QueryMoveAvailability, MoveAvailabilityTestMasks}
 */
function queryMoveWith(array $bookings, array $services): array
{
    $masks = new MoveAvailabilityTestMasks;
    $byId = [];

    foreach ($services as $service) {
        $byId[$service->id] = $service;
    }

    return [new QueryMoveAvailability(
        new MoveAvailabilityTestSold($bookings),
        new MoveAvailabilityTestServices($byId),
        $masks,
        new MoveAvailabilityTestTenant,
        FixedClock::at('2026-04-06 05:00:00'),
    ), $masks];
}

/**
 * Every Athens reading from one to another inclusive on a day, five minutes apart.
 *
 * @return list<string>
 */
function moveEvery(string $day, string $from, string $to): array
{
    $readings = [];

    for ($at = moveAthens($day.' '.$from); $at <= moveAthens($day.' '.$to); $at = $at->add(new DateInterval('PT5M'))) {
        $readings[] = $at->format('H:i');
    }

    return $readings;
}

/**
 * @return array<string, list<string>>
 */
function moveReadings(AvailableStarts $starts): array
{
    return array_map(fn (array $day): array => array_map(fn (DateTimeImmutable $start): string => $start->setTimezone(new DateTimeZone('Europe/Athens'))->format('H:i'), $day), $starts->byDay);
}

it('answers the chain the booking was sold as over the window, leaving its own staff-days out of what is busy and its current start out of the answer', function () {
    [$query, $masks] = queryMoveWith(['b-1' => [soldAt(7, 3, '2026-04-06 09:00', 45)]], [moveService(7, 45, [3, 4])]);

    $answer = $query->query('b-1', new DayWindow('2026-04-06', '2026-04-07'));

    expect(moveReadings($answer))->toBe(['2026-04-06' => array_values(array_diff(moveEvery('2026-04-06', '09:00', '12:15'), ['09:00']))])
        ->and($masks->asked)->toBe([['b-1', ['3:2026-04-06'], [3], '2026-04-05', 4]]);
});

it('pins every segment to the member the rows name, whoever else performs the service', function () {
    [$query, $masks] = queryMoveWith(
        ['b-1' => [soldAt(7, 4, '2026-04-06 09:00', 30), soldAt(8, 3, '2026-04-06 09:30', 30)]],
        [moveService(7, 30, [3, 4]), moveService(8, 30, [3, 4])],
    );

    $query->query('b-1', new DayWindow('2026-04-06', '2026-04-07'));

    expect($masks->asked[0][1])->toBe(['3:2026-04-06', '4:2026-04-06'])
        ->and($masks->asked[0][2])->toBe([3, 4]);
});

it('names both local days for an item whose contended range crosses local midnight', function () {
    [$query, $masks] = queryMoveWith(['b-1' => [soldAt(7, 3, '2026-04-06 23:30', 60)]], [moveService(7, 60, [3])]);

    $query->query('b-1', new DayWindow('2026-04-06', '2026-04-07'));

    expect($masks->asked[0][1])->toBe(['3:2026-04-06', '3:2026-04-07']);
});

it('answers every day empty, reading no mask, for {case}', function (array $services) {
    /** @var list<BookableService> $services */
    [$query, $masks] = queryMoveWith(['b-1' => [soldAt(7, 3, '2026-04-06 09:00', 45)]], $services);

    $answer = $query->query('b-1', new DayWindow('2026-04-06', '2026-04-08'));

    expect(moveReadings($answer))->toBe(['2026-04-06' => [], '2026-04-07' => []])
        ->and($masks->asked)->toBe([]);
})->with([
    'a service withdrawn from the catalog' => [[moveService(8, 45, [3])]],
    'a sold member who no longer performs it' => [[moveService(7, 45, [4])]],
]);

it('refuses a booking nobody of this tenant has', function () {
    [$query] = queryMoveWith([], [moveService(7, 45, [3])]);

    $query->query('b-9', new DayWindow('2026-04-06', '2026-04-07'));
})->throws(BookingUnknown::class);
