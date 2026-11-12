import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { localeOf } from '../../booking/language';
import { key, mount, type Mounted, press } from '@/components/ui/harness';
import { formatAmount, formatPercent } from '@/lib/format';
import { CASH_SENTENCE, DEGRADE_SENTENCE, GONE_SENTENCE, PAYMENT_WAIT, type PaymentConnection, type PaymentFee, type PaymentProvider, type Payments } from '@/settings/payments';
import { type BusinessMap, CHECKOUT_SENTENCE, CURRENCY_SENTENCE, type Limits, type Links, LOCKED_SENTENCE, type Logo, profileRecord, profileSentence, REMINDER_SENTENCE, type RosterMember, type StoredImage, startIntervalSentence, type Values } from '@/settings/model';
import type { WeeklyWindow } from '@/shifts/model';
import { KEYED_BILLING, KEYED_EMAILS, KEYED_PAYMENTS, KEYED_SETTINGS, keyedBilling, keyedEmails, keyedPayments, keyedSettings, keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import { COPIED, COPY_LINK } from '@/lib/copy';
import { DOWNLOAD_QR, LINKS_HELP, LINKS_TITLE, PAGE_LINK, SERVICE_LINKS, STAFF_LINKS } from '@/settings/LinksCard';
import { FAILED } from '@/settings/MapLine';
import { ROSTER_SENTENCE } from '@/settings/OpeningHours';
import type { PaymentsKey } from '@/settings/words';
import { CLOSE_ACCOUNT, CLOSE_BILLING, CLOSE_FAILED, CLOSE_KEEPS, CLOSE_PATH, CLOSE_PAUSES, CLOSE_TITLE, CLOSE_WHAT, CLOSE_WHEN, type Close, EXPORT_EVERYTHING, EXPORT_FAILED, EXPORT_PATH, EXPORT_WHAT } from '@/settings/DangerZone';
import Settings from './Settings';

/*
 * The Settings screen over a recording router: the ordered heading list pinned, the currency
 * uppercased and refused at two and four letters, the Theme picker's seven pills with the chosen
 * one ringed, the snippet carrying the theme and its copy button, the logo's two writes under
 * the Field shell and the cover's and the gallery's after the profile, the booking rules with
 * the reminder lead's own sentence and the start interval's select after it, offering only the
 * intervals on the granularity, one email checkbox per message of the catalog reading the off
 * list the other way round, Save sending the whole patch and re-seeding the settings fields
 * alone on the landed answer, so an unsaved week edit and a chosen file survive it and the other
 * two writes leave the fields alone in turn, a submit held while a check fails with focus left
 * where it was, every branch of Opening hours fed its props, the Payments section generated from
 * the prop's descriptors, and the Danger zone after the form with Export everything, busy while
 * its fetch is on its way, the zip handed to the browser under the server's name, a failure's
 * toast, and no zone for a viewer without manageSettings; under it Close this account, its
 * dialog saying the window, the server's day in the tenant's zone, what keeps running, what
 * pauses, that billing stops and the server's way back, busy and never optimistic, a refusal's
 * toast and the prop read again, and while closing the server's sentence in the control's place.
 */

const shell = keyedShell();

/**
 * The settings section's words, keyed: the page's lines read through the same table.
 */
const settings = keyedSettings();

/** The Danger zone's words, the billing section keyed. */
const billing = keyedBilling();

/**
 * The image helps' sizes as the server spells them: the words are the fixture's own, ICU's on the
 * server.
 */
const LIMITS: Limits = { logo: { megabytes: 1, spelled: 'one' }, cover: { megabytes: 8, spelled: 'eight' }, gallery: { megabytes: 8, spelled: 'eight' }, capacity: { count: 8, spelled: 'eight' } };

/** The gallery's help as the page fills it from LIMITS. */
const GALLERY_HELP = settings.choice('booking_page.gallery_help', 8, { n: 'eight', capacity: 'eight' });

/**
 * The country select's names as the server hands them, countries.php's codes in the user's
 * language: neutral names, unsorted, an accented capital and lower cases among them, which code
 * point order would sort apart from the collation.
 */
const COUNTRIES: Record<string, string> = { GR: 'Zeta land', CY: 'beta isle', DE: 'Ceta', AT: 'alpha realm', AL: '\u00c9ta' };

/** The gallery's count line against the capacity as LIMITS carries it. */
function photoCount(count: number): string {
    return settings.text('gallery.count', { count: String(count), capacity: String(LIMITS.capacity.count) });
}

/** The full gallery's sentence, the server's refusal, its capacity the words LIMITS carries. */
const GALLERY_FULL = settings.text('images.refusals.full', { capacity: LIMITS.capacity.spelled });

/** ICU's long name of an ISO weekday in English, the page's language here. */
function weekday(iso: number): string {
    return new Intl.DateTimeFormat(localeOf('en'), { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, iso)));
}

