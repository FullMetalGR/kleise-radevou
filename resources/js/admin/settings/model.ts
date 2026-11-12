import { stringsOf } from '@/lib/strings';
import type { WeeklyWindow } from '@/shifts/model';
import type { SettingsStrings } from '@/types';
import type { EmailsKey, SettingsKey, SettingsWords } from './words';

/*
 * The Settings screen's data: the values the server hands the form, the form as a person edits
 * it with every number kept as typed, the validity rules with a sentence each, and the whole
 * patch a save sends, every field every time and never a null. The theme is one of the seven
 * words, chosen by a press, so it has no rule; it is the one colour a tenant chooses, the free
 * accent gone.
 *
 * The reminder lead has its own rule and its own sentence because zero is its meaningful value;
 * the three holds share the positive one. The off list is stored as the negative, so a checkbox
 * is ticked when its key is absent, and a key the client does not know stays on the list
 * through a save.
 *
 * Start times are one of the six intervals dividing the hour, and only those on the tenant's
 * granularity are offered or valid, the rule the server refuses with the same sentence. The
 * granularity is the shared props', so the rules take it beside the form rather than inside it:
 * it is the tenant's grid, not anything a person edits here.
 *
 * The profile, the business page's text and Important info, rides the same patch: thirteen text
 * fields, each sent every time as typed and trimmed, a blank one as the empty string the server
 * stores as null. Each has its rule and its sentence, the server's own.
 */

/** The profile's fields in the order the Booking page section lists them. */
const PROFILE_FIELDS = ['about', 'address_line1', 'address_line2', 'city', 'postal_code', 'country', 'phone', 'public_email', 'website', 'instagram', 'facebook', 'google_reviews_url', 'important_info'] as const;

export type ProfileField = (typeof PROFILE_FIELDS)[number];

/**
 * A record of the thirteen profile fields, each the value the function gives it: the one loop over
 * PROFILE_FIELDS that builds one, which the seed, the patch and the tests read. Object.fromEntries
 * types its record by string keys alone; its entries are PROFILE_FIELDS' own, every one, so the
 * record is whole, and this is the one place that says so.
 */
export function profileRecord<T>(value: (field: ProfileField) => T): Record<ProfileField, T> {
    return Object.fromEntries(PROFILE_FIELDS.map((field) => [field, value(field)])) as Record<ProfileField, T>;
}

/** The seven themes in order, Theme's cases on the server and the column's CHECK. */
export const THEMES = ['neutral', 'blue', 'green', 'orange', 'red', 'rose', 'violet'] as const;

export type ThemeWord = (typeof THEMES)[number];

export type Values = {
    currency: string;
    theme: ThemeWord;
    /** The default language's code, en or el. */
    language: string;
    checkout_ttl_min: number;
    approval_ttl_hours: number;
    payment_ttl_hours: number;
    reminder_lead_hours: number;
    start_interval_min: number;
    emails_off: string[];
} & Record<ProfileField, string | null>;

/** One stored image as the screen shows it: the file name the removal names, and its URL. */
export type StoredImage = { name: string; url: string };

export type Logo = StoredImage | null;

/**
 * The business page's map as the screen reads it: where it stands, and its stored image on the
 * images route on ready alone. The settings save that changes the address is what makes it;
 * nothing here asks for one.
 */
export type BusinessMap = { state: 'none' | 'pending' | 'failed'; image: null } | { state: 'ready'; image: StoredImage };

/** The types every image chooser offers, the same three the server accepts by the bytes. */
export const IMAGE_TYPES = 'image/png,image/jpeg,image/webp';

/**
 * An image's largest file in whole megabytes, beside its spelling in words, ICU's on the server.
 */
type Size = { megabytes: number; spelled: string };

/**
 * The sizes the image helps name, the server's: each image's largest file and the gallery's
 * capacity, each spelled.
 */
export type Limits = { logo: Size; cover: Size; gallery: Size; capacity: { count: number; spelled: string } };

export type Urls = { hosted: string; snippet: string };

/** One row of the Links card: a service or a member the public catalogue offers, its link and its QR code's path. */
export type LinkRow = { id: number; name: string; link: string; qr: string };

/** The Links card, BookingLinks on the server: the page's link and the offered rows of each kind. */
export type Links = { page: { link: string; qr: string }; services: LinkRow[]; staff: LinkRow[] };

/** An active member as Opening hours' roster lists them: the usual week, their pattern's windows. */
export type RosterMember = { id: number; name: string; windows: WeeklyWindow[] };

export type SettingsForm = {
    currency: string;
    theme: ThemeWord;
    /** The default language's code, en or el. */
    language: string;
    checkout_ttl_min: string;
    approval_ttl_hours: string;
    payment_ttl_hours: string;
    reminder_lead_hours: string;
    start_interval_min: string;
    emails_off: string[];
} & Record<ProfileField, string>;

