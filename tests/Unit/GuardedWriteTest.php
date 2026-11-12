<?php

use App\Application\ApproveBooking;
use App\Application\BookingUnknown;
use App\Application\CancelBooking;
use App\Application\Clock;
use App\Application\ConfirmBooking;
use App\Application\Dto\LockedBooking;
use App\Application\Dto\LockedItem;
use App\Application\Dto\NewHold;
use App\Application\Dto\ServerNowPlus;
use App\Application\Dto\Transition;
use App\Application\Dto\TransitionLanding;
use App\Application\Event\TransitionEvent;
use App\Application\Event\TransitionPublisher;
use App\Application\GuardedWrite;
use App\Application\Payment\NullPaymentProvider;
use App\Application\Payment\SettlementRule;
use App\Application\Payment\SettlesByProvider;
use App\Application\RejectBooking;
use App\Application\ReleaseHold;
use App\Application\Repository\BookingLookup;
use App\Application\Repository\BookingRepository;
use App\Application\Repository\BookingWriter;
use App\Application\TransitionRefused;
use App\Application\WriteContended;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\Revisions;
use App\Domain\Booking\ServiceWindows;
use App\Domain\Booking\StaffBlock;
use App\Domain\Booking\TransitionColumn;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Money;
use PHPUnit\Framework\Assert;
use Tests\Support\FixedClock;
use Tests\Support\FixedTenant;

/*
 * The nine steps of the guarded write with no database: a writer that records every step it is
 * asked for and answers the re-read from a script the test writes, a repository that records
 * the transaction around them, a lookup that answers the pre-read, and a publisher that writes
 * into the same log. What is on trial is the order, what the guard is shown, and what a
 * mismatch between the key set locked and the key set read back does.
 *
 * 2026-04-06 is a Monday and April in Athens is UTC+3, so 09:00 local is 06:00Z.
 */

final class GuardedWriteTestWriter implements BookingWriter
{
    /** @var list<array<mixed>> */
    public array $calls = [];

    /** @var list<array{found: string, target: string, columns: array<array-key, mixed>}> every compare-and-set, in order */
    public array $sets = [];

    /** @var list<LockedBooking> one per re-read, in order */
    private array $reads;

    /**
     * @param  list<LockedBooking>  $reads  what each re-read answers, the last repeating
     */
    public function __construct(array $reads, private bool $found = true)
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

        return new Revisions(array_map(fn ($key): array => [$key, 3], $keys->all()));
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

    public function compareAndSet(BookingStatus $found, BookingStatus $target, array $columns): void
    {
        $this->calls[] = ['compare-and-set', $found->value, $target->value, $columns];
        $this->sets[] = ['found' => $found->value, 'target' => $target->value, 'columns' => $columns];
    }

    public function unblockItems(): void
    {
        $this->calls[] = ['unblock'];
    }

    public function deleteItems(): void
    {
        throw new LogicException('A guarded transition moves no items; the reschedule is the one write that does.');
    }

    public function insertItems(array $items, Money $total): void
    {
        throw new LogicException('A guarded transition moves no items; the reschedule is the one write that does.');
    }

    public function clearSession(): void
    {
        throw new LogicException('A guarded transition clears no session; the reschedule is the one write that does.');
    }

    public function writeAudit(AuditEntry $entry): void
    {
        $this->calls[] = ['audit', $entry->action->value, $entry->actor->value, $entry->bookingUuid, $entry->payload];
    }

    public function reapExpiredHolds(array $staffIds, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        throw new LogicException('A guarded transition reaps nothing: the reap is a step of the insert protocol alone.');
    }

    public function overlapsBlockingItem(StaffBlock $block): bool
    {
        throw new LogicException('A guarded transition re-validates no availability: it moves a booking that already holds its range.');
    }

    public function availability(): never
    {
        throw new LogicException('A guarded transition reads no masks.');
    }

    public function insertHold(NewHold $hold): never
    {
        throw new LogicException('A guarded transition inserts nothing.');
    }

    public function warmAfterCommit(LockKeySet $keys, Revisions $revisions): void
    {
        throw new LogicException('A guarded transition keeps no masks: the revision bump is what makes the old ones unreachable.');
    }
}

final class GuardedWriteTestRepository implements BookingRepository
{
    public function __construct(public GuardedWriteTestWriter $writer) {}

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

final class GuardedWriteTestLookup implements BookingLookup
{
    /** @var list<non-empty-list<StaffBlock>> one per call, the last repeating */
    private array $answers;

