<?php

declare(strict_types=1);

namespace App\Domain\Money;

/**
 * An integer amount of minor units beside its currency. Built from minor units and nothing
 * else: the amount is an int by type, so a float or a decimal string never reaches it, and a
 * total is the plain sum of its parts with no rounding step. Every operation across two
 * currencies raises CurrencyMismatch; nothing converts.
 *
 * The decimal rendering here is the plain one a test, an audit row or a mailable needs: exactly
 * the currency's digits, a dot, a leading minus, no separators and no symbol. Locale formatting
 * is the presentation layer's job and is not here.
 */
final readonly class Money
{
    public function __construct(
        public int $minor,
        public Currency $currency,
    ) {}

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * The sum of the amounts in the currency asked for, starting from zero of it, so an empty
     * list is zero and a stray currency is refused against the one every part must carry.
     *
     * @param  iterable<self>  $amounts
     */
    public static function sum(Currency $currency, iterable $amounts): self
    {
        $total = self::zero($currency);

        foreach ($amounts as $amount) {
            $total = $total->add($amount);
        }

        return $total;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    /**
     * Minus one, zero or one, as the spaceship operator answers for the minor amounts.
     */
    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    public function equals(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor === $other->minor;
    }

    /**
     * The amount as a plain decimal string with exactly the currency's digits after the point,
     * or no point for a currency with none: 5000 JPY as 5000, 5000 EUR as 50.00, 5000 KWD as
     * 5.000, and a negative amount with a leading minus. Built from the digits of the integer
     * rather than by division, so the smallest int renders as exactly as any other.
     */
    public function decimal(): string
    {
        $digits = $this->currency->digits();
        $sign = $this->minor < 0 ? '-' : '';
        $magnitude = ltrim((string) $this->minor, '-');

        if ($digits === 0) {
            return $sign.$magnitude;
        }

        $magnitude = str_pad($magnitude, $digits + 1, '0', STR_PAD_LEFT);

        return $sign.substr($magnitude, 0, -$digits).'.'.substr($magnitude, -$digits);
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
