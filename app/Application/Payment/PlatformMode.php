<?php

declare(strict_types=1);

namespace App\Application\Payment;

use SensitiveParameter;

/**
 * The platform's own partner credentials for each provider, read from configuration under keys
 * of their own (config/payments.php, payments.platform), and whether platform mode is offered: a
 * provider's mode is offered only while its keys are all set and the platform's fee is
 * configured (PlatformFee, payments.platform_fee; a fee of zero is configured). The keys verify
 * the platform's webhook whatever the fee, so a connection already made keeps hearing from its
 * provider.
 *
 * The keys live in Credentials values, which dump no value and refuse serialization. They are
 * not billing's: config/billing.php is never read here.
 */
final readonly class PlatformMode
{
    /**
     * @param  array<string, Credentials>  $keys  by provider slug, every value of each set
     */
    public function __construct(
        private array $keys,
        private ?PlatformFee $fee,
    ) {}

    /**
     * @param  mixed  $configured  payments.platform: provider slug => field name => value
     * @param  mixed  $fee  payments.platform_fee, read by PlatformFee::of
     */
    public static function of(#[SensitiveParameter] mixed $configured, mixed $fee): self
    {
        $keys = [];
        foreach (is_array($configured) ? $configured : [] as $slug => $fields) {
            $set = [];
            $complete = is_array($fields) && $fields !== [];
            foreach (is_array($fields) ? $fields : [] as $name => $value) {
                $complete = $complete && is_string($name) && is_string($value) && $value !== '';
                $set[(string) $name] = is_string($value) ? $value : '';
            }
            if (is_string($slug) && $complete) {
                $keys[$slug] = new Credentials($set);
            }
        }

        return new self($keys, PlatformFee::of($fee));
    }

    /**
     * The platform's keys for one provider, or null where any of them is unset.
     */
    public function keys(string $slug): ?Credentials
    {
        return $this->keys[$slug] ?? null;
    }

    public function offered(string $slug): bool
    {
        return $this->fee !== null && isset($this->keys[$slug]);
    }

    /**
     * The fee as configured now, or null where none is. A payment object takes the fee in force
     * when it is created and keeps it.
     */
    public function fee(): ?PlatformFee
    {
        return $this->fee;
    }
}
