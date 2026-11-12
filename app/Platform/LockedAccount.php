<?php

declare(strict_types=1);

namespace App\Platform;

use App\Billing\SubscriptionState;
use App\Tenancy\TenantStatus;
use DateTimeImmutable;

/**
 * A tenant row as AccountClose, the one writer of a close, read it under the row's lock, handed to
 * the Closer to decide whether it closes the row as it stands: its status, its subscription state,
 * whether its trial end is set, the end of the last period its subscriptions row recorded, null
 * where no snapshot ever named one or no row stands beside it, and the row's last update. The
 * owner's and the console's closers ask the status alone; the sweep's close of a lapsed subscriber
 * asks the rest, since a subscription landing between its read of the due rows and the lock would
 * otherwise be closed and cancelled at the provider. Every instant is in UTC. Whether the
 * subscription's first payment never went through is read off the provider's status on the
 * subscriptions row, the one status that ends a subscription nobody ever paid for; the sweep's mail
 * names no period for it.
 */
final readonly class LockedAccount
{
    public function __construct(
        public TenantStatus $status,
        public SubscriptionState $state,
        public bool $trialEnd,
        public ?DateTimeImmutable $periodEnd,
        public ?DateTimeImmutable $updatedAt,
        public bool $neverPaid = false,
    ) {}
}
