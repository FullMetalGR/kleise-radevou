<?php

use App\Domain\Booking\ManageToken;
use App\Domain\Credential\Secret;
use App\Domain\Customer\CustomerSecret;

/*
 * The secret of a My bookings link and of the session it opens: thirty-two random bytes in hex,
 * stored as their sha256 and found by it, the one shape of Secret it shares with the manage
 * token.
 */

it('generates thirty-two random bytes spelled in lower-case hex, a fresh one each time', function () {
    $one = CustomerSecret::generate();
    $two = CustomerSecret::generate();

    expect($one->plain)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($two->plain)->not->toBe($one->plain);
});

it('hashes to the sha256 of the plain value, the one spelling the rows keep, and a presented value to the same', function () {
    $secret = CustomerSecret::generate();

    expect($secret->hash())->toBe(hash('sha256', $secret->plain))
        ->and($secret->hash())->toMatch('/\A[0-9a-f]{64}\z/')
        ->and(CustomerSecret::presented($secret->plain)->hash())->toBe($secret->hash())
        ->and(CustomerSecret::presented('anything at all')->hash())->toBe(hash('sha256', 'anything at all'));
});

it('is one Secret with the manage token: the same thirty-two bytes, the same sha256 of a plain value, the same constant-time match, and neither standing for the other', function () {
    $plain = CustomerSecret::generate()->plain;

    expect(get_parent_class(CustomerSecret::class))->toBe(Secret::class)
        ->and(get_parent_class(ManageToken::class))->toBe(Secret::class)
        ->and(ManageToken::generate()->plain)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and(CustomerSecret::presented($plain)->hash())->toBe(ManageToken::presented($plain)->hash())
        ->and(ManageToken::hashOf($plain))->toBe(hash('sha256', $plain))
        ->and(CustomerSecret::matches($plain, hash('sha256', $plain)))->toBeTrue()
        ->and(CustomerSecret::presented($plain))->not->toBeInstanceOf(ManageToken::class)
        ->and(ManageToken::presented($plain))->not->toBeInstanceOf(CustomerSecret::class)
        ->and((new ReflectionClass(Secret::class))->isAbstract())->toBeTrue();
});
