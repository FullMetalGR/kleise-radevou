<?php

use App\Application\ChainUnavailable;
use App\Application\Checkpoint;
use App\Application\Dto\BookableService;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldItem;
use App\Application\Dto\HoldPlan;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\InsertedHold;
use App\Application\Dto\Intake;
use App\Application\Dto\LockedBooking;
use App\Application\Dto\NewHold;
use App\Application\Dto\ReapedHold;
use App\Application\Dto\SegmentChoice;
use App\Application\Event\BookingExpired;
use App\Application\Event\BookingHeld;
use App\Application\Event\TransitionEvent;
use App\Application\Event\TransitionPublisher;
use App\Application\HoldBooking;
use App\Application\HoldPlanner;
use App\Application\Repository\AvailabilityRepository;
use App\Application\Repository\BookingRepository;
use App\Application\Repository\BookingWriter;
use App\Application\ServiceNotBookable;
use App\Application\TenantContext;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditEntry;
use App\Domain\Availability\BlockRange;
use App\Domain\Availability\SegmentShape;
use App\Domain\Booking\LockKey;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\Revisions;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Tests\Support\ArrayServices;
use Tests\Support\FixedClock;
use Tests\Support\FixedSettings;
use Tests\Support\FixedTenant;
use Tests\Support\FixtureAvailability;

/*
 * The use case with no database: a writer that records every step it is asked for and answers
 * the availability step from fixtures, a repository that records the transaction around them,
 * and the checkpoint observer writing into the same log, so the order the protocol runs in is
 * one list. The planner in front of it runs over the fakes under tests/Support.
 *
 * 2026-04-06 is a Monday and April in Athens is UTC+3, so 09:00 local is 06:00Z.
 */

final class HoldBookingTestWriter implements BookingWriter
{
    /** @var list<array<mixed>> */
    public array $calls = [];

    public ?NewHold $inserted = null;

    /**
     * @param  list<int>  $conflicting  the segments the overlap check answers yes for
     * @param  array<int, list<array{string, string}>>  $hours  what the masks under lock say each member's hours are
     * @param  array<int, list<BlockRange>>  $blocks  what the masks under lock say is booked
     * @param  list<ReapedHold>  $reaped  the holds the reap answers it let go
     */
    public function __construct(private array $conflicting = [], private array $hours = [], private array $blocks = [], private array $reaped = []) {}

    public function lock(LockKeySet $keys): Revisions
    {
        $this->calls[] = ['lock', array_map(fn (LockKey $key): string => $key->toString(), $keys->all())];

        return new Revisions(array_map(fn (LockKey $key): array => [$key, 7], $keys->all()));
    }

    public function lockBooking(string $uuid): bool
    {
        throw new LogicException('A hold locks no booking row: it is writing the booking that does not exist yet.');
    }

    public function readLocked(): LockedBooking
    {
        throw new LogicException('A hold re-reads no booking under a lock.');
    }

    public function compareAndSet(BookingStatus $found, BookingStatus $target, array $columns): void
    {
        throw new LogicException('A hold inserts; it sets no status against one it found.');
    }

    public function unblockItems(): void
    {
        throw new LogicException('A hold blocks its items; the reap is what unblocks any.');
    }

    public function deleteItems(): void
    {
        throw new LogicException('A hold has no items to delete; it is inserting them.');
    }

    public function insertItems(array $items, Money $total): void
    {
        throw new LogicException('A hold inserts its items with its booking row, through insertHold.');
    }

    public function clearSession(): void
    {
        throw new LogicException('A hold has no session to clear; only a move clears one.');
    }

    public function reapExpiredHolds(array $staffIds, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        $this->calls[] = ['reap', $staffIds, $windowStart->format('H:i'), $windowEnd->format('H:i')];

        return $this->reaped;
    }

    public function overlapsBlockingItem(StaffBlock $block): bool
    {
        $segment = count(array_filter($this->calls, fn (array $call): bool => $call[0] === 'overlap'));
        $this->calls[] = ['overlap', $block->staffId, $block->start->format('H:i'), $block->end->format('H:i')];

        return in_array($segment, $this->conflicting, true);
    }

