<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Money\Money;

/**
 * The platform's fee on a payment taken through a platform connection: the second revenue
 * stream, read from configuration (payments.platform_fee) and never from a tenant row, so it is
 * a pricing decision rather than a negotiation. It is not an entitlement and gates nothing.
 *
 * Two parts, either or both. The percentage is a decimal string of at most two places from 0 to
 * 100, "1.5" being one and a half percent of the booking's total, held as basis points. The
 * fixed part is a list of "CODE:minor" pairs, "EUR:25,JPY:30", each an amount in that
 * currency's own minor units, since a fixed amount means nothing apart from its currency; a
 * currency it does not name takes no fixed part. Nothing set, or anything it cannot read, is no
 * fee at all, and platform mode is then not offered. A fee of zero is a fee: the mode is offered
 * and takes nothing.
 *
 * The fee on a total is the percentage rounded half up to the total's own minor unit, plus the
 * fixed part in the total's currency, and never more than the total. It comes out of the
 * tenant's side: the customer is asked for the total and nothing more.
 */
final readonly class PlatformFee
{
    /**
     * @param  array<string, int>  $fixed  minor units by three-letter currency code
     */
    private function __construct(
        private ?int $basisPoints,
        private array $fixed,
    ) {}

    /**
     * @param  mixed  $configured  payments.platform_fee: ['percent' => ?string, 'fixed' => ?string]
     */
    public static function of(mixed $configured): ?self
    {
        if (! is_array($configured)) {
            return null;
        }

        $points = self::points($configured['percent'] ?? null);
        $fixed = self::amounts($configured['fixed'] ?? null);

        return $points === false || $fixed === false || ($points === null && $fixed === []) ? null : new self($points, $fixed);
    }

    /**
     * The fee on a booking's total, in the total's own currency and minor units.
     */
    public function on(Money $total): Money
    {
        $share = $this->basisPoints === null ? 0 : intdiv($total->minor * $this->basisPoints + 5000, 10000);

        return new Money(max(0, min($total->minor, $share + ($this->fixed[$total->currency->code] ?? 0))), $total->currency);
    }

    /**
     * The percentage as a decimal string with no trailing zeros, "1.5", or null where none is set.
     */
    public function percent(): ?string
    {
        if ($this->basisPoints === null) {
            return null;
        }

        $fraction = rtrim(sprintf('%02d', $this->basisPoints % 100), '0');

        return intdiv($this->basisPoints, 100).($fraction === '' ? '' : '.'.$fraction);
    }

    /**
     * The fixed part in one currency's minor units, or null where it names none for it.
     */
    public function fixedIn(string $currency): ?int
    {
        return $this->fixed[$currency] ?? null;
    }

    /**
     * @return int|false|null basis points, false for a value that cannot be read
     */
    private static function points(mixed $percent): int|false|null
    {
        if ($percent === null || $percent === '') {
            return null;
        }

        if ((! is_string($percent) && ! is_int($percent)) || preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', (string) $percent, $parts) !== 1) {
            return false;
        }

        $points = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

        return $points > 10000 ? false : $points;
    }

    /**
     * @return array<string, int>|false minor units by currency, false for a value that cannot be read
     */
    private static function amounts(mixed $fixed): array|false
    {
        if ($fixed === null || $fixed === '') {
            return [];
        }

        if (! is_string($fixed)) {
            return false;
        }

        $amounts = [];
        foreach (explode(',', $fixed) as $pair) {
            if (preg_match('/^\s*([A-Z]{3})\s*:\s*(\d{1,9})\s*$/', $pair, $parts) !== 1 || isset($amounts[$parts[1]])) {
                return false;
            }
            $amounts[$parts[1]] = (int) $parts[2];
        }

        return $amounts;
    }
}
