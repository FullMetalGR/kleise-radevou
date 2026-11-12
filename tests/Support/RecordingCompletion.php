<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\CheckoutCompletion;
use App\Billing\SubscriptionSync;
use App\Billing\SyncOutcome;

/**
 * A checkout seam that records every pair it is handed. It answers the outcome it was built
 * with or, built to refresh, does what a signup completion does with the billing columns: the
 * session's subscription synced through the port at the revision the webhook read the row at.
 */
final class RecordingCompletion implements CheckoutCompletion
{
    /** @var list<array{0: string, 1: string}> */
    public array $completed = [];

    public function __construct(
        private readonly ?SyncOutcome $answer = null,
        private readonly bool $refresh = false,
    ) {}

    public function completed(string $customerId, string $subscriptionId, int $revision): ?SyncOutcome
    {
        $this->completed[] = [$customerId, $subscriptionId];

        return $this->refresh ? app(SubscriptionSync::class)->refresh($subscriptionId, $revision) : $this->answer;
    }
}