export type SettingsPatch = {
    currency: string;
    theme: ThemeWord;
    /** The default language's code, en or el. */
    language: string;
    checkout_ttl_min: number;
    approval_ttl_hours: number;
    payment_ttl_hours: number;
    reminder_lead_hours: number;
    start_interval_min: number;
    emails_off: string[];
} & Record<ProfileField, string>;

export type Problems = Partial<Record<keyof Omit<SettingsForm, 'emails_off' | 'theme' | 'language'>, string>>;

/** What the settings save cannot carry: the week has its own button and its own route. */
export type HoursBranch = 'absent' | 'editor' | 'roster';

/*
 * The checks' sentences are keys of admin/settings.php's validation group, the group the server
 * refuses with through the translator (SettingsRequest), so the two read alike and cannot part:
 * each constant holds its key and keeps its name.
 */

export const CURRENCY_SENTENCE = 'validation.currency' satisfies SettingsKey;

export const CHECKOUT_SENTENCE = 'validation.checkout' satisfies SettingsKey;

export const APPROVAL_SENTENCE = 'validation.approval' satisfies SettingsKey;

export const PAYMENT_SENTENCE = 'validation.payment' satisfies SettingsKey;

export const REMINDER_SENTENCE = 'validation.reminder' satisfies SettingsKey;

/**
 * The server's sentence for a start interval off the six or off the granularity: the six listed by
 * the file's own list words, which SettingsRequest joins them with too, and the granularity.
 */
export function startIntervalSentence(granularity: number, words: SettingsWords): string {
    const intervals = START_INTERVALS.map(String);
    const last = intervals.pop() ?? '';
    const listed = words.text('validation.list.last', { items: intervals.join(words.text('validation.list.separator')), last });

    return words.text('validation.start_interval', { intervals: listed, n: String(granularity) });
}

/** The country the select starts on when the row holds none. */
const DEFAULT_COUNTRY = 'GR';

const LINK = /^https:\/\/\S+$/;

/**
 * Each profile field's rule, the server's: a length, the limits SettingsRequest names, and for some
 * a shape, with the one sentence for either, the length's filled with its limit as :max.
 */
const PROFILE_RULES: Record<ProfileField, { max: number; shape?: RegExp; sentence: SettingsKey }> = {
    about: { max: 2000, sentence: 'validation.about' },
    address_line1: { max: 200, sentence: 'validation.address_line' },
    address_line2: { max: 200, sentence: 'validation.address_line' },
    city: { max: 100, sentence: 'validation.city' },
    postal_code: { max: 20, sentence: 'validation.postal_code' },
    country: { max: 2, shape: /^[A-Z]{2}$/, sentence: 'validation.country' },
    phone: { max: 40, sentence: 'validation.phone' },
    public_email: { max: 255, shape: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, sentence: 'validation.public_email' },
    website: { max: 500, shape: LINK, sentence: 'validation.website' },
    instagram: { max: 500, shape: LINK, sentence: 'validation.instagram' },
    facebook: { max: 500, shape: LINK, sentence: 'validation.facebook' },
    google_reviews_url: { max: 500, shape: LINK, sentence: 'validation.google_reviews' },
    important_info: { max: 1000, sentence: 'validation.important_info' },
};

/** The sentence a profile field is refused with, here and by the server, in the words given. */
export function profileSentence(field: ProfileField, words: SettingsWords): string {
    const rule = PROFILE_RULES[field];

    return words.text(rule.sentence, { max: String(rule.max) });
}

const CURRENCY = /^[A-Z]{3}$/;

const WHOLE = /^\d+$/;

/** Words answering every key with itself, for a check whose sentence is not read. */
const KEYS: SettingsWords = stringsOf<SettingsStrings>(null, 'en');

/** Every interval a start may be offered on: each divides the hour. */
const START_INTERVALS = [5, 10, 15, 20, 30, 60];

/** The start intervals on the granularity, labelled in minutes, the only ones the select offers. */
export function startIntervalOptions(granularity: number, words: SettingsWords): { value: string; label: string }[] {
    return START_INTERVALS.filter((minutes) => minutes % granularity === 0).map((minutes) => ({ value: String(minutes), label: words.text('booking_rules.start_option', { n: String(minutes) }) }));
}

export function seedForm(values: Values): SettingsForm {
    return {
        currency: values.currency,
        theme: values.theme,
        language: values.language,
        checkout_ttl_min: String(values.checkout_ttl_min),
        approval_ttl_hours: String(values.approval_ttl_hours),
        payment_ttl_hours: String(values.payment_ttl_hours),
        reminder_lead_hours: String(values.reminder_lead_hours),
        start_interval_min: String(values.start_interval_min),
        emails_off: [...values.emails_off],
        ...seedProfile(values),
    };
}

function seedProfile(values: Values): Record<ProfileField, string> {
    return { ...profileRecord((field) => values[field] ?? ''), country: values.country ?? DEFAULT_COUNTRY };
}

