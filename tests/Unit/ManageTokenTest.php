<?php

use App\Domain\Booking\ManageToken;

it('generates thirty-two random bytes spelled in hex, different every time', function () {
    $first = ManageToken::generate();
    $second = ManageToken::generate();

    expect($first->plain)->toMatch('/^[0-9a-f]{64}$/')
        ->and($second->plain)->toMatch('/^[0-9a-f]{64}$/')
        ->and($first->plain)->not->toBe($second->plain);
});

it('hashes the plain value with sha256, so the row never holds the secret', function () {
    $token = ManageToken::generate();

    expect($token->hash())->toBe(hash('sha256', $token->plain))
        ->and($token->hash())->toBe(ManageToken::hashOf($token->plain))
        ->and($token->hash())->toMatch('/^[0-9a-f]{64}$/')
        ->and($token->hash())->not->toBe($token->plain);
});

it('matches a presented plain value against a stored hash, and nothing else', function () {
    $token = ManageToken::generate();

    expect(ManageToken::matches($token->plain, $token->hash()))->toBeTrue()
        ->and(ManageToken::matches($token->hash(), $token->hash()))->toBeFalse()
        ->and(ManageToken::matches($token->plain, ManageToken::generate()->hash()))->toBeFalse()
        ->and(ManageToken::matches('', $token->hash()))->toBeFalse();
});
