import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { hostLanguageOf, LANGUAGE_QUERY, languageOfTag, localeOf, otherOf, parseLanguage, pathUnder, prefixOf, switchHrefOf, withLanguage, type Language } from './language';
import { formatDay, formatDayFace, formatTime, wallClockOf } from './time';

/*
 * The booking app's two languages: a code off the wire, the host page's language tag read for
 * the embed's start, a region subtag ignored, and the lang every request carries beside whatever
 * query it has. Then the locale the formatters take for a language on a machine, proven on an
 * en-US and an en-GB machine to change nothing for an English page and only the names for a
 * Greek one; a page path under a language's prefix; and the hosted page's switch, the same path
 * under the other language's prefix, held to TenantPages::addresses over the fixture the
 * server's test reads too (tests/Support/language-switch.json,
 * tests/Feature/Language/BundleMirrorsTest.php).
 */

describe('parseLanguage', () => {
    it('reads the two codes and nothing else', () => {
        expect(parseLanguage('en')).toBe('en');
        expect(parseLanguage('el')).toBe('el');
        expect(parseLanguage('EL')).toBeNull();
        expect(parseLanguage('fr')).toBeNull();
        expect(parseLanguage('el-GR')).toBeNull();
        expect(parseLanguage('')).toBeNull();
        expect(parseLanguage(null)).toBeNull();
        expect(parseLanguage(7)).toBeNull();
    });
});

describe('languageOfTag', () => {
    it.each<[string | null, string | null]>([
        ['en', 'en'],
        ['el', 'el'],
        ['el-GR', 'el'],
        ['en-US', 'en'],
        ['EL', 'el'],
        ['  el  ', 'el'],
        ['el-Grek-GR', 'el'],
        ['fr', null],
        ['fr-GR', null],
        ['english', null],
        ['', null],
        [null, null],
    ])('reads %j as %j', (tag, language) => {
        expect(languageOfTag(tag)).toBe(language);
    });
});

describe('hostLanguageOf', () => {
    it('reads the root element\'s lang, and none where it names neither language or is absent', () => {
        const doc = document.implementation.createHTMLDocument('host');

        expect(hostLanguageOf(doc)).toBeNull();

        doc.documentElement.setAttribute('lang', 'el-GR');
        expect(hostLanguageOf(doc)).toBe('el');

        doc.documentElement.setAttribute('lang', 'de');
        expect(hostLanguageOf(doc)).toBeNull();
    });
});

describe('withLanguage', () => {
    it('appends the language as lang, beside a query the path carries, and leaves a path as it is for none', () => {
        expect(LANGUAGE_QUERY).toBe('lang');
        expect(withLanguage('/api/v1/services', 'el')).toBe('/api/v1/services?lang=el');
        expect(withLanguage('/api/v1/bookings/x?token=abc', 'en')).toBe('/api/v1/bookings/x?token=abc&lang=en');
        expect(withLanguage('/api/v1/services', undefined)).toBe('/api/v1/services');
        expect(withLanguage('/api/v1/services', null)).toBe('/api/v1/services');
    });
});

describe('otherOf', () => {
    it('answers the other language', () => {
        expect(otherOf('en')).toBe('el');
        expect(otherOf('el')).toBe('en');
    });
});

