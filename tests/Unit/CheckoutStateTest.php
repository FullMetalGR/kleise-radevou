<?php

use App\Application\Payment\CheckoutState;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\PaymentMode;

/*
 * CheckoutState::confirmedBy: whether a payment notice the paid transition did not confirm
 * is read as another notice of the payment that did. Paid or not, the confirming payment
 * named or not, crossed with a notice naming nothing, the recorded payment or another.
 */

function checkoutStateOf(bool $paid, bool $named, ?string $ref = 'pd_1'): CheckoutState
{
    return new CheckoutState(BookingStatus::Confirmed, PaymentMode::Online, $ref, false, null, $paid, $named);
}

it('reads a notice as the confirming payment\'s only where a payment landed and nothing tells the two apart', function (CheckoutState $state, ?string $notice, bool $expected) {
    expect($state->confirmedBy($notice))->toBe($expected);
})->with([
    'no payment landed, no payment named' => [fn () => checkoutStateOf(false, false), null, false],
    'no payment landed, the recorded reference' => [fn () => checkoutStateOf(false, false), 'pd_1', false],
    'no payment landed, another payment' => [fn () => checkoutStateOf(false, false), 'pd_2', false],
    'paid and named, no payment named' => [fn () => checkoutStateOf(true, true), null, true],
    'paid and named, the same payment' => [fn () => checkoutStateOf(true, true), 'pd_1', true],
    'paid and named, another payment' => [fn () => checkoutStateOf(true, true), 'pd_2', false],
    'paid on a notice naming none, a session recorded, another payment' => [fn () => checkoutStateOf(true, false), 'pd_2', true],
    'paid on a notice naming none, no session recorded, a payment named' => [fn () => checkoutStateOf(true, false, null), 'pd_2', true],
]);