    /**
     * @param  list<non-empty-list<StaffBlock>>  $answers
     */
    public function __construct(public GuardedWriteTestWriter $writer, array $answers)
    {
        $this->answers = $answers;
    }

    public function blocksOf(string $uuid): ?array
    {
        $this->writer->calls[] = ['pre-read', $uuid];

        if ($this->answers === []) {
            return null;
        }

        return count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
    }
}

final class GuardedWriteTestPublisher implements TransitionPublisher
{
    public function __construct(public GuardedWriteTestWriter $writer) {}

    public function publish(TransitionEvent $event): void
    {
        $this->writer->calls[] = ['publish', $event::class];
    }
}

/**
 * A clock that stands where the sequence stands. It answers the first instant of its script
 * until the first pre-read, the second during the first attempt, the third during the second,
 * the last repeating, and it remembers where each read fell: the attempt, counted by the
 * pre-reads in the writer's log, and the step before it. An attempt rolled back for a moved key
 * set reads no clock, so a count of reads is one wherever the read sits; the instant answered
 * and the place it was asked from are what tell a read under the landing attempt's lock from
 * one hoisted above the attempts or carried from the first into the second.
 */
final class GuardedWriteTestClock implements Clock
{
    /** @var list<array{attempt: int, after: string}> every read, in order */
    public array $reads = [];

    /** The log the attempts are counted in; guardedWriteWith() binds it. */
    public ?GuardedWriteTestWriter $log = null;

    /** @var non-empty-list<DateTimeImmutable> */
    private array $instants;

    public function __construct(string $before, string ...$attempts)
    {
        $this->instants = array_map(guardedInstant(...), [$before, ...array_values($attempts)]);
    }

    public function now(): DateTimeImmutable
    {
        if ($this->log === null) {
            Assert::fail('The clock was read with no log to place the read in.');
        }

        $steps = $this->log->steps();
        $attempt = count(array_keys($steps, 'pre-read', true));
        $this->reads[] = ['attempt' => $attempt, 'after' => $steps === [] ? 'nothing' : $steps[array_key_last($steps)]];

        return $this->instants[min($attempt, count($this->instants) - 1)];
    }
}

final readonly class GuardedWriteTestEvent implements TransitionEvent
{
    public function __construct(
        public string $uuid,
        public ?BookingStatus $from,
        public BookingStatus $to,
        public ?HoldClass $holdClass,
        public Actor $actor,
    ) {}
}

function guardedInstant(string $text): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone('UTC'));
}

/**
 * An instant a column was set to, spelled in UTC, or the words saying it was not one.
 */
function guardedStamp(mixed $value): string
{
    return $value instanceof DateTimeImmutable ? $value->format('Y-m-d H:i:sP') : 'not an instant';
}

/**
 * One item's contended range on a staff member, in UTC text.
 */
function guardedBlock(int $staffId, string $from, string $to): StaffBlock
{
    return new StaffBlock($staffId, guardedInstant($from), guardedInstant($to));
}

/**
 * A booking as the lock found it: pending on a live checkout hold over the ranges given, unless
 * the test says otherwise. Each item starts where its contended range does and carries the
 * default windows, neither of which any test here reads: what is on trial in this file is the
 * sequence, and the guard it runs is the test's own.
 *
 * @param  non-empty-list<StaffBlock>  $blocks
 */
function guardedLocked(
    array $blocks,
    BookingStatus $status = BookingStatus::Pending,
    ?HoldClass $holdClass = HoldClass::Checkout,
    bool $live = true,
    PaymentMode $mode = PaymentMode::Onsite,
): LockedBooking {
    $items = array_map(
        fn (StaffBlock $block): LockedItem => new LockedItem($block, $block->start, new ServiceWindows(24, 24)),
        $blocks,
    );

    return new LockedBooking('11111111-0000-4000-8000-000000000001', $status, $holdClass, $live, $mode, $items);
}

/**
 * A transition that settles a booking, with the guard the test names and a landing that reads
 * nothing: the status and the columns given, whatever the lock found.
 *
 * @param  Closure(LockedBooking, DateTimeImmutable): ?TransitionRefusal  $guard
 * @param  array<array-key, mixed>  $columns
 */
function guardedTransition(
    Closure $guard,
    BookingStatus $target = BookingStatus::Confirmed,
    array $columns = [TransitionColumn::HoldClass->value => null, TransitionColumn::HoldExpiresAt->value => null],
    ?Actor $actor = null,
): Transition {
    return guardedLanding($guard, fn (): TransitionLanding => new TransitionLanding($target, $columns), $actor);
}

