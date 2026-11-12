<?php

use App\Application\BookingUnknown;
use App\Application\Dto\BookableService;
use App\Application\Dto\InsertedHold;
use App\Application\Dto\LockedBooking;
use App\Application\Dto\LockedItem;
use App\Application\Dto\NewHold;
use App\Application\Dto\SoldItem;
use App\Application\Event\BookingRescheduled;
use App\Application\Event\TransitionEvent;
use App\Application\Event\TransitionPublisher;
use App\Application\HoldPlanner;
use App\Application\Repository\AvailabilityRepository;
use App\Application\Repository\BookingRepository;
use App\Application\Repository\BookingWriter;
use App\Application\Repository\SoldChain;
use App\Application\RescheduleBooking;
use App\Application\TransitionRefused;
use App\Application\WriteContended;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditEntry;
use App\Domain\Availability\SegmentShape;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\Revisions;
use App\Domain\Booking\ServiceWindows;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use PHPUnit\Framework\Assert;
use Tests\Support\ArrayServices;
use Tests\Support\FixedClock;
use Tests\Support\FixedSettings;
use Tests\Support\FixedTenant;
use Tests\Support\FixtureAvailability;

/*
 * The move with no database: a writer that records every step it is asked for and answers the
 * re-read from a script the test writes, a repository that records the transaction around them,
 * a sold-chain read that answers the pre-read, and the real planner over fixtures.
 *
 * What is on trial is the order of the steps, and in particular that the re-read's containment
 * check comes before the guard. A verdict passed on rows the transaction may not be holding is
 * truthful only by inference; the check comes first so that it is not, and nothing but a test
 * keeps the two from being swapped back by a later hand.
 *
 * 2026-04-06 is a Monday and April in Athens is UTC+3, so 09:00 local is 06:00Z.
 */

final class MovingTestWriter implements BookingWriter
{
    /** @var list<array<mixed>> */
    public array $calls = [];

    /** @var list<LockedBooking> one per re-read, in order, the last repeating */
    private array $reads;

    /**
     * @param  list<LockedBooking>  $reads  what each re-read answers
     */
    public function __construct(array $reads, private readonly AvailabilityRepository $availability, private readonly bool $found = true)
    {
        $this->reads = $reads;
    }

    /**
     * The steps this writer was taken through, in order, by name.
     *
     * @return list<string>
     */
    public function steps(): array
    {
        return array_map(fn (array $call): string => is_string($call[0] ?? null) ? $call[0] : 'unnamed', $this->calls);
    }

    public function lock(LockKeySet $keys): Revisions
    {
        $this->calls[] = ['lock', array_map(fn ($key): string => $key->toString(), $keys->all())];

        return new Revisions(array_map(fn ($key): array => [$key, 7], $keys->all()));
    }

    public function lockBooking(string $uuid): bool
    {
        $this->calls[] = ['lock-booking', $uuid];

        return $this->found;
    }

    public function readLocked(): LockedBooking
    {
        $read = count($this->reads) > 1 ? array_shift($this->reads) : ($this->reads[0] ?? null);

        if ($read === null) {
            Assert::fail('The writer was asked to re-read with no reading scripted.');
        }

        $this->calls[] = ['re-read', $read->status->value];

        return $read;
    }

    public function deleteItems(): void
    {
        $this->calls[] = ['delete-items'];
    }

    public function reapExpiredHolds(array $staffIds, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        $this->calls[] = ['reap', $staffIds, $windowStart->format('H:i'), $windowEnd->format('H:i')];

        return [];
    }

    public function overlapsBlockingItem(StaffBlock $block): bool
    {
        $this->calls[] = ['overlap', $block->staffId, $block->start->format('H:i')];

        return false;
    }

    public function availability(): AvailabilityRepository
    {
        $this->calls[] = ['availability'];

        return $this->availability;
    }

    public function insertItems(array $items, Money $total): void
    {
        $this->calls[] = ['insert-items', count($items), $total->minor];
    }

    public function clearSession(): void
    {
        $this->calls[] = ['clear-session'];
    }

    public function writeAudit(AuditEntry $entry): void
    {
        $this->calls[] = ['audit', $entry->action->value, $entry->actor->value, $entry->payload];
    }

    public function warmAfterCommit(LockKeySet $keys, Revisions $revisions): void
    {
        $this->calls[] = ['warm', array_map(fn ($key): string => $key->toString(), $keys->all())];
    }

    public function compareAndSet(BookingStatus $found, BookingStatus $target, array $columns): void
    {
        throw new LogicException('A move writes no status: it leaves the booking exactly where the lock found it.');
    }

    public function unblockItems(): void
    {
        throw new LogicException('A move frees no slot by unblocking: it deletes the old items and inserts new ones.');
    }

    public function insertHold(NewHold $hold): InsertedHold
    {
        throw new LogicException('A move inserts no booking: the container is already there.');
    }
}

final class MovingTestRepository implements BookingRepository
{
    public function __construct(public MovingTestWriter $writer) {}

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

final class MovingTestSold implements SoldChain
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param  non-empty-list<SoldItem>|null  $items
     */
    public function __construct(private readonly ?array $items) {}

