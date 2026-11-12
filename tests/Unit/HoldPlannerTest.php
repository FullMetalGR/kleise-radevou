<?php

use App\Application\ChainUnavailable;
use App\Application\Clock;
use App\Application\Dto\BookableService;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldItem;
use App\Application\Dto\HoldPlan;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\Dto\SoldItem;
use App\Application\HoldPlanner;
use App\Application\Repository\SettingsRepository;
use App\Application\ServiceNotBookable;
use App\Application\StaffNotEligible;
use App\Domain\Availability\BlockRange;
use App\Domain\Availability\SegmentShape;
use App\Domain\Booking\Actor as PolicyActor;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatch;
use App\Domain\Money\Money;
use Tests\Support\ArrayServices;
use Tests\Support\FixedClock;
use Tests\Support\FixedSettings;
use Tests\Support\FixedTenant;
use Tests\Support\FixtureAvailability;

/*
 * The planner with no database, over the fakes under tests/Support: services from an array,
 * masks built through the real MaskBuilder over whatever window it asks for, loads from an
 * array, the settings TTLs given, and a clock the test sets. Every ask on the availability
 * port is recorded, so a refusal before the masks are read is proven by the port never having
 * been asked.
 *
 * 2026-04-06 is a Monday and April in Athens is UTC+3, so 09:00 local is 06:00Z. Now is 08:00
 * local unless a test says otherwise.
 */

/**
 * @param  list<int>  $staffIds
 */
function plannerService(int $id, int $durationMin, array $staffIds, int $gapMin = 0, int $before = 0, int $after = 0, int $priceMinor = 2500, string $currency = 'EUR', PaymentMode $mode = PaymentMode::Onsite, bool $approval = false, ?int $approvalHours = null, ApprovalTimeout $timeout = ApprovalTimeout::Expire, int $leadTimeMin = 0, int $horizonDays = 60): BookableService
{
    return new BookableService($id, new SegmentShape($durationMin, $before, $after), $gapMin, $leadTimeMin, $horizonDays, $staffIds, new Money($priceMinor, Currency::of($currency)), $mode, $approval, $approvalHours, $timeout);
}

/**
 * @param  list<BookableService>  $services
 * @param  array<int, list<array{string, string}>>  $hours
 * @param  array<int, list<BlockRange>>  $blocks
 * @param  array<int, int>  $loads
 * @return array{HoldPlanner, FixtureAvailability}
 */
function plannerWith(array $services, array $hours, array $blocks = [], array $loads = [], string $now = '2026-04-06 05:00:00', ?SettingsRepository $settings = null, ?Clock $clock = null, string $currency = 'EUR'): array
{
    $availability = new FixtureAvailability(new DateTimeZone('Europe/Athens'), $hours, $blocks, $loads);

    return [new HoldPlanner(new ArrayServices($services), $availability, $settings ?? new FixedSettings, new FixedTenant(currency: $currency), $clock ?? FixedClock::at($now)), $availability];
}

/**
 * @param  list<array{int, int|null}>  $items
 */
function plannerRequest(array $items, string $start = '2026-04-06 09:00', bool $sameStaff = false): HoldRequest
{
    return new HoldRequest(
        new Customer('Casey Customer', 'casey@example.com'),
        array_map(fn (array $item): SegmentChoice => new SegmentChoice($item[0], $item[1]), $items),
        new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')),
        new Intake(BookingSource::Embed),
        $sameStaff,
    );
}

function plannerBlock(string $start, string $end): BlockRange
{
    $zone = new DateTimeZone('Europe/Athens');

    return new BlockRange(new DateTimeImmutable($start, $zone), new DateTimeImmutable($end, $zone));
}

/**
 * Each item as staff, start-end, block start-end and processing end in Athens wall-clock time.
 *
 * @return list<string>
 */
function plannerItems(HoldPlan $plan): array
{
    $zone = new DateTimeZone('Europe/Athens');
    $at = fn (DateTimeImmutable $instant): string => $instant->setTimezone($zone)->format('H:i');

    return array_map(fn (HoldItem $item): string => sprintf(
        '%d %s-%s [%s-%s]%s',
        $item->staffId,
        $at($item->start),
        $at($item->end),
        $at($item->blockStart),
        $at($item->blockEnd),
        $item->processingEndsAt === null ? '' : ' gap to '.$at($item->processingEndsAt),
    ), $plan->items);
}