/**
 * A transition with the guard and the landing function the test names.
 *
 * @param  Closure(LockedBooking, DateTimeImmutable): ?TransitionRefusal  $guard
 * @param  Closure(LockedBooking, DateTimeImmutable): TransitionLanding  $landing
 */
function guardedLanding(Closure $guard, Closure $landing, ?Actor $actor = null): Transition
{
    $actor ??= Actor::customer();

    return new Transition(
        '11111111-0000-4000-8000-000000000001',
        $actor,
        $guard,
        $landing,
        AuditAction::Held,
        fn (BookingStatus $from, BookingStatus $to, ?HoldClass $holdClass): TransitionEvent => new GuardedWriteTestEvent('11111111-0000-4000-8000-000000000001', $from, $to, $holdClass, $actor),
    );
}

/**
 * The use case over the doubles, with what the pre-read answers and what each re-read answers.
 *
 * @param  list<non-empty-list<StaffBlock>>  $preReads
 * @param  list<LockedBooking>  $reads
 * @return array{GuardedWrite, GuardedWriteTestWriter}
 */
function guardedWriteWith(array $preReads, array $reads, bool $found = true, string $now = '2026-04-06 08:00', ?Clock $clock = null): array
{
    $writer = new GuardedWriteTestWriter($reads, $found);

    if ($clock instanceof GuardedWriteTestClock) {
        $clock->log = $writer;
    }

    return [
        new GuardedWrite(
            new GuardedWriteTestLookup($writer, $preReads),
            new GuardedWriteTestRepository($writer),
            new FixedTenant,
            $clock ?? FixedClock::at($now),
            new GuardedWriteTestPublisher($writer),
        ),
        $writer,
    ];
}

/**
 * The allowing guard, and what it was shown.
 *
 * @param  list<array{LockedBooking, DateTimeImmutable}>  $seen
 */
function guardedAllows(array &$seen): Closure
{
    return function (LockedBooking $locked, DateTimeImmutable $now) use (&$seen): ?TransitionRefusal {
        $seen[] = [$locked, $now];

        return null;
    };
}

it('runs the nine steps in order for a transition that keeps the slot: the pre-read, the mutex rows, the booking row, the re-read, the compare-and-set naming what the lock found, the trail, the commit, and only then the event', function () {
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    [$writes, $writer] = guardedWriteWith([$blocks], [guardedLocked($blocks)]);
    $seen = [];

    $writes->apply(guardedTransition(guardedAllows($seen)));

    expect($writer->calls)->toBe([
        ['pre-read', '11111111-0000-4000-8000-000000000001'],
        ['begin'],
        ['lock', ['1:2026-04-06']],
        ['lock-booking', '11111111-0000-4000-8000-000000000001'],
        ['re-read', 'pending'],
        ['compare-and-set', 'pending', 'confirmed', ['hold_class' => null, 'hold_expires_at' => null]],
        ['audit', 'held', 'customer', '11111111-0000-4000-8000-000000000001', [
            'status_from' => 'pending',
            'status_to' => 'confirmed',
            'hold_class' => 'checkout',
        ]],
        ['commit'],
        ['publish', GuardedWriteTestEvent::class],
    ]);
});

it('clears the blocking flag between the compare-and-set and the trail when the target frees the slot, and not when it keeps it', function () {
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    $seen = [];

    [$cancels, $cancelling] = guardedWriteWith([$blocks], [guardedLocked($blocks)]);
    $cancels->apply(guardedTransition(guardedAllows($seen), BookingStatus::Cancelled));

    [$confirms, $confirming] = guardedWriteWith([$blocks], [guardedLocked($blocks)]);
    $confirms->apply(guardedTransition(guardedAllows($seen)));

    expect($cancelling->steps())
        ->toBe(['pre-read', 'begin', 'lock', 'lock-booking', 're-read', 'compare-and-set', 'unblock', 'audit', 'commit', 'publish'])
        ->and(array_filter($confirming->calls, fn (array $call): bool => $call[0] === 'unblock'))->toBe([]);
});

it('shows the guard the booking the lock read and the clock, and nothing the pre-read saw', function () {
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    $locked = guardedLocked($blocks, BookingStatus::Pending, HoldClass::Approval);
    [$writes] = guardedWriteWith([$blocks], [$locked], now: '2026-04-06 08:30');
    $seen = [];

    $writes->apply(guardedTransition(guardedAllows($seen)));

    expect($seen)->toHaveCount(1)
        ->and($seen[0][0])->toBe($locked)
        ->and($seen[0][1]->format('Y-m-d H:i:sP'))->toBe('2026-04-06 08:30:00+00:00');
});

