<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * What one live call with a connection's credentials said: it passed, or it failed with the
 * provider's reason, worded and holding no secret.
 *
 * A failed check whose reason is a sentence of ours also names its key, a key of the reasons
 * group of lang/en/admin/payments.php, and the values that fill its placeholders, so the
 * Payments section words it in the viewer's language; the English reason stays the line filled
 * with those values, for the log and for a reader that knows no key. A check naming no key,
 * another adapter's or a provider's own words alone, carries its reason alone.
 */
final readonly class ConnectionCheck
{
    /** The reasons group of payments.php, the one prefix of every reason key a check names. */
    public const string REASONS = 'admin/payments.reasons.';

    /**
     * @param  array<string, string>  $values  the key's placeholders by name, empty where none
     */
    private function __construct(
        public bool $ok,
        public ?string $reason,
        public ?string $key = null,
        public array $values = [],
    ) {}

    public static function passed(): self
    {
        return new self(true, null);
    }

    /**
     * A failed check; values given with no key are dropped.
     *
     * @param  array<string, string>  $values
     */
    public static function failed(string $reason, ?string $key = null, array $values = []): self
    {
        return new self(false, $reason, $key, $key === null ? [] : $values);
    }
}
