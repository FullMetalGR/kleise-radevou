<?php

use App\Application\Dto\AvailabilityQuery;
use App\Application\Dto\BookableService;
use App\Application\Dto\SegmentChoice;
use App\Application\QueryAvailability;
use App\Application\Repository\AvailabilityRepository;
use App\Application\Repository\ServiceRepository;
use App\Application\TenantContext;
use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\BlockRange;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\MaskBuilder;
use App\Domain\Availability\SegmentShape;
use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Availability\WallClockTime;
use App\Domain\Availability\WindowGeometry;
use App\Domain\Booking\Actor as PolicyActor;
use App\Domain\Chain\Chain;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Tests\Support\FixedClock;

/*
 * The use case with no database: services from an array, masks built through the real
 * MaskBuilder over whatever window the use case asks for, the ask recorded, and a clock the
 * test sets. The wall-clock arithmetic of the engine is proven in its own tests; here the
 * questions are what the use case asks, what it refuses, and what it keeps.
 */

final class QueryAvailabilityTestServices implements ServiceRepository
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

final class QueryAvailabilityTestMasks implements AvailabilityRepository
{
    /** @var list<array{list<int>, string, int}> */
    public array $asked = [];

    /**
     * @param  array<int, list<array{string, string}>>  $hours  open and close per staff member, every weekday
     * @param  array<int, list<BlockRange>>  $blocks  per staff member
     */
    public function __construct(private DateTimeZone $zone, private array $hours = [], private array $blocks = []) {}

    public function masks(array $staffIds, string $firstDay, int $dayCount): StaffDayMaskSet
    {
        $this->asked[] = [$staffIds, $firstDay, $dayCount];
        $window = WindowGeometry::spanning($firstDay, $dayCount, $this->zone, Granularity::default());
        $byStaff = [];

        foreach ($staffIds as $staffId) {
            $rules = [];

            foreach ($this->hours[$staffId] ?? [] as [$open, $close]) {
                foreach (range(1, 7) as $weekday) {
                    $rules[] = new AvailabilityRule($weekday, WallClockTime::of($open), WallClockTime::of($close));
                }
            }

            $days = [];

            foreach ($window->days as $day) {
                $days[] = (new MaskBuilder)->build($day, $staffId, $rules, [], $this->blocks[$staffId] ?? []);
            }

            $byStaff[$staffId] = $days;
        }

        return new StaffDayMaskSet($window, $byStaff);
    }

    public function loads(array $staffIds, string $day): array
    {
        return [];
    }
}

final readonly class QueryAvailabilityTestTenant implements TenantContext
{
    public function __construct(private string $zone = 'Europe/Athens') {}

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->zone);
    }

    public function currencyCode(): string
    {
        return 'EUR';
    }
}

/**
 * @param  list<int>  $staffIds
 */
function bookableService(int $id, int $durationMin, array $staffIds, int $gapMin = 0, int $bufferBeforeMin = 0, int $bufferAfterMin = 0, int $leadTimeMin = 0, int $horizonDays = 60): BookableService
{
    return new BookableService($id, new SegmentShape($durationMin, $bufferBeforeMin, $bufferAfterMin), $gapMin, $leadTimeMin, $horizonDays, $staffIds, new Money(2500, Currency::of('EUR')), PaymentMode::Onsite, false, null, ApprovalTimeout::Expire);
}

/**
 * @param  list<BookableService>  $services
 * @param  array<int, list<array{string, string}>>  $hours
 * @param  array<int, list<BlockRange>>  $blocks
 * @return array{QueryAvailability, QueryAvailabilityTestMasks}
 */
function queryAvailabilityWith(array $services, array $hours, string $now = '2026-04-06 06:00:00', string $zone = 'Europe/Athens', array $blocks = []): array
{
    $masks = new QueryAvailabilityTestMasks(new DateTimeZone($zone), $hours, $blocks);
    $byId = [];

    foreach ($services as $service) {
        $byId[$service->id] = $service;
    }

    return [new QueryAvailability(new QueryAvailabilityTestServices($byId), $masks, new QueryAvailabilityTestTenant($zone), FixedClock::at($now)), $masks];
}

/**
 * @param  list<array{int, int|null}>  $items
 */
