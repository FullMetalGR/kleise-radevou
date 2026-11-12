<?php

use App\Domain\Audit\Actor as AuditActor;
use App\Domain\Booking\Actor;
use App\Domain\Booking\CancellationPolicy;
use App\Domain\Booking\PolicyDecision;
use App\Domain\Booking\ServiceWindows;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;

/*
 * The two decisions the server makes on the row it re-read under lock, with no clock of their
 * own: may this booking be cancelled now, and may it be rescheduled now. The window rule binds
 * the customer alone.
 *
 * Unless a test says otherwise the first segment starts at 10:00 UTC on the tenth of June and
 * now is nine days earlier, far outside any window here, so the status rule is the only thing
 * a status test can trip on.
 */

function policyInstant(string $text, string $zone = 'UTC'): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone($zone));
}

function policyWindows(int $cancelHours = 24, int $rescheduleHours = 24): ServiceWindows
{
    return new ServiceWindows(cancelHours: $cancelHours, rescheduleHours: $rescheduleHours);
}

/**
 * @param  bool  $holdIsLive  the expiry comparison the server made on the row, which the policy takes as given
 * @param  list<ServiceWindows>  $windows
 */
function policyFor(BookingStatus $status, bool $holdIsLive = false, ?DateTimeImmutable $firstStart = null, array $windows = []): CancellationPolicy
{
    return new CancellationPolicy(
        status: $status,
        holdIsLive: $holdIsLive,
        firstStart: $firstStart ?? policyInstant('2026-06-10 10:00:00'),
        windows: $windows === [] ? [policyWindows()] : $windows,
    );
}

function policyNow(): DateTimeImmutable
{
    return policyInstant('2026-06-01 10:00:00');
}

/**
 * A hold the server found still live: its expiry is ahead of the database's now, compared where
 * the row was read. The comparison is made before the policy sees it and the policy makes none
 * of its own.
 */
const POLICY_HOLD_LIVE = true;

const POLICY_HOLD_LAPSED = false;

/**
 * The verdict a held status arrives with unless a test says otherwise: live. A status that is
 * not a held one has no hold for the server to have judged, and the policy reads the verdict
 * only for the three that do.
 */
function policyHoldOf(BookingStatus $status): bool
{
    return $status->isHeld();
}

it('lets {status} be cancelled by the customer when the status rule is the only rule in play: {allowed}', function (BookingStatus $status, bool $allowed) {
    $decision = policyFor($status, policyHoldOf($status))->mayCancel(Actor::Customer, policyNow());

    expect($decision->isAllowed())->toBe($allowed)
        ->and($decision->status)->toBe($status)
        ->and($decision->refusal)->toBe($allowed ? null : TransitionRefusal::NotCancellable);
})->with([
    'pending' => [BookingStatus::Pending, true],
    'awaiting_approval' => [BookingStatus::AwaitingApproval, true],
    'awaiting_payment' => [BookingStatus::AwaitingPayment, true],
    'confirmed' => [BookingStatus::Confirmed, true],
    'completed' => [BookingStatus::Completed, false],
    'no_show' => [BookingStatus::NoShow, false],
    'cancelled' => [BookingStatus::Cancelled, false],
    'rejected' => [BookingStatus::Rejected, false],
    'expired' => [BookingStatus::Expired, false],
]);

it('lets {status} with a live hold be rescheduled by the customer when the status rule is the only rule in play: {allowed}', function (BookingStatus $status, bool $allowed) {
    $decision = policyFor($status, policyHoldOf($status))->mayReschedule(Actor::Customer, policyNow());

    expect($decision->isAllowed())->toBe($allowed)
        ->and($decision->status)->toBe($status)
        ->and($decision->refusal)->toBe($allowed ? null : TransitionRefusal::NotReschedulable);
})->with([
    'pending' => [BookingStatus::Pending, true],
    'awaiting_approval' => [BookingStatus::AwaitingApproval, true],
    'awaiting_payment' => [BookingStatus::AwaitingPayment, true],
    'confirmed' => [BookingStatus::Confirmed, true],
    'completed' => [BookingStatus::Completed, false],
    'no_show' => [BookingStatus::NoShow, false],
    'cancelled' => [BookingStatus::Cancelled, false],
    'rejected' => [BookingStatus::Rejected, false],
    'expired' => [BookingStatus::Expired, false],
]);

