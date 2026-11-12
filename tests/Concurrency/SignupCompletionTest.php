<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Billing\BillingWebhook;
use App\Billing\NoticeKind;
use App\Billing\SubscriptionSnapshot;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FakeBillingProvider;

/*
 * The checkout completion against another delivery for the same built tenant: the completion is
 * stopped once the provider has answered it and before it writes, the other delivery runs to
 * the end, and the completion then goes on, the row's revision sending it back to read the row
 * again. The signup opens no checkout, so no completion builds a row; the build's lock is the
 * verification click's, proven in SignupReserveTest. The completion is the one the container
 * binds; worker closures call only autoloaded code.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_luna cascade');
});

afterEach(function () {
    DB::statement('drop schema if exists tenant_luna cascade');
});

/**
 * The first worker is stopped once the provider answered it; the second runs to the end; the
 * first finishes. Answers what each returned.
 *
 * @param  array{0: string, 1: Closure}  $first
 * @param  array{0: string, 1: Closure}  $second
 * @return array<string, mixed>
 */
function completionSecondCommitsFirst(array $first, array $second): array
{
    $run = ConcurrentRun::of([$first[0] => $first[1], $second[0] => $second[1]])->holding(FakeBillingProvider::ANSWERED, 'go')->start();
    $run->release();
    $run->releaseCheckpoint('go', $first[0]);
    $run->awaitCheckpoint($first[0], FakeBillingProvider::ANSWERED);
    $run->releaseCheckpoint(FakeBillingProvider::ANSWERED, $second[0]);
    $run->releaseCheckpoint('go', $second[0]);
    $returned = [$second[0] => $run->outcomeOf($second[0])->returned()];
    $run->releaseCheckpoint(FakeBillingProvider::ANSWERED, $first[0]);
    $outcomes = $run->wait();

    return [$first[0] => $outcomes[$first[0]]->returned(), ...$returned];
}

/**
 * A worker that delivers one event with its fake answering the given snapshots in turn, and
 * answers how many tenant migrations ran in it and how many times it read the provider.
 */
function completionWorker(string $eventId, NoticeKind $kind, string $subscriptionId, SubscriptionSnapshot ...$answers): Closure
{
    return function () use ($eventId, $kind, $subscriptionId, $answers): array {
        Checkpoint::reach('go');
        $fake = FakeBillingProvider::bound();
        $fake->scriptInTurn(...$answers);
        $migrated = 0;
        Event::listen(MigrationEnded::class, function () use (&$migrated): void {
            $migrated++;
        });

        $payload = FakeBillingProvider::notice($eventId, $kind, 'cus_luna', $subscriptionId);
        app(BillingWebhook::class)->receive($fake->verify($payload, FakeBillingProvider::sign($payload)), $payload);

        return ['migrated' => $migrated, 'reads' => count(array_filter($fake->asked, fn (array $question): bool => $question[0] === 'subscription'))];
    };
}

it('ends on the new subscription, active, when a late delete of the old one commits while the completion waits on its provider read', function () {
    billingTenant('luna');
    billingFake()->script(billingSnapshot('sub_old', 'cus_luna', 'active', ['price_floor_test']));
    syncWith()->refresh('sub_old');
    $new = billingSnapshot('sub_new', 'cus_luna', 'active', ['price_staff_test']);

    completionSecondCommitsFirst(
        ['completed', completionWorker('evt_completed', NoticeKind::CheckoutCompleted, 'sub_new', $new, $new)],
        ['deleted', completionWorker('evt_deleted', NoticeKind::SubscriptionDeleted, 'sub_old', billingSnapshot('sub_old', 'cus_luna', 'canceled', ['price_floor_test']))],
    );

    expect(subscriptionColumns()['provider_subscription_id'])->toBe('sub_new')
        ->and(billingColumns()['subscription_state'])->toBe('active')
        ->and(billingColumns()['entitlements'])->toBe('{staff}')
        ->and(DB::table('webhook_events')->whereNotNull('processed_at')->pluck('provider_event_id')->sort()->values()->all())->toBe(['evt_completed', 'evt_deleted']);
});

it('lands two completions of a trial tenant\'s checkout at once as one subscription on the built row, building nothing', function () {
    billingTenant('luna');
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => app(Clock::class)->now()->setTimezone(new DateTimeZone('UTC'))->add(new DateInterval('P7D'))->format('Y-m-d H:i:sP')]);
    $paid = billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']);

    $migrated = completionSecondCommitsFirst(
        ['first', completionWorker('evt_first', NoticeKind::CheckoutCompleted, 'sub_luna', $paid)],
        ['second', completionWorker('evt_second', NoticeKind::CheckoutCompleted, 'sub_luna', $paid)],
    );

    expect($migrated)->toBe(['first' => ['migrated' => 0, 'reads' => 1], 'second' => ['migrated' => 0, 'reads' => 1]])
        ->and(DB::table('tenants')->where('slug', 'luna')->value('status'))->toBe('active')
        ->and(billingColumns())->toMatchArray(['subscription_state' => 'active', 'entitlements' => '{staff}'])
        ->and(DB::table('tenants')->where('slug', 'luna')->value('trial_ends_at'))->toBeNull()
        ->and(subscriptionColumns()['provider_subscription_id'])->toBe('sub_luna')
        ->and(DB::table('pg_namespace')->where('nspname', 'tenant_luna')->exists())->toBeFalse()
        ->and(DB::table('webhook_events')->whereNull('processed_at')->count())->toBe(0);
});