it('plans a cut then a colour with a processing gap on different people: each segment starts after the earlier durations and gaps, the gap sets the processing end, prices come from the services and the total is their sum', function () {
    [$planner] = plannerWith(
        [plannerService(1, 45, [1, 2], priceMinor: 3500), plannerService(2, 60, [3], gapMin: 30, priceMinor: 8000, mode: PaymentMode::Online)],
        [1 => [['09:00', '18:00']], 2 => [['09:00', '18:00']], 3 => [['09:00', '18:00']]],
    );

    $plan = $planner->plan(plannerRequest([[1, 1], [2, 3]]));

    expect(plannerItems($plan))->toBe(['1 09:00-09:45 [09:00-09:45]', '3 09:45-10:45 [09:45-10:45] gap to 11:15'])
        ->and(array_map(fn (HoldItem $item): int => $item->serviceId, $plan->items))->toBe([1, 2])
        ->and(array_map(fn (HoldItem $item): int => $item->price->minor, $plan->items))->toBe([3500, 8000])
        ->and($plan->total->minor)->toBe(11500)
        ->and($plan->total->currency->code)->toBe('EUR')
        ->and($plan->status)->toBe(BookingStatus::Pending)
        ->and($plan->holdClass)->toBe(HoldClass::Checkout)
        ->and($plan->paymentMode)->toBe(PaymentMode::Online)
        ->and($plan->requiresApproval)->toBeFalse()
        ->and($plan->onApprovalTimeout)->toBe(ApprovalTimeout::Expire)
        ->and($plan->customer->email)->toBe('casey@example.com');
});

it('starts the segment after a gap when the gap ends, the member free during it, and counts the timeline in elapsed seconds across a clock change', function () {
    [$planner] = plannerWith(
        [plannerService(2, 60, [3], gapMin: 30), plannerService(1, 45, [1])],
        [1 => [['00:00', '24:00']], 3 => [['00:00', '24:00']]],
        now: '2026-03-28 12:00:00',
    );

    $plan = $planner->plan(plannerRequest([[2, 3], [1, 1]]));
    $spring = $planner->plan(plannerRequest([[2, 3], [1, 1]], '2026-03-29 02:00'));
    $zone = new DateTimeZone('Europe/Athens');

    // 02:00 on the spring day is 00:00Z; ninety elapsed minutes later the wall clock reads 04:30.
    expect(plannerItems($plan))->toBe(['3 09:00-10:00 [09:00-10:00] gap to 10:30', '1 10:30-11:15 [10:30-11:15]'])
        ->and($spring->items[1]->start->getTimestamp() - $spring->items[0]->start->getTimestamp())->toBe(90 * 60)
        ->and($spring->items[1]->start->setTimezone($zone)->format('H:i'))->toBe('04:30');
});

it('rounds durations, buffers and gaps up to whole slots, each on its own, and widens the block by the rounded buffers', function () {
    [$planner] = plannerWith(
        [plannerService(1, 43, [1], gapMin: 31, before: 7, after: 1), plannerService(2, 20, [1])],
        [1 => [['09:00', '18:00']]],
    );

    expect(plannerItems($planner->plan(plannerRequest([[1, 1], [2, 1]]))))->toBe(['1 09:00-09:45 [08:50-09:50] gap to 10:20', '1 10:20-10:40 [10:20-10:40]']);
});

it('honours a pick, else takes the least loaded eligible member with the lowest id on a tie, the same answer on every run, and under same_staff one member for every segment', function () {
    $services = [plannerService(1, 30, [1, 2, 3]), plannerService(2, 30, [2, 3])];
    $hours = [1 => [['09:00', '18:00']], 2 => [['09:00', '18:00']], 3 => [['09:00', '18:00']]];

    [$picked] = plannerWith($services, $hours, loads: [1 => 0, 2 => 0, 3 => 0]);
    [$tied] = plannerWith($services, $hours);
    [$loaded] = plannerWith($services, $hours, loads: [1 => 3, 2 => 2, 3 => 1]);
    [$sameStaff] = plannerWith($services, $hours, loads: [1 => 0, 2 => 5, 3 => 1]);

    $staffOf = fn (HoldPlan $plan): array => array_map(fn (HoldItem $item): int => $item->staffId, $plan->items);
    $runs = [];

    for ($run = 0; $run < 5; $run++) {
        $runs[] = $staffOf($loaded->plan(plannerRequest([[1, null], [2, null]])));
    }

    expect($staffOf($picked->plan(plannerRequest([[1, 3], [2, 2]]))))->toBe([3, 2])
        ->and($staffOf($tied->plan(plannerRequest([[1, null], [2, null]]))))->toBe([1, 2])
        ->and($staffOf($loaded->plan(plannerRequest([[1, null], [2, null]]))))->toBe([3, 3])
        ->and(array_unique($runs, SORT_REGULAR))->toHaveCount(1)
        ->and($staffOf($sameStaff->plan(plannerRequest([[1, null], [2, null]], sameStaff: true))))->toBe([3, 3]);
});