it('lets a pending booking whose hold has lapsed be cancelled and not rescheduled', function () {
    $policy = policyFor(BookingStatus::Pending, POLICY_HOLD_LAPSED);

    expect($policy->mayCancel(Actor::Customer, policyNow())->isAllowed())->toBeTrue()
        ->and($policy->mayReschedule(Actor::Customer, policyNow())->isAllowed())->toBeFalse()
        ->and($policy->mayReschedule(Actor::Customer, policyNow())->refusal)->toBe(TransitionRefusal::HoldLapsed);
});

it('refuses to reschedule {status} whose hold is no longer live, for the hold and not for the status', function (BookingStatus $status) {
    expect(policyFor($status, POLICY_HOLD_LAPSED)->mayReschedule(Actor::Customer, policyNow())->refusal)->toBe(TransitionRefusal::HoldLapsed);
})->with([
    'pending' => [BookingStatus::Pending],
    'awaiting_approval' => [BookingStatus::AwaitingApproval],
    'awaiting_payment' => [BookingStatus::AwaitingPayment],
]);

it('takes the expiry verdict as given and compares no instant of its own, because the server made that comparison where it read the row', function () {
    expect(policyFor(BookingStatus::Pending, POLICY_HOLD_LIVE)->mayReschedule(Actor::Customer, policyNow())->isAllowed())->toBeTrue()
        ->and(policyFor(BookingStatus::Pending, POLICY_HOLD_LAPSED)->mayReschedule(Actor::Customer, policyNow())->refusal)->toBe(TransitionRefusal::HoldLapsed)
        // The question is an expiry in the future, and not the blocking predicate's, which also
        // counts an auto-approving request past its window as occupying its slot. An approval
        // request reads its expiry like any other held status here.
        ->and(policyFor(BookingStatus::AwaitingApproval, POLICY_HOLD_LIVE)->mayReschedule(Actor::Customer, policyNow())->isAllowed())->toBeTrue()
        ->and(policyFor(BookingStatus::AwaitingApproval, POLICY_HOLD_LAPSED)->mayReschedule(Actor::Customer, policyNow())->refusal)->toBe(TransitionRefusal::HoldLapsed);
});

it('ignores the expiry verdict on a booking whose status is not a held one', function () {
    expect(policyFor(BookingStatus::Confirmed, POLICY_HOLD_LAPSED)->mayReschedule(Actor::Customer, policyNow())->isAllowed())->toBeTrue()
        ->and(policyFor(BookingStatus::Completed, POLICY_HOLD_LIVE)->mayReschedule(Actor::Customer, policyNow())->refusal)->toBe(TransitionRefusal::NotReschedulable);
});

it('carries no refusal when allowed, and is a value the caller can compare', function () {
    $decision = policyFor(BookingStatus::Confirmed)->mayCancel(Actor::Customer, policyNow());

    expect($decision->isAllowed())->toBeTrue()
        ->and($decision->refusal)->toBeNull()
        ->and($decision)->toEqual(PolicyDecision::allowed(BookingStatus::Confirmed));
});

