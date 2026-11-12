<?php

use App\Domain\Booking\TransitionRefusal;

/*
 * The one vocabulary a refusal has. Each case is a code a customer's client can branch on and
 * each value reaches the wire verbatim, so the list is pinned here: a case renamed or
 * respelled is a contract change and shows up as a failure rather than as a client that
 * quietly stops recognising an answer.
 */

it('spells every refusal as one snake-case code, with no two cases saying the same thing', function () {
    $codes = array_map(fn (TransitionRefusal $reason): string => $reason->value, TransitionRefusal::cases());

    expect($codes)->toBe([
        'hold_expired',
        'not_confirmable',
        'payment_required',
        'not_cancellable',
        'not_reschedulable',
        'hold_lapsed',
        'not_releasable',
        'window_closed',
        'not_approvable',
        'not_rejectable',
        'not_confirmed',
        'too_early',
    ])
        ->and($codes)->toBe(array_values(array_unique($codes)))
        ->and(array_filter($codes, fn (string $code): bool => preg_match('/^[a-z][a-z_]*[a-z]$/D', $code) !== 1))->toBe([]);
});