function queryOf(array $items, string $from = '2026-04-06', string $to = '2026-04-07', bool $sameStaff = false): AvailabilityQuery
{
    return new AvailabilityQuery(array_map(fn (array $item): SegmentChoice => new SegmentChoice($item[0], $item[1]), $items), $from, $to, $sameStaff);
}

/**
 * @param  list<DateTimeImmutable>  $starts
 * @return list<string>
 */
function startsAs(array $starts, string $format = 'H:i'): array
{
    return array_map(fn (DateTimeImmutable $start): string => $start->format($format), $starts);
}

it('answers the starts of one segment where its staff members can start it, keyed by every day asked for, in the tenant zone', function () {
    [$useCase] = queryAvailabilityWith(
        [bookableService(1, 30, [1, 2])],
        [1 => [['09:00', '10:00']], 2 => [['09:45', '10:30']]],
    );

    $starts = $useCase->query(queryOf([[1, null]], '2026-04-06', '2026-04-08'))->byDay;

    expect(array_keys($starts))->toBe(['2026-04-06', '2026-04-07'])
        ->and(startsAs($starts['2026-04-06']))->toBe(['09:00', '09:05', '09:10', '09:15', '09:20', '09:25', '09:30', '09:45', '09:50', '09:55', '10:00'])
        ->and(startsAs($starts['2026-04-07']))->toBe(startsAs($starts['2026-04-06']))
        ->and($starts['2026-04-06'][0]->getTimezone()->getName())->toBe('Europe/Athens')
        ->and($starts['2026-04-06'][0]->format(DATE_RFC3339))->toBe('2026-04-06T09:00:00+03:00');
});

it('asks the repository once, for every staff member the chain can touch, over the window widened by a day before and by a day plus the chain\'s span after', function () {
    [$useCase, $masks] = queryAvailabilityWith(
        [bookableService(1, 30, [4, 2], gapMin: 30), bookableService(2, 60, [9, 2], bufferAfterMin: 15)],
        [],
    );

    $useCase->query(queryOf([[1, null], [2, 9]], '2026-04-06', '2026-04-13'));
    $useCase->query(queryOf([[1, 4], [2, 9]], '2026-04-06', '2026-04-07'));

    // A span of 135 minutes rounds up to one day; the window runs from the 5th for eight days
    // plus one plus one, so its last day is the 14th, the day after the last day asked for.
    expect($masks->asked)->toBe([
        [[2, 4, 9], '2026-04-05', 10],
        [[4, 9], '2026-04-05', 4],
    ]);
});

it('widens by as many days as the span needs: a chain a day and a half long adds two days after the last asked for', function () {
    [$useCase, $masks] = queryAvailabilityWith([bookableService(1, 1440, [1]), bookableService(2, 720, [1])], []);

    $useCase->query(queryOf([[1, null], [2, null]], '2026-04-06', '2026-04-07'));

    expect($masks->asked)->toBe([[[1], '2026-04-05', 1 + 1 + 1 + 2]]);
});

it('offers a start on the last day asked for whose chain runs past that day\'s midnight, because the window was widened', function () {
    [$useCase] = queryAvailabilityWith(
        [bookableService(1, 60, [1])],
        [1 => [['23:00', '24:00'], ['00:00', '01:00']]],
    );

    $starts = $useCase->query(queryOf([[1, null]], '2026-04-06', '2026-04-07'))->byDay;

    expect(array_keys($starts))->toBe(['2026-04-06'])
        ->and(startsAs($starts['2026-04-06']))->toBe(['23:00', '23:05', '23:10', '23:15', '23:20', '23:25', '23:30', '23:35', '23:40', '23:45', '23:50', '23:55']);
});

it('offers a start on the first day asked for whose setup buffer falls on the day before, when that day is free there and not when it is booked', function () {
    $service = bookableService(1, 30, [1], bufferBeforeMin: 30);
    $hours = [1 => [['00:00', '01:00']]];
    [$free] = queryAvailabilityWith([$service], $hours, now: '2026-04-05 12:00:00');
    [$booked] = queryAvailabilityWith([$service], $hours, now: '2026-04-05 12:00:00', blocks: [1 => [new BlockRange(
        new DateTimeImmutable('2026-04-05 23:45', new DateTimeZone('Europe/Athens')),
        new DateTimeImmutable('2026-04-06 00:00', new DateTimeZone('Europe/Athens')),
    )]]);

    expect(startsAs($free->query(queryOf([[1, null]]))->byDay['2026-04-06']))->toBe(['00:00', '00:05', '00:10', '00:15', '00:20', '00:25', '00:30'])
        ->and(startsAs($booked->query(queryOf([[1, null]]))->byDay['2026-04-06']))->toBe(['00:30']);
});