it('answers only from the one refusal vocabulary, and names the act it refused rather than the rule it refused by', function () {
    $terminal = policyFor(BookingStatus::Completed);
    $inside = policyInstant('2026-06-09 10:00:01');

    expect($terminal->mayCancel(Actor::Customer, policyNow())->refusal)->toBe(TransitionRefusal::NotCancellable)
        ->and($terminal->mayReschedule(Actor::Customer, policyNow())->refusal)->toBe(TransitionRefusal::NotReschedulable)
        ->and(policyFor(BookingStatus::Confirmed)->mayCancel(Actor::Customer, $inside)->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and(policyFor(BookingStatus::Pending, POLICY_HOLD_LAPSED)->mayReschedule(Actor::Customer, policyNow())->refusal)->toBe(TransitionRefusal::HoldLapsed);
});

it('knows two actors, the customer through the manage token and an admin', function () {
    expect(array_map(fn (Actor $actor): string => $actor->value, Actor::cases()))
        ->toBe(['customer', 'admin']);
});

it('reads the trail\'s five actor shapes down to the two a window distinguishes, support acting for the business', function () {
    expect(Actor::behind(AuditActor::customer()))->toBe(Actor::Customer)
        ->and(Actor::behind(AuditActor::user(1)))->toBe(Actor::Admin)
        ->and(Actor::behind(AuditActor::system()))->toBe(Actor::Admin)
        ->and(Actor::behind(AuditActor::job('expire-holds')))->toBe(Actor::Admin)
        ->and(Actor::behind(AuditActor::support(1)))->toBe(Actor::Admin);
});

/*
 * The window rule. With the first segment at 10:00 UTC on the tenth of June, a 24-hour window
 * closes at 10:00 UTC on the ninth: at that instant the customer is still allowed, one second
 * later they are not.
 */

it('allows a customer cancel at the limit of the window and refuses one a second past it', function () {
    $policy = policyFor(BookingStatus::Confirmed);

    expect($policy->mayCancel(Actor::Customer, policyInstant('2026-06-09 10:00:00'))->isAllowed())->toBeTrue()
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-06-09 10:00:01'))->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-06-09 10:00:00.000001'))->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-06-09 09:59:59'))->isAllowed())->toBeTrue()
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-06-10 10:00:01'))->refusal)->toBe(TransitionRefusal::WindowClosed);
});

