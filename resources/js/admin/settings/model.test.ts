import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { ADMIN_LINES_EN } from '../lines.generated';
import { stringsOf } from '@/lib/strings';
import { keyedSettings } from '@/testing/keyed';
import type { SettingsStrings } from '@/types';
import { APPROVAL_SENTENCE, CHECKOUT_SENTENCE, CURRENCY_SENTENCE, firstProblem, hoursBranch, isOn, PAYMENT_SENTENCE, type ProfileField, problemsOf, profileRecord, profileSentence, REMINDER_SENTENCE, saveable, seedForm, type SettingsForm, startIntervalOptions, startIntervalSentence, switched, upperCurrency, type Values, wholePatchOf } from './model';
import type { SettingsKey } from './words';

/*
 * The screen's model: the form seeded and sent whole, the start intervals on the granularity, the
 * checks each refusing in its sentence, a key of the settings section's validation group read
 * through the keyed table, and the refusals shared with the server: the cases of
 * tests/Support/settings-refusals.json, which SettingsScreenTest posts to the server, read alike
 * here from the real English, so the two cannot part.
 */

const words = keyedSettings();

/**
 * The real English of the settings section, as the export writes it, for the cases shared with the
 * server.
 */
const english = stringsOf<SettingsStrings>(ADMIN_LINES_EN.settings, 'en');

type RefusalCase = { field: keyof Omit<SettingsForm, 'emails_off' | 'theme' | 'language'>; typed: string; times: number; key: SettingsKey; values: Record<string, string> };

/** A row with no profile written: every field null but the country, whose column defaults to GR. */
const NO_PROFILE = { ...profileRecord(() => null), country: 'GR' };

/** The profile as a save of an untouched form sends it: every field empty but the country. */
const BLANK_PROFILE = { ...profileRecord(() => ''), country: 'GR' };

const VALUES: Values = { currency: 'EUR', theme: 'violet', language: 'en', checkout_ttl_min: 15, approval_ttl_hours: 48, payment_ttl_hours: 24, reminder_lead_hours: 24, start_interval_min: 15, emails_off: ['mystery_key'], ...NO_PROFILE };

/** The shared props' granularity, the grid the start intervals are filtered to. */
const GRANULARITY = 5;

describe('the form', () => {
    it('seeds every field from the values as text, and carries no accent', () => {
        expect(seedForm(VALUES)).toEqual({ currency: 'EUR', theme: 'violet', language: 'en', checkout_ttl_min: '15', approval_ttl_hours: '48', payment_ttl_hours: '24', reminder_lead_hours: '24', start_interval_min: '15', emails_off: ['mystery_key'], ...BLANK_PROFILE });
        expect(seedForm(VALUES)).not.toHaveProperty('brand_accent');
    });

    it('uppercases the currency on every keystroke', () => {
        expect(upperCurrency('us')).toBe('US');
        expect(upperCurrency('usd')).toBe('USD');
    });

    it('sends the whole patch as numbers, never a null, and no accent', () => {
        const patch = wholePatchOf({ ...seedForm(VALUES), reminder_lead_hours: '0' });

        expect(patch).toEqual({ currency: 'EUR', theme: 'violet', language: 'en', checkout_ttl_min: 15, approval_ttl_hours: 48, payment_ttl_hours: 24, reminder_lead_hours: 0, start_interval_min: 15, emails_off: ['mystery_key'], ...BLANK_PROFILE });
        expect(Object.values(patch)).not.toContain(null);
        expect(patch).not.toHaveProperty('brand_accent');
        expect(wholePatchOf({ ...seedForm(VALUES), start_interval_min: '30' })).toEqual({ ...patch, reminder_lead_hours: 24, start_interval_min: 30 });
    });

    it('offers the start intervals on the granularity alone, labelled in minutes', () => {
        expect(startIntervalOptions(5, words)).toEqual(['5', '10', '15', '20', '30', '60'].map((n) => ({ value: n, label: words.text('booking_rules.start_option', { n }) })));
        expect(startIntervalOptions(10, words).map((option) => option.value)).toEqual(['10', '20', '30', '60']);
        expect(startIntervalOptions(15, words).map((option) => option.value)).toEqual(['15', '30', '60']);
    });
});

