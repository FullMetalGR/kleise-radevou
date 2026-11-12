<?php

use App\Application\Dto\LapsedHold;
use App\Application\Dto\ServerNowPlus;
use App\Application\Event\TransitionEvent;
use App\Application\Event\TransitionPublisher;
use App\Application\ExpireHolds;
use App\Application\Payment\SettlementRule;
use App\Application\Repository\HoldSweep;
use App\Application\Repository\SweepBatch;
use App\Domain\Audit\AuditEntry;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use Tests\Support\FixedClock;

/*
 * The hold sweeper with no database: a batch that answers a selection the test scripted and
 * records every call, a sweep that records the transaction around them, and a publisher that
 * writes into the same log. What is on trial is the loop, the split between the two
 * settlements, and that nothing is announced until the batch it belongs to has returned.
 */

final class ExpireHoldsTestBatch implements SweepBatch
{
    /** @var list<array<mixed>> */
    public array $calls = [];

    /** @var list<list<LapsedHold>> what each selection answers, in order */
    private array $selections;

    /**
     * @param  list<list<LapsedHold>>  $selections
     */
    public function __construct(array $selections, private readonly bool $claims = true)
    {
        $this->selections = $selections;
    }

    public function claim(): bool
    {
        $this->calls[] = ['claim', $this->claims];

        return $this->claims;
    }

    public function lapsed(int $limit): array
    {
        $this->calls[] = ['lapsed', $limit];

        return array_shift($this->selections) ?? [];
    }

    public function expire(array $uuids): void
    {
        $this->calls[] = ['expire', $uuids];
    }

    public function awaitPayment(array $uuids, array $columns): void
    {
        $this->calls[] = ['await payment', $uuids, array_map(
            fn (mixed $value): mixed => match (true) {
                $value instanceof DateTimeImmutable => $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
                $value instanceof ServerNowPlus => 'now() + '.$value->hours.'h',
                default => $value,
            },
            $columns,
        )];
    }

    public function confirm(array $uuids, array $columns): void
    {
        $this->calls[] = ['confirm', $uuids, array_map(
            fn (mixed $value): mixed => $value instanceof DateTimeImmutable
                ? $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP')
                : $value,
            $columns,
        )];
    }

    public function writeTrail(array $entries): void
    {
        $this->calls[] = ['trail', array_map(
            fn (AuditEntry $entry): array => [$entry->bookingUuid, $entry->action->value, $entry->actor->value, $entry->payload],
            $entries,
        )];
    }

    /**
     * The calls this batch was taken through, in order, by name.
     *
     * @return list<string>
     */
    public function steps(): array
    {
        return array_map(fn (array $call): string => is_string($call[0] ?? null) ? $call[0] : 'unnamed', $this->calls);
    }
}

final class ExpireHoldsTestSweep implements HoldSweep, TransitionPublisher
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(public ExpireHoldsTestBatch $batch) {}

    public function transaction(Closure $work): mixed
    {
        $this->log[] = 'begin';
        $result = $work($this->batch);
        $this->log[] = 'commit';

        return $result;
    }

    public function publish(TransitionEvent $event): void
    {
        $name = strrchr($event::class, '\\');

        $this->log[] = 'publish '.($name === false ? $event::class : substr($name, 1));
    }
}

/**
 * @param  list<list<LapsedHold>>  $selections
 * @return array{ExpireHolds, ExpireHoldsTestSweep, ExpireHoldsTestBatch}
 */
function sweeping(array $selections, bool $claims = true, bool $connected = false): array
{
    $batch = new ExpireHoldsTestBatch($selections, $claims);
    $sweep = new ExpireHoldsTestSweep($batch);
    $settlement = new readonly class($connected) implements SettlementRule
    {
        public function __construct(private bool $connected) {}

        public function settlesWithoutPayment(PaymentMode $mode): bool
        {
            return $mode !== PaymentMode::Online || ! $this->connected;
        }
    };

    return [new ExpireHolds($sweep, FixedClock::at('2026-11-03 09:00:00'), $sweep, $settlement, fn (): int => 24), $sweep, $batch];
}

function lapsedHold(string $uuid, string $status = 'pending', ?string $holdClass = 'checkout', string $timeout = 'expire', string $mode = 'online'): LapsedHold
{
    return new LapsedHold(
        $uuid,
        BookingStatus::from($status),
        $holdClass === null ? null : HoldClass::from($holdClass),
        ApprovalTimeout::from($timeout),
        PaymentMode::from($mode),
    );
}

it('takes the singleton lock before it reads anything, selects a batch of five hundred, expires what it found and writes one audit row each', function () {
    [$sweep, , $batch] = sweeping([[lapsedHold('a'), lapsedHold('b', 'awaiting_payment', 'payment')], []]);

    $swept = $sweep->sweep();

    expect($batch->steps())->toBe(['claim', 'lapsed', 'expire', 'trail', 'claim', 'lapsed'])
        ->and($batch->calls[1])->toBe(['lapsed', 500])
        ->and($batch->calls[2])->toBe(['expire', ['a', 'b']])
        // One trail call for the whole batch, never one per booking.
        ->and($batch->calls[3])->toBe(['trail', [
            ['a', 'expired', 'job:sweeper', [
                'status_from' => 'pending',
                'status_to' => 'expired',
                'hold_class' => 'checkout',
            ]],
            ['b', 'expired', 'job:sweeper', [
                'status_from' => 'awaiting_payment',
                'status_to' => 'expired',
                'hold_class' => 'payment',
            ]],
        ]])
        ->and($swept->ran)->toBeTrue()
        ->and($swept->expired)->toBe(2)
        ->and($swept->confirmed)->toBe(0)
        ->and($swept->batches)->toBe(1);
});