    public function availability(): AvailabilityRepository
    {
        $this->calls[] = ['availability'];

        return new FixtureAvailability(new DateTimeZone('Europe/Athens'), $this->hours, $this->blocks);
    }

    public function insertHold(NewHold $hold): InsertedHold
    {
        $this->calls[] = ['insert'];
        $this->inserted = $hold;

        return new InsertedHold('7b7f0e5a-0000-4000-8000-000000000001', new DateTimeImmutable('2026-04-06 06:15', new DateTimeZone('UTC')));
    }

    public function writeAudit(AuditEntry $entry): void
    {
        $this->calls[] = ['audit', $entry->bookingUuid, $entry->action->value, $entry->actor->value, $entry->payload];
    }

    public function warmAfterCommit(LockKeySet $keys, Revisions $revisions): void
    {
        $this->calls[] = ['warm', array_map(fn (LockKey $key): string => $key->toString(), $keys->all()), $revisions->toArray()];
    }
}

final class HoldBookingTestPublisher implements TransitionPublisher
{
    public function __construct(private HoldBookingTestWriter $writer) {}

    public function publish(TransitionEvent $event): void
    {
        $this->writer->calls[] = ['publish', $event::class];
    }
}

final class HoldBookingTestRepository implements BookingRepository
{
    public function __construct(public HoldBookingTestWriter $writer) {}

    public function transaction(Closure $work): mixed
    {
        $this->writer->calls[] = ['begin'];

        try {
            $result = $work($this->writer);
        } catch (Throwable $thrown) {
            $this->writer->calls[] = ['rollback'];

            throw $thrown;
        }

        $this->writer->calls[] = ['commit'];

        return $result;
    }
}

/**
 * The use case over a writer whose masks under lock say member 1 works 09:00 to 12:00 and
 * member 2 works 09:00 to 18:00, unless the test says otherwise, in front of a planner whose
 * advisory masks say both work all day. The publisher writes into the writer's own call log,
 * so where an event went out is one entry in the same ordered list as the statements.
 *
 * @param  list<int>  $conflicting
 * @param  array<int, list<array{string, string}>>  $hours
 * @param  array<int, list<BlockRange>>  $blocks
 * @param  list<ReapedHold>  $reaped
 * @return array{HoldBooking, HoldBookingTestWriter, FixtureAvailability}
 */
function holdBookingWith(array $conflicting = [], ?array $hours = null, array $blocks = [], array $reaped = []): array
{
    $writer = new HoldBookingTestWriter($conflicting, $hours ?? [1 => [['09:00', '12:00']], 2 => [['09:00', '18:00']]], $blocks, $reaped);
    Checkpoint::observe(function (string $name) use ($writer): void {
        $writer->calls[] = ['checkpoint', $name];
    });

    $advisory = new FixtureAvailability(new DateTimeZone('Europe/Athens'), [1 => [['00:00', '24:00']], 2 => [['00:00', '24:00']]]);
    $services = new ArrayServices([
        new BookableService(1, new SegmentShape(60, 10, 5), 0, 0, 60, [1, 2], new Money(2500, Currency::of('EUR')), PaymentMode::Onsite, false, null, ApprovalTimeout::Expire),
        new BookableService(2, new SegmentShape(60), 0, 0, 60, [1, 2], new Money(1500, Currency::of('EUR')), PaymentMode::Free, false, null, ApprovalTimeout::Expire),
    ]);
    $planner = new HoldPlanner($services, $advisory, new FixedSettings, new FixedTenant, FixedClock::at('2026-04-06 05:00:00'));

    return [new HoldBooking($planner, new HoldBookingTestRepository($writer), new FixedTenant, new HoldBookingTestPublisher($writer)), $writer, $advisory];
}

