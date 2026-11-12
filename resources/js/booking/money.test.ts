import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { decimalOf, formatMoney, MINOR_DIGITS, minorDigitsOf } from './money';

/*
 * The money rules: the scale comes from the currency, the fallback is a fixed two decimals
 * with the code beside it, and the scale table here is held to the one the domain's Currency
 * class pins, read off the PHP source, so the two lists cannot drift apart.
 */

const CURRENCY_PHP = resolve(process.cwd(), 'app/Domain/Money/Currency.php');

/**
 * The MINOR_DIGITS entries of the PHP class, as written: every 'XXX' => n pair inside the
 * constant's array.
 */
function phpMinorDigits(): Record<string, number> {
    const source = readFileSync(CURRENCY_PHP, 'utf8');
    const start = source.indexOf('MINOR_DIGITS = [');
    const end = source.indexOf('];', start);

    expect(start, 'Currency.php no longer declares MINOR_DIGITS as an array').toBeGreaterThan(-1);
    expect(end).toBeGreaterThan(start);

    const table: Record<string, number> = {};

    for (const match of source.slice(start, end).matchAll(/'([A-Z]{3})'\s*=>\s*(\d+)/g)) {
        table[match[1] as string] = Number(match[2]);
    }

    return table;
}

describe('the scale table', () => {
    it('is the same list the PHP Currency class pins, entry for entry', () => {
        const php = phpMinorDigits();

        expect(Object.keys(php).length).toBeGreaterThan(0);
        expect({ ...MINOR_DIGITS }).toEqual(php);
    });

    it('holds only non-two entries, so a two would hide a drift', () => {
        expect(Object.values(MINOR_DIGITS).every((digits) => digits !== 2)).toBe(true);
    });

    it('answers two for everything absent', () => {
        expect(minorDigitsOf('EUR')).toBe(2);
        expect(minorDigitsOf('XYZ')).toBe(2);
        expect(minorDigitsOf('JPY')).toBe(0);
        expect(minorDigitsOf('KWD')).toBe(3);
        expect(minorDigitsOf('CLF')).toBe(4);
    });
});

describe('decimalOf', () => {
    it.each([
        [5000, 0, '5000'],
        [5000, 2, '50.00'],
        [5000, 3, '5.000'],
        [5, 2, '0.05'],
        [0, 2, '0.00'],
        [0, 0, '0'],
        [-5, 2, '-0.05'],
        [-5000, 0, '-5000'],
        [123456789, 4, '12345.6789'],
    ])('renders %d minor at %d digits as %s', (minor, digits, expected) => {
        expect(decimalOf(minor, digits)).toBe(expected);
    });
});

describe('formatMoney', () => {
    it('reads 5000 JPY as five thousand yen and 5000 EUR as fifty euro', () => {
        expect(formatMoney(5000, 'JPY', 'en')).toBe('\u00a55,000');
        expect(formatMoney(5000, 'EUR', 'en')).toBe('\u20ac50.00');
    });

    it('scales a thousandths currency to three places', () => {
        expect(formatMoney(5000, 'KWD', 'en')).toBe('KWD\u00a05.000');
    });

    it('follows the locale given for the glyphs, not for the scale', () => {
        expect(formatMoney(5000, 'EUR', 'de')).toBe('50,00\u00a0\u20ac');
        expect(formatMoney(5000, 'JPY', 'de')).toBe('5.000\u00a0\u00a5');
    });

    it('formats in the machine locale when given none', () => {
        expect(formatMoney(3500, 'EUR')).toBe(new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(35));
    });

    it('falls back to two decimals with the code beside it where the formatter refuses', () => {
        expect(formatMoney(5000, 'EU', 'en')).toBe('50.00 EU');
        expect(formatMoney(5000, '', 'en')).toBe('50.00 ');
        expect(formatMoney(-150, 'not a code', 'en')).toBe('-1.50 not a code');
    });

    it('formats an unknown but well-formed code at two decimals', () => {
        expect(formatMoney(5000, 'XYZ', 'en')).toBe('XYZ\u00a050.00');
    });
});