it('reads the masks over the day before, the day, and one more day plus the chain span in whole days, and the loads on the tenant-local day of the start', function () {
    [$planner, $availability] = plannerWith(
        [plannerService(1, 30, [1, 2]), plannerService(2, 60, [2], gapMin: 30, after: 15)],
        [1 => [['00:00', '24:00']], 2 => [['00:00', '24:00']]],
    );

    $planner->plan(plannerRequest([[1, null], [2, null]], '2026-04-06 23:30'));

    // The span is 30 + 60 + 15 minutes, under a day: the window is the Sunday, the Monday, the
    // Tuesday and one day for the span.
    expect($availability->asked)->toBe([
        ['masks', [[1, 2], '2026-04-05', 4]],
        ['loads', [[1, 2], '2026-04-06']],
    ]);
});

it('refuses an unknown or inactive service naming the item, a pick outside the eligible members, and a service nobody performs, all before any row of availability is read', function () {
    [$planner, $availability] = plannerWith(
        [plannerService(1, 30, [1]), plannerService(2, 30, [])],
        [1 => [['09:00', '18:00']]],
    );

    $unknown = null;

    try {
        $planner->plan(plannerRequest([[1, 1], [9, null]]));
    } catch (ServiceNotBookable $unknown) {
    }

    $nobody = null;

    try {
        $planner->plan(plannerRequest([[1, 1], [2, null]]));
    } catch (ChainUnavailable $nobody) {
    }

    expect($unknown)->toBeInstanceOf(ServiceNotBookable::class)
        ->and($unknown?->segment)->toBe(1)
        ->and($unknown?->serviceId)->toBe(9)
        ->and($unknown?->getMessage())->toBe('Item 1 names service 9, which is not available.')
        ->and(fn () => $planner->plan(plannerRequest([[1, 2]])))->toThrow(StaffNotEligible::class, 'Item 0 names staff member 2, who does not perform its service.')
        ->and($nobody)->toBeInstanceOf(ChainUnavailable::class)
        ->and($nobody?->segment)->toBe(1)
        ->and($availability->asked)->toBe([]);
});

it('refuses a chain mixing two currencies before any availability is read', function () {
    [$planner, $availability] = plannerWith(
        [plannerService(1, 30, [1]), plannerService(2, 30, [1], currency: 'GBP')],
        [1 => [['09:00', '18:00']]],
    );

    expect(fn () => $planner->plan(plannerRequest([[1, 1], [2, 1]])))->toThrow(CurrencyMismatch::class, 'Money in EUR cannot be combined with money in GBP.')
        ->and($availability->asked)->toBe([]);
});

it('tells a start that has already passed apart from one inside the lead time, each naming its rule, before any availability is read', function () {
    [$planner, $availability] = plannerWith(
        [plannerService(1, 30, [1], leadTimeMin: 120)],
        [1 => [['09:00', '18:00']]],
        now: '2026-04-06 06:30:00', // 09:30 in Athens; the lead time reaches 11:30
    );

    $rule = function (string $start) use ($planner): ?string {
        try {
            $planner->plan(plannerRequest([[1, 1]], $start));
        } catch (ChainUnavailable $refused) {
            return $refused->rule;
        }

        return null;
    };

    expect($rule('2026-04-06 09:00'))->toBe(ChainUnavailable::PAST)
        ->and($rule('2026-04-06 10:00'))->toBe(ChainUnavailable::LEAD_TIME)
        ->and($availability->asked)->toBe([]);
});

