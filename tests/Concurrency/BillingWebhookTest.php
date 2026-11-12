<?php

use App\Application\Checkpoint;
use App\Billing\BillingWebhook;
use App\Billing\CheckoutCompletion;
use App\Billing\NoticeKind;
use App\Billing\SubscriptionSnapshot;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FakeBillingProvider;
use Tests\Support\RecordingCompletion;

/*
 * Two billing events for one tenant handled at the same moment land as if one had been handled
 * after the other: the comparison against the row's current subscription, the re-read through
 * the port, the charge's status decision and the sync's write hold together. The first worker
 * is stopped once the provider has answered it and before it writes; the second is let through
 * to the end; the first then goes on. The provider's truth has moved by then, which the first
 * worker's fake says by answering a second read with what the second worker read.
 *
 * Worker closures run in another process and call only autoloaded code; each builds and
 * verifies its own notification with the fake the test environment binds there.
 */

beforeEach(function () {
    billingTenant('luna');
});

/**
 * The first worker is stopped once the provider answered it; the second runs to the end; the
 * first finishes.
 *
 * @param  array{0: string, 1: Closure}  $first
 * @param  array{0: string, 1: Closure}  $second
 */
function billingSecondCommitsFirst(array $first, array $second): void
{
    $run = ConcurrentRun::of([$first[0] => $first[1], $second[0] => $second[1]])->holding(FakeBillingProvider::ANSWERED, 'go')->start();
    $run->release();
    $run->releaseCheckpoint('go', $first[0]);
    $run->awaitCheckpoint($first[0], FakeBillingProvider::ANSWERED);
    $run->releaseCheckpoint(FakeBillingProvider::ANSWERED, $second[0]);
    $run->releaseCheckpoint('go', $second[0]);
    expect($run->outcomeOf($second[0])->returned())->toBe('handled');
    $run->releaseCheckpoint(FakeBillingProvider::ANSWERED, $first[0]);

    foreach ($run->wait() as $outcome) {
        expect($outcome->returned())->toBe('handled');
    }
}

/**
 * A worker that delivers one event with its fake answering the given snapshots in turn, after
 * binding the checkout seam to a refreshing one.
 */
function billingDeliveryWorker(string $eventId, NoticeKind $kind, string $subscriptionId, SubscriptionSnapshot ...$answers): Closure
{
    return function () use ($eventId, $kind, $subscriptionId, $answers): string {
        Checkpoint::reach('go');
        $fake = FakeBillingProvider::bound();

        if ($answers !== []) {
            $fake->scriptInTurn(...$answers);
        }

        if ($kind === NoticeKind::CheckoutCompleted) {
            app()->instance(CheckoutCompletion::class, new RecordingCompletion(refresh: true));
        }

        $payload = FakeBillingProvider::notice($eventId, $kind, 'cus_luna', $subscriptionId);
        app(BillingWebhook::class)->receive($fake->verify($payload, FakeBillingProvider::sign($payload)), $payload);

        return 'handled';
    };
}

it('lands the newer snapshot when an older one read first would commit last', function () {
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']));
    syncWith()->refresh('sub_luna');
    $created = billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']);
    $updated = billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']);

    billingSecondCommitsFirst(
        ['created', billingDeliveryWorker('evt_created', NoticeKind::SubscriptionChanged, 'sub_luna', $created, $updated)],
        ['updated', billingDeliveryWorker('evt_updated', NoticeKind::SubscriptionChanged, 'sub_luna', $updated)],
    );

    expect(billingColumns()['entitlements'])->toBe('{staff}')
        ->and(subscriptionColumns()['items'])->toBe('[{"price": "price_staff_test", "quantity": 1}]')
        ->and(DB::table('webhook_events')->whereNull('processed_at')->count())->toBe(0);
});

it('opens no grace on a paid tenant when a succeeded charge commits between a failed charge\'s re-read and its write', function () {
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']));
    syncWith()->refresh('sub_luna');
    $pastDue = billingSnapshot('sub_luna', 'cus_luna', 'past_due', ['price_floor_test']);
    $paid = billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']);

    billingSecondCommitsFirst(
        ['failed', billingDeliveryWorker('evt_failed', NoticeKind::ChargeFailed, 'sub_luna', $pastDue, $paid)],
        ['succeeded', billingDeliveryWorker('evt_succeeded', NoticeKind::ChargeSucceeded, 'sub_luna', $paid)],
    );

    expect(billingColumns()['subscription_state'])->toBe('active')
        ->and(billingColumns()['grace_ends_at'])->toBeNull();
});

it('keeps a resubscriber\'s new subscription when a late delete of the old one is handled at the moment the checkout completes', function () {
    billingFake()->script(billingSnapshot('sub_old', 'cus_luna', 'active', ['price_floor_test']));
    syncWith()->refresh('sub_old');

    billingSecondCommitsFirst(
        ['deleted', billingDeliveryWorker('evt_deleted', NoticeKind::SubscriptionDeleted, 'sub_old', billingSnapshot('sub_old', 'cus_luna', 'canceled', ['price_floor_test']))],
        ['completed', billingDeliveryWorker('evt_completed', NoticeKind::CheckoutCompleted, 'sub_new', billingSnapshot('sub_new', 'cus_luna', 'active', ['price_staff_test']))],
    );

    expect(subscriptionColumns()['provider_subscription_id'])->toBe('sub_new')
        ->and(billingColumns()['subscription_state'])->toBe('active')
        ->and(billingColumns()['entitlements'])->toBe('{staff}');
});