it('reads the cancel window for a cancel and the reschedule window for a reschedule', function () {
    $policy = policyFor(BookingStatus::Confirmed, windows: [policyWindows(cancelHours: 2, rescheduleHours: 48)]);
    $now = policyInstant('2026-06-09 10:00:00');

    expect($policy->mayCancel(Actor::Customer, $now)->isAllowed())->toBeTrue()
        ->and($policy->mayReschedule(Actor::Customer, $now)->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($policy->mayReschedule(Actor::Customer, policyInstant('2026-06-08 10:00:00'))->isAllowed())->toBeTrue()
        ->and($policy->mayReschedule(Actor::Customer, policyInstant('2026-06-08 10:00:01'))->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-06-10 08:00:00'))->isAllowed())->toBeTrue()
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-06-10 08:00:01'))->refusal)->toBe(TransitionRefusal::WindowClosed);
});

it('takes the largest window of each kind across the segments, measured back from the first start', function () {
    $policy = policyFor(BookingStatus::Confirmed, windows: [
        policyWindows(cancelHours: 2, rescheduleHours: 2),
        policyWindows(cancelHours: 48, rescheduleHours: 6),
        policyWindows(cancelHours: 24, rescheduleHours: 24),
    ]);

    expect($policy->mayCancel(Actor::Customer, policyInstant('2026-06-08 10:00:00'))->isAllowed())->toBeTrue()
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-06-08 10:00:01'))->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($policy->mayReschedule(Actor::Customer, policyInstant('2026-06-09 10:00:00'))->isAllowed())->toBeTrue()
        ->and($policy->mayReschedule(Actor::Customer, policyInstant('2026-06-09 10:00:01'))->refusal)->toBe(TransitionRefusal::WindowClosed);
});

it('binds a customer inside the window on {status} the same way as on a confirmed booking', function (BookingStatus $status) {
    $inside = policyInstant('2026-06-09 10:00:01');
    $held = policyFor($status, POLICY_HOLD_LIVE);
    $confirmed = policyFor(BookingStatus::Confirmed);

    expect($held->mayCancel(Actor::Customer, $inside)->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($held->mayReschedule(Actor::Customer, $inside)->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($confirmed->mayCancel(Actor::Customer, $inside)->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($confirmed->mayReschedule(Actor::Customer, $inside)->refusal)->toBe(TransitionRefusal::WindowClosed);
})->with([
    'pending' => [BookingStatus::Pending],
    'awaiting_approval' => [BookingStatus::AwaitingApproval],
    'awaiting_payment' => [BookingStatus::AwaitingPayment],
]);

it('binds an admin by the status rule alone, never by a window', function (BookingStatus $status, bool $allowed) {
    $inside = policyInstant('2026-06-10 09:59:59');
    $policy = policyFor($status, policyHoldOf($status));

    expect($policy->mayCancel(Actor::Admin, $inside)->isAllowed())->toBe($allowed)
        ->and($policy->mayCancel(Actor::Admin, $inside)->refusal)->toBe($allowed ? null : TransitionRefusal::NotCancellable)
        ->and($policy->mayReschedule(Actor::Admin, $inside)->isAllowed())->toBe($allowed && $status !== BookingStatus::Pending)
        ->and($policy->mayCancel(Actor::Admin, policyInstant('2026-06-10 10:00:01'))->isAllowed())->toBe($allowed);
})->with([
    'pending' => [BookingStatus::Pending, true],
    'awaiting_approval' => [BookingStatus::AwaitingApproval, true],
    'awaiting_payment' => [BookingStatus::AwaitingPayment, true],
    'confirmed' => [BookingStatus::Confirmed, true],
    'completed' => [BookingStatus::Completed, false],
    'no_show' => [BookingStatus::NoShow, false],
    'cancelled' => [BookingStatus::Cancelled, false],
    'rejected' => [BookingStatus::Rejected, false],
    'expired' => [BookingStatus::Expired, false],
]);

it('still refuses an admin reschedule of a hold that is no longer live, because the hold is part of the status rule', function (BookingStatus $status) {
    expect(policyFor($status, POLICY_HOLD_LAPSED)->mayReschedule(Actor::Admin, policyNow())->refusal)->toBe(TransitionRefusal::HoldLapsed);
})->with([
    'awaiting_approval' => [BookingStatus::AwaitingApproval],
    'awaiting_payment' => [BookingStatus::AwaitingPayment],
]);

it('never lets the business move a pending checkout hold, live or lapsed, since that customer is still inside the booking flow, while the customer still moves a live one', function () {
    foreach ([POLICY_HOLD_LIVE, POLICY_HOLD_LAPSED] as $hold) {
        expect(policyFor(BookingStatus::Pending, $hold)->mayReschedule(Actor::Admin, policyNow())->refusal)->toBe(TransitionRefusal::NotReschedulable)
            ->and(CancellationPolicy::rescheduleRefusalByStatus(BookingStatus::Pending, $hold, Actor::Admin))->toBe(TransitionRefusal::NotReschedulable);
    }

    expect(policyFor(BookingStatus::Pending, POLICY_HOLD_LIVE)->mayReschedule(Actor::Customer, policyNow())->isAllowed())->toBeTrue();
});

it('answers the status half of a reschedule alone, for the admin\'s list of what may be moved now, as mayReschedule answers it', function (BookingStatus $status) {
    foreach ([POLICY_HOLD_LIVE, POLICY_HOLD_LAPSED] as $hold) {
        foreach ([Actor::Admin, Actor::Customer] as $actor) {
            expect(CancellationPolicy::rescheduleRefusalByStatus($status, $hold, $actor))->toBe(policyFor($status, $hold)->mayReschedule($actor, policyInstant('2026-06-01 00:00:00'))->refusal);
        }
    }
})->with(array_map(fn (BookingStatus $status): array => [$status], BookingStatus::cases()));

it('answers with the status rule before the window, so a refusal inside the window names the earlier reason', function () {
    $inside = policyInstant('2026-06-09 10:00:01');
    $lapsed = policyFor(BookingStatus::Pending, POLICY_HOLD_LAPSED);

    expect(policyFor(BookingStatus::Completed)->mayCancel(Actor::Customer, $inside)->refusal)->toBe(TransitionRefusal::NotCancellable)
        ->and($lapsed->mayCancel(Actor::Customer, $inside)->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($lapsed->mayReschedule(Actor::Customer, $inside)->refusal)->toBe(TransitionRefusal::HoldLapsed);
});

it('lets a zero-hour window run up to the start instant itself', function () {
    $policy = policyFor(BookingStatus::Confirmed, windows: [policyWindows(cancelHours: 0, rescheduleHours: 0)]);

    expect($policy->mayCancel(Actor::Customer, policyInstant('2026-06-10 10:00:00'))->isAllowed())->toBeTrue()
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-06-10 10:00:01'))->refusal)->toBe(TransitionRefusal::WindowClosed);
});

it('compares instants and not their spellings, so now in the tenant zone meets a start in UTC', function () {
    $policy = policyFor(BookingStatus::Confirmed);

    expect($policy->mayCancel(Actor::Customer, policyInstant('2026-06-09 13:00:00', 'Europe/Athens'))->isAllowed())->toBeTrue()
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-06-09 13:00:01', 'Europe/Athens'))->refusal)->toBe(TransitionRefusal::WindowClosed);
});

/*
 * The window is hours of elapsed time, never a wall-clock reading. Athens moves from +02:00 to
 * +03:00 at 03:00 on 2026-03-29 and back at 04:00 on 2026-10-25, so a 24-hour window that
 * straddles either change closes one wall-clock hour away from where "the same time yesterday"
 * would put it.
 */

it('measures the window as elapsed time across the spring clock change', function () {
    // 10:00+03:00 on the 29th is 07:00 UTC; 24 elapsed hours earlier is 07:00 UTC on the 28th,
    // which Athens spells 09:00+02:00. The same wall-clock time a day earlier is 10:00+02:00.
    $policy = policyFor(BookingStatus::Confirmed, firstStart: policyInstant('2026-03-29 10:00:00', 'Europe/Athens'));
    $limit = policyInstant('2026-03-28 09:00:00', 'Europe/Athens');

    expect($policy->firstStart->getTimestamp() - $limit->getTimestamp())->toBe(24 * 3600)
        ->and($policy->mayCancel(Actor::Customer, $limit)->isAllowed())->toBeTrue()
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-03-28 09:00:01', 'Europe/Athens'))->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-03-28 09:30:00', 'Europe/Athens'))->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($policy->mayReschedule(Actor::Customer, $limit)->isAllowed())->toBeTrue()
        ->and($policy->mayReschedule(Actor::Customer, policyInstant('2026-03-28 09:00:01', 'Europe/Athens'))->refusal)->toBe(TransitionRefusal::WindowClosed);
});

it('measures the window as elapsed time across the autumn clock change', function () {
    // 10:00+02:00 on the 25th is 08:00 UTC; 24 elapsed hours earlier is 08:00 UTC on the 24th,
    // which Athens spells 11:00+03:00. The same wall-clock time a day earlier is 10:00+03:00.
    $policy = policyFor(BookingStatus::Confirmed, firstStart: policyInstant('2026-10-25 10:00:00', 'Europe/Athens'));
    $limit = policyInstant('2026-10-24 11:00:00', 'Europe/Athens');

    expect($policy->firstStart->getTimestamp() - $limit->getTimestamp())->toBe(24 * 3600)
        ->and($policy->mayCancel(Actor::Customer, $limit)->isAllowed())->toBeTrue()
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-10-24 11:00:01', 'Europe/Athens'))->refusal)->toBe(TransitionRefusal::WindowClosed)
        ->and($policy->mayCancel(Actor::Customer, policyInstant('2026-10-24 10:30:00', 'Europe/Athens'))->isAllowed())->toBeTrue()
        ->and($policy->mayReschedule(Actor::Customer, $limit)->isAllowed())->toBeTrue()
        ->and($policy->mayReschedule(Actor::Customer, policyInstant('2026-10-24 11:00:01', 'Europe/Athens'))->refusal)->toBe(TransitionRefusal::WindowClosed);
});

it('refuses a booking with no segment windows, because a booking has at least one segment', function () {
    expect(fn () => new CancellationPolicy(BookingStatus::Confirmed, POLICY_HOLD_LAPSED, policyInstant('2026-06-10 10:00:00'), []))
        ->toThrow(InvalidArgumentException::class, 'at least one segment');
});

it('refuses a negative window', function () {
    expect(fn () => new ServiceWindows(cancelHours: -1, rescheduleHours: 24))
        ->toThrow(InvalidArgumentException::class, 'never negative')
        ->and(fn () => new ServiceWindows(cancelHours: 24, rescheduleHours: -1))
        ->toThrow(InvalidArgumentException::class, 'never negative');
});