it('refuses a start inside the largest lead time as unavailable at segment zero, the lead time counted in elapsed minutes from the clock, before any availability is read', function () {
    [$planner, $availability] = plannerWith(
        [plannerService(1, 30, [1]), plannerService(2, 30, [1], leadTimeMin: 120)],
        [1 => [['09:00', '18:00']]],
        now: '2026-04-06 04:30:00', // 07:30 in Athens; the two-hour lead time reaches 09:30
    );

    $refused = null;

    try {
        $planner->plan(plannerRequest([[1, 1], [2, 1]], '2026-04-06 09:25'));
    } catch (ChainUnavailable $refused) {
    }

    expect($refused)->toBeInstanceOf(ChainUnavailable::class)
        ->and($refused?->segment)->toBe(0)
        ->and($refused?->getMessage())->toBe('The chain cannot start before 2026-04-06 06:30:00 UTC, its lead time.')
        ->and($availability->asked)->toBe([])
        ->and(plannerItems($planner->plan(plannerRequest([[1, 1], [2, 1]], '2026-04-06 09:30'))))->toBe(['1 09:30-10:00 [09:30-10:00]', '1 10:00-10:30 [10:00-10:30]']);
});

it('refuses a start on a date past the smallest horizon, counted in calendar days from the tenant\'s today, before any availability is read', function () {
    [$planner, $availability] = plannerWith(
        [plannerService(1, 30, [1], horizonDays: 60), plannerService(2, 30, [1], horizonDays: 2)],
        [1 => [['09:00', '18:00']]],
        now: '2026-04-06 21:30:00', // already the Tuesday in Athens, so the horizon ends on the Thursday
    );

    $refused = null;

    try {
        $planner->plan(plannerRequest([[1, 1], [2, 1]], '2026-04-10 09:00'));
    } catch (ChainUnavailable $refused) {
    }

    expect($refused)->toBeInstanceOf(ChainUnavailable::class)
        ->and($refused?->segment)->toBe(0)
        ->and($refused?->getMessage())->toBe('The chain cannot start on a date after 2026-04-09, its horizon.')
        ->and($availability->asked)->toBe([])
        ->and($planner->plan(plannerRequest([[1, 1], [2, 1]], '2026-04-09 09:00'))->items)->toHaveCount(2);
});

it('binds the lead time and the horizon to the customer alone: the business places inside the lead time and past the horizon, a caller saying nothing is bound as a customer, and a start already passed is refused to both as passed', function () {
    [$planner] = plannerWith(
        [plannerService(1, 30, [1], leadTimeMin: 120, horizonDays: 2)],
        [1 => [['09:00', '18:00']]],
        now: '2026-04-06 06:30:00', // 09:30 in Athens; the lead time reaches 11:30, the horizon the 8th
    );

    $rule = function (string $start, ?PolicyActor $by) use ($planner): ?string {
        try {
            $by === null ? $planner->plan(plannerRequest([[1, 1]], $start)) : $planner->plan(plannerRequest([[1, 1]], $start), $by);
        } catch (ChainUnavailable $refused) {
            return $refused->rule;
        }

        return null;
    };

    expect($rule('2026-04-06 10:00', null))->toBe(ChainUnavailable::LEAD_TIME)
        ->and($rule('2026-04-06 10:00', PolicyActor::Customer))->toBe(ChainUnavailable::LEAD_TIME)
        ->and($rule('2026-04-09 09:00', null))->toBe(ChainUnavailable::HORIZON)
        ->and($rule('2026-04-09 09:00', PolicyActor::Customer))->toBe(ChainUnavailable::HORIZON)
        ->and($rule('2026-04-06 10:00', PolicyActor::Admin))->toBeNull()
        ->and($rule('2026-04-09 09:00', PolicyActor::Admin))->toBeNull()
        ->and($rule('2027-03-01 09:00', PolicyActor::Admin))->toBeNull()
        ->and($rule('2026-04-06 09:00', PolicyActor::Admin))->toBe(ChainUnavailable::PAST)
        ->and($rule('2026-04-06 09:00', PolicyActor::Customer))->toBe(ChainUnavailable::PAST)
        ->and($rule('2026-04-06 10:02', PolicyActor::Admin))->toBe(ChainUnavailable::OFF_GRID);
});