function unitItem(int $staffId, string $start, string $end, ?string $blockStart = null, ?string $blockEnd = null, int $price = 2500, int $serviceId = 1): HoldItem
{
    $zone = new DateTimeZone('Europe/Athens');

    return new HoldItem(
        $staffId,
        $serviceId,
        new DateTimeImmutable($start, $zone),
        new DateTimeImmutable($end, $zone),
        new DateTimeImmutable($blockStart ?? $start, $zone),
        new DateTimeImmutable($blockEnd ?? $end, $zone),
        null,
        new Money($price, Currency::of('EUR')),
    );
}

function unitPlan(HoldItem ...$items): HoldPlan
{
    return new HoldPlan(new Customer('Casey Customer', 'casey@example.com', '+30 210 0000000'), array_values($items), BookingStatus::Pending, HoldClass::Checkout, 15, PaymentMode::Onsite, false, ApprovalTimeout::Expire, new Intake(BookingSource::Embed));
}

function unitBlock(string $start, string $end): BlockRange
{
    $zone = new DateTimeZone('Europe/Athens');

    return new BlockRange(new DateTimeImmutable($start, $zone), new DateTimeImmutable($end, $zone));
}

afterEach(function () {
    Checkpoint::observe(null);
});

it('runs the steps in the protocol order inside one transaction: lock, checkpoint, reap, one overlap check per item, the availability read, checkpoint, insert, the audit row, then the masks kept for after the commit under the revisions the lock returned, and publishes only once the commit has returned', function () {
    [$useCase, $writer] = holdBookingWith();

    $held = $useCase->execute(unitPlan(
        unitItem(2, '2026-04-06 09:00', '2026-04-06 10:00', '2026-04-06 08:50', '2026-04-06 10:05'),
        unitItem(1, '2026-04-06 10:00', '2026-04-06 11:00'),
    ), Actor::customer());

    expect($writer->calls)->toBe([
        ['begin'],
        ['lock', ['1:2026-04-06', '2:2026-04-06']],
        ['checkpoint', 'hold.locked'],
        ['reap', [2, 1], '08:50', '11:00'],
        ['overlap', 2, '08:50', '10:05'],
        ['overlap', 1, '10:00', '11:00'],
        ['availability'],
        ['checkpoint', 'hold.validated'],
        ['insert'],
        ['audit', '7b7f0e5a-0000-4000-8000-000000000001', 'held', 'customer', [
            'status_to' => 'pending',
            'hold_class' => 'checkout',
            'hold_expires_at' => '2026-04-06T06:15:00.000000+00:00',
            'item_count' => 2,
        ]],
        ['warm', ['1:2026-04-06', '2:2026-04-06'], ['1:2026-04-06' => 7, '2:2026-04-06' => 7]],
        ['commit'],
        ['publish', BookingHeld::class],
    ])
        ->and($held->uuid)->toBe('7b7f0e5a-0000-4000-8000-000000000001')
        ->and($held->total->minor)->toBe(5000)
        ->and(HoldBooking::AFTER_LOCK)->toBe('hold.locked')
        ->and(HoldBooking::AFTER_VALIDATION)->toBe('hold.validated');
});

it('builds the key set in the tenant zone, a range that is one UTC day and two local days locking two staff-days, and reads the masks over the run of days the keys span', function () {
    [$useCase, $writer] = holdBookingWith(hours: [1 => [['00:00', '24:00']]]);

    $useCase->execute(unitPlan(unitItem(1, '2026-04-06 23:30', '2026-04-07 00:30')), Actor::customer());

    expect($writer->calls[1] ?? null)->toBe(['lock', ['1:2026-04-06', '1:2026-04-07']])
        ->and($writer->calls[9] ?? null)->toBe(['warm', ['1:2026-04-06', '1:2026-04-07'], ['1:2026-04-06' => 7, '1:2026-04-07' => 7]]);
});