it('drops starts inside the largest lead time from now and days past the smallest horizon from the tenant\'s today', function () {
    [$useCase] = queryAvailabilityWith(
        [bookableService(1, 30, [1], leadTimeMin: 60, horizonDays: 5), bookableService(2, 30, [1], leadTimeMin: 120, horizonDays: 1)],
        [1 => [['09:00', '13:00']]],
        now: '2026-04-06 06:30:00',
    );

    $starts = $useCase->query(queryOf([[1, null], [2, null]], '2026-04-06', '2026-04-09'))->byDay;

    expect(startsAs($starts['2026-04-06'])[0])->toBe('11:30')
        ->and(startsAs($starts['2026-04-07'])[0])->toBe('09:00')
        ->and($starts['2026-04-08'])->toBe([]);
});

it('offers the business every future start, inside the lead time and past the horizon, while a caller saying nothing is answered as a customer is, and the past is gone for both', function () {
    [$useCase] = queryAvailabilityWith(
        [bookableService(1, 30, [1], leadTimeMin: 60, horizonDays: 5), bookableService(2, 30, [1], leadTimeMin: 120, horizonDays: 1)],
        [1 => [['09:00', '13:00']]],
        now: '2026-04-06 06:30:00', // 09:30 in Athens
    );

    $business = $useCase->query(queryOf([[1, null], [2, null]], '2026-04-06', '2026-04-09'), PolicyActor::Admin)->byDay;
    $customer = $useCase->query(queryOf([[1, null], [2, null]], '2026-04-06', '2026-04-09'))->byDay;
    $far = $useCase->query(queryOf([[1, null], [2, null]], '2027-01-04', '2027-01-05'), PolicyActor::Admin)->byDay;

    expect(startsAs($business['2026-04-06'])[0])->toBe('09:30')
        ->and(startsAs($business['2026-04-08'])[0])->toBe('09:00')
        ->and(startsAs($far['2027-01-04'])[0])->toBe('09:00')
        ->and(startsAs($customer['2026-04-06'])[0])->toBe('11:30')
        ->and($customer['2026-04-08'])->toBe([]);
});

it('derives today from now in the tenant\'s zone, not from the date now is spelled on', function () {
    // 03:00Z on the 7th is 20:00 on the 6th in Los Angeles: today is the 6th there, and a
    // horizon of zero days keeps the 6th and drops the 7th.
    [$useCase] = queryAvailabilityWith(
        [bookableService(1, 30, [1], horizonDays: 0)],
        [1 => [['19:00', '23:00']]],
        now: '2026-04-07 03:00:00',
        zone: 'America/Los_Angeles',
    );

    $starts = $useCase->query(queryOf([[1, null]], '2026-04-06', '2026-04-08'))->byDay;

    expect(startsAs($starts['2026-04-06'], 'Y-m-d H:i P')[0])->toBe('2026-04-06 20:00 -07:00')
        ->and($starts['2026-04-06'])->toHaveCount(31)
        ->and($starts['2026-04-07'])->toBe([]);
});

it('honours the same-staff preference: a start the plain form offers is refused when no one member can carry both segments', function () {
    $services = [bookableService(1, 30, [1, 2], gapMin: 30), bookableService(2, 30, [1, 2])];
    $hours = [1 => [['09:00', '10:00']], 2 => [['10:00', '11:00']]];
    [$plain] = queryAvailabilityWith($services, $hours);
    [$same] = queryAvailabilityWith($services, $hours);

    expect(startsAs($plain->query(queryOf([[1, null], [2, null]]))->byDay['2026-04-06']))->toBe(['09:00', '09:05', '09:10', '09:15', '09:20', '09:25', '09:30'])
        ->and(startsAs($same->query(queryOf([[1, null], [2, null]], sameStaff: true))->byDay['2026-04-06']))->toBe([]);
});

