/*
 * Money at the presentation layer. The wire carries an integer of minor units beside a
 * three-letter currency, and the row is formatted in the currency it carries, never in the
 * tenant's current default. The minor-unit scale is derived from the currency: hundredths for
 * most, whole units for some, thousandths for others. A hardcoded divisor of a hundred renders
 * 5000 JPY as fifty yen, two orders of magnitude out, in the direction that undercharges.
 *
 * The glyphs follow the locale the caller hands in: the booking app's is the page's language,
 * and a caller that gives none gets the machine's.
 */

/**
 * The ISO 4217 currencies whose minor unit is not a hundredth: the same list the domain's
 * Currency class pins in app/Domain/Money/Currency.php, and a test reads that file and fails
 * when the two differ. Everything absent answers two.
 */
export const MINOR_DIGITS: Readonly<Record<string, number>> = {
    // Whole units.
    BIF: 0, CLP: 0, DJF: 0, GNF: 0, ISK: 0, JPY: 0, KMF: 0,
    KRW: 0, PYG: 0, RWF: 0, UGX: 0, UYI: 0, VND: 0, VUV: 0,
    XAF: 0, XOF: 0, XPF: 0,
    // Thousandths.
    BHD: 3, IQD: 3, JOD: 3, KWD: 3, LYD: 3, OMR: 3, TND: 3,
    // Ten-thousandths: units of account rather than cash.
    CLF: 4, UYW: 4,
};

const DEFAULT_DIGITS = 2;

export function minorDigitsOf(currency: string): number {
    return MINOR_DIGITS[currency] ?? DEFAULT_DIGITS;
}

/**
 * The amount as a plain decimal string with exactly the given digits after the point, or no
 * point for zero digits: 5000 at 0 digits is 5000, at 2 is 50.00, at 3 is 5.000. Built from
 * the digits of the integer rather than by division, so no amount picks up a binary fraction
 * on the way to the formatter.
 */
export function decimalOf(minor: number, digits: number): string {
    const sign = minor < 0 ? '-' : '';
    const magnitude = String(Math.abs(minor));

    if (digits === 0) {
        return sign + magnitude;
    }

    const padded = magnitude.padStart(digits + 1, '0');

    return `${sign}${padded.slice(0, -digits)}.${padded.slice(-digits)}`;
}

/**
 * The amount in the currency's own scale, in the machine's locale when no locale is given.
 * Where the formatter refuses the currency, the fallback is a fixed two-decimal rendering with
 * the code beside it: a fixed scale is defensible only there, where there are no resolved
 * digits to derive from.
 */
export function formatMoney(minor: number, currency: string, locale?: string): string {
    const digits = minorDigitsOf(currency);

    try {
        const formatter = new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        });

        return formatter.format(Number(decimalOf(minor, digits)));
    } catch {
        return `${decimalOf(minor, DEFAULT_DIGITS)} ${currency}`;
    }
}