type Visit = { onStart?: () => void; onFinish?: (visit?: { cancelled?: boolean; interrupted?: boolean }) => void; onSuccess?: (page: { props: Record<string, unknown> }) => void; data?: Record<string, unknown>; forceFormData?: boolean };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: inertia.props }),
    router: {
        get: inertia.get,
        post: inertia.post,
        put: inertia.put,
        delete: inertia.delete,
        reload: inertia.reload,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const KEYS = ['acknowledged', 'confirmed', 'approved', 'rejected', 'cancelled', 'moved', 'reminder', 'request_expired', 'new_booking', 'approval_request', 'approval_nag', 'auto_approved', 'cancellation_notice', 'move_notice', 'late_payment', 'my_bookings_link', 'thank_you'];

const MESSAGES = KEYS.map((key, at) => ({ key, label: `Message ${key}`, audience: at < 8 || key === 'my_bookings_link' || key === 'thank_you' ? 'customer' : 'business', owners_alone: key === 'late_payment', locked: false }));

/** The catalog while a payment connection works: the approved message held on. */
const LOCKED_MESSAGES = MESSAGES.map((message) => ({ ...message, locked: message.key === 'approved' }));

/** A row with no profile written: every field null but the country, whose column defaults to GR. */
const NO_PROFILE = { ...profileRecord(() => null), country: 'GR' };

/** The profile as a save of an untouched form sends it: every field empty but the country. */
const BLANK_PROFILE = { ...profileRecord(() => ''), country: 'GR' };

const VALUES: Values = { currency: 'EUR', theme: 'violet', language: 'en', checkout_ttl_min: 15, approval_ttl_hours: 48, payment_ttl_hours: 24, reminder_lead_hours: 24, start_interval_min: 15, emails_off: ['mystery_key'], ...NO_PROFILE };

/** The default language's choices as the server hands them, each name in its own language. */
const LANGUAGES = [
    { value: 'en', name: 'English' },
    { value: 'el', name: '\u0395\u03bb\u03bb\u03b7\u03bd\u03b9\u03ba\u03ac' },
];

const SNIPPET = '<div class="kr-booking" data-theme="violet"></div>\n<script src="http://luna.localhost:8000/embed.js" async></script>';

const WEEK: WeeklyWindow[] = [{ weekday: 1, start: '09:00', end: '17:00' }, { weekday: 3, start: '10:07', end: '16:00' }];

const IMPLICIT: RosterMember = { id: 1, name: 'Luna Hair Studio', windows: WEEK };

const ELENA: RosterMember = { id: 2, name: 'Elena Papadaki', windows: [{ weekday: 2, start: '09:00', end: '13:00' }, { weekday: 2, start: '14:00', end: '18:00' }] };

/*
 * Two providers shaped as the registry's two are, under invented names, since no file under
 * resources/js names a registered provider: one declaring two secret fields, one declaring three
 * fields of another shape, a plain merchant code among them. The same components render both.
 */
const TWO_SECRETS: PaymentProvider = {
    slug: 'cardhouse',
    name: 'Cardhouse',
    rate_url: 'https://cardhouse.example/pricing',
    modes: [
        { mode: 'platform', offered: false, takes_fee: true, fee: null },
        { mode: 'own', offered: true, takes_fee: false, fee: null },
    ],
    fields: [
        { name: 'secret_key', label: 'secret_key-label', help: 'secret_key-help', secret: true },
        { name: 'webhook_secret', label: 'webhook_secret-label', help: 'webhook_secret-help', secret: true },
    ],
};

const THREE_FIELDS: PaymentProvider = {
    slug: 'tillpoint',
    name: 'Tillpoint',
    rate_url: 'https://tillpoint.example/pricing',
    modes: [
        { mode: 'platform', offered: false, takes_fee: true, fee: null },
        { mode: 'own', offered: true, takes_fee: false, fee: null },
    ],
    fields: [
        { name: 'merchant_code', label: 'merchant_code-label', help: 'merchant_code-help', secret: false },
        { name: 'api_token', label: 'api_token-label', help: 'api_token-help', secret: true },
        { name: 'signing_phrase', label: 'signing_phrase-label', help: 'signing_phrase-help', secret: true },
    ],
};

const NOTHING_CONNECTED: Payments = { providers: [TWO_SECRETS, THREE_FIELDS], connection: null };

const WEBHOOK = 'http://localhost:8000/webhooks/tillpoint/Q2hvb3NlIGEgdG9rZW4gaGVyZQ_-';

function connected(connection: Partial<PaymentConnection> = {}): Payments {
    return {
        providers: [TWO_SECRETS, THREE_FIELDS],
        connection: {
            provider: 'tillpoint',
            name: 'Tillpoint',
            mode: 'own',
            state: 'active',
            last_checked_at: '2026-11-02T06:00:00+00:00',
            last_check_ok: true,
            requirements: null,
            information_due: false,
            fee: null,
            stored_secrets: ['api_token', 'signing_phrase'],
            webhook_url: WEBHOOK,
            ...connection,
        },
    };
}

/** A provider whose every mode the prop offers, its platform mode carrying the fee given. */
function offered(provider: PaymentProvider, fee: PaymentFee | null = null): PaymentProvider {
    return { ...provider, modes: provider.modes.map((mode) => ({ ...mode, offered: true, fee: mode.takes_fee ? fee : null })) };
}

/** The launch fee as the prop carries it on a connection made through us: zero, in the tenant's currency. */
const LAUNCH_FEE: PaymentFee = { percent: '0', fixed_minor: null, currency: 'EUR' };

/** A connection made through us: no secrets, no URL, the line under it the provider's standing, the fee in force beside it. */
function platformRow(connection: Partial<PaymentConnection>): Payments {
    return connected({ mode: 'platform', stored_secrets: [], webhook_url: null, fee: LAUNCH_FEE, ...connection });
}

type Overrides = { permissions?: string[]; entitlements?: string[]; values?: Values; logo?: Logo; cover?: StoredImage | null; gallery?: StoredImage[]; map?: BusinessMap; roster?: RosterMember[] | null; week?: WeeklyWindow[]; granularity?: number; payments?: Payments; links?: Links; messages?: typeof MESSAGES; close?: Close; countries?: Record<string, string> };

/**
 * The Danger zone's close as the server hands it to an open account: the day it would close on
 * now, the way back, and the window's days with their spelling.
 */
const OPEN: Close = { day: 'day-token', way_back: 'way-back-token', notice: null, window: { count: 30, spelled: 'thirty-token' } };

/** The Links card as the server hands it: the page's link and one offered service and member. */
const LINKS: Links = {
    page: { link: 'http://luna.localhost:8000/', qr: '/admin/settings/links/page.svg' },
    services: [
        { id: 7, name: 'Haircut', link: 'http://luna.localhost:8000/?service=7', qr: '/admin/settings/links/service/7.svg' },
        { id: 9, name: 'Colour', link: 'http://luna.localhost:8000/?service=9', qr: '/admin/settings/links/service/9.svg' },
    ],
    staff: [{ id: 2, name: 'Elena Papadaki', link: 'http://luna.localhost:8000/?staff=2', qr: '/admin/settings/links/staff/2.svg' }],
};

function propsWith({ permissions = ['viewBookings', 'manageSettings'], entitlements = ['staff'], values = VALUES, logo = null, cover = null, gallery = [], map = { state: 'none', image: null }, roster = [IMPLICIT, ELENA], week = WEEK, granularity = 5, payments = NOTHING_CONNECTED, links = LINKS, messages = MESSAGES, close = OPEN, countries = COUNTRIES }: Overrides = {}): Record<string, unknown> {
    return {
        tenant: { name: 'Luna', slug: 'luna', timezone: 'Europe/Athens', currency: values.currency },
        auth: { user: { id: 11, name: 'Alex Owner', role: 'owner' } },
        flash: null,
        errors: {},
        permissions,
        entitlements,
        subscription: { state: 'active' },
        settings: { granularity, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', messages },
        nav: [],
        values,
        logo,
        cover,
        gallery,
        map,
        urls: { hosted: 'http://luna.localhost:8000/', snippet: SNIPPET },
        links,
        week,
        roster,
        payments,
        close,
        limits: LIMITS,
        countries,
        languages: LANGUAGES,
        language: 'en',
        strings: { shell: {}, settings: KEYED_SETTINGS, payments: KEYED_PAYMENTS, billing: KEYED_BILLING, emails: KEYED_EMAILS },
    };
}

function lastVisit(method: ReturnType<typeof vi.fn>): [string, Record<string, unknown>, Visit] {
    const call = method.mock.lastCall;

    if (call === undefined) {
        throw new Error('No visit was made.');
    }

    if (method === inertia.delete) {
        return [call[0] as string, {}, call[1] as Visit];
    }

    return call as [string, Record<string, unknown>, Visit];
}

/** The patch an untouched form over VALUES sends, before the profile. */
function wholeBody(): Record<string, unknown> {
    return { currency: 'EUR', theme: 'violet', language: 'en', checkout_ttl_min: 15, approval_ttl_hours: 48, payment_ttl_hours: 24, reminder_lead_hours: 24, start_interval_min: 15, emails_off: ['mystery_key'] };
}

function start(visit: Visit): void {
    act(() => visit.onStart?.());
}

function fail(visit: Visit, response?: { status: number; data: unknown }): void {
    const event = response === undefined ? new CustomEvent('exception', { cancelable: true, detail: { exception: new Error('offline') } }) : new CustomEvent('invalid', { cancelable: true, detail: { response } });

    act(() => inertia.listeners.get(event.type)?.(event));
    act(() => visit.onFinish?.());
}

function land(visit: Visit, props: Record<string, unknown>): void {
    render(props);
    act(() => visit.onSuccess?.({ props }));
    act(() => visit.onFinish?.());
}

function type(selector: string, value: string): void {
    const field = document.querySelector(selector);

    if (!(field instanceof HTMLInputElement) && !(field instanceof HTMLTextAreaElement)) {
        throw new Error(`No field at ${selector}.`);
    }

    act(() => {
        Object.getOwnPropertyDescriptor(Object.getPrototypeOf(field), 'value')?.set?.call(field, value);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

/**
 * A submit event on the settings form itself, reaching the handler's own refusal: in a browser the
 * disabled Save holds a press and an Enter in a field alike, under the rule that a gated action
 * also refuses in its handler.
 */
function submitSettings(): void {
    act(() => document.querySelector('form.admin-settings')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
}

function value(selector: string): string {
    const field = document.querySelector(selector);

    return field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement ? field.value : '';
}

function pick(selector: string, choice: string): void {
    const field = document.querySelector(selector);

    if (!(field instanceof HTMLSelectElement)) {
        throw new Error(`No select at ${selector}.`);
    }

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')?.set?.call(field, choice);
        field.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

/** The start interval's options as value and label pairs, in their order. */
function intervals(): string[][] {
    return Array.from(document.querySelectorAll('#settings-start-interval option')).map((option) => [option.getAttribute('value') ?? '', option.textContent ?? '']);
}

function text(selector: string): string | null | undefined {
    return document.querySelector(selector)?.textContent;
}

/** The page's section headings in order, the form's and the Danger zone's after it. */
function headings(): string[] {
    return Array.from(document.querySelectorAll('.admin-settings-page section h2')).map((heading) => heading.textContent ?? '');
}

function reason(): string | null | undefined {
    return text('.admin-save-bar__reason');
}

function saveDisabled(): boolean {
    return document.querySelector<HTMLButtonElement>('.admin-settings-save')?.disabled ?? false;
}

/** The file input as its label reaches it: the label names the control by id. */
function logoInput(): HTMLInputElement {
    const label = Array.from(document.querySelectorAll('label')).find((candidate) => candidate.textContent === settings.text('booking_page.logo'));

    if (label === undefined) {
        throw new Error('No Logo label.');
    }

    const input = document.getElementById(label.htmlFor);

    if (!(input instanceof HTMLInputElement) || input.type !== 'file') {
        throw new Error('The Logo label is bound to no file input.');
    }

    return input;
}

/** The file input a label names by its text: the label reaches the control by id. */
function labelledInput(name: string): HTMLInputElement {
    const label = Array.from(document.querySelectorAll('label')).find((candidate) => candidate.textContent === name);
    const input = label === undefined ? null : document.getElementById(label.htmlFor);

    if (!(input instanceof HTMLInputElement) || input.type !== 'file') {
        throw new Error(`No file input labelled ${name}.`);
    }

    return input;
}

function chooseAt(input: HTMLInputElement, file: File): void {
    Object.defineProperty(input, 'files', { value: [file], configurable: true });
    act(() => input.dispatchEvent(new Event('change', { bubbles: true })));
}

function choose(file: File): void {
    chooseAt(logoInput(), file);
}

function fridayWindows(): number {
    return document.querySelectorAll('.admin-weekly__day[data-weekday="5"] .admin-weekly__window').length;
}

function uploadDisabled(): boolean {
    return document.querySelector<HTMLButtonElement>('.admin-settings-logo-upload')?.disabled ?? true;
}

let view: Mounted;

function render(props: Record<string, unknown>): void {
    inertia.props = props;
    view.render(
        <ToastProvider>
            <Settings />
        </ToastProvider>,
    );
}

beforeEach(() => {
    view = mount();
    inertia.put.mockReset();
    inertia.post.mockReset();
    inertia.delete.mockReset();
    inertia.reload.mockReset();
    inertia.listeners.clear();
});

afterEach(() => {
    view.unmount();
    vi.restoreAllMocks();
});

describe('the sections', () => {
    it('render in the one fixed order, Opening hours at its place under the empty entitlement set and one member, Payments and Danger zone on every plan, and never as tabs', () => {
        render(propsWith({ entitlements: [], roster: [IMPLICIT] }));
        expect(headings()).toEqual([settings.text('sections.general'), settings.text('sections.booking_page'), settings.text('sections.booking_rules'), settings.text('sections.opening_hours'), settings.text('sections.payments'), settings.text('sections.emails'), billing.text('danger.title')]);
        expect(document.querySelectorAll('[role="tab"], [role="tablist"]')).toHaveLength(0);

        render(propsWith({ entitlements: ['staff'] }));
        expect(headings()).toEqual([settings.text('sections.general'), settings.text('sections.booking_page'), settings.text('sections.booking_rules'), settings.text('sections.payments'), settings.text('sections.emails'), billing.text('danger.title')]);
    });

    it('keep Danger zone after the form and outside it, the save bar closing the form before it', () => {
        render(propsWith());
        const form = document.querySelector('form.admin-settings');
        const zone = document.querySelector('.admin-section--danger');

        expect(zone).not.toBeNull();
        expect(form?.contains(zone)).toBe(false);
        expect(form?.lastElementChild?.classList.contains('admin-save-bar')).toBe(true);
        expect(form?.nextElementSibling).toBe(zone);
        expect(document.querySelectorAll('.admin-settings-page > h1')).toHaveLength(1);
    });
});

describe('General', () => {
    it('uppercases the currency on each keystroke, refuses two letters and four with the sentence, and admits three', () => {
        render(propsWith());
        expect(value('#settings-currency')).toBe('EUR');

        type('#settings-currency', 'us');
        expect(value('#settings-currency')).toBe('US');
        expect(text('#settings-currency-error')).toBe(settings.text(CURRENCY_SENTENCE));
        expect(document.querySelector('#settings-currency')?.getAttribute('aria-invalid')).toBe('true');
        expect(saveDisabled()).toBe(true);
        expect(reason()).toBe(settings.text(CURRENCY_SENTENCE));

        type('#settings-currency', 'usdx');
        expect(value('#settings-currency')).toBe('USDX');
        expect(saveDisabled()).toBe(true);

        type('#settings-currency', 'usd');
        expect(value('#settings-currency')).toBe('USD');
        expect(document.querySelector('#settings-currency-error')).toBeNull();
        expect(saveDisabled()).toBe(false);
        expect(document.querySelector('.admin-save-bar__reason')).toBeNull();
    });
});

describe('General\'s default language', () => {
    it('offers each language by its own name, the saved one chosen, and sends the one chosen in the whole patch', () => {
        render(propsWith({ values: { ...VALUES, language: 'el' } }));
        expect(document.querySelector('#settings-language')?.tagName).toBe('SELECT');
        expect(Array.from(document.querySelectorAll('#settings-language option')).map((option) => [option.getAttribute('value'), option.textContent])).toEqual(LANGUAGES.map((language) => [language.value, language.name]));
        expect(value('#settings-language')).toBe('el');
        expect(text('#settings-language-help')).toBe(settings.text('general.language_help'));

        pick('#settings-language', 'en');
        press(document.querySelector('.admin-settings-save'));
        const [, body] = lastVisit(inertia.put);
        expect(body).toEqual({ ...wholeBody(), language: 'en', ...BLANK_PROFILE });
    });
});

describe('Booking page', () => {
    it('shows the hosted URL as a link, the snippet carrying the theme as the server spelled it, and copies it', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        render(propsWith());

        expect(document.querySelector('.admin-settings-hosted__link')?.getAttribute('href')).toBe('http://luna.localhost:8000/');
        expect(text('.admin-settings-hosted__link')).toBe('http://luna.localhost:8000/');
        expect(document.querySelector('#settings-accent')).toBeNull();
        expect(value('#settings-snippet')).toBe(SNIPPET);
        expect(value('#settings-snippet')).toContain('<div class="kr-booking" data-theme="violet"></div>');
        expect(document.querySelector('#settings-snippet')?.hasAttribute('readonly')).toBe(true);

        press(document.querySelector('.admin-settings-copy'));
        expect(writeText).toHaveBeenCalledWith(SNIPPET);
        await act(async () => {
            await Promise.resolve();
        });
        expect(text('.admin-settings-copied')).toBe(shell.text(COPIED));
    });

    it('draws the Theme picker in Accent colour\'s place: seven pills in their order, each a swatch of its light primary and its name, the saved one pressed and ringed, the help under them', () => {
        render(propsWith());
        const group = document.querySelector('[role="group"].admin-settings-themes');
        const pills = [...document.querySelectorAll<HTMLButtonElement>('.admin-settings-theme')];

        expect(group?.getAttribute('aria-labelledby')).toBe('settings-theme-label');
        expect(text('#settings-theme-label')).toBe(settings.text('booking_page.theme'));
        expect(group?.getAttribute('aria-describedby')).toBe('settings-theme-help');
        expect(text('#settings-theme-help')).toBe(settings.text('booking_page.theme_help'));
        expect(pills.map((pill) => pill.textContent)).toEqual((['neutral', 'blue', 'green', 'orange', 'red', 'rose', 'violet'] as const).map((word) => settings.text(`booking_page.themes.${word}`)));
        expect(pills.map((pill) => pill.dataset.value)).toEqual(['neutral', 'blue', 'green', 'orange', 'red', 'rose', 'violet']);
        expect(pills.map((pill) => pill.querySelector<HTMLElement>('.admin-settings-theme__swatch')?.style.backgroundColor)).toEqual(['rgb(23, 23, 23)', 'rgb(37, 99, 235)', 'rgb(21, 128, 61)', 'rgb(194, 65, 12)', 'rgb(220, 38, 38)', 'rgb(225, 29, 72)', 'rgb(124, 58, 237)']);
        // Each swatch edged one pixel in the kit's border token, so Neutral's near-black reads on
        // the dark admin too.
        expect(pills.map((pill) => ['border', 'border-border'].every((name) => pill.querySelector('.admin-settings-theme__swatch')?.classList.contains(name)))).toEqual(Array<boolean>(7).fill(true));
        expect(pills.filter((pill) => pill.getAttribute('aria-pressed') === 'true').map((pill) => pill.dataset.value)).toEqual(['violet']);
        expect(pills.filter((pill) => pill.classList.contains('admin-settings-theme--chosen')).map((pill) => pill.dataset.value)).toEqual(['violet']);
        // Its place: the picker opens the Booking page section, before the logo.
        expect(document.querySelector('.admin-settings-themes')?.compareDocumentPosition(document.querySelector('.admin-settings-logo') as Node) ?? 0).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    });

    it('moves the ring to the theme pressed and sends it in the whole patch, with no accent', () => {
        render(propsWith());
        press(document.querySelector('.admin-settings-theme[data-value="blue"]'));

        const chosen = [...document.querySelectorAll<HTMLButtonElement>('.admin-settings-theme--chosen')].map((pill) => pill.dataset.value);
        expect(chosen).toEqual(['blue']);
        expect(document.querySelector('.admin-settings-theme[data-value="blue"]')?.getAttribute('aria-pressed')).toBe('true');
        expect(document.querySelector('.admin-settings-theme[data-value="violet"]')?.getAttribute('aria-pressed')).toBe('false');

        press(document.querySelector('.admin-settings-save'));
        const [, body] = lastVisit(inertia.put);
        expect(body).toEqual({ ...wholeBody(), theme: 'blue', ...BLANK_PROFILE });
        expect(body).not.toHaveProperty('brand_accent');
    });

    it('records the copy after the clipboard write as an async visit that keeps the page, with no toast, and records nothing when copying fails', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        render(propsWith());

        press(document.querySelector('.admin-settings-copy'));
        expect(writeText).toHaveBeenCalledWith(SNIPPET);
        expect(inertia.post).not.toHaveBeenCalled();
        await act(async () => {
            await Promise.resolve();
        });

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.post).toHaveBeenCalledWith('/admin/settings/link-copied', {}, { async: true, preserveState: true, preserveScroll: true });
        expect(writeText.mock.invocationCallOrder[0]).toBeLessThan(inertia.post.mock.invocationCallOrder[0] ?? 0);
        expect(document.querySelector('.admin-toast')).toBeNull();

        inertia.post.mockReset();
        const refused = vi.fn(() => Promise.reject(new Error('denied')));
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: refused }, configurable: true });
        press(document.querySelector('.admin-settings-copy'));
        await act(async () => {
            await Promise.resolve();
        });

        expect(text('.admin-settings-copied')).toBe(settings.text('errors.copy_failed'));
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('gathers the page\'s link, the snippet and one row per offered service and member into the Links card, each row with Copy link and its QR code to download', () => {
        render(propsWith());

        const card = document.querySelector('.admin-settings-links');
        const rows = Array.from(card?.querySelectorAll('.admin-settings-link') ?? []);

        expect(card?.querySelector('#settings-links-head')?.textContent).toBe(settings.text(LINKS_TITLE));
        expect(card?.querySelector('.admin-settings-links__help')?.textContent).toBe(settings.text(LINKS_HELP));
        expect(card?.querySelector('.admin-settings-links__help')?.className).toBe('admin-field__help text-xs text-muted-foreground admin-settings-links__help');
        expect(card?.querySelector('#settings-snippet')).not.toBeNull();
        expect(card?.querySelector('.admin-settings-copy')?.textContent).toBe(settings.text('booking_page.copy'));
        expect(Array.from(card?.querySelectorAll('h4') ?? []).map((heading) => heading.textContent)).toEqual([settings.text(SERVICE_LINKS), settings.text(STAFF_LINKS)]);
        expect(rows.map((row) => row.querySelector('.admin-settings-link__name')?.textContent)).toEqual([settings.text(PAGE_LINK), 'Haircut', 'Colour', 'Elena Papadaki']);
        expect(rows.map((row) => row.querySelector('.admin-settings-link__url')?.getAttribute('href'))).toEqual([LINKS.page.link, ...LINKS.services.map((row) => row.link), ...LINKS.staff.map((row) => row.link)]);
        expect(rows.map((row) => row.querySelector('.admin-settings-link__url')?.getAttribute('class'))).toEqual([
            'admin-settings-link__url text-sm break-all underline admin-settings-hosted__link',
            'admin-settings-link__url text-sm break-all underline',
            'admin-settings-link__url text-sm break-all underline',
            'admin-settings-link__url text-sm break-all underline',
        ]);
        expect(rows.map((row) => row.querySelector('.admin-settings-link__copy')?.textContent)).toEqual(Array(4).fill(shell.text(COPY_LINK)));
        expect(rows.map((row) => [row.querySelector('.admin-settings-link__qr')?.textContent, row.querySelector('.admin-settings-link__qr')?.getAttribute('href'), row.querySelector('.admin-settings-link__qr')?.getAttribute('download')])).toEqual([
            [settings.text(DOWNLOAD_QR), '/admin/settings/links/page.svg', 'booking-page-qr.svg'],
            [settings.text(DOWNLOAD_QR), '/admin/settings/links/service/7.svg', 'service-7-qr.svg'],
            [settings.text(DOWNLOAD_QR), '/admin/settings/links/service/9.svg', 'service-9-qr.svg'],
            [settings.text(DOWNLOAD_QR), '/admin/settings/links/staff/2.svg', 'staff-2-qr.svg'],
        ]);
    });

    it('copies a row\'s link and records the press, each row saying so on its own line', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        render(propsWith());

        const rows = Array.from(document.querySelectorAll('.admin-settings-link'));
        press(rows[2]?.querySelector('.admin-settings-link__copy') ?? null);
        expect(writeText).toHaveBeenCalledWith('http://luna.localhost:8000/?service=9');
        await act(async () => {
            await Promise.resolve();
        });

        expect(rows[2]?.querySelector('.admin-settings-link__copied')?.textContent).toBe(shell.text(COPIED));
        expect(rows[1]?.querySelector('.admin-settings-link__copied')).toBeNull();
        expect(inertia.post).toHaveBeenCalledWith('/admin/settings/link-copied', {}, { async: true, preserveState: true, preserveScroll: true });
    });

    it('lists no service or staff heading where the catalogue offers none, the page\'s link and the snippet standing alone', () => {
        render(propsWith({ links: { ...LINKS, services: [], staff: [] } }));

        expect(document.querySelectorAll('.admin-settings-links h4')).toHaveLength(0);
        expect(document.querySelectorAll('.admin-settings-link')).toHaveLength(1);
        expect(document.querySelector('.admin-settings-links #settings-snippet')).not.toBeNull();
    });

    it('binds the Logo label to the file input by id, hangs the help off aria-describedby, and offers the three raster types alone', () => {
        render(propsWith());
        const input = logoInput();
        expect(input.id).toBe('settings-logo');
        expect(input.hasAttribute('aria-label')).toBe(false);
        expect(input.getAttribute('accept')).toBe('image/png,image/jpeg,image/webp');
        expect(input.getAttribute('aria-describedby')).toBe('settings-logo-help');
        expect(text('#settings-logo-help')).toBe(settings.choice('booking_page.logo_help', 1, { n: 'one' }));
    });

    it('uploads a chosen file as its own write, busy alone with the other controls held, and rekeys the chooser on the landed answer', () => {
        render(propsWith());
        expect(document.querySelector('.admin-settings-logo__image')).toBeNull();
        expect(document.querySelector('.admin-settings-logo-remove')).toBeNull();
        expect(document.querySelector<HTMLButtonElement>('.admin-settings-logo-upload')?.disabled).toBe(true);

        const file = new File(['png bytes'], 'logo.png', { type: 'image/png' });
        choose(file);
        expect(document.querySelector<HTMLButtonElement>('.admin-settings-logo-upload')?.disabled).toBe(false);
        press(document.querySelector('.admin-settings-logo-upload'));

        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/logo');
        expect(body).toEqual({ logo: file });
        expect(visit.forceFormData).toBe(true);
        start(visit);
        expect(document.querySelector('.admin-settings-logo-upload')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-settings-logo-upload')).toContain(shell.text('shared.image.uploading'));
        expect(saveDisabled()).toBe(true);
        expect(reason()).toBe(settings.text('waits.logo_upload'));
        expect(document.querySelector('.admin-settings-save')?.getAttribute('aria-busy')).toBeNull();
        expect(logoInput().disabled).toBe(true);

        const logo: Logo = { name: 'logo-0123456789abcdef.png', url: 'http://luna.localhost:8000/logo/logo-0123456789abcdef.png' };
        land(visit, propsWith({ logo }));
        expect(document.querySelector('.admin-settings-logo__image')?.getAttribute('src')).toBe(logo.url);
        expect(text('.admin-settings-logo__name')).toBe(logo.name);
        expect(document.querySelector<HTMLButtonElement>('.admin-settings-logo-upload')?.disabled).toBe(true);
        expect(text('.admin-settings-logo-upload')).toBe(settings.text('booking_page.logo_replace'));
        expect(saveDisabled()).toBe(false);
    });

    it('removes the logo as its own DELETE, busy alone, and toasts a refusal in its sentence', () => {
        render(propsWith({ logo: { name: 'logo-0123456789abcdef.png', url: 'http://luna.localhost:8000/logo/logo-0123456789abcdef.png' } }));

        press(document.querySelector('.admin-settings-logo-remove'));
        const [url, , visit] = lastVisit(inertia.delete);
        expect(url).toBe('/admin/settings/logo');
        start(visit);
        expect(document.querySelector('.admin-settings-logo-remove')?.getAttribute('aria-busy')).toBe('true');
        expect(document.querySelector<HTMLButtonElement>('.admin-settings-logo-upload')?.disabled).toBe(true);
        expect(reason()).toBe(settings.text('waits.logo_remove'));

        expect(text('.admin-settings-logo__wait')).toBe(settings.text('waits.logo_remove'));

        fail(visit, { status: 403, data: { code: 'subscription_required', data: { detail: GATE } } });
        expect(text('.admin-toast')).toContain(GATE);
        expect(document.querySelector('.admin-settings-logo__image')).not.toBeNull();
        expect(saveDisabled()).toBe(false);
    });
});

describe('Booking page profile', () => {
    const IDS = ['settings-about', 'settings-address-line1', 'settings-address-line2', 'settings-city', 'settings-postal-code', 'settings-country', 'settings-phone', 'settings-public-email', 'settings-website', 'settings-instagram', 'settings-facebook', 'settings-google-reviews-url', 'settings-important-info'];

    it('renders the profile fields in order after the snippet, the country starting on GR, Important info with its help naming both places', () => {
        render(propsWith());
        const controls = [...document.querySelectorAll('input[id^="settings-"], textarea[id^="settings-"], select[id^="settings-"]')].map((control) => control.id);

        expect(controls.filter((id) => IDS.includes(id))).toEqual(IDS);
        expect(controls.indexOf('settings-about')).toBeGreaterThan(controls.indexOf('settings-snippet'));
        expect(controls.indexOf('settings-important-info')).toBeLessThan(controls.indexOf('settings-checkout'));
        expect(value('#settings-country')).toBe('GR');
        const help = document.querySelector('#settings-important-info')?.getAttribute('aria-describedby')?.split(' ')[0] ?? '';
        expect(document.getElementById(help)?.textContent).toBe(settings.text('profile.important_info_help'));
    });

    it('offers countries.php\'s codes by their names in the user\'s language, sorted by its collation, and a stored code outside them as itself', () => {
        const option = (code: string): string => settings.text('profile.country_option', { country: COUNTRIES[code] ?? '', code });
        const offered = (): [string, string][] => [...document.querySelectorAll('#settings-country option')].map((choice) => [choice.getAttribute('value') ?? '', choice.textContent]);

        render(propsWith());
        expect(offered()).toEqual(['AT', 'CY', 'DE', 'AL', 'GR'].map((code) => [code, option(code)]));
        view.unmount();
        view = mount();

        render(propsWith({ values: { ...VALUES, country: 'JP' } }));
        expect(offered()).toEqual([...['AT', 'CY', 'DE', 'AL', 'GR'].map((code): [string, string] => [code, option(code)]), ['JP', 'JP']]);
        expect(value('#settings-country')).toBe('JP');
    });

    it('labels every profile field and its help by key', () => {
        render(propsWith());
        const label = (id: string): string => document.querySelector(`label[for="${id}"]`)?.textContent ?? '';
        const help = (id: string): string => document.getElementById(document.getElementById(id)?.getAttribute('aria-describedby')?.split(' ')[0] ?? '')?.textContent ?? '';

        expect(['about', 'address-line1', 'address-line2', 'city', 'postal-code', 'country', 'phone', 'public-email', 'website', 'instagram', 'facebook', 'google-reviews-url', 'important-info'].map((field) => label(`settings-${field}`))).toEqual(
            (['about', 'address_line1', 'address_line2', 'city', 'postal_code', 'country', 'phone', 'public_email', 'website', 'instagram', 'facebook', 'google_reviews', 'important_info'] as const).map((key) => settings.text(`profile.${key}`)),
        );
        expect(['about', 'address-line1', 'phone', 'public-email', 'website', 'instagram', 'facebook', 'google-reviews-url'].map((field) => help(`settings-${field}`))).toEqual(
            (['about_help', 'address_help', 'phone_help', 'public_email_help', 'link_help', 'link_help', 'link_help', 'reviews_help'] as const).map((key) => settings.text(`profile.${key}`)),
        );
    });

    it('refuses a link that is not https with its sentence and holds Save, then sends the profile trimmed in the whole patch and re-seeds it from the landed answer', () => {
        render(propsWith());
        type('#settings-website', 'http://luna.example');
        expect(document.body.textContent).toContain(profileSentence('website', settings));
        expect(document.querySelector<HTMLButtonElement>('.admin-settings-save')?.disabled).toBe(true);

        type('#settings-website', ' https://luna.example ');
        type('#settings-about', 'A small studio by the sea.');
        type('#settings-important-info', 'Please arrive five minutes early.');
        pick('#settings-country', 'CY');
        press(document.querySelector('.admin-settings-save'));

        const [, body, visit] = lastVisit(inertia.put);
        expect(body).toEqual({ ...wholeBody(), ...BLANK_PROFILE, about: 'A small studio by the sea.', website: 'https://luna.example', country: 'CY', important_info: 'Please arrive five minutes early.' });
        start(visit);
        land(visit, propsWith({ values: { ...VALUES, about: 'Saved about.', website: 'https://luna.example', country: 'CY', important_info: null } }));
        expect(value('#settings-about')).toBe('Saved about.');
        expect(value('#settings-country')).toBe('CY');
        expect(value('#settings-important-info')).toBe('');
    });
});

describe('Booking page cover and gallery', () => {
    const COVER: StoredImage = { name: 'cover-0123456789abcdef.jpg', url: 'http://luna.localhost:8000/images/cover-0123456789abcdef.jpg' };

    /** Photos named a, b, c and on, in that stored order. */
    function photos(count: number): StoredImage[] {
        return Array.from({ length: count }, (_, index) => {
            const name = `gallery-${String.fromCharCode(97 + index).repeat(16)}.jpg`;

            return { name, url: `http://luna.localhost:8000/images/${name}` };
        });
    }

    function disabled(selector: string): boolean | undefined {
        return document.querySelector<HTMLButtonElement>(selector)?.disabled;
    }

    it('places the cover and the gallery after the profile\'s text fields, each label bound to its chooser with its help, the count shown', () => {
        render(propsWith());
        const controls = [...document.querySelectorAll('input[id^="settings-"], textarea[id^="settings-"], select[id^="settings-"]')].map((control) => control.id);

        expect(controls.indexOf('settings-cover')).toBe(controls.indexOf('settings-important-info') + 1);
        expect(controls.indexOf('settings-gallery')).toBe(controls.indexOf('settings-cover') + 1);
        expect(controls.indexOf('settings-gallery')).toBeLessThan(controls.indexOf('settings-checkout'));

        const cover = labelledInput(settings.text('booking_page.cover'));
        expect(cover.id).toBe('settings-cover');
        expect(cover.getAttribute('accept')).toBe('image/png,image/jpeg,image/webp');
        expect(text(`#${cover.getAttribute('aria-describedby') ?? ''}`)).toBe(settings.choice('booking_page.cover_help', 8, { n: 'eight' }));
        expect(text('.admin-settings-cover__none')).toBe(settings.text('booking_page.cover_none'));
        expect(text('.admin-settings-cover-upload')).toBe(settings.text('booking_page.cover_upload'));
        expect(document.querySelector('.admin-settings-cover-remove')).toBeNull();

        const gallery = labelledInput(settings.text('booking_page.gallery'));
        expect(gallery.id).toBe('settings-gallery');
        expect(gallery.getAttribute('accept')).toBe('image/png,image/jpeg,image/webp');
        expect(text(`#${gallery.getAttribute('aria-describedby') ?? ''}`)).toBe(GALLERY_HELP);
        expect(text('.admin-settings-gallery__count')).toBe(photoCount(0));
        expect(document.querySelector('.admin-settings-gallery__grid')).toBeNull();
        expect(disabled('.admin-settings-gallery-add')).toBe(true);
    });

    it('uploads a cover as its own write, busy alone with every other image control held, and rekeys its chooser on the landed answer', () => {
        render(propsWith({ gallery: photos(1) }));
        const file = new File(['jpeg bytes'], 'cover.jpg', { type: 'image/jpeg' });

        chooseAt(labelledInput(settings.text('booking_page.cover')), file);
        expect(disabled('.admin-settings-cover-upload')).toBe(false);
        press(document.querySelector('.admin-settings-cover-upload'));

        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/cover');
        expect(body).toEqual({ cover: file });
        expect(visit.forceFormData).toBe(true);
        start(visit);
        expect(document.querySelector('.admin-settings-cover-upload')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-settings-cover-upload')).toContain(shell.text('shared.image.uploading'));
        expect(reason()).toBe(settings.text('waits.cover_upload'));
        expect(text('.admin-settings-cover__wait')).toBe(settings.text('waits.cover_upload'));
        expect(saveDisabled()).toBe(true);
        expect(labelledInput(settings.text('booking_page.cover')).disabled).toBe(true);
        expect(labelledInput(settings.text('booking_page.logo')).disabled).toBe(true);
        expect(labelledInput(settings.text('booking_page.gallery')).disabled).toBe(true);
        expect(disabled('.admin-settings-gallery-remove')).toBe(true);

        land(visit, propsWith({ cover: COVER, gallery: photos(1) }));
        expect(document.querySelector('.admin-settings-cover__image')?.getAttribute('src')).toBe(COVER.url);
        expect(text('.admin-settings-cover__name')).toBe(COVER.name);
        expect(text('.admin-settings-cover-upload')).toBe(settings.text('booking_page.cover_replace'));
        expect(disabled('.admin-settings-cover-upload')).toBe(true);
        expect(document.querySelector('.admin-settings-logo__image')).toBeNull();
        expect(saveDisabled()).toBe(false);
    });

    it('removes the cover as its own DELETE, busy alone, and toasts a refusal in its sentence with the cover kept', () => {
        render(propsWith({ cover: COVER }));

        press(document.querySelector('.admin-settings-cover-remove'));
        const [url, , visit] = lastVisit(inertia.delete);
        expect(url).toBe('/admin/settings/cover');
        start(visit);
        expect(document.querySelector('.admin-settings-cover-remove')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-settings-cover-remove')).toContain(shell.text('shared.image.removing'));
        expect(reason()).toBe(settings.text('waits.cover_remove'));
        expect(labelledInput(settings.text('booking_page.cover')).disabled).toBe(true);

        fail(visit, { status: 403, data: { code: 'subscription_required', data: { detail: GATE } } });
        expect(text('.admin-toast')).toContain(GATE);
        expect(document.querySelector('.admin-settings-cover__image')?.getAttribute('src')).toBe(COVER.url);
        expect(saveDisabled()).toBe(false);
    });

    it('shows the stored photos in order, adds a chosen photo as its own write busy alone, and rekeys the chooser on the landed answer', () => {
        render(propsWith({ gallery: photos(2) }));
        expect([...document.querySelectorAll('.admin-settings-gallery__image')].map((image) => image.getAttribute('src'))).toEqual(photos(2).map((photo) => photo.url));
        expect([...document.querySelectorAll('.admin-settings-gallery__image')].map((image) => image.getAttribute('alt'))).toEqual([settings.text('gallery.photo_alt', { n: '1', count: '2' }), settings.text('gallery.photo_alt', { n: '2', count: '2' })]);
        expect(document.querySelectorAll('.admin-settings-gallery-remove')).toHaveLength(2);
        expect(text('.admin-settings-gallery__count')).toBe(photoCount(2));

        const file = new File(['png bytes'], 'photo.png', { type: 'image/png' });
        chooseAt(labelledInput(settings.text('booking_page.gallery')), file);
        press(document.querySelector('.admin-settings-gallery-add'));

        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/gallery');
        expect(body).toEqual({ photo: file });
        expect(visit.forceFormData).toBe(true);
        start(visit);
        expect(document.querySelector('.admin-settings-gallery-add')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-settings-gallery-add')).toContain(settings.text('gallery.adding'));
        expect(reason()).toBe(settings.text('waits.photo_add'));
        expect(text('.admin-settings-gallery__wait')).toBe(settings.text('waits.photo_add'));
        expect([...document.querySelectorAll<HTMLButtonElement>('.admin-settings-gallery-remove')].map((button) => button.disabled)).toEqual([true, true]);
        expect(disabled('.admin-settings-cover-upload')).toBe(true);

        land(visit, propsWith({ gallery: photos(3) }));
        expect(text('.admin-settings-gallery__count')).toBe(photoCount(3));
        expect(document.querySelectorAll('.admin-settings-gallery__image')).toHaveLength(3);
        expect(disabled('.admin-settings-gallery-add')).toBe(true);
        expect(saveDisabled()).toBe(false);
    });

    it('toasts a refused photo in the server\'s sentence and keeps the chosen file for another try', () => {
        render(propsWith({ gallery: photos(1) }));
        chooseAt(labelledInput(settings.text('booking_page.gallery')), new File(['png bytes'], 'photo.png', { type: 'image/png' }));
        press(document.querySelector('.admin-settings-gallery-add'));
        const [, , visit] = lastVisit(inertia.post);
        start(visit);

        fail(visit, { status: 400, data: { code: 'invalid_request', data: { detail: 'refusal-photo-held' } } });
        expect(text('.admin-toast')).toContain('refusal-photo-held');
        expect(text('.admin-settings-gallery__count')).toBe(photoCount(1));
        expect(disabled('.admin-settings-gallery-add')).toBe(false);
    });

    it('removes one photo by its name as its own DELETE, that photo\'s button alone busy, and toasts a refusal in its sentence', () => {
        const held = photos(3);
        render(propsWith({ gallery: held }));

        press(document.querySelectorAll('.admin-settings-gallery-remove')[1] ?? null);
        const [url, , visit] = lastVisit(inertia.delete);
        expect(url).toBe(`/admin/settings/gallery/${held[1]?.name ?? ''}`);
        start(visit);
        const buttons = [...document.querySelectorAll<HTMLButtonElement>('.admin-settings-gallery-remove')];
        expect(buttons.map((button) => button.getAttribute('aria-busy'))).toEqual([null, 'true', null]);
        expect(buttons.map((button) => button.disabled)).toEqual([true, true, true]);
        expect(reason()).toBe(settings.text('waits.photo_remove'));
        expect(labelledInput(settings.text('booking_page.gallery')).disabled).toBe(true);

        fail(visit, { status: 403, data: { code: 'subscription_required', data: { detail: GATE } } });
        expect(text('.admin-toast')).toContain(GATE);
        expect(document.querySelectorAll('.admin-settings-gallery__image')).toHaveLength(3);
        expect(document.querySelector('.admin-settings-gallery-remove')?.getAttribute('aria-busy')).toBeNull();

        press(document.querySelectorAll('.admin-settings-gallery-remove')[0] ?? null);
        const [, , removal] = lastVisit(inertia.delete);
        start(removal);
        land(removal, propsWith({ gallery: held.slice(1) }));
        expect([...document.querySelectorAll('.admin-settings-gallery__image')].map((image) => image.getAttribute('src'))).toEqual(held.slice(1).map((photo) => photo.url));
        expect(text('.admin-settings-gallery__count')).toBe(photoCount(2));
    });

    it('keeps the chooser on a full gallery, disabled and described by the server\'s own sentence, and Add photo with it, so nothing vanishes under the pointer', () => {
        render(propsWith({ gallery: photos(8) }));
        const chooser = labelledInput(settings.text('booking_page.gallery'));
        const described = (chooser.getAttribute('aria-describedby') ?? '').split(' ').map((id) => document.getElementById(id)?.textContent);

        expect(text('.admin-settings-gallery__count')).toBe(photoCount(8));
        expect(document.querySelectorAll('.admin-settings-gallery-remove')).toHaveLength(8);
        expect(chooser.className).toContain('admin-settings-gallery__file');
        expect(chooser.className).toContain('file:h-8');
        expect(chooser.disabled).toBe(true);
        expect(disabled('.admin-settings-gallery-add')).toBe(true);
        expect(text('.admin-settings-gallery__full')).toBe(GALLERY_FULL);
        expect(described).toEqual([GALLERY_FULL, GALLERY_HELP]);
    });

    it('works the chooser at seven photos, described by its help alone and with no full sentence', () => {
        render(propsWith({ gallery: photos(7) }));
        const chooser = labelledInput(settings.text('booking_page.gallery'));

        expect(text('.admin-settings-gallery__count')).toBe(photoCount(7));
        expect(chooser.disabled).toBe(false);
        expect(document.querySelector('.admin-settings-gallery__full')).toBeNull();
        expect(text(`#${chooser.getAttribute('aria-describedby') ?? 'none'}`)).toBe(GALLERY_HELP);

        const file = new File(['png bytes'], 'photo.png', { type: 'image/png' });
        chooseAt(chooser, file);
        expect(disabled('.admin-settings-gallery-add')).toBe(false);
        press(document.querySelector('.admin-settings-gallery-add'));
        const [url, body] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/gallery');
        expect(body).toEqual({ photo: file });
    });
});

describe('Booking page map', () => {
    const MAP_IMAGE: StoredImage = { name: 'map-0123456789abcdef.png', url: 'http://luna.localhost:8000/images/map-0123456789abcdef.png' };

    /** Whether the map's line stands after the Country field and before Phone, under the address fields. */
    function underTheAddress(line: Element): boolean {
        const country = document.getElementById('settings-country');
        const phone = document.getElementById('settings-phone');

        return country !== null && phone !== null && (country.compareDocumentPosition(line) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0 && (line.compareDocumentPosition(phone) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
    }

    it('reads nothing while there is no map to make', () => {
        render(propsWith());

        expect(document.querySelector('.admin-settings-map')).toBeNull();
    });

    it('reads the preparing line while the map is pending, and the failed line with what to do when it failed, each under the address fields and with no control', () => {
        render(propsWith({ map: { state: 'pending', image: null } }));
        const pending = document.querySelector('.admin-settings-map');

        expect(pending?.textContent).toBe(settings.text('map.pending'));
        expect(pending !== null && underTheAddress(pending)).toBe(true);
        expect(pending?.querySelector('button, input, a, img')).toBeNull();

        render(propsWith({ map: { state: 'failed', image: null } }));
        const failed = document.querySelector('.admin-settings-map');

        expect(failed?.textContent).toBe(settings.text(FAILED));
        expect(failed !== null && underTheAddress(failed)).toBe(true);
        expect(failed?.querySelector('button, input, a, img')).toBeNull();
    });

    it('shows the stored map on ready, from the images route, under the address fields and with no control', () => {
        render(propsWith({ map: { state: 'ready', image: MAP_IMAGE } }));
        const ready = document.querySelector('.admin-settings-map');
        const image = ready?.querySelector('img');

        expect(image?.getAttribute('src')).toBe(MAP_IMAGE.url);
        expect(image?.getAttribute('alt')).toBe(settings.text('map.alt'));
        expect(ready?.textContent).toBe('');
        expect(ready !== null && underTheAddress(ready)).toBe(true);
        expect(ready?.querySelector('button, input, a')).toBeNull();
    });
});

describe('Booking rules', () => {
    it('refuses zero in a hold with the positive sentence, admits zero in the reminder lead, and refuses a negative lead with its own sentence', () => {
        render(propsWith());

        type('#settings-checkout', '0');
        expect(text('#settings-checkout-error')).toBe(settings.text(CHECKOUT_SENTENCE));
        expect(saveDisabled()).toBe(true);
        expect(reason()).toBe(settings.text(CHECKOUT_SENTENCE));
        type('#settings-checkout', '15');

        type('#settings-reminder', '0');
        expect(document.querySelector('#settings-reminder-error')).toBeNull();
        expect(saveDisabled()).toBe(false);

        type('#settings-reminder', '-1');
        expect(text('#settings-reminder-error')).toBe(settings.text(REMINDER_SENTENCE));
        expect(REMINDER_SENTENCE).not.toBe(settings.text(CHECKOUT_SENTENCE));
        expect(saveDisabled()).toBe(true);
        expect(reason()).toBe(settings.text(REMINDER_SENTENCE));
    });

    it('offers start times as a select after the reminder lead, every interval dividing the hour at the 5-minute granularity, labelled in minutes, with its help', () => {
        render(propsWith());

        const labels = Array.from(document.querySelectorAll('.admin-section--booking-rules .admin-field__label')).map((label) => label.textContent);
        expect(labels).toEqual((['booking_rules.checkout', 'booking_rules.approval', 'booking_rules.payment', 'booking_rules.reminder', 'booking_rules.start_interval'] as const).map((key) => settings.text(key)));
        expect(document.querySelector('#settings-start-interval')?.tagName).toBe('SELECT');
        expect(value('#settings-start-interval')).toBe('15');
        expect(intervals()).toEqual([
            ['5', settings.text('booking_rules.start_option', { n: '5' })],
            ['10', settings.text('booking_rules.start_option', { n: '10' })],
            ['15', settings.text('booking_rules.start_option', { n: '15' })],
            ['20', settings.text('booking_rules.start_option', { n: '20' })],
            ['30', settings.text('booking_rules.start_option', { n: '30' })],
            ['60', settings.text('booking_rules.start_option', { n: '60' })],
        ]);
        expect(text('#settings-start-interval-help')).toBe(settings.text('booking_rules.start_interval_help'));
        expect(document.querySelector('#settings-start-interval')?.getAttribute('aria-describedby')).toBe('settings-start-interval-help');
    });

    it('offers only the intervals on a coarser granularity: at 10, the 10, 20, 30 and 60 minutes', () => {
        render(propsWith({ granularity: 10, values: { ...VALUES, start_interval_min: 30 } }));

        expect(intervals()).toEqual([
            ['10', settings.text('booking_rules.start_option', { n: '10' })],
            ['20', settings.text('booking_rules.start_option', { n: '20' })],
            ['30', settings.text('booking_rules.start_option', { n: '30' })],
            ['60', settings.text('booking_rules.start_option', { n: '60' })],
        ]);
        expect(value('#settings-start-interval')).toBe('30');
        expect(saveDisabled()).toBe(false);
    });

    it('states the rule under the field for a value off the granularity, holds Save, and clears once an offered interval is chosen', () => {
        render(propsWith({ granularity: 10 }));

        expect(text('#settings-start-interval-error')).toBe(startIntervalSentence(10, settings));
        expect(document.querySelector('#settings-start-interval')?.getAttribute('aria-invalid')).toBe('true');
        expect(saveDisabled()).toBe(true);
        expect(reason()).toBe(startIntervalSentence(10, settings));

        pick('#settings-start-interval', '20');
        expect(document.querySelector('#settings-start-interval-error')).toBeNull();
        expect(saveDisabled()).toBe(false);
    });
});

describe('Save', () => {
    it('sends the whole patch every time with no null, a reminder lead of zero included, no week among it, and re-seeds from the landed answer alone', () => {
        render(propsWith({ entitlements: [], roster: [IMPLICIT] }));
        type('#settings-currency', 'usd');
        type('#settings-reminder', '0');
        press(document.querySelector('.admin-settings-theme[data-value="rose"]'));
        act(() => document.querySelector<HTMLInputElement>('#settings-email-reminder')?.click());
        press(document.querySelector('.admin-settings-save'));

        const [url, body, visit] = lastVisit(inertia.put);
        expect(url).toBe('/admin/settings');
        expect(body).toEqual({ currency: 'USD', theme: 'rose', language: 'en', checkout_ttl_min: 15, approval_ttl_hours: 48, payment_ttl_hours: 24, reminder_lead_hours: 0, start_interval_min: 15, emails_off: ['mystery_key', 'reminder'], ...BLANK_PROFILE });
        expect(Object.values(body)).not.toContain(null);
        expect(body).not.toHaveProperty('rules');
        start(visit);
        expect(document.querySelector('.admin-settings-save')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-settings-save')).toContain(settings.text('save.saving'));
        expect(document.querySelector<HTMLButtonElement>('.admin-settings-hours-save')?.disabled).toBe(true);
        expect(text('.admin-settings-hours__wait')).toBe(settings.text('waits.settings'));

        // A background refetch carrying the old row leaves the edit alone; the landed save
        // re-seeds.
        render(propsWith({ entitlements: [], roster: [IMPLICIT] }));
        expect(value('#settings-currency')).toBe('USD');
        const saved: Values = { currency: 'USD', theme: 'rose', language: 'en', checkout_ttl_min: 15, approval_ttl_hours: 48, payment_ttl_hours: 24, reminder_lead_hours: 0, start_interval_min: 15, emails_off: ['mystery_key', 'reminder'], ...NO_PROFILE };
        land(visit, propsWith({ entitlements: [], roster: [IMPLICIT], values: saved }));
        expect(value('#settings-currency')).toBe('USD');
        expect(value('#settings-reminder')).toBe('0');
        expect(document.querySelector('.admin-settings-theme--chosen')?.getAttribute('data-value')).toBe('rose');
        expect(document.querySelector<HTMLInputElement>('#settings-email-reminder')?.checked).toBe(false);
        expect(document.querySelector('.admin-settings-save')?.getAttribute('aria-busy')).toBeNull();
    });

    it('sends a chosen start interval as a number in the whole patch, every other field beside it, and re-seeds it from the landed answer', () => {
        render(propsWith());
        pick('#settings-start-interval', '30');
        press(document.querySelector('.admin-settings-save'));

        const [url, body, visit] = lastVisit(inertia.put);
        expect(url).toBe('/admin/settings');
        expect(body).toEqual({ currency: 'EUR', theme: 'violet', language: 'en', checkout_ttl_min: 15, approval_ttl_hours: 48, payment_ttl_hours: 24, reminder_lead_hours: 24, start_interval_min: 30, emails_off: ['mystery_key'], ...BLANK_PROFILE });
        start(visit);
        land(visit, propsWith({ values: { ...VALUES, start_interval_min: 60 } }));
        expect(value('#settings-start-interval')).toBe('60');
    });

    it('re-seeds the settings fields alone: an unsaved week edit and a chosen file survive a landed settings save', () => {
        render(propsWith({ entitlements: [], roster: [IMPLICIT] }));
        press(document.querySelector('.admin-weekly__day[data-weekday="5"] .admin-weekly__add'));
        expect(fridayWindows()).toBe(1);
        choose(new File(['png bytes'], 'logo.png', { type: 'image/png' }));
        expect(uploadDisabled()).toBe(false);
        type('#settings-currency', 'usd');

        press(document.querySelector('.admin-settings-save'));
        const [url, , visit] = lastVisit(inertia.put);
        expect(url).toBe('/admin/settings');
        start(visit);
        // The server's answer carries a checkout hold the form never typed: the re-seed is what
        // puts it there.
        land(visit, propsWith({ entitlements: [], roster: [IMPLICIT], values: { ...VALUES, currency: 'USD', checkout_ttl_min: 20 } }));

        expect(value('#settings-currency')).toBe('USD');
        expect(value('#settings-checkout')).toBe('20');
        expect(fridayWindows()).toBe(1);
        expect(uploadDisabled()).toBe(false);
        expect(inertia.put).toHaveBeenCalledTimes(1);

        // The week edit is what the hours Save then sends: nothing was lost to the settings save.
        press(document.querySelector('.admin-settings-hours-save'));
        const [hoursUrl, hoursBody] = lastVisit(inertia.put);
        expect(hoursUrl).toBe('/admin/opening-hours');
        expect(hoursBody).toEqual({ rules: [...WEEK, { weekday: 5, start: '09:00', end: '17:00' }] });
    });

    it('leaves the settings fields\' edits alone when the hours save and the upload land, the mirror', () => {
        render(propsWith({ entitlements: [], roster: [IMPLICIT] }));
        type('#settings-currency', 'usd');
        type('#settings-reminder', '0');

        press(document.querySelector('.admin-settings-hours-save'));
        const [hoursUrl, , hoursVisit] = lastVisit(inertia.put);
        expect(hoursUrl).toBe('/admin/opening-hours');
        start(hoursVisit);
        // A row refetched with the hours carries a checkout hold the fields never see: no re-seed
        // here.
        land(hoursVisit, propsWith({ entitlements: [], roster: [IMPLICIT], values: { ...VALUES, checkout_ttl_min: 20 } }));
        expect(value('#settings-currency')).toBe('USD');
        expect(value('#settings-reminder')).toBe('0');
        expect(value('#settings-checkout')).toBe('15');

        choose(new File(['png bytes'], 'logo.png', { type: 'image/png' }));
        press(document.querySelector('.admin-settings-logo-upload'));
        const [uploadUrl, , uploadVisit] = lastVisit(inertia.post);
        expect(uploadUrl).toBe('/admin/settings/logo');
        start(uploadVisit);
        land(uploadVisit, propsWith({ entitlements: [], roster: [IMPLICIT], values: { ...VALUES, checkout_ttl_min: 20 }, logo: { name: 'logo-0123456789abcdef.png', url: 'http://luna.localhost:8000/logo/logo-0123456789abcdef.png' } }));
        expect(value('#settings-currency')).toBe('USD');
        expect(value('#settings-reminder')).toBe('0');
        expect(value('#settings-checkout')).toBe('15');
        expect(uploadDisabled()).toBe(true);
        expect(saveDisabled()).toBe(false);
    });

    it('holds a submit while any check fails, the disabled Save and the handler alike: nothing is sent and focus stays where it was', () => {
        render(propsWith());
        type('#settings-reminder', '-1');
        type('#settings-currency', 'us');
        const copy = document.querySelector<HTMLButtonElement>('.admin-settings-copy');
        copy?.focus();

        expect(saveDisabled()).toBe(true);

        submitSettings();

        expect(inertia.put).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(copy);
    });

    it('shows a 400 as its sentence in an error toast and keeps the edits', () => {
        render(propsWith());
        type('#settings-approval', '72');
        press(document.querySelector('.admin-settings-save'));
        const [, , visit] = lastVisit(inertia.put);
        start(visit);
        fail(visit, { status: 400, data: { code: 'invalid_request', data: { detail: 'refused-approval-hold' } } });

        expect(text('.admin-toast')).toContain('refused-approval-hold');
        expect(value('#settings-approval')).toBe('72');
        expect(saveDisabled()).toBe(false);
    });

    it('shows a refused start interval as its sentence in an error toast and keeps the choice', () => {
        render(propsWith());
        pick('#settings-start-interval', '20');
        press(document.querySelector('.admin-settings-save'));
        const [, , visit] = lastVisit(inertia.put);
        start(visit);
        fail(visit, { status: 400, data: { code: 'invalid_request', data: { detail: 'refused-start-interval' } } });

        expect(text('.admin-toast')).toContain('refused-start-interval');
        expect(value('#settings-start-interval')).toBe('20');
        expect(saveDisabled()).toBe(false);
    });
});

describe('Emails', () => {
    it('renders seventeen checkboxes, as many as the catalog has, ticked when the key is absent from the off list, unticking appends and ticking removes, a key it does not know kept', () => {
        render(propsWith({ values: { ...VALUES, emails_off: ['mystery_key', 'reminder'] } }));
        const ticks = document.querySelectorAll<HTMLInputElement>('.admin-settings-email__tick');
        expect(ticks).toHaveLength(17);
        expect(Array.from(ticks).filter((tick) => !tick.checked).map((tick) => tick.id)).toEqual(['settings-email-reminder']);
        expect(text('label[for="settings-email-reminder"]')).toContain('Message reminder');

        act(() => document.querySelector<HTMLInputElement>('#settings-email-reminder')?.click());
        press(document.querySelector('.admin-settings-save'));
        expect(lastVisit(inertia.put)[1].emails_off).toEqual(['mystery_key']);

        act(() => document.querySelector<HTMLInputElement>('#settings-email-reminder')?.click());
        act(() => document.querySelector<HTMLInputElement>('#settings-email-confirmed')?.click());
        press(document.querySelector('.admin-settings-save'));
        expect(lastVisit(inertia.put)[1].emails_off).toEqual(['mystery_key', 'reminder', 'confirmed']);
        expect(document.querySelectorAll('.admin-settings-email__tick:disabled')).toHaveLength(0);
        expect(document.querySelector('.admin-settings-email__locked')).toBeNull();
    });

    it('tags the late payment as the owners\', every other business message as the business\'s and every customer message as the customer\'s, each in the emails section\'s words', () => {
        render(propsWith());
        const emails = keyedEmails();
        const tags = Object.fromEntries(Array.from(document.querySelectorAll('.admin-settings-email')).map((row) => [row.querySelector('.admin-settings-email__tick')?.id.replace('settings-email-', ''), row.querySelector('.admin-settings-email__audience')?.textContent]));

        expect(Object.keys(tags)).toHaveLength(17);
        expect(document.querySelector('.admin-settings-emails')?.className).toContain('sm:grid-cols-2');
        expect(tags).toEqual(Object.fromEntries(MESSAGES.map((message) => [message.key, emails.text(message.key === 'late_payment' ? 'audience.owners' : message.audience === 'business' ? 'audience.business' : 'audience.customer')])));
    });

    it('renders a message held on checked and disabled whatever the off list holds, with its one sentence bound to it, sends the list as it holds it, and leaves every other message switchable', () => {
        render(propsWith({ messages: LOCKED_MESSAGES, values: { ...VALUES, emails_off: ['mystery_key', 'approved'] } }));
        const approved = document.querySelector<HTMLInputElement>('#settings-email-approved');
        const sentence = document.querySelector('.admin-settings-email__locked');

        expect(approved?.checked).toBe(true);
        expect(approved?.disabled).toBe(true);
        expect(document.querySelectorAll('.admin-settings-email__locked')).toHaveLength(1);
        expect(sentence?.textContent).toBe(keyedEmails().text(LOCKED_SENTENCE));
        expect(sentence?.className).toContain('admin-field__help text-xs');
        expect(sentence?.closest('.admin-settings-email')?.querySelector('.admin-settings-email__tick')?.id).toBe('settings-email-approved');
        expect(approved?.getAttribute('aria-describedby')).toBe(sentence?.id);
        expect(Array.from(document.querySelectorAll<HTMLInputElement>('.admin-settings-email__tick:disabled')).map((tick) => tick.id)).toEqual(['settings-email-approved']);

        act(() => approved?.click());
        expect(approved?.checked).toBe(true);
        act(() => document.querySelector<HTMLInputElement>('#settings-email-reminder')?.click());
        press(document.querySelector('.admin-settings-save'));
        expect(lastVisit(inertia.put)[1].emails_off).toEqual(['mystery_key', 'approved', 'reminder']);
    });
});

describe('Opening hours', () => {
    it('renders the weekly editor over the implicit week with its own Save, which sends the whole week to the route naming no member and re-seeds on the answer', () => {
        render(propsWith({ entitlements: [], roster: [IMPLICIT] }));
        expect(document.querySelectorAll('.admin-weekly__day')).toHaveLength(7);
        expect(document.querySelector('.admin-weekly__day[data-weekday="3"] .admin-weekly__start')?.getAttribute('value')).toBe('10:07');
        expect(document.querySelector('.admin-settings-roster')).toBeNull();

        press(document.querySelector('.admin-weekly__day[data-weekday="5"] .admin-weekly__add'));
        press(document.querySelector('.admin-settings-hours-save'));
        const [url, body, visit] = lastVisit(inertia.put);
        expect(url).toBe('/admin/opening-hours');
        expect(body).toEqual({ rules: [...WEEK, { weekday: 5, start: '09:00', end: '17:00' }] });
        expect(body).not.toHaveProperty('staff_id');
        start(visit);
        expect(document.querySelector('.admin-settings-hours-save')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-settings-hours-save')).toContain(settings.text('hours.saving'));
        expect(saveDisabled()).toBe(true);
        expect(reason()).toBe(settings.text('waits.hours'));

        land(visit, propsWith({ entitlements: [], roster: [IMPLICIT], week: [{ weekday: 5, start: '09:00', end: '17:00' }] }));
        expect(document.querySelector('.admin-weekly__day[data-weekday="1"] .admin-weekly__closed')).not.toBeNull();
        expect(document.querySelectorAll('.admin-weekly__day[data-weekday="5"] .admin-weekly__window')).toHaveLength(1);
    });

    it('renders the editor over the roster the server hands it when one active member remains, a member deactivated not counting, 6.3', () => {
        // The server filters the roster to active members, so a tenant of two members with one
        // inactive hands one row.
        render(propsWith({ entitlements: [], roster: [IMPLICIT] }));
        expect(headings()).toContain(settings.text('sections.opening_hours'));
        expect(document.querySelector('.admin-settings-roster')).toBeNull();
        expect(document.querySelector('.admin-weekly')).not.toBeNull();
        expect(document.querySelector('.admin-settings-hours-save')).not.toBeNull();
    });

    it('renders the read-only roster of every active member with their windows under 6.3\'s sentence when two remain, and the editor when the roster could not be read', () => {
        render(propsWith({ entitlements: [], roster: [IMPLICIT, ELENA] }));
        expect(headings()).toContain(settings.text('sections.opening_hours'));
        expect(text('.admin-settings-roster__notice')).toBe(settings.text(ROSTER_SENTENCE));
        expect(document.querySelectorAll('.admin-settings-roster__member')).toHaveLength(2);
        expect(Array.from(document.querySelectorAll('.admin-settings-roster__name')).map((name) => name.textContent)).toEqual(['Luna Hair Studio', 'Elena Papadaki']);
        expect(text('.admin-settings-roster__member:nth-child(2) .admin-settings-roster__day[data-weekday="2"]')).toBe(settings.text('hours.day', { day: weekday(2), windows: [settings.text('hours.window', { start: '09:00', end: '13:00' }), settings.text('hours.window', { start: '14:00', end: '18:00' })].join(settings.text('hours.separator')) }));
        expect(text('.admin-settings-roster__member:nth-child(1) .admin-settings-roster__day[data-weekday="3"]')).toBe(settings.text('hours.day', { day: weekday(3), windows: settings.text('hours.window', { start: '10:07', end: '16:00' }) }));
        expect(text('.admin-settings-roster__member:nth-child(1) .admin-settings-roster__day[data-weekday="7"]')).toBe(settings.text('hours.closed_day', { day: weekday(7) }));
        expect(document.querySelector('.admin-weekly')).toBeNull();
        expect(document.querySelector('.admin-settings-hours-save')).toBeNull();

        render(propsWith({ entitlements: [], roster: null }));
        expect(document.querySelector('.admin-settings-roster')).toBeNull();
        expect(document.querySelector('.admin-weekly')).not.toBeNull();
        expect(document.querySelector('.admin-settings-hours-save')).not.toBeNull();
    });
});

function texts(selector: string): string[] {
    return Array.from(document.querySelectorAll(selector)).map((element) => element.textContent ?? '');
}

/** The own-mode control of the provider whose name is given, reached through its row. */
function ownOf(name: string): Element | null {
    const row = Array.from(document.querySelectorAll('.admin-payments-provider')).find((candidate) => candidate.querySelector('.admin-payments-provider__name')?.textContent === name);

    return row?.querySelector('.admin-payments-own') ?? null;
}

/** The credential inputs on screen as id, type and value, in document order. */
function credentialInputs(): string[][] {
    return Array.from(document.querySelectorAll<HTMLInputElement>('.admin-payments-credentials input')).map((input) => [input.id, input.type, input.value]);
}

function fillTillpoint(): void {
    type('#payments-tillpoint-merchant_code', 'M-0042');
    type('#payments-tillpoint-api_token', 'tok_9f3c');
    type('#payments-tillpoint-signing_phrase', 'correct horse');
}

const TILLPOINT_BODY = { provider: 'tillpoint', credentials: { merchant_code: 'M-0042', api_token: 'tok_9f3c', signing_phrase: 'correct horse' } };

const GATE = 'gate-refusal-token';

/*
 * The payments section's words, keyed: each payments line read through the same table, a filled
 * sentence showing what was put into it.
 */
const payments = keyedPayments();

/** A cost sentence whole: the provider's rate at :rate, a figure at :fee where it states one. */
function paymentsCost(key: PaymentsKey, provider: string, fee?: string): string {
    return payments.text(key, { rate: payments.text('cost.rate', { provider }), ...(fee === undefined ? {} : { fee }) });
}

/** The figure of a fee of 1.5% and 25 cents, as the section spells it. */
const PAYMENTS_FIGURE = payments.text('cost.fee', { percent: formatPercent(1.5, payments.locale, false, 2), fixed: formatAmount(25, 'EUR', payments.locale) });

describe('Payments with nothing connected', () => {
    it('states the degrade line, each provider with its modes and what each costs as the prop states it, platform mode not offered, and the cash sentence', () => {
        render(propsWith());

        expect(text('.admin-payments-degrade')).toBe(payments.text(DEGRADE_SENTENCE));
        expect(document.querySelector('.admin-payments-degrade')?.getAttribute('role')).toBe('status');
        expect(texts('.admin-payments-provider__name')).toEqual(['Cardhouse', 'Tillpoint']);
        expect(texts('.admin-payments-provider[data-provider="tillpoint"] .admin-payments-mode__name')).toEqual([payments.text('modes.platform'), payments.text('modes.own')]);
        expect(texts('.admin-payments-provider[data-provider="tillpoint"] .admin-payments-mode__cost')).toEqual([paymentsCost('cost.unstated', 'Tillpoint'), paymentsCost('cost.own', 'Tillpoint')]);
        expect(texts('.admin-payments-mode--platform .admin-payments-mode__unoffered')).toEqual([payments.text('not_offered'), payments.text('not_offered')]);
        expect(document.querySelectorAll('.admin-payments-mode--own .admin-payments-mode__unoffered')).toHaveLength(0);
        expect(texts('.admin-payments-own')).toEqual([payments.text('buttons.own', { provider: 'Cardhouse' }), payments.text('buttons.own', { provider: 'Tillpoint' })]);
        expect(text('.admin-payments-cash')).toBe(payments.text(CASH_SENTENCE));
        expect(document.querySelector('.admin-payments-cash')?.className).toBe('admin-field__help text-xs text-muted-foreground admin-payments-cash');
        expect(document.querySelectorAll('.admin-payments input')).toHaveLength(0);
        expect(document.querySelector('.admin-payments-connection')).toBeNull();
    });

    it('offers platform mode\'s one button where the prop offers it, beside its share with the fee as configured and own mode\'s nothing, and none where it does not', () => {
        render(propsWith({ payments: { providers: [TWO_SECRETS, offered(THREE_FIELDS, { percent: '1.5', fixed_minor: 25, currency: 'EUR' })], connection: null } }));

        expect(texts('.admin-payments-mode--platform .admin-payments-mode__cost')).toEqual([paymentsCost('cost.unstated', 'Cardhouse'), paymentsCost('cost.figure', 'Tillpoint', PAYMENTS_FIGURE)]);
        expect(texts('.admin-payments-provider[data-provider="tillpoint"] .admin-payments-mode--own .admin-payments-mode__cost')).toEqual([paymentsCost('cost.own', 'Tillpoint')]);
        expect(texts('.admin-payments-provider[data-provider="cardhouse"] .admin-payments-mode--platform .admin-payments-mode__unoffered')).toEqual([payments.text('not_offered')]);
        expect(document.querySelectorAll('.admin-payments-provider[data-provider="cardhouse"] .admin-payments-mode--platform button')).toHaveLength(0);
        expect(document.querySelectorAll('.admin-payments-provider[data-provider="tillpoint"] .admin-payments-mode__unoffered')).toHaveLength(0);
        expect(texts('.admin-payments-platform')).toEqual([payments.text('buttons.platform', { provider: 'Tillpoint' })]);
    });

    it('states each mode\'s cost in one rule, the platform taking nothing at a zero fee and the figure where it is not zero, the provider\'s own processing rate linked to the rate it publishes', () => {
        render(propsWith({ payments: { providers: [offered(TWO_SECRETS, { percent: '0', fixed_minor: null, currency: 'EUR' }), offered(THREE_FIELDS, { percent: '1.5', fixed_minor: 25, currency: 'EUR' })], connection: null } }));

        expect(texts('.admin-payments-mode__cost')).toEqual([
            paymentsCost('cost.nothing', 'Cardhouse'),
            paymentsCost('cost.own', 'Cardhouse'),
            paymentsCost('cost.figure', 'Tillpoint', PAYMENTS_FIGURE),
            paymentsCost('cost.own', 'Tillpoint'),
        ]);

        const links = [...document.querySelectorAll<HTMLAnchorElement>('.admin-payments-mode__cost a.admin-payments-mode__rate')];

        expect(links.map((link) => link.textContent)).toEqual([payments.text('cost.rate', { provider: 'Cardhouse' }), payments.text('cost.rate', { provider: 'Cardhouse' }), payments.text('cost.rate', { provider: 'Tillpoint' }), payments.text('cost.rate', { provider: 'Tillpoint' })]);
        expect(links.map((link) => link.getAttribute('href'))).toEqual(['https://cardhouse.example/pricing', 'https://cardhouse.example/pricing', 'https://tillpoint.example/pricing', 'https://tillpoint.example/pricing']);
        expect(links.map((link) => [link.getAttribute('target'), link.getAttribute('rel')])).toEqual(Array(4).fill(['_blank', 'noreferrer']));
        expect(document.querySelectorAll('.admin-payments-mode__cost a')).toHaveLength(4);
    });

    it('posts the provider from platform mode\'s button, busy alone with every other write held until the location answer, and shows a refusal\'s sentence', () => {
        render(propsWith({ payments: { providers: [offered(TWO_SECRETS), offered(THREE_FIELDS)], connection: null } }));
        const tillpoint = '.admin-payments-provider[data-provider="tillpoint"] .admin-payments-platform';
        press(document.querySelector(tillpoint));

        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/payments/platform');
        expect(body).toEqual({ provider: 'tillpoint' });
        start(visit);
        expect(document.querySelector(tillpoint)?.getAttribute('aria-busy')).toBe('true');
        expect(text(tillpoint)).toContain(payments.text('buttons.opening'));
        expect(document.querySelector('.admin-payments-provider[data-provider="cardhouse"] .admin-payments-platform')?.getAttribute('aria-busy')).toBeNull();
        expect(document.querySelector<HTMLButtonElement>('.admin-payments-provider[data-provider="cardhouse"] .admin-payments-platform')?.disabled).toBe(true);
        expect(Array.from(document.querySelectorAll<HTMLButtonElement>('.admin-payments-own')).every((button) => button.disabled)).toBe(true);
        expect(text('.admin-payments__wait')).toBe(payments.text(PAYMENT_WAIT.platform));
        expect(reason()).toBe(payments.text(PAYMENT_WAIT.platform));
        press(document.querySelector(tillpoint));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        const taken = payments.text('refusals.taken_own', { provider: 'Tillpoint' });
        fail(visit, { status: 409, data: { code: 'connection_mode_taken', message: 'connection_mode_taken', data: { status: 409, detail: taken } } });
        expect(text('.admin-payments__refusal')).toBe(taken);
        expect(document.querySelector('.admin-toast')).toBeNull();
        expect(document.querySelector(tillpoint)?.getAttribute('aria-busy')).toBeNull();
        expect(document.querySelector('.admin-payments__wait')).toBeNull();

        press(document.querySelector(tillpoint));
        const [, , again] = lastVisit(inertia.post);
        start(again);
        const refused = payments.text('validation.not_platform');
        fail(again, { status: 400, data: { code: 'invalid_request', message: 'invalid_request', data: { status: 400, detail: refused } } });
        expect(text('.admin-payments__refusal')).toBe(refused);
        expect(document.querySelector('.admin-toast')).toBeNull();
    });

    it('holds every control from a location answer until the page goes, the pressed button busy with the wait sentence, so a press in that moment does nothing', () => {
        render(propsWith({ payments: { providers: [offered(TWO_SECRETS), offered(THREE_FIELDS)], connection: null } }));
        const tillpoint = '.admin-payments-provider[data-provider="tillpoint"] .admin-payments-platform';
        const cardhouse = '.admin-payments-provider[data-provider="cardhouse"] .admin-payments-platform';
        press(document.querySelector(tillpoint));

        const [, , visit] = lastVisit(inertia.post);
        start(visit);
        // The router answers a location by assigning the window's address and ending the visit
        // with no page, no success and no refusal: the page is going.
        act(() => visit.onFinish?.());

        expect(document.querySelector(tillpoint)?.getAttribute('aria-busy')).toBe('true');
        expect(text(tillpoint)).toContain(payments.text('buttons.opening'));
        expect(document.querySelector<HTMLButtonElement>(cardhouse)?.disabled).toBe(true);
        expect(Array.from(document.querySelectorAll<HTMLButtonElement>('.admin-payments-own')).every((button) => button.disabled)).toBe(true);
        expect(text('.admin-payments__wait')).toBe(payments.text(PAYMENT_WAIT.platform));
        expect(reason()).toBe(payments.text(PAYMENT_WAIT.platform));
        expect(saveDisabled()).toBe(true);
        expect(document.querySelector('.admin-toast')).toBeNull();

        press(document.querySelector(cardhouse));
        press(document.querySelector('.admin-payments-own'));
        submitSettings();
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.put).not.toHaveBeenCalled();
        expect(document.querySelector('.admin-payments-credentials')).toBeNull();
    });

    it.each([
        ['cancelled', { cancelled: true }],
        ['interrupted', { interrupted: true }],
    ])('gives the controls back where the router ended the visit as %s rather than following a location', (_, ended) => {
        render(propsWith({ payments: { providers: [offered(TWO_SECRETS), offered(THREE_FIELDS)], connection: null } }));
        const tillpoint = '.admin-payments-provider[data-provider="tillpoint"] .admin-payments-platform';
        press(document.querySelector(tillpoint));

        const [, , visit] = lastVisit(inertia.post);
        start(visit);
        act(() => visit.onFinish?.(ended));

        expect(document.querySelector(tillpoint)?.getAttribute('aria-busy')).toBeNull();
        expect(document.querySelector<HTMLButtonElement>(tillpoint)?.disabled).toBe(false);
        expect(Array.from(document.querySelectorAll<HTMLButtonElement>('.admin-payments-own')).every((button) => !button.disabled)).toBe(true);
        expect(document.querySelector('.admin-payments__wait')).toBeNull();
    });

    it('frees every control when the browser restores the page from its back/forward cache after a location answer, no navigation being in flight any more, and leaves the hold alone on a pageshow that restores nothing', () => {
        render(propsWith({ payments: { providers: [offered(TWO_SECRETS), offered(THREE_FIELDS)], connection: null } }));
        const tillpoint = '.admin-payments-provider[data-provider="tillpoint"] .admin-payments-platform';
        press(document.querySelector(tillpoint));

        const [, , visit] = lastVisit(inertia.post);
        start(visit);
        act(() => visit.onFinish?.());
        expect(document.querySelector(tillpoint)?.getAttribute('aria-busy')).toBe('true');

        // A fresh load fires pageshow too, persisted false: it restores no page and frees nothing.
        act(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: false })));
        expect(document.querySelector(tillpoint)?.getAttribute('aria-busy')).toBe('true');
        expect(saveDisabled()).toBe(true);

        // Back from the provider's page: the browser restores Settings as it was, the navigation
        // that held it long over, and says so with pageshow persisted.
        act(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
        expect(document.querySelector(tillpoint)?.getAttribute('aria-busy')).toBeNull();
        expect(document.querySelector<HTMLButtonElement>(tillpoint)?.disabled).toBe(false);
        expect(Array.from(document.querySelectorAll<HTMLButtonElement>('.admin-payments-own')).every((button) => !button.disabled)).toBe(true);
        expect(document.querySelector('.admin-payments__wait')).toBeNull();
        expect(saveDisabled()).toBe(false);

        press(document.querySelector(tillpoint));
        expect(inertia.post).toHaveBeenCalledTimes(2);
    });

    it('keeps the hold on a restore while a write is still in flight, which that write\'s own end releases', () => {
        render(propsWith({ payments: { providers: [offered(TWO_SECRETS), offered(THREE_FIELDS)], connection: null } }));
        const tillpoint = '.admin-payments-provider[data-provider="tillpoint"] .admin-payments-platform';
        press(document.querySelector(tillpoint));

        const [, , visit] = lastVisit(inertia.post);
        start(visit);
        act(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
        expect(document.querySelector(tillpoint)?.getAttribute('aria-busy')).toBe('true');

        act(() => visit.onFinish?.({ cancelled: true }));
        expect(document.querySelector(tillpoint)?.getAttribute('aria-busy')).toBeNull();
    });

    it('renders each provider\'s own-mode form from its descriptor alone, the same component for two secret fields and for three of another shape', () => {
        render(propsWith());

        press(ownOf('Cardhouse'));
        expect(text('.admin-payments-credentials__title')).toBe(payments.text('credentials.title', { provider: 'Cardhouse' }));
        expect(credentialInputs()).toEqual([
            ['payments-cardhouse-secret_key', 'password', ''],
            ['payments-cardhouse-webhook_secret', 'password', ''],
        ]);
        expect(document.querySelector('label[for="payments-cardhouse-secret_key"]')?.textContent).toBe('secret_key-label');
        expect(document.querySelector('#payments-cardhouse-secret_key')?.getAttribute('aria-describedby')).toBe('payments-cardhouse-secret_key-help');
        expect(text('#payments-cardhouse-secret_key-help')).toBe('secret_key-help');
        expect(document.querySelector('#payments-cardhouse-secret_key')?.getAttribute('autocomplete')).toBe('new-password');

        press(document.querySelector('.admin-payments-cancel'));
        expect(document.querySelector('.admin-payments-credentials')).toBeNull();

        press(ownOf('Tillpoint'));
        expect(document.querySelectorAll('.admin-payments-credentials')).toHaveLength(1);
        expect(credentialInputs()).toEqual([
            ['payments-tillpoint-merchant_code', 'text', ''],
            ['payments-tillpoint-api_token', 'password', ''],
            ['payments-tillpoint-signing_phrase', 'password', ''],
        ]);
        expect(document.querySelector('label[for="payments-tillpoint-merchant_code"]')?.textContent).toBe('merchant_code-label');
        expect(text('#payments-tillpoint-signing_phrase-help')).toBe('signing_phrase-help');
    });

    it('refuses a save missing a field in the server\'s words, marks the fields, moves focus to the first failing one once, and sends nothing', () => {
        render(propsWith());
        press(ownOf('Tillpoint'));
        type('#payments-tillpoint-merchant_code', 'M-0042');
        type('#payments-tillpoint-signing_phrase', 'correct horse');
        type('#payments-tillpoint-signing_phrase', '');

        expect(document.querySelectorAll('.admin-payments [aria-invalid="true"]')).toHaveLength(0);
        press(document.querySelector('.admin-payments-save'));

        expect(inertia.post).not.toHaveBeenCalled();
        expect(text('#payments-tillpoint-api_token-error')).toBe(payments.text('validation.missing', { field: 'api_token-label' }));
        expect(text('#payments-tillpoint-signing_phrase-error')).toBe(payments.text('validation.missing', { field: 'signing_phrase-label' }));
        expect(document.querySelector('#payments-tillpoint-merchant_code')?.hasAttribute('aria-invalid')).toBe(false);
        expect(document.activeElement?.id).toBe('payments-tillpoint-api_token');

        (document.querySelector('#payments-tillpoint-signing_phrase') as HTMLInputElement).focus();
        type('#payments-tillpoint-signing_phrase', 'correct horse');
        expect(document.activeElement?.id).toBe('payments-tillpoint-signing_phrase');
        expect(document.querySelector('#payments-tillpoint-signing_phrase-error')).toBeNull();
    });

    it('never lets Enter in a credential input submit the settings save: Enter runs the section\'s own save instead', () => {
        const submitted = vi.fn();
        render(propsWith());
        document.querySelector('form.admin-settings')?.addEventListener('submit', submitted);
        press(ownOf('Tillpoint'));
        fillTillpoint();

        const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        act(() => document.querySelector('#payments-tillpoint-api_token')?.dispatchEvent(enter));

        expect(enter.defaultPrevented).toBe(true);
        expect(submitted).not.toHaveBeenCalled();
        expect(inertia.put).not.toHaveBeenCalled();
        const [url, body] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/payments');
        expect(body).toEqual(TILLPOINT_BODY);
    });

    it('saves the whole set, busy with every other write held, and lands the connected state from the props', () => {
        render(propsWith());
        press(ownOf('Tillpoint'));
        fillTillpoint();
        press(document.querySelector('.admin-payments-save'));

        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/payments');
        expect(body).toEqual(TILLPOINT_BODY);
        start(visit);
        expect(document.querySelector('.admin-payments-save')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-payments-save')).toContain(payments.text('buttons.saving'));
        expect(saveDisabled()).toBe(true);
        expect(reason()).toBe(payments.text(PAYMENT_WAIT.connect));
        expect(text('.admin-payments__wait')).toBe(payments.text(PAYMENT_WAIT.connect));
        expect(document.querySelector<HTMLButtonElement>('.admin-payments-own')?.disabled).toBe(true);

        land(visit, propsWith({ payments: connected() }));
        expect(document.querySelector('.admin-payments-credentials')).toBeNull();
        expect(document.querySelector('.admin-payments-degrade')).toBeNull();
        expect(text('.admin-payments-state__sentence')).toBe(payments.text('states.active', { provider: 'Tillpoint' }));
        expect(saveDisabled()).toBe(false);
    });

    it('lands restricted with the provider\'s reason verbatim and Test connection offered again', () => {
        render(propsWith());
        press(ownOf('Tillpoint'));
        fillTillpoint();
        press(document.querySelector('.admin-payments-save'));
        const [, , visit] = lastVisit(inertia.post);
        start(visit);

        land(visit, propsWith({ payments: connected({ state: 'restricted', last_check_ok: false, requirements: { reason: 'The token [secret] was refused by Tillpoint.' } }) }));
        expect(text('.admin-payments-state__sentence')).toBe(payments.text('states.restricted', { provider: 'Tillpoint' }));
        expect(text('.admin-payments-reason')).toBe('The token [secret] was refused by Tillpoint.');
        expect(text('.admin-payments-checked')).toBe(payments.text('checked.failed', { when: '02/11/2026 08:00' }));
        expect(document.querySelector<HTMLButtonElement>('.admin-payments-test')?.disabled).toBe(false);
    });

    it('keeps the typed set on a refusal and shows its sentence in the section\'s polite notice: a racing save\'s 409 and a lapse\'s gate sentence', () => {
        render(propsWith());
        press(ownOf('Tillpoint'));
        fillTillpoint();
        press(document.querySelector('.admin-payments-save'));
        let [, , visit] = lastVisit(inertia.post);
        start(visit);
        const contended = payments.text('refusals.contended');
        fail(visit, { status: 409, data: { code: 'connection_contended', message: 'connection_contended', data: { status: 409, detail: contended } } });

        expect(text('.admin-payments__refusal')).toBe(contended);
        expect(document.querySelector('.admin-payments__refusal')?.getAttribute('role')).toBe('status');
        expect(document.querySelector('.admin-toast')).toBeNull();
        expect(value('#payments-tillpoint-api_token')).toBe('tok_9f3c');
        expect(document.querySelector('.admin-payments-save')?.getAttribute('aria-busy')).toBeNull();

        press(document.querySelector('.admin-payments-save'));
        [, , visit] = lastVisit(inertia.post);
        start(visit);
        fail(visit, { status: 403, data: { code: 'subscription_required', message: 'subscription_required', data: { status: 403, state: 'canceled', detail: GATE } } });
        expect(text('.admin-payments__refusal')).toBe(GATE);
        expect(value('#payments-tillpoint-merchant_code')).toBe('M-0042');
    });
});

describe('Payments with a connection', () => {
    it('leads with the connection from the prop: the provider, the mode, the state\'s sentence, the last test on the tenant\'s clock and the webhook URL', () => {
        render(propsWith({ payments: connected() }));

        expect(text('.admin-payments-connection__name')).toBe('Tillpoint');
        expect(text('.admin-payments-connection__mode')).toBe(payments.text('connected.own'));
        expect(text('.admin-payments-state__sentence')).toBe(payments.text('states.active', { provider: 'Tillpoint' }));
        expect(document.querySelector('.admin-payments-state')?.classList.contains('admin-notice--success')).toBe(true);
        expect(document.querySelector('.admin-payments-reason')).toBeNull();
        expect(text('.admin-payments-checked')).toBe(payments.text('checked.passed', { when: '02/11/2026 08:00' }));
        expect(text('.admin-payments-webhook__url')).toBe(WEBHOOK);
        expect(document.querySelector('.admin-payments-degrade')).toBeNull();
        expect(document.querySelectorAll('.admin-payments-provider')).toHaveLength(0);
        expect(text('.admin-payments-cash')).toBe(payments.text(CASH_SENTENCE));
        expect(texts('.admin-payments-controls button')).toEqual([payments.text('buttons.test'), payments.text('buttons.disable'), payments.text('buttons.replace'), payments.text('disconnect.button')]);
        expect(document.querySelector('.admin-payments-fee')).toBeNull();
        expect(document.querySelector('.admin-payments-due')).toBeNull();
    });

    it('changes the webhook URL only through a confirmation naming that the provider must be told the new address: Keep and Escape leave the token alone, Change URL posts, busy in the dialog with every other write held, and the dialog closes on the landed answer', () => {
        render(propsWith({ payments: connected() }));

        press(document.querySelector('.admin-payments-rotate'));
        expect(document.querySelector('.admin-payments-rotate-confirm')?.getAttribute('role')).toBe('dialog');
        expect(text('.admin-payments-rotate-confirm .admin-modal__title')).toBe(payments.text('webhook.confirm_title'));
        expect(text('.admin-payments-rotate-confirm__body')).toBe(
            payments.text('webhook.confirm_body', { provider: 'Tillpoint' }),
        );
        expect(inertia.post).not.toHaveBeenCalled();
        press(document.querySelector('.admin-payments-rotate-confirm__keep'));
        expect(document.querySelector('.admin-payments-rotate-confirm')).toBeNull();

        press(document.querySelector('.admin-payments-rotate'));
        key(document.activeElement, 'Escape');
        expect(document.querySelector('.admin-payments-rotate-confirm')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();
        expect(text('.admin-payments-webhook__url')).toBe(WEBHOOK);

        press(document.querySelector('.admin-payments-rotate'));
        press(document.querySelector('.admin-payments-rotate-confirm__change'));
        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/payments/rotate');
        expect(body).toEqual({});
        start(visit);
        expect(document.querySelector('.admin-payments-rotate-confirm__change')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-payments-rotate-confirm__change')).toContain(payments.text('webhook.changing'));
        expect(document.querySelector<HTMLButtonElement>('.admin-payments-rotate-confirm__keep')?.disabled).toBe(true);
        expect(text('.admin-payments__wait')).toBe(payments.text(PAYMENT_WAIT.rotate));
        expect(reason()).toBe(payments.text(PAYMENT_WAIT.rotate));
        for (const other of ['.admin-payments-test', '.admin-payments-disable', '.admin-payments-replace', '.admin-payments-disconnect']) {
            expect(document.querySelector<HTMLButtonElement>(other)?.disabled).toBe(true);
        }
        key(document.activeElement, 'Escape');
        expect(document.querySelector('.admin-payments-rotate-confirm')).not.toBeNull();

        const rotated = 'http://localhost:8000/webhooks/tillpoint/QW5vdGhlciB0b2tlbiBoZXJlIG5vdyBz';
        land(visit, propsWith({ payments: connected({ webhook_url: rotated }) }));
        expect(document.querySelector('.admin-payments-rotate-confirm')).toBeNull();
        expect(text('.admin-payments-webhook__url')).toBe(rotated);
        expect(document.querySelector('.admin-payments__wait')).toBeNull();
    });

    it('shows each stored secret as a mask and no input, a plain field never among them, and Replace opens every declared field empty and sends the whole set', () => {
        render(propsWith({ payments: connected() }));

        expect(texts('.admin-payments-secret__label')).toEqual(['api_token-label', 'signing_phrase-label']);
        expect(texts('.admin-payments-secret__mask')).toEqual(['********', '********']);
        expect(document.querySelectorAll('.admin-payments input')).toHaveLength(0);

        press(document.querySelector('.admin-payments-replace'));
        expect(text('.admin-payments-credentials__title')).toBe(payments.text('credentials.replace_title', { provider: 'Tillpoint' }));
        expect(text('.admin-payments-credentials__replace')).toBe(payments.text('credentials.replace'));
        expect(credentialInputs()).toEqual([
            ['payments-tillpoint-merchant_code', 'text', ''],
            ['payments-tillpoint-api_token', 'password', ''],
            ['payments-tillpoint-signing_phrase', 'password', ''],
        ]);
        expect(document.querySelector<HTMLButtonElement>('.admin-payments-replace')?.disabled).toBe(true);

        fillTillpoint();
        press(document.querySelector('.admin-payments-save'));
        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/payments');
        expect(body).toEqual(TILLPOINT_BODY);
        start(visit);
        land(visit, propsWith({ payments: connected({ last_checked_at: '2026-11-03T09:30:00+00:00' }) }));
        expect(document.querySelector('.admin-payments-credentials')).toBeNull();
        expect(text('.admin-payments-checked')).toBe(payments.text('checked.passed', { when: '03/11/2026 11:30' }));
    });

    it('copies the webhook URL and records no copy of the booking link', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        render(propsWith({ payments: connected() }));

        press(document.querySelector('.admin-payments-copy'));
        expect(writeText).toHaveBeenCalledWith(WEBHOOK);
        await act(async () => {
            await Promise.resolve();
        });
        expect(text('.admin-payments-copied')).toBe(shell.text(COPIED));
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it.each([
        ['.admin-payments-test', '/admin/settings/payments/test', payments.text('buttons.testing'), payments.text(PAYMENT_WAIT.test)],
        ['.admin-payments-disable', '/admin/settings/payments/disable', payments.text('buttons.disabling'), payments.text(PAYMENT_WAIT.disable)],
    ])('runs %s as its own body-less post, busy alone with every other write held, the answer arriving through the props', (selector, path, busyLabel, wait) => {
        render(propsWith({ payments: connected() }));
        press(document.querySelector(selector));

        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe(path);
        expect(body).toEqual({});
        start(visit);
        expect(document.querySelector(selector)?.getAttribute('aria-busy')).toBe('true');
        expect(text(selector)).toContain(busyLabel);
        expect(text('.admin-payments__wait')).toBe(wait);
        expect(reason()).toBe(wait);
        for (const other of ['.admin-payments-test', '.admin-payments-rotate', '.admin-payments-disable', '.admin-payments-replace', '.admin-payments-disconnect'].filter((candidate) => candidate !== selector)) {
            expect(document.querySelector<HTMLButtonElement>(other)?.disabled).toBe(true);
        }

        press(document.querySelector('.admin-payments-test'));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        land(visit, propsWith({ payments: connected({ state: 'disabled' }) }));
        expect(document.querySelector('.admin-payments__wait')).toBeNull();
        expect(text('.admin-payments-state__sentence')).toBe(payments.text('states.disabled'));
    });

    it('offers Switch back on for a disabled connection, keeping the failed test\'s reason, and switches it on', () => {
        render(propsWith({ payments: connected({ state: 'disabled', last_check_ok: false, requirements: { reason: 'Merchant suspended.' } }) }));

        expect(document.querySelector('.admin-payments-disable')).toBeNull();
        expect(text('.admin-payments-reason')).toBe('Merchant suspended.');
        press(document.querySelector('.admin-payments-enable'));
        const [url, , visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/payments/enable');
        start(visit);
        expect(text('.admin-payments-enable')).toContain(payments.text('buttons.enabling'));
        land(visit, propsWith({ payments: connected({ state: 'restricted', last_check_ok: false, requirements: { reason: 'Merchant suspended.' } }) }));
        expect(document.querySelector('.admin-payments-disable')).not.toBeNull();
    });

    it('disconnects behind a confirmation naming what stops, busy in the dialog, and lands the provider list', () => {
        render(propsWith({ payments: connected() }));

        press(document.querySelector('.admin-payments-disconnect'));
        expect(document.querySelector('.admin-payments-confirm')?.getAttribute('role')).toBe('dialog');
        expect(text('.admin-payments-confirm__body')).toBe(
            payments.text('disconnect.body'),
        );
        expect(inertia.delete).not.toHaveBeenCalled();
        press(document.querySelector('.admin-payments-confirm__keep'));
        expect(document.querySelector('.admin-payments-confirm')).toBeNull();

        press(document.querySelector('.admin-payments-disconnect'));
        press(document.querySelector('.admin-payments-confirm__disconnect'));
        const [url, , visit] = lastVisit(inertia.delete);
        expect(url).toBe('/admin/settings/payments');
        start(visit);
        expect(document.querySelector('.admin-payments-confirm__disconnect')?.getAttribute('aria-busy')).toBe('true');
        expect(document.querySelector<HTMLButtonElement>('.admin-payments-confirm__keep')?.disabled).toBe(true);

        land(visit, propsWith());
        expect(document.querySelector('.admin-payments-confirm')).toBeNull();
        expect(document.querySelector('.admin-payments-connection')).toBeNull();
        expect(text('.admin-payments-degrade')).toBe(payments.text(DEGRADE_SENTENCE));
    });

    it('words the framework\'s 404 in place where the connection went, and shows a lapse\'s gate sentence', () => {
        render(propsWith({ payments: connected() }));
        press(document.querySelector('.admin-payments-test'));
        let [, , visit] = lastVisit(inertia.post);
        start(visit);
        fail(visit, { status: 404, data: '<!doctype html><title>Not Found</title>' });
        expect(text('.admin-payments__refusal')).toBe(payments.text(GONE_SENTENCE));
        expect(document.querySelector('.admin-toast')).toBeNull();
        expect(document.querySelector('.admin-payments-test')?.getAttribute('aria-busy')).toBeNull();

        press(document.querySelector('.admin-payments-rotate'));
        expect(document.querySelector('.admin-payments-confirm__refusal')).toBeNull();
        press(document.querySelector('.admin-payments-rotate-confirm__change'));
        [, , visit] = lastVisit(inertia.post);
        start(visit);
        fail(visit, { status: 403, data: { code: 'subscription_required', message: 'subscription_required', data: { status: 403, state: 'canceled', detail: GATE } } });
        expect(text('.admin-payments__refusal')).toBe(GATE);
        expect(text('.admin-payments-rotate-confirm .admin-payments-confirm__refusal')).toBe(GATE);
        expect(document.querySelector('.admin-toast')).toBeNull();
        expect(document.querySelector('.admin-payments-rotate-confirm')).not.toBeNull();
    });

    it('words a Test connection pressed past the live calls\' limit as a warning notice with role status in the section, never an alert or a toast, clears it on the next press, and toasts a 5xx alone', () => {
        const limited = 'limited-token';
        render(propsWith({ payments: connected() }));
        press(document.querySelector('.admin-payments-test'));
        const [, , visit] = lastVisit(inertia.post);
        start(visit);
        fail(visit, { status: 429, data: { code: 'rate_limited', message: 'rate_limited', data: { status: 429, detail: limited } } });

        const notice = document.querySelector('.admin-payments__refusal');
        expect(notice?.getAttribute('role')).toBe('status');
        expect(notice?.textContent).toBe(limited);
        expect(document.querySelector('[role="alert"]')).toBeNull();
        expect(document.querySelector('.admin-toast')).toBeNull();
        expect(document.querySelector<HTMLButtonElement>('.admin-payments-test')?.disabled).toBe(false);

        press(document.querySelector('.admin-payments-disable'));
        expect(document.querySelector('.admin-payments__refusal')).toBeNull();
        expect(inertia.post).toHaveBeenCalledTimes(2);

        const [, , failing] = lastVisit(inertia.post);
        start(failing);
        fail(failing, { status: 500, data: '<!doctype html><title>Server Error</title>' });
        expect(document.querySelector('.admin-payments__refusal')).toBeNull();
        expect(document.querySelector('.admin-toast')?.getAttribute('role')).toBe('alert');
    });

    it('offers no test and no URL for a platform connection, which the provider answers for, and reads a pending row nobody has heard of yet', () => {
        render(propsWith({ payments: platformRow({ state: 'pending', last_checked_at: null, last_check_ok: null }) }));

        expect(text('.admin-payments-connection__mode')).toBe(payments.text('connected.platform'));
        expect(text('.admin-payments-state__sentence')).toBe(payments.text('states.pending', { provider: 'Tillpoint' }));
        expect(text('.admin-payments-checked')).toBe(payments.text('standing.never', { provider: 'Tillpoint' }));
        expect(document.querySelector('.admin-payments-webhook')).toBeNull();
        expect(document.querySelector('.admin-payments-secrets')).toBeNull();
        expect(document.querySelector('.admin-payments-platform')).toBeNull();
        expect(texts('.admin-payments-controls button')).toEqual([payments.text('buttons.resume'), payments.text('buttons.disable'), payments.text('disconnect.button')]);
    });

    it.each([
        ['pending', null, payments.text('states.pending', { provider: 'Tillpoint' }), payments.text('standing.unfinished', { provider: 'Tillpoint', when: '02/11/2026 08:00' }), [payments.text('buttons.resume'), payments.text('buttons.disable'), payments.text('disconnect.button')]],
        ['restricted', false, payments.text('states.restricted', { provider: 'Tillpoint' }), payments.text('standing.outstanding', { provider: 'Tillpoint', when: '02/11/2026 08:00' }), [payments.text('buttons.resume'), payments.text('buttons.disable'), payments.text('disconnect.button')]],
        ['active', true, payments.text('states.active', { provider: 'Tillpoint' }), payments.text('standing.taking', { provider: 'Tillpoint', when: '02/11/2026 08:00' }), [payments.text('buttons.disable'), payments.text('disconnect.button')]],
        ['disabled', false, payments.text('states.disabled'), payments.text('standing.outstanding', { provider: 'Tillpoint', when: '02/11/2026 08:00' }), [payments.text('buttons.enable'), payments.text('disconnect.button')]],
        ['disabled', true, payments.text('states.disabled'), payments.text('standing.taking', { provider: 'Tillpoint', when: '02/11/2026 08:00' }), [payments.text('buttons.enable'), payments.text('disconnect.button')]],
    ] as const)('reads a %s platform row\'s line (last check %s) as the onboarding\'s standing under the state\'s sentence, not a test\'s, offers resume only while unfinished or wanting, and never the platform button', (state, ok, opening, line, controls) => {
        render(propsWith({ payments: { ...platformRow({ state, last_check_ok: ok }), providers: [offered(TWO_SECRETS), offered(THREE_FIELDS)] } }));

        const sentence = document.querySelector('.admin-payments-state__sentence');
        const checked = document.querySelector('.admin-payments-checked');
        expect(sentence?.textContent?.startsWith(opening)).toBe(true);
        expect(checked?.textContent).toBe(line);
        expect(checked !== null && sentence !== null && (sentence.compareDocumentPosition(checked) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0).toBe(true);
        expect(texts('.admin-payments-controls button')).toEqual([...controls]);
        expect(document.querySelector('.admin-payments-platform')).toBeNull();
        expect(document.querySelector('.admin-payments-test')).toBeNull();
        expect(document.querySelector('.admin-payments-replace')).toBeNull();
        expect(text('.admin-payments-fee')).toBe(paymentsCost('cost.in_force', 'Tillpoint'));
        expect(document.querySelector('.admin-payments-due')).toBeNull();
    });

    it('states the fee in force on a row made through us from the prop, nothing at launch and the figure of a fee raised after connecting, the provider\'s own processing rate linked', () => {
        render(propsWith({ payments: platformRow({ state: 'active' }) }));

        expect(text('.admin-payments-fee')).toBe(paymentsCost('cost.in_force', 'Tillpoint'));
        const link = document.querySelector<HTMLAnchorElement>('.admin-payments-fee a.admin-payments-fee__rate');
        expect(link?.textContent).toBe(payments.text('cost.rate', { provider: 'Tillpoint' }));
        expect([link?.getAttribute('href'), link?.getAttribute('target'), link?.getAttribute('rel')]).toEqual(['https://tillpoint.example/pricing', '_blank', 'noreferrer']);

        render(propsWith({ payments: platformRow({ state: 'active', fee: { percent: '1.5', fixed_minor: 25, currency: 'EUR' } }) }));
        expect(text('.admin-payments-fee')).toBe(paymentsCost('cost.in_force_figure', 'Tillpoint', PAYMENTS_FIGURE));
    });

    it('says in one line that an active row\'s provider still needs information, in place of quoting what it lists, and links its page through the onboarding, busy alone with every other write held and held from the location answer', () => {
        const due = { currently_due: ['individual.verification.document'], past_due: [] };
        render(propsWith({ payments: platformRow({ state: 'active', requirements: due, information_due: true }) }));

        expect(text('.admin-payments-state__sentence')).toBe(payments.text('states.active', { provider: 'Tillpoint' }));
        expect(text('.admin-payments-due__sentence')).toBe(payments.text('information', { provider: 'Tillpoint' }));
        expect(document.querySelectorAll('.admin-payments-due')).toHaveLength(1);
        expect(document.querySelector('.admin-payments-reason')).toBeNull();
        expect(document.querySelector('.admin-payments-resume')).toBeNull();
        expect(text('.admin-payments-due__open')).toBe(payments.text('give', { provider: 'Tillpoint' }));

        press(document.querySelector('.admin-payments-due__open'));
        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/payments/platform/resume');
        expect(body).toEqual({});
        start(visit);
        expect(document.querySelector('.admin-payments-due__open')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-payments-due__open')).toContain(payments.text('buttons.opening'));
        expect(text('.admin-payments__wait')).toBe(payments.text(PAYMENT_WAIT.resume));
        for (const other of ['.admin-payments-disable', '.admin-payments-disconnect']) {
            expect(document.querySelector<HTMLButtonElement>(other)?.disabled).toBe(true);
        }

        act(() => visit.onFinish?.());
        expect(document.querySelector('.admin-payments-due__open')?.getAttribute('aria-busy')).toBe('true');
        press(document.querySelector('.admin-payments-disable'));
        expect(inertia.post).toHaveBeenCalledTimes(1);
    });

    it('carries no information line on an active row whose provider lists nothing, and no link where this build no longer registers the provider', () => {
        render(propsWith({ payments: platformRow({ state: 'active', requirements: null, information_due: false }) }));
        expect(document.querySelector('.admin-payments-due')).toBeNull();

        render(propsWith({ payments: { ...platformRow({ state: 'active', requirements: { currently_due: ['external_account'] }, information_due: true }), providers: [TWO_SECRETS] } }));
        expect(text('.admin-payments-due__sentence')).toBe(payments.text('information', { provider: 'Tillpoint' }));
        expect(document.querySelector('.admin-payments-due__open')).toBeNull();
    });

    it('shows what the provider says is outstanding on a restricted platform row verbatim, a requirements object with no reason as its JSON', () => {
        const outstanding = { currently_due: ['external_account', 'individual.verification.document'], disabled_reason: 'requirements.past_due', past_due: ['external_account'] };
        render(propsWith({ payments: platformRow({ state: 'restricted', last_check_ok: false, requirements: outstanding }) }));

        expect(text('.admin-payments-state__sentence')).toBe(payments.text('states.restricted', { provider: 'Tillpoint' }));
        expect(text('.admin-payments-reason')).toBe(JSON.stringify(outstanding));
        expect(document.querySelector('.admin-payments-state .admin-payments-reason')?.tagName).toBe('Q');
    });

    it('resumes the onboarding of a restricted row, busy alone with every other write held, and words the 404 where nothing is left to resume', () => {
        render(propsWith({ payments: platformRow({ state: 'restricted', last_check_ok: false, requirements: { outstanding: ['bank_account'] } }) }));
        press(document.querySelector('.admin-payments-resume'));

        const [url, body, visit] = lastVisit(inertia.post);
        expect(url).toBe('/admin/settings/payments/platform/resume');
        expect(body).toEqual({});
        start(visit);
        expect(document.querySelector('.admin-payments-resume')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-payments-resume')).toContain(payments.text('buttons.opening'));
        expect(text('.admin-payments__wait')).toBe(payments.text(PAYMENT_WAIT.resume));
        for (const other of ['.admin-payments-disable', '.admin-payments-disconnect']) {
            expect(document.querySelector<HTMLButtonElement>(other)?.disabled).toBe(true);
        }
        press(document.querySelector('.admin-payments-resume'));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        fail(visit, { status: 404, data: '<!doctype html><title>Not Found</title>' });
        expect(text('.admin-payments__refusal')).toBe(payments.text(GONE_SENTENCE));
        expect(document.querySelector('.admin-payments-resume')?.getAttribute('aria-busy')).toBeNull();
    });

    it('shows a resume the platform cannot serve, its keys unset, as the server\'s worded refusal and never as a connection gone', () => {
        const unavailable = payments.text('refusals.unavailable');
        render(propsWith({ payments: platformRow({ state: 'pending', last_checked_at: null, last_check_ok: null }) }));
        press(document.querySelector('.admin-payments-resume'));
        const [, , visit] = lastVisit(inertia.post);
        start(visit);
        fail(visit, { status: 409, data: { code: 'platform_unavailable', message: 'platform_unavailable', data: { status: 409, detail: unavailable } } });

        expect(text('.admin-payments__refusal')).toBe(unavailable);
        expect(document.querySelector('.admin-toast')).toBeNull();
        expect(document.querySelector<HTMLButtonElement>('.admin-payments-resume')?.disabled).toBe(false);
    });
});

const ARCHIVE = 'luna-2026-11-02.zip';

/** The archive the export answers, as the server sends it. */
function archived(): Response {
    return new Response('PK-archive', { status: 200, headers: { 'Content-Type': 'application/zip', 'Content-Disposition': `attachment; filename=${ARCHIVE}` } });
}

/** Lets the fetch's answer and every step after it run, inside act. */
async function settle(): Promise<void> {
    for (let turn = 0; turn < 3; turn++) {
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });
    }
}

describe('Danger zone', () => {
    let answer: (response: Response) => void;
    let refuse: (failure: Error) => void;
    let fetched: ReturnType<typeof vi.fn>;
    let saved: { href: string; download: string }[];
    const created = vi.fn((_file: Blob) => 'blob:archive');
    const revoked = vi.fn();

    beforeEach(() => {
        fetched = vi.fn(
            () =>
                new Promise<Response>((resolve, reject) => {
                    answer = resolve;
                    refuse = reject;
                }),
        );
        vi.stubGlobal('fetch', fetched);
        saved = [];
        created.mockClear();
        revoked.mockClear();
        Object.defineProperty(globalThis.URL, 'createObjectURL', { value: created, configurable: true });
        Object.defineProperty(globalThis.URL, 'revokeObjectURL', { value: revoked, configurable: true });
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function click(this: HTMLAnchorElement) {
            saved.push({ href: this.getAttribute('href') ?? '', download: this.download });
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('says what the archive holds beside Export everything, a secondary button that fetches nothing until pressed, and renders for no viewer without manageSettings', () => {
        render(propsWith());
        const button = document.querySelector('.admin-settings-danger .admin-settings-export');

        expect(text('#section-danger')).toBe(billing.text('danger.title'));
        expect(text('.admin-settings-danger__what')).toBe(billing.text(EXPORT_WHAT));
        expect(button?.textContent).toBe(billing.text(EXPORT_EVERYTHING));
        expect(button?.className).toContain('admin-button--secondary');
        expect(button?.className).not.toContain('admin-button--destructive');
        expect(document.querySelector('.admin-settings-danger')?.className).toContain('border-destructive');
        expect(fetched).not.toHaveBeenCalled();

        render(propsWith({ permissions: ['viewBookings', 'manageBookings'] }));

        expect(document.querySelector('.admin-section--danger')).toBeNull();
        expect(headings()).toEqual([settings.text('sections.general'), settings.text('sections.booking_page'), settings.text('sections.booking_rules'), settings.text('sections.payments'), settings.text('sections.emails')]);
    });

    it('is busy while the request is on its way, asking for JSON, then hands the zip to the browser under the name the server gave it, leaving the form as it was', async () => {
        render(propsWith());
        type('#settings-currency', 'usd');
        press(document.querySelector('.admin-settings-export'));

        expect(fetched).toHaveBeenCalledTimes(1);
        expect(fetched.mock.lastCall?.[0]).toBe(EXPORT_PATH);
        expect(fetched.mock.lastCall?.[1]).toMatchObject({ headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        expect(document.querySelector('.admin-settings-export')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-settings-export')).toContain(billing.text('danger.export.busy'));
        expect(document.querySelector('.admin-settings-export')?.hasAttribute('disabled')).toBe(true);

        // A second press while busy asks nothing more, and nothing was submitted or saved.
        press(document.querySelector('.admin-settings-export'));
        expect(fetched).toHaveBeenCalledTimes(1);
        expect(inertia.put).not.toHaveBeenCalled();
        expect(saved).toEqual([]);

        answer(archived());
        await settle();

        expect(created).toHaveBeenCalledTimes(1);
        expect(await created.mock.lastCall?.[0].text()).toBe('PK-archive');
        expect(saved).toEqual([{ href: 'blob:archive', download: ARCHIVE }]);
        expect(revoked).toHaveBeenCalledWith('blob:archive');
        expect(document.querySelector('a[href="blob:archive"]')).toBeNull();
        expect(document.querySelector('.admin-settings-export')?.getAttribute('aria-busy')).toBeNull();
        expect(text('.admin-settings-export')).toBe(billing.text(EXPORT_EVERYTHING));
        expect(document.querySelector('.admin-toast')).toBeNull();
        expect(value('#settings-currency')).toBe('USD');
    });

    it('answers a refusal with the envelope\'s sentence where the server worded one and its own otherwise, and saves nothing from a sign-in page, a JSON file or no answer at all', async () => {
        render(propsWith());
        const toasts = () => [...document.body.querySelectorAll('.admin-toast')].map((toast) => toast.textContent);

        press(document.querySelector('.admin-settings-export'));
        answer(new Response(JSON.stringify({ code: 'forbidden', message: 'forbidden', data: { status: 403, detail: 'You do not have permission to do that.' } }), { status: 403, headers: { 'Content-Type': 'application/json' } }));
        await settle();

        expect(document.querySelector('.admin-toast')?.getAttribute('role')).toBe('alert');
        expect(toasts().at(-1)).toContain('You do not have permission to do that.');

        press(document.querySelector('.admin-settings-export'));
        answer(new Response('<!doctype html><html><body><h1>Sign in</h1></body></html>', { status: 200, headers: { 'Content-Type': 'text/html; charset=UTF-8' } }));
        await settle();

        expect(toasts().at(-1)).toContain(billing.text(EXPORT_FAILED));

        press(document.querySelector('.admin-settings-export'));
        answer(new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json', 'Content-Disposition': 'attachment; filename=customer.json' } }));
        await settle();

        press(document.querySelector('.admin-settings-export'));
        refuse(new TypeError('Failed to fetch'));
        await settle();

        expect(toasts()).toHaveLength(4);
        expect(toasts().slice(1)).toEqual([expect.stringContaining(billing.text(EXPORT_FAILED)), expect.stringContaining(billing.text(EXPORT_FAILED)), expect.stringContaining(billing.text(EXPORT_FAILED))]);
        expect(saved).toEqual([]);
        expect(created).not.toHaveBeenCalled();
        expect(document.querySelector('.admin-settings-export')?.getAttribute('aria-busy')).toBeNull();
    });
});

describe('Danger zone close', () => {
    const CLOSING = 'closing-notice-token';

    function lastClose(): [string, Record<string, unknown>, Visit] {
        const call = inertia.post.mock.lastCall;

        if (call === undefined) {
            throw new Error('No close was posted.');
        }

        return call as [string, Record<string, unknown>, Visit];
    }

    function toasts(): (string | null)[] {
        return [...document.body.querySelectorAll('.admin-toast')].map((toast) => toast.textContent);
    }

    it('sits under the export with one sentence beside a danger button, and opens the confirm saying the window, the day in the tenant\'s zone, what keeps running, what pauses, that billing stops and the way back, posting nothing', () => {
        render(propsWith());
        const close = document.querySelector('.admin-settings-danger .admin-settings-close__button');
        const exported = document.querySelector('.admin-settings-export');

        expect(text('.admin-settings-close__what')).toBe(billing.choice(CLOSE_WHAT, 30, { count: 'thirty-token' }));
        expect(close?.textContent).toBe(billing.text(CLOSE_ACCOUNT));
        expect(close?.className).toContain('admin-button--destructive');
        expect(exported !== null && close !== null && (exported.compareDocumentPosition(close) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0).toBe(true);
        expect(document.querySelector('.admin-close-prompt')).toBeNull();

        press(close);

        expect(text('.admin-modal__title')).toBe(billing.text(CLOSE_TITLE));
        expect(texts('.admin-close-prompt .admin-modal__body p')).toEqual([
            billing.choice(CLOSE_WHEN, 30, { count: 'thirty-token', day: 'day-token', zone: 'Europe/Athens' }),
            billing.text(CLOSE_KEEPS),
            billing.text(CLOSE_PAUSES),
            billing.text(CLOSE_BILLING),
            OPEN.way_back,
        ]);
        expect(document.activeElement?.className).toContain('admin-close-prompt__keep');
        expect(text('.admin-close-prompt__confirm')).toBe(billing.text('danger.close.confirm'));
        expect(inertia.post).not.toHaveBeenCalled();

        press(document.querySelector('.admin-close-prompt__keep'));

        expect(document.querySelector('.admin-close-prompt')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('offers no close to a support session, whose close the server refuses, the export still there, while the owner keeps the button', () => {
        render({ ...propsWith(), support: { operator: 'Operator Name', ends_at: '2026-11-11T10:00:00+00:00' } });

        expect(document.querySelector('.admin-settings-export')).not.toBeNull();
        expect(document.querySelector('.admin-settings-close')).toBeNull();
        expect(document.querySelector('.admin-close-prompt')).toBeNull();

        render({ ...propsWith(), support: null });

        expect(document.querySelector('.admin-settings-close__button')?.textContent).toBe(billing.text(CLOSE_ACCOUNT));
    });

    it('names the day and the way back the server hands it, a free tenant\'s support among them, whatever day the browser keeps', () => {
        render(propsWith({ close: { ...OPEN, day: 'other-day-token', way_back: 'support-token' } }));
        press(document.querySelector('.admin-settings-close__button'));

        expect(text('.admin-close-prompt__when')).toBe(billing.choice(CLOSE_WHEN, 30, { count: 'thirty-token', day: 'other-day-token', zone: 'Europe/Athens' }));
        expect(text('.admin-close-prompt__way-back')).toBe('support-token');
    });

    it('posts the close with no body once confirmed, busy and undismissable until the server answers, nothing on the page changed meanwhile', () => {
        render(propsWith());
        press(document.querySelector('.admin-settings-close__button'));
        press(document.querySelector('.admin-close-prompt__confirm'));

        const [url, body, visit] = lastClose();

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(url).toBe(CLOSE_PATH);
        expect(body).toEqual({});

        act(() => visit.onStart?.());

        expect(document.querySelector('.admin-close-prompt__confirm')?.getAttribute('aria-busy')).toBe('true');
        expect(text('.admin-close-prompt__confirm')).toContain(billing.text('danger.close.busy'));
        expect(document.querySelector('.admin-close-prompt__keep')?.hasAttribute('disabled')).toBe(true);
        expect(text('.admin-close-prompt__pending')).toBe(billing.text('danger.close.pending'));
        expect(text('.admin-settings-close__button')).toBe(billing.text(CLOSE_ACCOUNT));

        press(document.querySelector('.admin-close-prompt__keep'));
        expect(document.querySelector('.admin-close-prompt')).not.toBeNull();

        act(() => visit.onSuccess?.({ props: {} }));
        act(() => visit.onFinish?.());

        expect(document.querySelector('.admin-close-prompt')).toBeNull();
        expect(inertia.reload).not.toHaveBeenCalled();
        expect(inertia.put).not.toHaveBeenCalled();
    });

    it('answers a refused close with its sentence in an error toast, the dialog left as it stood, then reads the close prop again, whose closing sentence takes the control\'s place', () => {
        render(propsWith());
        press(document.querySelector('.admin-settings-close__button'));
        press(document.querySelector('.admin-close-prompt__confirm'));
        const visit = lastClose()[2];
        const event = new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 409, data: { code: 'already_closing', message: 'already_closing', data: { status: 409, detail: 'already-closing-token' } } } } });

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('invalid')?.(event));
        act(() => visit.onFinish?.());

        expect(event.defaultPrevented).toBe(true);
        expect(document.querySelector('.admin-toast')?.getAttribute('role')).toBe('alert');
        expect(toasts().at(-1)).toContain('already-closing-token');
        expect(document.querySelector('.admin-close-prompt')).not.toBeNull();
        expect(inertia.reload).toHaveBeenCalledTimes(1);
        expect(inertia.reload.mock.lastCall?.[0]).toMatchObject({ only: ['close'] });

        render(propsWith({ close: { ...OPEN, notice: CLOSING } }));

        expect(text('.admin-settings-close__closing')).toBe(CLOSING);
        expect(document.querySelector('.admin-settings-close__button')).toBeNull();
        expect(document.querySelector('.admin-close-prompt')).toBeNull();
    });

    it('words a close that got no answer in its own sentence', () => {
        render(propsWith());
        press(document.querySelector('.admin-settings-close__button'));
        press(document.querySelector('.admin-close-prompt__confirm'));
        const visit = lastClose()[2];
        const event = new CustomEvent('exception', { cancelable: true, detail: { exception: new Error('offline') } });

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(event));
        act(() => visit.onFinish?.());

        expect(toasts().at(-1)).toContain(billing.text(CLOSE_FAILED));
    });

    it('shows the server\'s closing sentence in place of the control while the account is closing, with nothing to open, the export still offered', () => {
        render(propsWith({ close: { ...OPEN, notice: CLOSING } }));

        expect(text('.admin-settings-close__closing')).toBe(CLOSING);
        expect(document.querySelector('.admin-settings-close__button')).toBeNull();
        expect(document.querySelector('.admin-settings-close__what')).toBeNull();
        expect(text('.admin-settings-export')).toBe(billing.text(EXPORT_EVERYTHING));
    });
});