it('narrows a segment to the customer\'s pick', function () {
    [$useCase] = queryAvailabilityWith(
        [bookableService(1, 30, [1, 2])],
        [1 => [['09:00', '10:00']], 2 => [['12:00', '13:00']]],
    );

    expect(startsAs($useCase->query(queryOf([[1, 2]]))->byDay['2026-04-06']))->toBe(['12:00', '12:05', '12:10', '12:15', '12:20', '12:25', '12:30']);
});

it('answers every day empty without reading a mask when some segment\'s service has nobody to perform it', function () {
    [$useCase, $masks] = queryAvailabilityWith([bookableService(1, 30, [1]), bookableService(2, 30, [])], [1 => [['09:00', '10:00']]]);

    expect($useCase->query(queryOf([[1, null], [2, null]], '2026-04-06', '2026-04-08'))->byDay)->toBe(['2026-04-06' => [], '2026-04-07' => []])
        ->and($masks->asked)->toBe([]);
});

it('refuses a service the catalog does not offer and a pick who does not perform the service, naming the item, before reading a mask', function () {
    [$useCase, $masks] = queryAvailabilityWith([bookableService(1, 30, [1, 2])], []);

    expect(fn () => $useCase->query(queryOf([[1, null], [7, null]])))
        ->toThrow(InvalidArgumentException::class, 'Item 1 names a service that is not available.')
        ->and(fn () => $useCase->query(queryOf([[1, 3]])))
        ->toThrow(InvalidArgumentException::class, 'Item 0 names a staff member who does not perform its service.')
        ->and($masks->asked)->toBe([]);
});

/**
 * A block over whole tenant-local days in Athens, from the first day's midnight to the last's
 * end: the unit harness's stand-in for a closure, since its hours repeat every weekday.
 */
function wholeDaysBlocked(string $first, string $last): BlockRange
{
    $zone = new DateTimeZone('Europe/Athens');

    return new BlockRange(new DateTimeImmutable($first.' 00:00', $zone), (new DateTimeImmutable($last.' 00:00', $zone))->add(new DateInterval('P1D')));
}

it('names, for an empty window, the first later day with a start, reading forward from the window\'s end in seven-day windows through the same mask reads', function () {
    [$useCase, $masks] = queryAvailabilityWith(
        [bookableService(1, 30, [1])],
        [1 => [['09:00', '10:00']]],
        blocks: [1 => [wholeDaysBlocked('2026-04-06', '2026-04-21')]],
    );

    $query = queryOf([[1, null]], '2026-04-06', '2026-04-13');

    // The first seven days after the window are blocked too, so the walk crosses into a second
    // window and stops at its first day with a start.
    expect($useCase->nextAvailableFrom($query, 5))->toBe('2026-04-22')
        ->and($masks->asked)->toBe([
            [[1], '2026-04-12', 1 + 7 + 1 + 1],
            [[1], '2026-04-19', 1 + 7 + 1 + 1],
        ]);
});

it('stops at the smallest horizon across the chain: the last day inside it is found and nothing past it is read', function () {
    $hours = [1 => [['09:00', '10:00']]];
    $blocked = [1 => [wholeDaysBlocked('2026-04-06', '2026-04-15')]];

    // Today is the 6th; the chain's smallest horizon is ten days, so the 16th is the last day.
    [$inside, $insideMasks] = queryAvailabilityWith([bookableService(1, 30, [1], horizonDays: 60), bookableService(2, 30, [1], horizonDays: 10)], $hours, blocks: $blocked);
    [$past, $pastMasks] = queryAvailabilityWith([bookableService(1, 30, [1], horizonDays: 60), bookableService(2, 30, [1], horizonDays: 9)], $hours, blocks: $blocked);

    expect($inside->nextAvailableFrom(queryOf([[1, null], [2, null]], '2026-04-06', '2026-04-13'), 5))->toBe('2026-04-16')
        ->and($insideMasks->asked)->toBe([[[1], '2026-04-12', 1 + 4 + 1 + 1]])
        ->and($past->nextAvailableFrom(queryOf([[1, null], [2, null]], '2026-04-06', '2026-04-13'), 5))->toBeNull()
        ->and($pastMasks->asked)->toBe([[[1], '2026-04-12', 1 + 3 + 1 + 1]]);
});