it('binds a move as it binds a hold: the business moves inside the lead time and past the horizon, a customer and a caller saying nothing are refused both, and the past is refused to both', function () {
    [$planner] = plannerWith(
        [plannerService(1, 30, [1], leadTimeMin: 120, horizonDays: 2)],
        [1 => [['09:00', '18:00']]],
        now: '2026-04-06 06:30:00',
    );
    $zone = new DateTimeZone('Europe/Athens');
    $sold = [new SoldItem(1, 1, new StaffBlock(1, new DateTimeImmutable('2026-04-07 09:00', $zone), new DateTimeImmutable('2026-04-07 09:30', $zone)), new Money(2500, Currency::of('EUR')), new DateTimeImmutable('2026-04-07 09:00', $zone))];

    $rule = function (string $start, ?PolicyActor $by) use ($planner, $sold, $zone): ?string {
        try {
            ($by === null ? $planner->replanning($sold) : $planner->replanning($sold, [], $by))->at(new DateTimeImmutable($start, $zone));
        } catch (ChainUnavailable $refused) {
            return $refused->rule;
        }

        return null;
    };

    expect($rule('2026-04-06 10:00', null))->toBe(ChainUnavailable::LEAD_TIME)
        ->and($rule('2026-04-09 09:00', PolicyActor::Customer))->toBe(ChainUnavailable::HORIZON)
        ->and($rule('2026-04-06 10:00', PolicyActor::Admin))->toBeNull()
        ->and($rule('2026-05-20 09:00', PolicyActor::Admin))->toBeNull()
        ->and($rule('2026-04-06 09:00', PolicyActor::Admin))->toBe(ChainUnavailable::PAST)
        ->and($planner->replan($sold, new DateTimeImmutable('2026-04-06 10:00', $zone), [], PolicyActor::Admin))->toHaveCount(1);
});

it('refuses a start off the tenant\'s slot grid', function () {
    [$planner] = plannerWith([plannerService(1, 30, [1])], [1 => [['09:00', '18:00']]]);

    expect(fn () => $planner->plan(plannerRequest([[1, 1]], '2026-04-06 09:02')))->toThrow(ChainUnavailable::class, 'grid');
});

it('refuses a start no eligible member can take by the advisory masks, naming the first segment with nobody, or segment zero under same_staff, and writes nothing', function () {
    [$planner] = plannerWith(
        [plannerService(1, 30, [1, 2]), plannerService(2, 30, [1, 2])],
        [1 => [['09:00', '12:00']], 2 => [['09:00', '10:00']]],
        blocks: [1 => [plannerBlock('2026-04-06 11:00', '2026-04-06 12:00')]],
    );

    $outsideHours = null;

    try {
        $planner->plan(plannerRequest([[1, null], [2, 2]], '2026-04-06 09:45'));
    } catch (ChainUnavailable $outsideHours) {
    }

    $booked = null;

    try {
        $planner->plan(plannerRequest([[1, 1], [2, 1]], '2026-04-06 10:15'));
    } catch (ChainUnavailable $booked) {
    }

    $sameStaff = null;

    try {
        $planner->plan(plannerRequest([[1, null], [2, null]], '2026-04-06 10:15', sameStaff: true));
    } catch (ChainUnavailable $sameStaff) {
    }

    // At 09:45 the first segment is member 1's alone, since member 2 closes at 10:00, and the
    // second at 10:15 is theirs too: the plain form places it.
    expect($outsideHours?->segment)->toBe(1)
        ->and($outsideHours?->getMessage())->toBe('No eligible staff member can start segment 1 of a chain starting at 2026-04-06 06:45:00 UTC.')
        ->and($booked?->segment)->toBe(1)
        ->and($sameStaff?->segment)->toBe(0)
        ->and(array_map(fn (HoldItem $item): int => $item->staffId, $planner->plan(plannerRequest([[1, null], [2, null]], '2026-04-06 09:45'))->items))->toBe([1, 1]);
});