describe('the validity rules', () => {
    it('hold every rule on the seeded form', () => {
        expect(problemsOf(seedForm(VALUES), GRANULARITY, words)).toEqual({});
        expect(saveable(seedForm(VALUES), GRANULARITY)).toBe(true);
    });

    it('refuse a currency of two letters, four letters or lowercase', () => {
        for (const currency of ['EU', 'EURO', 'eur', '']) {
            expect(problemsOf({ ...seedForm(VALUES), currency }, GRANULARITY, words).currency).toBe(words.text(CURRENCY_SENTENCE));
        }
    });

    it('refuse zero, a negative, a fraction and words in each hold with that hold\'s sentence', () => {
        for (const typed of ['0', '-1', '1.5', 'ten', '']) {
            expect(problemsOf({ ...seedForm(VALUES), checkout_ttl_min: typed }, GRANULARITY, words).checkout_ttl_min).toBe(words.text(CHECKOUT_SENTENCE));
            expect(problemsOf({ ...seedForm(VALUES), approval_ttl_hours: typed }, GRANULARITY, words).approval_ttl_hours).toBe(words.text(APPROVAL_SENTENCE));
            expect(problemsOf({ ...seedForm(VALUES), payment_ttl_hours: typed }, GRANULARITY, words).payment_ttl_hours).toBe(words.text(PAYMENT_SENTENCE));
        }
    });

    it('admit zero in the reminder lead and refuse a negative with its own sentence, not the holds\'', () => {
        expect(problemsOf({ ...seedForm(VALUES), reminder_lead_hours: '0' }, GRANULARITY, words).reminder_lead_hours).toBeUndefined();
        expect(problemsOf({ ...seedForm(VALUES), reminder_lead_hours: '-1' }, GRANULARITY, words).reminder_lead_hours).toBe(words.text(REMINDER_SENTENCE));
        expect(REMINDER_SENTENCE).not.toBe(words.text(CHECKOUT_SENTENCE));
        expect(firstProblem({ ...seedForm(VALUES), reminder_lead_hours: '-1' }, GRANULARITY, words)).toBe(words.text(REMINDER_SENTENCE));
        expect(firstProblem({ ...seedForm(VALUES), currency: 'E', reminder_lead_hours: '-1' }, GRANULARITY, words)).toBe(words.text(CURRENCY_SENTENCE));
    });

    it('admit each start interval on the granularity and refuse any other with the sentence naming the granularity', () => {
        for (const start_interval_min of ['5', '10', '15', '20', '30', '60']) {
            expect(problemsOf({ ...seedForm(VALUES), start_interval_min }, GRANULARITY, words)).toEqual({});
        }
        for (const start_interval_min of ['7', '0', '45', '-15', '', 'fifteen']) {
            expect(problemsOf({ ...seedForm(VALUES), start_interval_min }, GRANULARITY, words).start_interval_min).toBe(startIntervalSentence(5, words));
            expect(saveable({ ...seedForm(VALUES), start_interval_min }, GRANULARITY)).toBe(false);
        }
        expect(startIntervalSentence(5, words)).toBe(words.text('validation.start_interval', { intervals: words.text('validation.list.last', { items: ['5', '10', '15', '20', '30'].join(words.text('validation.list.separator')), last: '60' }), n: '5' }));

        for (const start_interval_min of ['5', '15']) {
            expect(problemsOf({ ...seedForm(VALUES), start_interval_min }, 10, words).start_interval_min).toBe(startIntervalSentence(10, words));
        }
        expect(problemsOf({ ...seedForm(VALUES), start_interval_min: '20' }, 10, words)).toEqual({});
        expect(firstProblem({ ...seedForm(VALUES), start_interval_min: '7' }, GRANULARITY, words)).toBe(startIntervalSentence(5, words));
        expect(firstProblem({ ...seedForm(VALUES), reminder_lead_hours: '-1', start_interval_min: '7' }, GRANULARITY, words)).toBe(words.text(REMINDER_SENTENCE));
    });
});

