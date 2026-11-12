<?php

declare(strict_types=1);

namespace App\Application\Payment;

use LogicException;
use SensitiveParameter;

/**
 * A connection's credentials, decrypted, lent to the adapter of the connection's own provider
 * for one call and never kept.
 *
 * Only the store makes one, inside PaymentConnections::withCredentials, and hands it to the
 * closure it was given; nothing returns one. It refuses to be serialized, so it cannot ride a
 * queue payload or a cache entry, and a dump of it shows the field names and no value.
 */
final readonly class Credentials
{
    /**
     * @param  array<string, string>  $fields  the fields the registry declared for the provider, by name
     */
    public function __construct(#[SensitiveParameter] private array $fields) {}

    /**
     * One field's value, or the empty string for a field the connection does not hold, which no
     * signature verifies against.
     */
    public function field(string $name): string
    {
        return $this->fields[$name] ?? '';
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return array_fill_keys(array_keys($this->fields), '********');
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Credentials are lent for one call and are never serialized.');
    }

    /**
     * @param  array<mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Credentials are lent for one call and are never serialized.');
    }
}