it('copies payment_mode as online over onsite over free, requires_approval when any segment requires it, and lands an approval chain awaiting approval on the approval class', function () {
    $hours = [1 => [['09:00', '18:00']]];
    [$free] = plannerWith([plannerService(1, 30, [1], mode: PaymentMode::Free), plannerService(2, 30, [1], mode: PaymentMode::Free)], $hours);
    [$onsite] = plannerWith([plannerService(1, 30, [1], mode: PaymentMode::Free), plannerService(2, 30, [1], mode: PaymentMode::Onsite)], $hours);
    [$online] = plannerWith([plannerService(1, 30, [1], mode: PaymentMode::Online), plannerService(2, 30, [1], mode: PaymentMode::Onsite)], $hours);
    [$approval] = plannerWith([plannerService(1, 30, [1]), plannerService(2, 30, [1], approval: true)], $hours);

    $two = plannerRequest([[1, 1], [2, 1]]);
    $approved = $approval->plan($two);

    expect($free->plan($two)->paymentMode)->toBe(PaymentMode::Free)
        ->and($onsite->plan($two)->paymentMode)->toBe(PaymentMode::Onsite)
        ->and($online->plan($two)->paymentMode)->toBe(PaymentMode::Online)
        ->and($free->plan($two)->requiresApproval)->toBeFalse()
        ->and($approved->requiresApproval)->toBeTrue()
        ->and($approved->status)->toBe(BookingStatus::AwaitingApproval)
        ->and($approved->holdClass)->toBe(HoldClass::Approval);
});

it('takes the checkout TTL from the settings row, and for an approval chain the smallest window among the approving segments, each the service\'s own hours or the settings default', function () {
    $hours = [1 => [['09:00', '18:00']]];
    $settings = new FixedSettings(checkoutMinutes: 20, approvalHours: 72);
    [$checkout] = plannerWith([plannerService(1, 30, [1])], $hours, settings: $settings);
    [$defaulted] = plannerWith([plannerService(1, 30, [1], approval: true)], $hours, settings: $settings);
    [$own] = plannerWith([plannerService(1, 30, [1], approval: true, approvalHours: 6), plannerService(2, 30, [1], approval: true), plannerService(3, 30, [1], approvalHours: 1)], $hours, settings: $settings);

    expect($checkout->plan(plannerRequest([[1, 1]]))->ttlMinutes)->toBe(20)
        ->and($defaulted->plan(plannerRequest([[1, 1]]))->ttlMinutes)->toBe(72 * 60)
        ->and($own->plan(plannerRequest([[1, 1], [2, 1], [3, 1]]))->ttlMinutes)->toBe(6 * 60);
});

it('copies on_approval_timeout as auto_approve only when every approving segment says so, and expire for a chain requiring no approval whatever its services say', function () {
    $hours = [1 => [['09:00', '18:00']]];
    [$mixed] = plannerWith([plannerService(1, 30, [1], approval: true, timeout: ApprovalTimeout::AutoApprove), plannerService(2, 30, [1], approval: true)], $hours);
    [$auto] = plannerWith([plannerService(1, 30, [1], approval: true, timeout: ApprovalTimeout::AutoApprove), plannerService(2, 30, [1], timeout: ApprovalTimeout::Expire)], $hours);
    [$none] = plannerWith([plannerService(1, 30, [1], timeout: ApprovalTimeout::AutoApprove)], $hours);

    expect($mixed->plan(plannerRequest([[1, 1], [2, 1]]))->onApprovalTimeout)->toBe(ApprovalTimeout::Expire)
        ->and($auto->plan(plannerRequest([[1, 1], [2, 1]]))->onApprovalTimeout)->toBe(ApprovalTimeout::AutoApprove)
        ->and($none->plan(plannerRequest([[1, 1]]))->onApprovalTimeout)->toBe(ApprovalTimeout::Expire)
        ->and($none->plan(plannerRequest([[1, 1]]))->requiresApproval)->toBeFalse();
});

it('puts the boundary at the later segment\'s start where two segments on one member overlap in block range, keeps both buffers whole across a gap longer than both, and leaves buffers on different members alone', function () {
    $hours = [1 => [['09:00', '18:00']], 2 => [['09:00', '18:00']]];
    [$meeting] = plannerWith([plannerService(1, 30, [1], after: 15), plannerService(2, 60, [1], before: 15)], $hours);
    [$apart] = plannerWith([plannerService(1, 30, [1], gapMin: 45, after: 15), plannerService(2, 60, [1], before: 15)], $hours);
    [$others] = plannerWith([plannerService(1, 30, [1], after: 15), plannerService(2, 60, [2], before: 15)], $hours);
    [$filled] = plannerWith([plannerService(1, 30, [1], gapMin: 30, after: 5), plannerService(2, 60, [1], before: 40)], $hours);

    expect(plannerItems($meeting->plan(plannerRequest([[1, 1], [2, 1]]))))->toBe(['1 09:00-09:30 [09:00-09:30]', '1 09:30-10:30 [09:30-10:30]'])
        ->and(plannerItems($apart->plan(plannerRequest([[1, 1], [2, 1]]))))->toBe(['1 09:00-09:30 [09:00-09:45] gap to 10:15', '1 10:15-11:15 [10:00-11:15]'])
        ->and(plannerItems($others->plan(plannerRequest([[1, 1], [2, 2]]))))->toBe(['1 09:00-09:30 [09:00-09:45]', '2 09:30-10:30 [09:15-10:30]'])
        ->and(plannerItems($filled->plan(plannerRequest([[1, 1], [2, 1]]))))->toBe(['1 09:00-09:30 [09:00-10:00] gap to 10:00', '1 10:00-11:00 [10:00-11:00]']);
});