describe('the profile\'s checks', () => {
    it('refuse each field past its limit with its sentence filled with that limit, and admit it at the limit', () => {
        const limits: [ProfileField, number, SettingsKey][] = [
            ['about', 2000, 'validation.about'],
            ['address_line1', 200, 'validation.address_line'],
            ['address_line2', 200, 'validation.address_line'],
            ['city', 100, 'validation.city'],
            ['postal_code', 20, 'validation.postal_code'],
            ['phone', 40, 'validation.phone'],
            ['important_info', 1000, 'validation.important_info'],
        ];

        for (const [field, max, key] of limits) {
            expect(problemsOf({ ...seedForm(VALUES), [field]: 'a'.repeat(max) }, GRANULARITY, words)[field]).toBeUndefined();
            expect(problemsOf({ ...seedForm(VALUES), [field]: 'a'.repeat(max + 1) }, GRANULARITY, words)[field]).toBe(words.text(key, { max: String(max) }));
            expect(profileSentence(field, words)).toBe(words.text(key, { max: String(max) }));
        }
    });

    it('refuse each link off https with its own whole sentence, the three no longer spliced from a name', () => {
        const links: [ProfileField, SettingsKey][] = [
            ['website', 'validation.website'],
            ['instagram', 'validation.instagram'],
            ['facebook', 'validation.facebook'],
            ['google_reviews_url', 'validation.google_reviews'],
        ];

        for (const [field, key] of links) {
            expect(problemsOf({ ...seedForm(VALUES), [field]: 'http://luna.example' }, GRANULARITY, words)[field]).toBe(words.text(key));
            expect(problemsOf({ ...seedForm(VALUES), [field]: 'https://luna.example' }, GRANULARITY, words)[field]).toBeUndefined();
        }
        expect(problemsOf({ ...seedForm(VALUES), country: 'gr' }, GRANULARITY, words).country).toBe(words.text('validation.country'));
        expect(problemsOf({ ...seedForm(VALUES), public_email: 'hello at luna' }, GRANULARITY, words).public_email).toBe(words.text('validation.public_email'));
    });
});

describe('the refusals shared with the server', () => {
    const cases = JSON.parse(readFileSync(resolve(process.cwd(), 'tests/Support/settings-refusals.json'), 'utf8')) as readonly RefusalCase[];

    it.each(cases.map((one) => [one.field, one] as const))('refuses %s in the words the server refuses it with', (_field, one) => {
        const problems = problemsOf({ ...seedForm(VALUES), [one.field]: one.typed.repeat(one.times) }, GRANULARITY, english);

        expect(problems[one.field]).toBe(english.text(one.key, one.values));
        expect(problems[one.field]).not.toBe(one.key);
    });

    it('lists the start intervals in the words SettingsRequest lists them with, the granularity filled', () => {
        const listed = english.text('validation.list.last', { items: ['5', '10', '15', '20', '30'].join(english.text('validation.list.separator')), last: '60' });

        expect(startIntervalSentence(10, english)).toBe(english.text('validation.start_interval', { intervals: listed, n: '10' }));
        expect(startIntervalSentence(10, english)).not.toContain(':');
    });
});

describe('the off list', () => {
    it('reads a checkbox the other way round: ticked when the key is absent', () => {
        expect(isOn(['reminder'], 'reminder')).toBe(false);
        expect(isOn(['reminder'], 'confirmed')).toBe(true);
    });

    it('unticking appends the key, ticking removes it, and a key it does not know stays', () => {
        expect(switched(['mystery_key'], 'reminder', false)).toEqual(['mystery_key', 'reminder']);
        expect(switched(['mystery_key', 'reminder'], 'reminder', false)).toEqual(['mystery_key', 'reminder']);
        expect(switched(['mystery_key', 'reminder'], 'reminder', true)).toEqual(['mystery_key']);
    });
});

describe('the opening hours branch', () => {
    const member = (id: number) => ({ id, name: `Member ${id}`, windows: [] });

    it('is absent while the staff feature is entitled, whatever was loaded', () => {
        expect(hoursBranch(['staff'], [member(1), member(2)])).toBe('absent');
        expect(hoursBranch(['staff'], null)).toBe('absent');
    });

    it('is the editor with one active member loaded and the roster with more, reading the rows and never a flag', () => {
        expect(hoursBranch([], [member(1)])).toBe('editor');
        expect(hoursBranch([], [member(1), member(2)])).toBe('roster');
    });

    it('falls back to the editor when the roster could not be read', () => {
        expect(hoursBranch([], null)).toBe('editor');
    });
});