it('rolls back on a refusal, carrying the reason and the status the lock found, with no compare-and-set, no trail and nothing published', function () {
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    [$writes, $writer] = guardedWriteWith([$blocks], [guardedLocked($blocks, BookingStatus::Cancelled, null, false)]);

    $refused = null;

    try {
        $writes->apply(guardedTransition(fn (): TransitionRefusal => TransitionRefusal::NotConfirmable));
    } catch (TransitionRefused $refused) {
    }

    expect($refused)->toBeInstanceOf(TransitionRefused::class)
        ->and($refused?->reason)->toBe(TransitionRefusal::NotConfirmable)
        ->and($refused?->status)->toBe(BookingStatus::Cancelled)
        ->and($writer->steps())
        ->toBe(['pre-read', 'begin', 'lock', 'lock-booking', 're-read', 'rollback']);
});

it('rolls back and runs the whole transaction again from the pre-read when the re-read shows items that moved off the locked staff-days, and commits the second time', function () {
    $locked = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    $moved = [guardedBlock(2, '2026-04-07 06:00', '2026-04-07 07:00')];
    [$writes, $writer] = guardedWriteWith([$locked, $moved], [guardedLocked($moved), guardedLocked($moved)]);
    $seen = [];

    $writes->apply(guardedTransition(guardedAllows($seen)));

    expect($writer->steps())->toBe([
        'pre-read', 'begin', 'lock', 'lock-booking', 're-read', 'rollback',
        'pre-read', 'begin', 'lock', 'lock-booking', 're-read', 'compare-and-set', 'audit', 'commit', 'publish',
    ])
        ->and($writer->calls[2])->toBe(['lock', ['1:2026-04-06']])
        ->and($writer->calls[8])->toBe(['lock', ['2:2026-04-07']])
        ->and($seen)->toHaveCount(1);
});

it('answers busy when the items move again on the second attempt, having run the transaction twice and written nothing', function () {
    $locked = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    $moved = [guardedBlock(2, '2026-04-07 06:00', '2026-04-07 07:00')];
    [$writes, $writer] = guardedWriteWith([$locked], [guardedLocked($moved)]);
    $seen = [];

    expect(fn () => $writes->apply(guardedTransition(guardedAllows($seen))))->toThrow(WriteContended::class)
        ->and($writer->steps())->toBe([
            'pre-read', 'begin', 'lock', 'lock-booking', 're-read', 'rollback',
            'pre-read', 'begin', 'lock', 'lock-booking', 're-read', 'rollback',
        ])
        ->and($seen)->toBe([])
        ->and(GuardedWrite::ATTEMPTS)->toBe(2);
});