    public function of(string $uuid): ?array
    {
        $this->asked[] = $uuid;

        return $this->items;
    }
}

final class MovingTestPublisher implements TransitionPublisher
{
    /** @var list<TransitionEvent> */
    public array $published = [];

    public function publish(TransitionEvent $event): void
    {
        $this->published[] = $event;
    }
}

function movingInstant(string $athens): DateTimeImmutable
{
    return new DateTimeImmutable($athens, new DateTimeZone('Europe/Athens'));
}

/**
 * The booking as it was sold: one Cut of forty-five minutes on member 1, at the Athens hour
 * given, at twenty-five euros.
 *
 * @return non-empty-list<SoldItem>
 */
function movingSold(string $start = '2026-04-06 09:00', string $end = '2026-04-06 09:45', int $staffId = 1): array
{
    return [new SoldItem(7, $staffId, new StaffBlock($staffId, movingInstant($start), movingInstant($end)), new Money(2500, Currency::of('EUR')), movingInstant($start))];
}

/**
 * The re-read the lock finds: the status, and one item over the Athens range given.
 */
function movingLocked(BookingStatus $status = BookingStatus::Confirmed, string $start = '2026-04-06 09:00', string $end = '2026-04-06 09:45', int $staffId = 1, ?string $paymentRef = null): LockedBooking
{
    return new LockedBooking(
        'a1b2c3d4-0000-4000-8000-000000000000',
        $status,
        $status->isHeld() ? HoldClass::Checkout : null,
        $status->isHeld(),
        PaymentMode::Onsite,
        [new LockedItem(
            new StaffBlock($staffId, movingInstant($start), movingInstant($end)),
            movingInstant($start),
            new ServiceWindows(24, 0),
        )],
        null,
        $paymentRef,
    );
}

/**
 * The use case over fixtures: the real planner on a catalog of one service performed by members
 * 1 and 2, both open nine to five every day, and the writer the test scripted.
 *
 * @param  list<LockedBooking>  $reads
 * @param  bool  $known  whether the pre-read finds a booking of this tenant at all
 * @return array{RescheduleBooking, MovingTestWriter, MovingTestPublisher, MovingTestSold}
 */
function movingUseCase(array $reads, bool $known = true): array
{
    $availability = new FixtureAvailability(
        new DateTimeZone('Europe/Athens'),
        [1 => [['09:00', '17:00']], 2 => [['09:00', '17:00']]],
    );
    $planner = new HoldPlanner(
        new ArrayServices([new BookableService(
            7,
            new SegmentShape(45, 0, 0),
            0,
            0,
            60,
            [1, 2],
            new Money(9900, Currency::of('EUR')),
            PaymentMode::Onsite,
            false,
            null,
            ApprovalTimeout::Expire,
        )]),
        $availability,
        new FixedSettings,
        new FixedTenant,
        FixedClock::at('2026-04-05 05:00:00'),
    );
    $writer = new MovingTestWriter($reads, $availability);
    $publisher = new MovingTestPublisher;
    $sold = new MovingTestSold($known ? movingSold() : null);

    return [
        new RescheduleBooking(
            $sold,
            $planner,
            new MovingTestRepository($writer),
            new FixedTenant,
            FixedClock::at('2026-04-05 05:00:00'),
            $publisher,
        ),
        $writer,
        $publisher,
        $sold,
    ];
}

it('runs the steps of a move in order: the mutex rows, the booking row, the re-read, the delete, the reap, the overlap checks, the masks, the insert and the trail, with no status written at all', function () {
    [$moves, $writer, $publisher] = movingUseCase([movingLocked()]);

    $moves->reschedule('a1b2c3d4-0000-4000-8000-000000000000', Actor::customer(), movingInstant('2026-04-06 11:00'));

    expect($writer->steps())->toBe([
        'begin',
        'lock',
        'lock-booking',
        're-read',
        'delete-items',
        'reap',
        'overlap',
        'availability',
        'insert-items',
        'audit',
        'warm',
        'commit',
    ])
        // The price is the row's and never the catalog's, which says ninety-nine euros here.
        ->and($writer->calls[8])->toBe(['insert-items', 1, 2500])
        ->and($writer->calls[9][3] ?? null)->toBe([
            'moved_from' => '2026-04-06T06:00:00.000000+00:00',
            'starts_at' => '2026-04-06T08:00:00.000000+00:00',
        ])
        ->and($publisher->published)->toHaveCount(1);
});