it('reads nothing for a window that ends past the horizon, nor for a chain nobody can perform', function () {
    [$late, $lateMasks] = queryAvailabilityWith([bookableService(1, 30, [1], horizonDays: 5)], [1 => [['09:00', '10:00']]]);
    [$nobody, $nobodyMasks] = queryAvailabilityWith([bookableService(1, 30, [1]), bookableService(2, 30, [])], [1 => [['09:00', '10:00']]]);

    expect($late->nextAvailableFrom(queryOf([[1, null]], '2026-04-11', '2026-04-12'), 5))->toBeNull()
        ->and($lateMasks->asked)->toBe([])
        ->and($nobody->nextAvailableFrom(queryOf([[1, null], [2, null]]), 5))->toBeNull()
        ->and($nobodyMasks->asked)->toBe([]);
});

it('counts a day as holding a start only on the start interval, so a day whose starts all fall off it is passed over', function () {
    // A thirty-minute service in 09:05 to 09:40 starts at 09:05 and 09:10 alone, every day.
    [$useCase] = queryAvailabilityWith([bookableService(1, 30, [1], horizonDays: 9)], [1 => [['09:05', '09:40']]], blocks: [1 => [wholeDaysBlocked('2026-04-06', '2026-04-06')]]);

    expect($useCase->nextAvailableFrom(queryOf([[1, null]], '2026-04-06', '2026-04-07'), 5))->toBe('2026-04-07')
        ->and($useCase->nextAvailableFrom(queryOf([[1, null]], '2026-04-06', '2026-04-07'), 15))->toBeNull();
});

it('judges the walk by now and the lead time as the answer does: a later start today is no later day', function () {
    [$useCase] = queryAvailabilityWith([bookableService(1, 30, [1], leadTimeMin: 120)], [1 => [['09:00', '10:00']]], now: '2026-04-07 06:00:00', blocks: [1 => [wholeDaysBlocked('2026-04-06', '2026-04-06')]]);

    // 09:00 in Athens on the 7th is now; the lead time of two hours leaves nothing that day.
    expect($useCase->nextAvailableFrom(queryOf([[1, null]], '2026-04-06', '2026-04-07'), 5))->toBe('2026-04-08');
});

it('caps the walk at 365 days from where it starts whatever the chain\'s horizon says: a start on the last day inside the cap is found, one a day past it is not, and nothing past it is read', function () {
    $hours = [1 => [['09:00', '10:00']]];
    $query = queryOf([[1, null]], '2026-04-06', '2026-04-13');

    // The walk starts at the window's end, the 13th; 365 days on is 2027-04-13, so the 12th of
    // April 2027 is the last day it reads, though the chain's horizon would reach ten years.
    [$inside, $insideMasks] = queryAvailabilityWith([bookableService(1, 30, [1], horizonDays: 3650)], $hours, blocks: [1 => [wholeDaysBlocked('2026-04-06', '2027-04-11')]]);
    [$past, $pastMasks] = queryAvailabilityWith([bookableService(1, 30, [1], horizonDays: 3650)], $hours, blocks: [1 => [wholeDaysBlocked('2026-04-06', '2027-04-12')]]);

    expect(Chain::MAX_HORIZON_DAYS)->toBe(365)
        ->and($inside->nextAvailableFrom($query, 5))->toBe('2027-04-12')
        ->and($insideMasks->asked)->toHaveCount(53)
        ->and($insideMasks->asked[52] ?? null)->toBe([[1], '2027-04-11', 1 + 1 + 1 + 1])
        ->and($past->nextAvailableFrom($query, 5))->toBeNull()
        ->and($pastMasks->asked)->toHaveCount(53)
        ->and($pastMasks->asked[52] ?? null)->toBe([[1], '2027-04-11', 1 + 1 + 1 + 1]);
});

it('leaves a horizon of 365 days whole for a window starting today: the cap counts from the walk\'s start, which is past today', function () {
    // Today is the 6th; a horizon of 365 days ends on 2027-04-06, the last day the walk reads.
    [$useCase] = queryAvailabilityWith([bookableService(1, 30, [1], horizonDays: 365)], [1 => [['09:00', '10:00']]], blocks: [1 => [wholeDaysBlocked('2026-04-06', '2027-04-05')]]);

    expect($useCase->nextAvailableFrom(queryOf([[1, null]], '2026-04-06', '2026-04-07'), 5))->toBe('2027-04-06');
});