it('answers a booking nobody has as unknown, before any transaction when the pre-read finds none and under the lock when the row does not lock', function () {
    [$missing, $missingWriter] = guardedWriteWith([], [guardedLocked([guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')])]);
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    [$vanished, $vanishedWriter] = guardedWriteWith([$blocks], [guardedLocked($blocks)], found: false);
    $seen = [];

    expect(fn () => $missing->apply(guardedTransition(guardedAllows($seen))))->toThrow(BookingUnknown::class)
        ->and($missingWriter->steps())->toBe(['pre-read'])
        ->and(fn () => $vanished->apply(guardedTransition(guardedAllows($seen))))->toThrow(BookingUnknown::class)
        ->and($vanishedWriter->steps())
        ->toBe(['pre-read', 'begin', 'lock', 'lock-booking', 'rollback'])
        ->and($seen)->toBe([]);
});

it('writes the trail with the actor it was given and names the hold class only where the booking was under one', function () {
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    [$settled, $settledWriter] = guardedWriteWith([$blocks], [guardedLocked($blocks, BookingStatus::Confirmed, null, false)]);
    $seen = [];

    $settled->apply(guardedTransition(guardedAllows($seen), BookingStatus::Completed, [], Actor::user(42)));
    $audit = array_values(array_filter($settledWriter->calls, fn (array $call): bool => $call[0] === 'audit'));

    expect($audit[0][2] ?? null)->toBe('user:42')
        ->and($audit[0][4] ?? null)->toBe(['status_from' => 'confirmed', 'status_to' => 'completed']);
});

it('writes a fact the transition states beside where it moved, and no fact replaces what the lock found or the landing answered', function () {
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    [$writes, $writer] = guardedWriteWith([$blocks], [guardedLocked($blocks)]);
    $seen = [];
    $plain = guardedTransition(guardedAllows($seen));

    $writes->apply(new Transition($plain->uuid, $plain->actor, $plain->guard, $plain->landing, $plain->audit, $plain->event, [
        'payment_named' => true,
        'status_from' => false,
        'status_to' => false,
        'hold_class' => false,
    ]));
    $audit = array_values(array_filter($writer->calls, fn (array $call): bool => $call[0] === 'audit'));

    expect($audit[0][4] ?? null)->toBe([
        'payment_named' => true,
        'status_from' => 'pending',
        'status_to' => 'confirmed',
        'hold_class' => 'checkout',
    ]);
});

it('lets no fact reach the trail under from, to or the hold class on a booking under no hold, where the lock found no class to write over it', function () {
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    [$writes, $writer] = guardedWriteWith([$blocks], [guardedLocked($blocks, BookingStatus::Confirmed, null, false)]);
    $seen = [];
    $plain = guardedTransition(guardedAllows($seen), BookingStatus::Completed, []);

    $writes->apply(new Transition($plain->uuid, $plain->actor, $plain->guard, $plain->landing, $plain->audit, $plain->event, [
        'hold_class' => true,
        'status_from' => false,
        'status_to' => false,
        'payment_named' => false,
    ]));
    $audit = array_values(array_filter($writer->calls, fn (array $call): bool => $call[0] === 'audit'));

    expect($audit[0][4] ?? null)->toBe([
        'payment_named' => false,
        'status_from' => 'confirmed',
        'status_to' => 'completed',
    ]);
});

it('builds the event from what the lock found and publishes it once, after the transaction has returned', function () {
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    $published = [];
    [$writes, $writer] = guardedWriteWith([$blocks], [guardedLocked($blocks, BookingStatus::Pending, HoldClass::Payment)]);
    $seen = [];

    $writes->apply(new Transition(
        '11111111-0000-4000-8000-000000000001',
        Actor::customer(),
        guardedAllows($seen),
        fn (): TransitionLanding => new TransitionLanding(BookingStatus::Confirmed),
        AuditAction::Held,
        function (BookingStatus $from, BookingStatus $to, ?HoldClass $holdClass) use (&$published): TransitionEvent {
            $published[] = [$from->value, $to->value, $holdClass?->value];

            return new GuardedWriteTestEvent('11111111-0000-4000-8000-000000000001', $from, $to, $holdClass, Actor::customer());
        },
    ));

    $order = $writer->steps();

    expect($published)->toBe([['pending', 'confirmed', 'payment']])
        ->and(array_slice($order, -2))->toBe(['commit', 'publish']);
});

it('asks the landing under the lock, once the guard has allowed, of the same re-read and the same now, and writes what it answers: the status the compare-and-set sets, the columns beside it, the trail\'s status_to and the event\'s', function () {
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    $locked = guardedLocked($blocks, BookingStatus::AwaitingApproval, HoldClass::Approval, mode: PaymentMode::Online);
    $clock = new GuardedWriteTestClock('2026-04-06 08:30');
    [$writes, $writer] = guardedWriteWith([$blocks], [$locked], clock: $clock);
    $guarded = [];
    $landed = [];
    $published = [];

    $writes->apply(new Transition(
        '11111111-0000-4000-8000-000000000001',
        Actor::user(42),
        guardedAllows($guarded),
        function (LockedBooking $found, DateTimeImmutable $now) use (&$landed, $writer): TransitionLanding {
            $landed[] = [$found, $now, $writer->steps()];

            // A landing the row decides: this one reads the payment mode the lock found.
            return new TransitionLanding(
                $found->paymentMode === PaymentMode::Online ? BookingStatus::AwaitingPayment : BookingStatus::Confirmed,
                [TransitionColumn::ApprovedAt->value => $now],
            );
        },
        AuditAction::Approved,
        function (BookingStatus $from, BookingStatus $to, ?HoldClass $holdClass) use (&$published): TransitionEvent {
            $published[] = [$from->value, $to->value, $holdClass?->value];

            return new GuardedWriteTestEvent('11111111-0000-4000-8000-000000000001', $from, $to, $holdClass, Actor::user(42));
        },
    ));

    $audit = array_values(array_filter($writer->calls, fn (array $call): bool => $call[0] === 'audit'));

    expect($landed)->toHaveCount(1)
        ->and($landed[0][0])->toBe($locked)
        // One read of the clock for the attempt: the guard and the landing are shown the same now.
        ->and($clock->reads)->toBe([['attempt' => 1, 'after' => 're-read']])
        ->and($landed[0][1])->toBe($guarded[0][1])
        // Asked after the re-read and before anything is written.
        ->and($landed[0][2])->toBe(['pre-read', 'begin', 'lock', 'lock-booking', 're-read'])
        ->and($writer->sets)->toBe([['found' => 'awaiting_approval', 'target' => 'awaiting_payment', 'columns' => ['approved_at' => $landed[0][1]]]])
        ->and($audit[0][4] ?? null)->toBe(['status_from' => 'awaiting_approval', 'status_to' => 'awaiting_payment', 'hold_class' => 'approval'])
        ->and($published)->toBe([['awaiting_approval', 'awaiting_payment', 'approval']]);
});

it('clears the blocking flag by the status the landing answered, so a landing the re-read sends to a status that frees the slot frees it and one it sends to a status that keeps it does not', function () {
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    $seen = [];
    $byMode = fn (LockedBooking $found): TransitionLanding => new TransitionLanding(
        $found->paymentMode === PaymentMode::Free ? BookingStatus::Cancelled : BookingStatus::Confirmed,
    );

    [$frees, $freeing] = guardedWriteWith([$blocks], [guardedLocked($blocks, mode: PaymentMode::Free)]);
    $frees->apply(guardedLanding(guardedAllows($seen), $byMode));

    [$keeps, $keeping] = guardedWriteWith([$blocks], [guardedLocked($blocks, mode: PaymentMode::Onsite)]);
    $keeps->apply(guardedLanding(guardedAllows($seen), $byMode));

    expect($freeing->steps())->toBe(['pre-read', 'begin', 'lock', 'lock-booking', 're-read', 'compare-and-set', 'unblock', 'audit', 'commit', 'publish'])
        ->and($keeping->steps())->toBe(['pre-read', 'begin', 'lock', 'lock-booking', 're-read', 'compare-and-set', 'audit', 'commit', 'publish']);
});

it('never asks the landing of an attempt that does not write: not when the guard refuses, and not when the key set moved', function () {
    $blocks = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    $moved = [guardedBlock(2, '2026-04-07 06:00', '2026-04-07 07:00')];
    $asked = 0;
    $landing = function () use (&$asked): TransitionLanding {
        $asked++;

        return new TransitionLanding(BookingStatus::Confirmed);
    };
    $seen = [];

    [$refuses] = guardedWriteWith([$blocks], [guardedLocked($blocks)]);
    [$contended] = guardedWriteWith([$blocks], [guardedLocked($moved)]);

    expect(fn () => $refuses->apply(guardedLanding(fn (): TransitionRefusal => TransitionRefusal::NotConfirmable, $landing)))->toThrow(TransitionRefused::class)
        ->and(fn () => $contended->apply(guardedLanding(guardedAllows($seen), $landing)))->toThrow(WriteContended::class)
        ->and($asked)->toBe(0);
});

it('reads the clock inside the attempt and after the key set is confirmed, so an attempt rolled back for a moved key set leaves no now behind it and the landing that commits is shown one read under its own lock', function () {
    $locked = [guardedBlock(1, '2026-04-06 06:00', '2026-04-06 07:00')];
    $moved = [guardedBlock(2, '2026-04-07 06:00', '2026-04-07 07:00')];
    // 08:00 until the first pre-read, 08:05 through the first attempt, 08:07 through the second.
    $clock = new GuardedWriteTestClock('2026-04-06 08:00', '2026-04-06 08:05', '2026-04-06 08:07');
    [$writes, $writer] = guardedWriteWith([$locked, $moved], [guardedLocked($moved), guardedLocked($moved)], clock: $clock);
    $seen = [];
    $shown = [];

    $writes->apply(guardedLanding(guardedAllows($seen), function (LockedBooking $found, DateTimeImmutable $now) use (&$shown): TransitionLanding {
        $shown[] = $now->format('Y-m-d H:i');

        return new TransitionLanding(BookingStatus::Confirmed, [TransitionColumn::ApprovedAt->value => $now]);
    }));

    // One read, in the second attempt, behind its re-read. The first attempt re-read too and
    // read no clock, which is what puts the read behind the key-set comparison and not only
    // behind the re-read. A read hoisted above the attempts stands at attempt 0 and answers
    // 08:00; one taken in the first attempt and carried into the second answers 08:05.
    expect($clock->reads)->toBe([['attempt' => 2, 'after' => 're-read']])
        ->and(array_count_values($writer->steps())['rollback'] ?? 0)->toBe(1)
        ->and($shown)->toBe(['2026-04-06 08:07'])
        ->and(guardedStamp($seen[0][1] ?? null))->toBe('2026-04-06 08:07:00+00:00')
        ->and(guardedStamp($writer->sets[0]['columns']['approved_at'] ?? null))->toBe('2026-04-06 08:07:00+00:00');
});

/*
 * The seven transitions as they are described to the shared write, over the same doubles. What
 * is on trial below is each description's landing, asked through the real sequence: no use case
 * here is given a writer of its own to be wrong about.
 */

dataset('landings out of a held state', [
    'confirm, from pending' => [BookingStatus::Pending, HoldClass::Checkout, 'confirmed', fn (GuardedWrite $writes) => (new ConfirmBooking($writes, new SettlesByProvider(new NullPaymentProvider)))->confirm('11111111-0000-4000-8000-000000000001', Actor::customer())],
    'release, from pending' => [BookingStatus::Pending, HoldClass::Checkout, 'cancelled', fn (GuardedWrite $writes) => (new ReleaseHold($writes))->release('11111111-0000-4000-8000-000000000001')],
    'cancel, from pending' => [BookingStatus::Pending, HoldClass::Checkout, 'cancelled', fn (GuardedWrite $writes) => (new CancelBooking($writes))->cancel('11111111-0000-4000-8000-000000000001', Actor::user(42))],
    'cancel, from awaiting_approval' => [BookingStatus::AwaitingApproval, HoldClass::Approval, 'cancelled', fn (GuardedWrite $writes) => (new CancelBooking($writes))->cancel('11111111-0000-4000-8000-000000000001', Actor::user(42))],
    'cancel, from awaiting_payment' => [BookingStatus::AwaitingPayment, HoldClass::Payment, 'cancelled', fn (GuardedWrite $writes) => (new CancelBooking($writes))->cancel('11111111-0000-4000-8000-000000000001', Actor::user(42))],
    'approve, from awaiting_approval' => [BookingStatus::AwaitingApproval, HoldClass::Approval, 'confirmed', fn (GuardedWrite $writes) => guardedApprove($writes)->approve('11111111-0000-4000-8000-000000000001', Actor::user(42))],
    'reject, from awaiting_approval' => [BookingStatus::AwaitingApproval, HoldClass::Approval, 'rejected', fn (GuardedWrite $writes) => (new RejectBooking($writes))->reject('11111111-0000-4000-8000-000000000001', Actor::user(42), 'No room that week.')],
]);

it('ends the hold on every landing out of a held state: the compare-and-set names hold_class and hold_expires_at and sets both to null, whichever transition it is and whichever hold it leaves', function (BookingStatus $from, HoldClass $class, string $lands, Closure $run) {
    $blocks = [guardedBlock(1, '2026-04-07 06:00', '2026-04-07 07:00')];
    [$writes, $writer] = guardedWriteWith([$blocks], [guardedLocked($blocks, $from, $class)]);

    $run($writes);

    $columns = $writer->sets[0]['columns'] ?? [];

    expect($writer->sets)->toHaveCount(1)
        ->and($writer->sets[0]['found'] ?? null)->toBe($from->value)
        ->and($writer->sets[0]['target'] ?? null)->toBe($lands)
        // Named and null, both: a column the landing left out would stay as the hold left it.
        ->and([
            'hold_class' => array_key_exists('hold_class', $columns) ? $columns['hold_class'] : 'not named',
            'hold_expires_at' => array_key_exists('hold_expires_at', $columns) ? $columns['hold_expires_at'] : 'not named',
        ])->toBe(['hold_class' => null, 'hold_expires_at' => null]);
})->with('landings out of a held state');

it('lands an approval by the payment mode the re-read found and the tenant\'s connection: awaiting_payment for online under a working one, confirmed otherwise', function (PaymentMode $mode, bool $connected, string $lands) {
    $blocks = [guardedBlock(1, '2026-04-07 06:00', '2026-04-07 07:00')];
    [$writes, $writer] = guardedWriteWith([$blocks], [guardedLocked($blocks, BookingStatus::AwaitingApproval, HoldClass::Approval, mode: $mode)]);

    guardedApprove($writes, $connected, 36)->approve('11111111-0000-4000-8000-000000000001', Actor::user(42));

    $columns = $writer->sets[0]['columns'] ?? [];

    expect(ApproveBooking::landsOn($mode, guardedSettlement($connected))->value)->toBe($lands)
        ->and($writer->sets[0]['target'] ?? null)->toBe($lands)
        ->and($columns[TransitionColumn::HoldClass->value] ?? null)->toBe($lands === 'awaiting_payment' ? 'payment' : null)
        ->and(($columns[TransitionColumn::HoldExpiresAt->value] ?? null) instanceof ServerNowPlus ? $columns[TransitionColumn::HoldExpiresAt->value]->hours : null)
        ->toBe($lands === 'awaiting_payment' ? 36 : null)
        ->and(array_filter($writer->calls, fn (array $call): bool => $call[0] === 'unblock'))->toBe([]);
})->with([
    'free, connected' => [PaymentMode::Free, true, 'confirmed'],
    'onsite, connected' => [PaymentMode::Onsite, true, 'confirmed'],
    'online, connected' => [PaymentMode::Online, true, 'awaiting_payment'],
    'free, unconnected' => [PaymentMode::Free, false, 'confirmed'],
    'onsite, unconnected' => [PaymentMode::Onsite, false, 'confirmed'],
    'online, unconnected, the degrade' => [PaymentMode::Online, false, 'confirmed'],
]);

it('stamps an approval with the now of the attempt that lands and the user who decided, when the first attempt found its key set moved and rolled back', function () {
    $locked = [guardedBlock(1, '2026-04-07 06:00', '2026-04-07 07:00')];
    $moved = [guardedBlock(1, '2026-04-08 06:00', '2026-04-08 07:00')];
    // 08:00 while the approval is described, 08:05 through the first attempt, 08:07 through the
    // second.
    $clock = new GuardedWriteTestClock('2026-04-06 08:00', '2026-04-06 08:05', '2026-04-06 08:07');
    [$writes, $writer] = guardedWriteWith(
        [$locked, $moved],
        [guardedLocked($moved, BookingStatus::AwaitingApproval, HoldClass::Approval)],
        clock: $clock,
    );

    guardedApprove($writes)->approve('11111111-0000-4000-8000-000000000001', Actor::user(42));

    // The description read no clock while it was built, which would have answered 08:00, and
    // the attempt that rolled back read none, which would have answered 08:05: the one read
    // there was is the landing attempt's, behind its re-read, and it is what the row is set to.
    expect($clock->reads)->toBe([['attempt' => 2, 'after' => 're-read']])
        ->and(array_count_values($writer->steps())['rollback'] ?? 0)->toBe(1)
        ->and($writer->sets)->toHaveCount(1)
        ->and($writer->sets[0]['columns']['approved_by'] ?? null)->toBe(42)
        ->and(guardedStamp($writer->sets[0]['columns']['approved_at'] ?? null))->toBe('2026-04-06 08:07:00+00:00');
});

it('refuses a landing that names a column outside the list a transition may write, or a value no column can hold, where it is stated', function () {
    expect(fn () => new TransitionLanding(BookingStatus::Confirmed, ['status' => 'confirmed']))->toThrow(InvalidArgumentException::class, 'outside the list a transition may write')
        ->and(fn () => new TransitionLanding(BookingStatus::Confirmed, [TransitionColumn::RejectionReason->value => new stdClass]))->toThrow(InvalidArgumentException::class, 'a column takes a scalar, a backed enum, an instant, a ServerNowPlus or null')
        ->and((new TransitionLanding(BookingStatus::Confirmed, [TransitionColumn::HoldClass->value => HoldClass::Payment]))->columns)->toBe(['hold_class' => 'payment'])
        ->and((new TransitionLanding(BookingStatus::Completed))->columns)->toBe([]);
});

/**
 * Whether a booking settles unpaid, as a tenant with or without a working connection answers it:
 * free and on-site always do, online only where nothing can take the money.
 */
function guardedSettlement(bool $connected): SettlementRule
{
    return new readonly class($connected) implements SettlementRule
    {
        public function __construct(private bool $connected) {}

        public function settlesWithoutPayment(PaymentMode $mode): bool
        {
            return $mode !== PaymentMode::Online || ! $this->connected;
        }
    };
}

function guardedApprove(GuardedWrite $writes, bool $connected = false, int $paymentHoldHours = 24): ApproveBooking
{
    return new ApproveBooking($writes, guardedSettlement($connected), fn (): int => $paymentHoldHours);
}
