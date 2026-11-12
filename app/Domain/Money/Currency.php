<?php

declare(strict_types=1);

namespace App\Domain\Money;

/**
 * A three-letter currency code and the number of minor-unit digits it carries. The shape is
 * the only thing checked: a code the table does not know is accepted and answers two digits,
 * because the domain cannot judge a tenant's currency and the presentation layer already falls
 * back for a code it cannot format.
 */
final readonly class Currency
{
    /**
     * The ISO 4217 currencies whose minor unit is not a hundredth, the one place the scale is
     * held. Everything absent answers two. A hardcoded divisor of a hundred renders 5000 JPY as
     * fifty yen, which is why the digits come from here and never from a constant at the edge.
     * The Unit suite pins every entry, and the booking app's own scale table is to be held to
     * the same list when it lands.
     */
    private const array MINOR_DIGITS = [
        // Whole units.
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0,
        'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0,
        'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        // Thousandths.
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        // Ten-thousandths: units of account rather than cash, listed so they render as the
        // standard says and not two orders of magnitude out.
        'CLF' => 4, 'UYW' => 4,
    ];

    private const int DEFAULT_DIGITS = 2;

    private function __construct(public string $code) {}

    /**
     * Exactly three uppercase ASCII letters, as given. Nothing is folded: a lowercase or
     * lookalike code is refused rather than mapped onto one the tenant did not write.
     *
     * @throws InvalidCurrency
     */
    public static function of(string $code): self
    {
        if (preg_match('/\A[A-Z]{3}\z/', $code) !== 1) {
            throw InvalidCurrency::code($code);
        }

        return new self($code);
    }

    /**
     * The minor-unit exponent: how many digits of a minor amount sit right of the point.
     */
    public function digits(): int
    {
        return self::MINOR_DIGITS[$this->code] ?? self::DEFAULT_DIGITS;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