/** The currency as every keystroke leaves it: uppercased, so what is typed is what is checked. */
export function upperCurrency(typed: string): string {
    return typed.toUpperCase();
}

function positive(typed: string): boolean {
    return WHOLE.test(typed) && Number(typed) >= 1;
}

/** Every check that fails, by field, each in its sentence in the words given. */
export function problemsOf(form: SettingsForm, granularity: number, words: SettingsWords): Problems {
    const problems: Problems = {};

    if (!CURRENCY.test(form.currency)) {
        problems.currency = words.text(CURRENCY_SENTENCE);
    }

    if (!positive(form.checkout_ttl_min)) {
        problems.checkout_ttl_min = words.text(CHECKOUT_SENTENCE);
    }

    if (!positive(form.approval_ttl_hours)) {
        problems.approval_ttl_hours = words.text(APPROVAL_SENTENCE);
    }

    if (!positive(form.payment_ttl_hours)) {
        problems.payment_ttl_hours = words.text(PAYMENT_SENTENCE);
    }

    if (!WHOLE.test(form.reminder_lead_hours)) {
        problems.reminder_lead_hours = words.text(REMINDER_SENTENCE);
    }

    if (!onGranularity(form.start_interval_min, granularity)) {
        problems.start_interval_min = startIntervalSentence(granularity, words);
    }

    for (const field of PROFILE_FIELDS) {
        const typed = form[field].trim();
        const rule = PROFILE_RULES[field];

        if (typed !== '' && (typed.length > rule.max || (rule.shape !== undefined && !rule.shape.test(typed)))) {
            problems[field] = profileSentence(field, words);
        }
    }

    return problems;
}

/** Whether a typed start interval is one of the six and on the granularity. */
function onGranularity(typed: string, granularity: number): boolean {
    return START_INTERVALS.some((minutes) => minutes % granularity === 0 && String(minutes) === typed);
}

/** The first sentence the save bar states, or null when every rule holds. */
export function firstProblem(form: SettingsForm, granularity: number, words: SettingsWords): string | null {
    const problems = problemsOf(form, granularity, words);

    return problems.currency ?? problems.checkout_ttl_min ?? problems.approval_ttl_hours ?? problems.payment_ttl_hours ?? problems.reminder_lead_hours ?? problems.start_interval_min ?? PROFILE_FIELDS.map((field) => problems[field]).find((sentence) => sentence !== undefined) ?? null;
}

/** Whether every check holds; the sentences are not read, so no words are needed. */
export function saveable(form: SettingsForm, granularity: number): boolean {
    return Object.keys(problemsOf(form, granularity, KEYS)).length === 0;
}

export function wholePatchOf(form: SettingsForm): SettingsPatch {
    return {
        currency: form.currency,
        theme: form.theme,
        language: form.language,
        checkout_ttl_min: Number(form.checkout_ttl_min),
        approval_ttl_hours: Number(form.approval_ttl_hours),
        payment_ttl_hours: Number(form.payment_ttl_hours),
        reminder_lead_hours: Number(form.reminder_lead_hours),
        start_interval_min: Number(form.start_interval_min),
        emails_off: [...form.emails_off],
        ...profileRecord((field) => form[field].trim()),
    };
}

/**
 * The one sentence beside a message held on: the approved message while a payment connection
 * works, since its awaiting-payment form carries the customer's only link to the payment. The
 * server sends it whatever the off list says and a save ignores it there, so the checkbox is
 * checked and disabled. A key of the emails section.
 */
export const LOCKED_SENTENCE = 'locked' satisfies EmailsKey;

/**
 * Who a message goes to, as the tag beside its checkbox reads: the customer, the business, or for
 * the one business message that goes to the owners alone, the late payment, the owners. The key
 * of the tag's words in the emails section.
 */
export function audienceTag(message: { audience: string; owners_alone: boolean }): EmailsKey {
    if (message.audience !== 'business') {
        return 'audience.customer';
    }

    return message.owners_alone ? 'audience.owners' : 'audience.business';
}

/** A checkbox reads the other way round: ticked means the key is absent from the off list. */
export function isOn(off: readonly string[], key: string): boolean {
    return !off.includes(key);
}

/** Ticking removes the key from the off list; unticking appends it. Every other key stays. */
export function switched(off: readonly string[], key: string, on: boolean): string[] {
    return on ? off.filter((stored) => stored !== key) : off.includes(key) ? [...off] : [...off, key];
}

/**
 * Which Opening hours the screen shows: nothing while the staff feature is entitled, the
 * read-only roster when the feature is gone and more than one active member was loaded, and
 * the editor otherwise, a failed roster read included, because freezing needs positive
 * evidence.
 */
export function hoursBranch(entitlements: readonly string[], roster: readonly RosterMember[] | null): HoursBranch {
    if (entitlements.includes('staff')) {
        return 'absent';
    }

    return roster !== null && roster.length > 1 ? 'roster' : 'editor';
}