it('assembles the hold from the plan: its status, class, lifetime, payment mode, approval flag and timeout policy, the re-summed total, the items as given, and a hash rather than the token', function () {
    [$useCase, $writer] = holdBookingWith();
    $plan = new HoldPlan(
        new Customer('Casey Customer', 'casey@example.com'),
        [unitItem(1, '2026-04-06 09:00', '2026-04-06 10:00', price: 2500), unitItem(2, '2026-04-06 09:00', '2026-04-06 10:00', price: 1500)],
        BookingStatus::AwaitingApproval,
        HoldClass::Approval,
        360,
        PaymentMode::Online,
        true,
        ApprovalTimeout::AutoApprove,
        new Intake(BookingSource::Embed),
    );

    $useCase->execute($plan, Actor::customer());
    $hold = $writer->inserted;

    expect($hold)->not->toBeNull()
        ->and($hold?->customer)->toBe($plan->customer)
        ->and($hold?->status)->toBe(BookingStatus::AwaitingApproval)
        ->and($hold?->holdClass)->toBe(HoldClass::Approval)
        ->and($hold?->ttlMinutes)->toBe(360)
        ->and($hold?->paymentMode)->toBe(PaymentMode::Online)
        ->and($hold?->requiresApproval)->toBeTrue()
        ->and($hold?->onApprovalTimeout)->toBe(ApprovalTimeout::AutoApprove)
        ->and($hold?->total->minor)->toBe(4000)
        ->and($hold?->total->currency->code)->toBe('EUR')
        ->and($hold?->items)->toBe($plan->items)
        ->and($hold?->manageTokenHash)->toMatch('/^[0-9a-f]{64}$/');
});

it('throws the overlap conflict naming the zero-based failing segment from inside the transaction, before the availability read, the second checkpoint and any insert', function () {
    [$useCase, $writer] = holdBookingWith(conflicting: [1]);
    $plan = unitPlan(
        unitItem(1, '2026-04-06 09:00', '2026-04-06 10:00'),
        unitItem(2, '2026-04-06 10:00', '2026-04-06 11:00'),
        unitItem(1, '2026-04-06 11:00', '2026-04-06 12:00'),
    );

    try {
        $useCase->execute($plan, Actor::customer());
        $thrown = null;
    } catch (SegmentConflict $thrown) {
    }

    expect($thrown)->toBeInstanceOf(SegmentConflict::class)
        ->and($thrown?->segment)->toBe(1)
        ->and($thrown?->block->staffId)->toBe(2)
        ->and($thrown?->getMessage())->toContain('overlaps a blocking item')
        ->and($writer->calls)->toBe([
            ['begin'],
            ['lock', ['1:2026-04-06', '2:2026-04-06']],
            ['checkpoint', 'hold.locked'],
            ['reap', [1, 2], '09:00', '12:00'],
            ['overlap', 1, '09:00', '10:00'],
            ['overlap', 2, '10:00', '11:00'],
            ['rollback'],
        ])
        ->and($writer->inserted)->toBeNull();
});