it('applies the boundary rule to any two of one member\'s segments in order, another member\'s segment between them, so a plan never trips the self-contention guard and the cleanup outrunning the later block stays on it', function () {
    $hours = [1 => [['09:00', '18:00']], 2 => [['09:00', '18:00']]];
    [$planner] = plannerWith([plannerService(1, 30, [1], after: 60), plannerService(2, 10, [2]), plannerService(3, 20, [1])], $hours);

    // Member 1 is occupied 09:00 to 10:30, cut at 09:40 where the third segment starts: the
    // hour of cleanup after the first segment outlives the twenty minutes of the third, and
    // the third's row carries the rest of it rather than anyone losing it.
    expect(plannerItems($planner->plan(plannerRequest([[1, 1], [2, 2], [3, 1]]))))->toBe(['1 09:00-09:30 [09:00-09:40]', '2 09:30-09:40 [09:30-09:40]', '1 09:40-10:00 [09:40-10:30]']);
});

it('keeps the member\'s occupied union whole where the boundary falls inside a block: a cleanup outrunning the later item\'s block, and a setup reaching back before the earlier item\'s block', function () {
    $hours = [1 => [['09:00', '18:00']]];
    [$cleanup] = plannerWith([plannerService(1, 60, [1], after: 30), plannerService(2, 15, [1])], $hours);
    [$setup] = plannerWith([plannerService(1, 15, [1]), plannerService(2, 60, [1], before: 30)], $hours);

    // Truncating the earlier block at the later start alone would end the member's day at
    // 10:15 with half an hour of cleanup still to run; widening the later block keeps it.
    expect(plannerItems($cleanup->plan(plannerRequest([[1, 1], [2, 1]]))))->toBe(['1 09:00-10:00 [09:00-10:00]', '1 10:00-10:15 [10:00-10:30]'])
        // The mirror: the second service's half-hour setup starts before the first segment
        // does, and the earlier block keeps what the later one gives up.
        ->and(plannerItems($setup->plan(plannerRequest([[1, 1], [2, 1]], '2026-04-06 10:00'))))->toBe(['1 10:00-10:15 [09:45-10:15]', '1 10:15-11:15 [10:15-11:15]']);
});

it('leaves one member\'s chain of three segments occupied continuously, each pair of rows meeting at the later segment\'s start, whichever block reaches over which', function () {
    $hours = [1 => [['09:00', '18:00']]];
    [$overlapping] = plannerWith([plannerService(1, 30, [1], after: 15), plannerService(2, 30, [1], before: 15, after: 15), plannerService(3, 30, [1], before: 15, after: 20)], $hours);
    [$reachingBack] = plannerWith([plannerService(1, 15, [1]), plannerService(2, 15, [1]), plannerService(3, 30, [1], before: 30)], $hours);
    $request = plannerRequest([[1, 1], [2, 1], [3, 1]]);

    // Three blocks that each run into the next: the member is occupied 09:00 to 10:50, which
    // is the first setup to the last cleanup, and the rows tile it.
    expect(plannerItems($overlapping->plan($request)))->toBe(['1 09:00-09:30 [09:00-09:30]', '1 09:30-10:00 [09:30-10:00]', '1 10:00-10:30 [10:00-10:50]'])
        // The third segment's setup reaches back over the whole of the second segment, whose
        // block only touches the first: all three are one stretch even so, and none overlaps.
        ->and(plannerItems($reachingBack->plan($request)))->toBe(['1 09:00-09:15 [09:00-09:15]', '1 09:15-09:30 [09:15-09:30]', '1 09:30-10:00 [09:30-10:00]']);
});