describe('localeOf', () => {
    const WALL = wallClockOf('2026-01-05T07:05:00Z', 'UTC');
    const AFTERNOON = wallClockOf('2026-01-05T14:05:00Z', 'UTC');

    /** What a formatter given no locale renders on a machine of this locale: the old calls. */
    function machineOf(locale: string): { readonly time: string; readonly afternoon: string; readonly day: string; readonly weekday: string } {
        return { time: formatTime(WALL, locale), afternoon: formatTime(AFTERNOON, locale), day: formatDay('2026-01-05', locale), weekday: formatDayFace('2026-01-05', locale).weekday };
    }

    it.each(['en-US', 'en-GB'])('keeps an English page on an %s machine exactly as the machine renders it', (machine) => {
        const locale = localeOf('en', machine);

        expect(locale).toBe(machine);
        expect(machineOf(locale)).toEqual(machineOf(machine));
    });

    it.each<[string, string]>([
        ['en-US', 'h12'],
        ['en-GB', 'h23'],
    ])('gives a Greek page on an %s machine the Greek names and the machine\'s %s hour cycle', (machine, cycle) => {
        const locale = localeOf('el', machine);
        const greek = machineOf(locale);

        expect(new Intl.DateTimeFormat(locale, { hour: 'numeric' }).resolvedOptions().hourCycle).toBe(cycle);
        expect(new Intl.DateTimeFormat(machine, { hour: 'numeric' }).resolvedOptions().hourCycle).toBe(cycle);
        expect(greek.weekday).toBe(formatDayFace('2026-01-05', 'el').weekday);
        expect(greek.weekday).not.toBe(machineOf(machine).weekday);
        expect(greek.day).not.toBe(machineOf(machine).day);
    });

    it('takes the machine\'s region where it has one, and the bare language where it has none', () => {
        expect(new Intl.Locale(localeOf('el', 'en-GB')).region).toBe('GB');
        expect(new Intl.Locale(localeOf('el', 'en')).region).toBeUndefined();
        expect(localeOf('en', 'en')).toBe('en');
        expect(new Intl.Locale(localeOf('en', 'el-GR')).language).toBe('en');
    });

    it('reads this machine\'s locale when none is given', () => {
        const machine = new Intl.DateTimeFormat().resolvedOptions().locale;
        const language = languageOfTag(machine);

        if (language !== null) {
            expect(localeOf(language)).toBe(machine);
        }

        expect(localeOf('el')).toBe(localeOf('el', machine));
    });
});

describe('pathUnder', () => {
    it('puts a page path under a language\'s prefix, the root as the bare prefix', () => {
        expect(pathUnder('/my-bookings', 'el')).toBe('/el/my-bookings');
        expect(pathUnder('/my-bookings', 'en')).toBe('/en/my-bookings');
        expect(pathUnder('/', 'el')).toBe('/el');
    });

    it('leaves a path as it is for no prefix, the root included', () => {
        expect(pathUnder('/my-bookings/link', null)).toBe('/my-bookings/link');
        expect(pathUnder('/', null)).toBe('/');
    });
});

describe('prefixOf', () => {
    it('reads the language a page path names by its first segment, as TenantPages::prefixOf does', () => {
        expect(prefixOf('/el/my-bookings')).toBe('el');
        expect(prefixOf('/en/my-bookings/link')).toBe('en');
        expect(prefixOf('/el')).toBe('el');
        expect(prefixOf('/el/')).toBe('el');
    });

    it('answers null for a bare path, the root, and a first segment naming no language', () => {
        expect(prefixOf('/my-bookings')).toBeNull();
        expect(prefixOf('/')).toBeNull();
        expect(prefixOf('')).toBeNull();
        expect(prefixOf('/EL/my-bookings')).toBeNull();
        expect(prefixOf('/eleven')).toBeNull();
        expect(prefixOf('/manage/el')).toBeNull();
    });
});

interface SwitchCase {
    readonly path: string;
    readonly default: Language;
    readonly language: Language;
    readonly switch: string;
}

describe('switchHrefOf', () => {
    const cases = JSON.parse(readFileSync(resolve(process.cwd(), 'tests/Support/language-switch.json'), 'utf8')) as readonly SwitchCase[];

    it('reads a fixture the server\'s test reads too', () => {
        expect(cases.length).toBeGreaterThan(10);
    });

    it.each(cases.map((one) => [one.path, one.language, one.switch] as const))('leads %s, in %s, to %s, as TenantPages::addresses does', (path, language, target) => {
        const url = new URL(path, 'https://luna.kleiseradevou.gr');

        expect(switchHrefOf(url.pathname, url.search, language)).toBe(target);
    });
});