it('throws the availability refusal naming the segment whose member cannot hold it under lock, outside their hours, on a booking the masks show, or with a buffer over a booking, when no item overlaps by the flag; it is not the overlap conflict, nothing is inserted and nothing kept for the cache', function () {
    [$hours, $hoursWriter] = holdBookingWith();
    [$booked, $bookedWriter] = holdBookingWith(blocks: [1 => [unitBlock('2026-04-06 10:00', '2026-04-06 10:30')]]);
    [$buffered, $bufferedWriter] = holdBookingWith(blocks: [2 => [unitBlock('2026-04-06 08:40', '2026-04-06 08:55')]]);
    [$fine, $fineWriter] = holdBookingWith(blocks: [2 => [unitBlock('2026-04-06 08:40', '2026-04-06 08:50')]]);

    $outside = null;

    try {
        $hours->execute(unitPlan(unitItem(2, '2026-04-06 09:00', '2026-04-06 10:00'), unitItem(1, '2026-04-06 11:30', '2026-04-06 12:30')), Actor::customer());
    } catch (ChainUnavailable $outside) {
    }

    $onBooking = null;

    try {
        $booked->execute(unitPlan(unitItem(1, '2026-04-06 09:00', '2026-04-06 10:15')), Actor::customer());
    } catch (ChainUnavailable $onBooking) {
    }

    $overBuffer = null;

    try {
        $buffered->execute(unitPlan(unitItem(2, '2026-04-06 09:00', '2026-04-06 10:00', '2026-04-06 08:50', '2026-04-06 10:05')), Actor::customer());
    } catch (ChainUnavailable $overBuffer) {
    }

    $fine->execute(unitPlan(unitItem(2, '2026-04-06 09:00', '2026-04-06 10:00', '2026-04-06 08:50', '2026-04-06 10:05')), Actor::customer());

    expect($outside)->toBeInstanceOf(ChainUnavailable::class)
        ->and($outside)->not->toBeInstanceOf(SegmentConflict::class)
        ->and($outside?->segment)->toBe(1)
        ->and($outside?->getMessage())->toBe('Segment 1 cannot be held for staff member 1 between 2026-04-06 08:30:00 and 2026-04-06 09:30:00 UTC: the range is outside their availability.')
        ->and(array_slice($hoursWriter->calls, -3))->toBe([['overlap', 1, '11:30', '12:30'], ['availability'], ['rollback']])
        ->and($hoursWriter->inserted)->toBeNull()
        ->and($onBooking?->segment)->toBe(0)
        ->and(array_slice($bookedWriter->calls, -2))->toBe([['availability'], ['rollback']])
        ->and($overBuffer?->segment)->toBe(0)
        ->and(array_slice($bufferedWriter->calls, -2))->toBe([['availability'], ['rollback']])
        ->and($fineWriter->inserted)->not->toBeNull();
});

it('plans a request through the planner and runs the plan: the inserted items are the planner\'s, and the availability step reads the writer\'s masks rather than the advisory ones', function () {
    [$useCase, $writer, $advisory] = holdBookingWith(hours: [1 => [['00:00', '24:00']], 2 => [['00:00', '24:00']]]);
    $zone = new DateTimeZone('Europe/Athens');

    $held = $useCase->hold(new HoldRequest(
        new Customer('Casey Customer', 'casey@example.com'),
        [new SegmentChoice(1, 2), new SegmentChoice(2, null)],
        new DateTimeImmutable('2026-04-06 09:00', $zone),
        new Intake(BookingSource::Embed),
    ), Actor::customer());

    $items = array_map(fn (HoldItem $item): string => sprintf('%d %s-%s [%s-%s]', $item->staffId, $item->start->setTimezone($zone)->format('H:i'), $item->end->setTimezone($zone)->format('H:i'), $item->blockStart->setTimezone($zone)->format('H:i'), $item->blockEnd->setTimezone($zone)->format('H:i')), $writer->inserted->items ?? []);
    $steps = [];

    foreach ($writer->calls as $call) {
        assert(is_string($call[0]));
        $steps[] = $call[0];
    }

    expect($items)->toBe(['2 09:00-10:00 [08:50-10:05]', '1 10:00-11:00 [10:00-11:00]'])
        ->and($held->total->minor)->toBe(4000)
        ->and($advisory->asked)->toHaveCount(2)
        ->and($steps)->toBe(['begin', 'lock', 'checkpoint', 'reap', 'overlap', 'overlap', 'availability', 'checkpoint', 'insert', 'audit', 'warm', 'commit', 'publish']);
});

it('refuses through the planner before any transaction opens: the repository is never entered', function () {
    [$useCase, $writer] = holdBookingWith();

    expect(fn () => $useCase->hold(new HoldRequest(
        new Customer('Casey Customer', 'casey@example.com'),
        [new SegmentChoice(9, null)],
        new DateTimeImmutable('2026-04-06 09:00', new DateTimeZone('Europe/Athens')),
        new Intake(BookingSource::Embed),
    ), Actor::customer()))->toThrow(ServiceNotBookable::class)
        ->and($writer->calls)->toBe([]);
});

it('depends on the planner, the booking repository, the tenant context and the publisher, and on nothing else', function () {
    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
        (new ReflectionClass(HoldBooking::class))->getConstructor()?->getParameters() ?? [],
    );

    expect($parameters)->toBe([HoldPlanner::class, BookingRepository::class, TenantContext::class, TransitionPublisher::class]);
});