it('confirms an auto-approving request instead, with the approval columns, nobody named and its items left blocking', function () {
    [$sweep, , $batch] = sweeping([[
        lapsedHold('granted', 'awaiting_approval', 'approval', 'auto_approve'),
        lapsedHold('lapsed', 'awaiting_approval', 'approval'),
    ], []]);

    $swept = $sweep->sweep();

    expect($batch->steps())->toBe(['claim', 'lapsed', 'expire', 'trail', 'confirm', 'trail', 'claim', 'lapsed'])
        ->and($batch->calls[2])->toBe(['expire', ['lapsed']])
        ->and($batch->calls[4])->toBe(['confirm', ['granted'], [
            'hold_class' => null,
            'hold_expires_at' => null,
            'approved_at' => '2026-11-03 09:00:00+00:00',
            'approved_by' => null,
        ]])
        ->and($batch->calls[5])->toBe(['trail', [
            ['granted', 'approved', 'job:sweeper', [
                'status_from' => 'awaiting_approval',
                'status_to' => 'confirmed',
                'hold_class' => 'approval',
            ]],
        ]])
        ->and($swept->expired)->toBe(1)
        ->and($swept->confirmed)->toBe(1)
        ->and($swept->batches)->toBe(1);
});

it('announces nothing until the batch it belongs to has returned, and announces one record per booking either way', function () {
    [$sweep, $log] = sweeping([[
        lapsedHold('gone'),
        lapsedHold('granted', 'awaiting_approval', 'approval', 'auto_approve'),
    ], []]);

    $sweep->sweep();

    expect($log->log)->toBe([
        'begin',
        'commit',
        'publish BookingExpired',
        'publish BookingApproved',
        'begin',
        'commit',
    ]);
});

it('stands down at once when another sweep of this tenant holds the lock, reading nothing, writing nothing and announcing nothing', function () {
    [$sweep, $log, $batch] = sweeping([[lapsedHold('a')]], claims: false);

    $swept = $sweep->sweep();

    expect($batch->steps())->toBe(['claim'])
        ->and($log->log)->toBe(['begin', 'commit'])
        ->and($swept->ran)->toBeFalse()
        ->and($swept->expired)->toBe(0)
        ->and($swept->batches)->toBe(0);
});

it('loops batch after batch while each one comes back full, and the batch that finds nothing ends the sweep without counting as one', function () {
    $full = fn (int $from): array => array_map(
        fn (int $offset): LapsedHold => lapsedHold('hold-'.($from + $offset)),
        range(0, 499),
    );

    [$sweep, $log] = sweeping([$full(0), $full(500), array_slice($full(1000), 0, 200), []]);

    $swept = $sweep->sweep();

    expect($swept->ran)->toBeTrue()
        ->and($swept->expired)->toBe(1200)
        ->and($swept->batches)->toBe(3)
        ->and(count(array_filter($log->log, fn (string $entry): bool => $entry === 'begin')))->toBe(4);
});

it('does nothing at all on a tenant with nothing to sweep: one transaction, no settlement and no record', function () {
    [$sweep, $log, $batch] = sweeping([[]]);

    $swept = $sweep->sweep();

    expect($batch->steps())->toBe(['claim', 'lapsed'])
        ->and($log->log)->toBe(['begin', 'commit'])
        ->and($swept->ran)->toBeTrue()
        ->and($swept->touched())->toBe(0)
        ->and($swept->batches)->toBe(0);
});

it('lands a request the clock granted by the rule the owner\'s Approve lands by: online under a working connection awaits its payment under a payment hold the server\'s clock ends, the rest confirm, and each says where it landed', function () {
    [$sweep, , $batch] = sweeping([[
        lapsedHold('online', 'awaiting_approval', 'approval', 'auto_approve'),
        lapsedHold('onsite', 'awaiting_approval', 'approval', 'auto_approve', 'onsite'),
    ], []], connected: true);

    $sweep->sweep();

    $approved = ['approved_at' => '2026-11-03 09:00:00+00:00', 'approved_by' => null];

    expect(array_values(array_filter($batch->calls, fn (array $call): bool => in_array($call[0], ['confirm', 'await payment'], true))))->toBe([
        ['await payment', ['online'], ['hold_class' => 'payment', 'hold_expires_at' => 'now() + 24h', ...$approved]],
        ['confirm', ['onsite'], ['hold_class' => null, 'hold_expires_at' => null, ...$approved]],
    ]);
});

it('expires an unpaid payment hold whatever its service\'s timeout policy, and never grants it a second time', function () {
    [$sweep, , $batch] = sweeping([[lapsedHold('unpaid', 'awaiting_payment', 'payment', 'auto_approve')], []], connected: true);

    $sweep->sweep();

    expect(array_values(array_filter($batch->calls, fn (array $call): bool => in_array($call[0], ['expire', 'confirm', 'await payment'], true))))
        ->toBe([['expire', ['unpaid']]]);
});
