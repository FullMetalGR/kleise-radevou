<?php

declare(strict_types=1);

namespace App\Domain\Credential;

use SensitiveParameter;

/**
 * A secret a customer holds, the one shape of its two kinds: the manage token a booking's magic
 * link carries (ManageToken), and the token a My bookings link carries and the secret the session
 * it opens is named by (CustomerSecret). Thirty-two random bytes spelled in lower-case hex, so it
 * travels in a URL unchanged; a row holds its sha256 alone, in lower-case hex too, and a
 * presented value is found by that hash or compared against it in constant time. The shape, and
 * so every stored hash, is spelled here once.
 *
 * Each kind is a final type of its own over this one rather than a string or this class, so a
 * credential cannot be handed to a parameter that wanted an identifier, nor one kind to a
 * parameter that wanted the other.
 */
abstract readonly class Secret
{
    private const int BYTES = 32;

    private const string ALGORITHM = 'sha256';

    final protected function __construct(#[SensitiveParameter] public string $plain) {}

    public static function generate(): static
    {
        return new static(bin2hex(random_bytes(self::BYTES)));
    }

    /**
     * A value as a request presented it, which may be any string at all: nothing is asserted
     * about it here, because whether it names a row is what the lookup by its hash answers, or
     * the comparison against a stored hash, and a rejected shape would answer a different
     * question loudly.
     */
    public static function presented(#[SensitiveParameter] string $plain): static
    {
        return new static($plain);
    }

    public function hash(): string
    {
        return self::hashOf($this->plain);
    }

    public static function hashOf(#[SensitiveParameter] string $plain): string
    {
        return hash(self::ALGORITHM, $plain);
    }

    /**
     * Whether a presented plain value is the one a stored hash was made from, compared in
     * constant time so the comparison leaks nothing about how far it got.
     */
    public static function matches(#[SensitiveParameter] string $plain, string $hash): bool
    {
        return hash_equals($hash, self::hashOf($plain));
    }
}