it('mints the manage token, hands the plaintext back with the hold and gives the store nothing but its hash', function () {
    [$useCase, $writer] = holdBookingWith();

    $held = $useCase->execute(unitPlan(unitItem(1, '2026-04-06 09:00', '2026-04-06 10:00')), Actor::customer());
    $stored = $writer->inserted?->manageTokenHash;
    $second = $useCase->execute(unitPlan(unitItem(1, '2026-04-06 10:00', '2026-04-06 11:00')), Actor::customer());

    expect($held->manageToken)->toMatch('/^[0-9a-f]{64}$/')
        ->and(hash('sha256', $held->manageToken))->toBe($stored)
        ->and($held->manageToken)->not->toBe($stored)
        ->and(hash('sha256', $second->manageToken))->toBe($writer->inserted?->manageTokenHash)
        ->and($second->manageToken)->not->toBe($held->manageToken);
});

it('writes one expired row per hold the reap let go, by the system, carrying the status it left and the class it held, and announces each of them after the commit', function () {
    [$useCase, $writer] = holdBookingWith(reaped: [
        new ReapedHold('11111111-0000-4000-8000-000000000001', BookingStatus::Pending, HoldClass::Checkout),
        new ReapedHold('11111111-0000-4000-8000-000000000002', BookingStatus::AwaitingApproval, HoldClass::Approval),
    ]);

    $useCase->execute(unitPlan(unitItem(1, '2026-04-06 09:00', '2026-04-06 10:00')), Actor::customer());
    $audited = array_values(array_filter($writer->calls, fn (array $call): bool => $call[0] === 'audit'));
    $published = array_values(array_filter($writer->calls, fn (array $call): bool => $call[0] === 'publish'));
    $steps = array_map(fn (array $call): mixed => $call[0], $writer->calls);

    expect($audited)->toBe([
        ['audit', '11111111-0000-4000-8000-000000000001', 'expired', 'system', ['status_from' => 'pending', 'status_to' => 'expired', 'hold_class' => 'checkout']],
        ['audit', '11111111-0000-4000-8000-000000000002', 'expired', 'system', ['status_from' => 'awaiting_approval', 'status_to' => 'expired', 'hold_class' => 'approval']],
        ['audit', '7b7f0e5a-0000-4000-8000-000000000001', 'held', 'customer', [
            'status_to' => 'pending',
            'hold_class' => 'checkout',
            'hold_expires_at' => '2026-04-06T06:15:00.000000+00:00',
            'item_count' => 1,
        ]],
    ])
        ->and($published)->toBe([['publish', BookingExpired::class], ['publish', BookingExpired::class], ['publish', BookingHeld::class]])
        ->and(array_slice($steps, -4))->toBe(['commit', 'publish', 'publish', 'publish']);
});

it('names the actor its caller passes, so a hold made by hand carries the user who made it', function () {
    [$useCase, $writer] = holdBookingWith();

    $useCase->execute(unitPlan(unitItem(1, '2026-04-06 09:00', '2026-04-06 10:00')), Actor::user(42));
    $audited = array_values(array_filter($writer->calls, fn (array $call): bool => $call[0] === 'audit'));

    expect($audited[0][3] ?? null)->toBe('user:42');
});

it('publishes nothing and writes no audit row when the re-validation refuses an item, because the transaction that would have carried them rolled back', function () {
    [$useCase, $writer] = holdBookingWith(conflicting: [0], reaped: [new ReapedHold('11111111-0000-4000-8000-000000000001', BookingStatus::Pending, HoldClass::Checkout)]);

    expect(fn () => $useCase->execute(unitPlan(unitItem(1, '2026-04-06 09:00', '2026-04-06 10:00')), Actor::customer()))
        ->toThrow(SegmentConflict::class)
        ->and(array_filter($writer->calls, fn (array $call): bool => $call[0] === 'publish'))->toBe([])
        ->and(array_slice($writer->calls, -1))->toBe([['rollback']]);
});