it('clears the session of a held booking waiting for its payment under the lock, after the insert and before the trail, and carries it on the move\'s record for the provider to expire after the commit; a booking carrying none, or a confirmed one\'s payment, is left as it stands', function (BookingStatus $status, ?string $ref, bool $cleared) {
    [$moves, $writer, $publisher] = movingUseCase([movingLocked($status, paymentRef: $ref)]);

    $moves->reschedule('a1b2c3d4-0000-4000-8000-000000000000', Actor::customer(), movingInstant('2026-04-06 11:00'));
    $record = $publisher->published[0] ?? null;

    assert($record instanceof BookingRescheduled);

    $trail = array_values(array_filter($writer->calls, fn (array $call): bool => $call[0] === 'audit'));
    $payload = $trail[0][3] ?? null;

    expect(array_slice($writer->steps(), 8, $cleared ? 3 : 2))->toBe($cleared ? ['insert-items', 'clear-session', 'audit'] : ['insert-items', 'audit'])
        ->and(in_array('clear-session', $writer->steps(), true))->toBe($cleared)
        ->and($record->session)->toBe($cleared ? $ref : null)
        ->and($record->status)->toBe($status)
        // The trail says a session was cleared, and only then: the pay hop counts those rows to
        // know the hold already follows a session's end.
        ->and(is_array($payload) ? ($payload['session_cleared'] ?? null) : 'no payload')->toBe($cleared ? true : null);
})->with([
    'a checkout hold carrying a session' => [BookingStatus::Pending, 'cs_test_open', true],
    'a payment hold carrying a session' => [BookingStatus::AwaitingPayment, 'cs_test_open', true],
    'a checkout hold carrying none' => [BookingStatus::Pending, null, false],
    'a booking its payment confirmed' => [BookingStatus::Confirmed, 'cs_test_paid', false],
]);

it('asks the containment check before the guard, so a booking whose items moved is answered as contention and never as a verdict on rows the transaction is not holding', function () {
    // The re-read finds the booking on member 2 on the day after, which is outside the union of
    // where it was sold and where it is going, and in a status a move does not reach. If the
    // guard ran first it would answer not_reschedulable on rows this transaction never locked.
    [$moves, $writer] = movingUseCase([movingLocked(BookingStatus::Cancelled, '2026-04-07 09:00', '2026-04-07 09:45', 2)]);

    $thrown = null;

    try {
        $moves->reschedule('a1b2c3d4-0000-4000-8000-000000000000', Actor::customer(), movingInstant('2026-04-06 11:00'));
    } catch (Throwable $caught) {
        $thrown = $caught;
    }

    expect($thrown)->toBeInstanceOf(WriteContended::class)
        ->and($thrown)->not->toBeInstanceOf(TransitionRefused::class)
        // Two attempts, each rolled back at the re-read with nothing written after it.
        ->and($writer->steps())->toBe([
            'begin', 'lock', 'lock-booking', 're-read', 'rollback',
            'begin', 'lock', 'lock-booking', 're-read', 'rollback',
        ]);
});

it('re-runs from step 1 once when the items moved and settles on the second reading, taking the pre-read again rather than locking the set that already failed', function () {
    [$moves, $writer, , $sold] = movingUseCase([
        movingLocked(BookingStatus::Confirmed, '2026-04-07 09:00', '2026-04-07 09:45', 2),
        movingLocked(),
    ]);

    $moves->reschedule('a1b2c3d4-0000-4000-8000-000000000000', Actor::customer(), movingInstant('2026-04-06 11:00'));

    // Step 1 again, not the set that already failed: what sent the first attempt back is the
    // booking having moved, so the union it locked is the one that cannot cover it.
    expect($sold->asked)->toHaveCount(2)
        ->and($writer->steps())->toBe([
            'begin', 'lock', 'lock-booking', 're-read', 'rollback',
            'begin', 'lock', 'lock-booking', 're-read', 'delete-items', 'reap', 'overlap', 'availability', 'insert-items', 'audit', 'warm', 'commit',
        ]);
});

it('refuses a status a move does not reach, on the re-read and not on anything the caller passed, with nothing written', function () {
    [$moves, $writer] = movingUseCase([movingLocked(BookingStatus::Completed)]);

    $thrown = null;

    try {
        $moves->reschedule('a1b2c3d4-0000-4000-8000-000000000000', Actor::customer(), movingInstant('2026-04-06 11:00'));
    } catch (TransitionRefused $caught) {
        $thrown = $caught;
    }

    expect($thrown?->reason->value)->toBe('not_reschedulable')
        ->and($thrown?->status)->toBe(BookingStatus::Completed)
        ->and($writer->steps())->toBe(['begin', 'lock', 'lock-booking', 're-read', 'rollback']);
});

it('answers a booking nobody has before it opens anything', function () {
    [$moves, $writer] = movingUseCase([movingLocked()], known: false);

    expect(fn () => $moves->reschedule('a1b2c3d4-0000-4000-8000-000000000000', Actor::customer(), movingInstant('2026-04-06 11:00')))
        ->toThrow(BookingUnknown::class)
        ->and($writer->steps())->toBe([]);
});

it('locks the union of where the booking is and where it is going, sorted and de-duplicated', function () {
    [$moves, $writer] = movingUseCase([movingLocked()]);

    $moves->reschedule('a1b2c3d4-0000-4000-8000-000000000000', Actor::customer(), movingInstant('2026-04-07 11:00'));

    expect($writer->calls[1])->toBe(['lock', ['1:2026-04-06', '1:2026-04-07']])
        // The warm covers both sides, because the masks of both changed.
        ->and($writer->calls[10])->toBe(['warm', ['1:2026-04-06', '1:2026-04-07']]);
});
