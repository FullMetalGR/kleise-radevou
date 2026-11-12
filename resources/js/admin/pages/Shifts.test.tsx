import type { ReactNode } from 'react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, expectTypeOf, it, vi } from 'vitest';
import { drag, key, mount, type Mounted, press } from '@/components/ui/harness';
import { formatCount, formatDayMonth } from '@/lib/format';
import { hueOf } from '@/lib/hue';
import { unitFigure, weekdayName } from '@/lib/names';
import { stringsOf } from '@/lib/strings';
import type { ShiftDay, ShiftPattern } from '@/shifts/model';
import { keyedTable } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { ShiftsStrings } from '@/types';
import { localeOf, type Language } from '../../booking/language';
import { ADMIN_LINES_EN } from '../lines.generated';
import Shifts from './Shifts';

/*
 * The Shifts screen: the heading, its sentence and the week's controls with its range in the
 * tenant's date format; the week grid, a row per member with the initials, the name linking to
 * their staff page and the row head of the week's hours and the cadence, seven day columns headed
 * by the ICU weekday, the date and the day's total, each cell its windows, Not working, This day
 * only and the closures linking to the Staff page; This week, previous and next as partial reloads
 * of the week's props; the day editor a cell opens, with its four actions, each busy while in
 * flight and the answer through the reload, a refusal the envelope's sentence; read-only under the
 * narrowing notice without the staff feature. Every word is a key of the shifts section, rendered
 * here through the keyed table, the page showing no literal, and the section's type is held to
 * lang/en/admin/shifts.php. The framework is stood in for by a router that records each visit and
 * the failure listeners.
 */

/** A table's shape: every line a string, every group its own shape, nothing else. */
type Shape<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Shape<T[K]> };

type Visit = { only?: string[]; replace?: boolean; preserveState?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

const KEYED_SHIFTS: ShiftsStrings = keyedTable<ShiftsStrings>(ADMIN_LINES_EN.shifts);

const words = stringsOf<ShiftsStrings>(KEYED_SHIFTS, 'en');

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, className, children }: { href: string; className?: string; children: ReactNode }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
    usePage: () => ({ props: inertia.props }),
    router: {
        get: inertia.get,
        put: inertia.put,
        delete: inertia.delete,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const DATES = ['2026-11-30', '2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04', '2026-12-05', '2026-12-06'];

const ELENA_WINDOWS = [
    { start: '09:00', end: '13:00' },
    { start: '14:00', end: '18:00' },
];

const DAYS: ShiftDay[] = [
    ...DATES.map((date): ShiftDay => {
        const working = date < '2026-12-05';

        return { staff_id: 2, date, windows: working ? ELENA_WINDOWS : [], source: working ? 'pattern' : 'none', closures: [], pattern: working ? ELENA_WINDOWS : [] };
    }),
    { staff_id: 3, date: '2026-12-01', windows: [], source: 'override', closures: [], pattern: [{ start: '10:00', end: '19:00' }] },
    { staff_id: 3, date: '2026-12-02', windows: [{ start: '12:00', end: '20:00' }], source: 'override', closures: [], pattern: [{ start: '10:00', end: '19:00' }] },
    { staff_id: 3, date: '2026-12-04', windows: [{ start: '10:00', end: '19:00' }], source: 'pattern', closures: [{ start: '14:00', end: '16:00', reason: 'Dentist', business: false }], pattern: [{ start: '10:00', end: '19:00' }] },
    { staff_id: 3, date: '2026-12-05', windows: [{ start: '10:00', end: '19:00' }], source: 'pattern', closures: [{ start: null, end: null, reason: null, business: true }], pattern: [{ start: '10:00', end: '19:00' }] },
];

const PATTERNS: ShiftPattern[] = [
    {
        staff_id: 2,
        cadence_weeks: 1,
        starts_on: '1999-12-27',
        ends_on: null,
        windows: [1, 2, 3, 4, 5].flatMap((weekday) => ELENA_WINDOWS.map((window) => ({ weekday, ...window }))),
        minutes: 2400,
    },
    { staff_id: 3, cadence_weeks: 2, starts_on: '2026-11-30', ends_on: '2027-02-26', windows: [2, 3, 4, 5, 6].map((weekday) => ({ weekday, start: '10:00', end: '19:00' })), minutes: 2700 },
];

const TOTALS = {
    days: DATES.map((date, index) => ({ date, minutes: [480, 480, 960, 480, 900, 0, 0][index] ?? 0 })),
    members: [
        { staff_id: 2, minutes: 2400 },
        { staff_id: 3, minutes: 930 },
    ],
};

function propsWith(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        tenant: { name: 'Luna Hair Studio', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        auth: { user: { id: 1, name: 'Alex Owner', role: 'owner' } },
        flash: null,
        errors: {},
        language: 'en',
        strings: { shell: {}, shifts: KEYED_SHIFTS },
        permissions: ['viewBookings', 'manageSettings', 'manageBilling'],
        entitlements: ['staff'],
        narrowed: [],
        free: false,
        subscription: { state: 'active' },
        settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'staff', messages: [] },
        nav: [],
        members: [
            { id: 2, name: 'Elena Papadaki', is_implicit: false },
            { id: 3, name: 'Nikos Georgiou', is_implicit: false },
        ],
        week: { from: '2026-11-30', to: '2026-12-07' },
        days: DAYS,
        patterns: PATTERNS,
        totals: TOTALS,
        editable: true,
        colours: [],
        ...overrides,
    };
}

/**
 * A text node's words with its keys taken out. Inside the runs placed() cuts alone, a pattern
 * window's span and a legend line's words, where a whole line set around its nodes spreads its
 * key over the text nodes between them, an opening bracket left open or a closing one with no
 * opening is forgiven too; anywhere else a stray bracket keeps the words around it.
 */
function keylessText(node: Node): string {
    const text = (node.textContent ?? '').replace(/\[[^\]]*\]/g, '');

    if ((node.parentElement?.closest('.admin-shift-pattern__span, .admin-shifts__legend-words') ?? null) === null) {
        return text;
    }

    return text.replace(/\[[^\]]*$/, '').replace(/^[^[]*\]/, '');
}

function q(selector: string): Element | null {
    return document.querySelector(selector);
}

function texts(selector: string): (string | null)[] {
    return [...document.querySelectorAll(selector)].map((element) => element.textContent);
}

function cell(staffId: number, date: string): Element | null {
    return q(`.admin-shifts__row[data-staff="${staffId}"] .admin-shifts__cell[data-date="${date}"]`);
}

function edit(staffId: number, date: string): void {
    press(cell(staffId, date)?.querySelector('.admin-shifts__edit') ?? null);
}

function lastVisit(method: ReturnType<typeof vi.fn>): [string, unknown, Visit] {
    const call = method.mock.lastCall;

    if (call === undefined) {
        throw new Error('No visit was made.');
    }

    return method === inertia.delete ? [call[0] as string, undefined, call[1] as Visit] : (call as [string, unknown, Visit]);
}

function start(visit: Visit): void {
    act(() => visit.onStart?.());
}

function succeed(visit: Visit): void {
    act(() => visit.onSuccess?.());
    act(() => visit.onFinish?.());
}

function fail(visit: Visit, response?: { status: number; data: unknown }): void {
    const event = response === undefined ? new CustomEvent('exception', { cancelable: true, detail: { exception: new Error('offline') } }) : new CustomEvent('invalid', { cancelable: true, detail: { response } });

    act(() => inertia.listeners.get(event.type)?.(event));
    act(() => visit.onFinish?.());
}

/** Picks a time in a time field: opens its listbox and presses the option. */
function pick(field: Element | null, time: string): void {
    press(field);
    press(q(`[role="option"][data-value="${time}"]`));
}

/** Sets a native select's value as a person choosing an option does. */
function choose(selector: string, value: string): void {
    const element = document.querySelector<HTMLSelectElement>(selector);

    if (element === null) {
        throw new Error(`No ${selector} on the page.`);
    }

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')?.set?.call(element, value);
        element.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

/** Opens a member's pattern editor from their row head. */
function openPatternEditor(staffId: number): void {
    press(q(`.admin-shifts__row[data-staff="${staffId}"] .admin-shifts__pattern`));
}

/** The first element under a weekday's row the selector names. */
function inDay(day: number, selector: string): Element | null {
    return q(`.admin-shift-pattern__day[data-weekday="${day}"] ${selector}`);
}

/** Every element under a weekday's row the selector names. */
function allInDay(day: number, selector: string): Element[] {
    return [...document.querySelectorAll(`.admin-shift-pattern__day[data-weekday="${day}"] ${selector}`)];
}

/** A weekday's Start and End values in order. */
function dayTimes(day: number): (string | null)[] {
    const row = q(`.admin-shift-pattern__day[data-weekday="${day}"]`);

    return [...(row?.querySelectorAll('.admin-shift-pattern__start, .admin-shift-pattern__end') ?? [])].map((field) => field.getAttribute('value'));
}

/** Whether a weekday's checkbox is ticked. */
function ticked(day: number): boolean {
    return (inDay(day, '.admin-shift-pattern__tick') as HTMLInputElement | null)?.checked === true;
}

function render(props: Record<string, unknown> = propsWith()): void {
    inertia.props = props;
    view.render(
        <ToastProvider>
            <Shifts />
        </ToastProvider>,
    );
}

let view: Mounted;

beforeEach(() => {
    view = mount();
    inertia.get.mockReset();
    inertia.put.mockReset();
    inertia.delete.mockReset();
    inertia.listeners.clear();
});

afterEach(() => {
    view.unmount();
    vi.restoreAllMocks();
});

describe('the shifts section\'s type', () => {
    it('is the keys of lang/en/admin/shifts.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.shifts>>().toEqualTypeOf<ShiftsStrings>();
        expect(Object.keys(ADMIN_LINES_EN.shifts)).toEqual(['page', 'editor', 'pattern', 'validation', 'flashes']);
    });
});

/** Every name the screen fills a placeholder of the section with, from its own value. */
const FILLED = ['count', 'date', 'end', 'from', 'hours', 'minutes', 'name', 'reason', 'sample', 'start', 'to', 'weekday', 'weeks', 'windows'];

/** A line's placeholders as the house reads them (LanguageFilesTest, testing/keyed). */
function placeholders(line: string): string[] {
    return [...line.matchAll(/(?<![A-Za-z0-9_:]):([A-Za-z][A-Za-z0-9_]*)/g)].map((match) => match[1] ?? '');
}

/** Every line of a table, by its dotted key. */
function linesOf(table: object, prefix = ''): [string, string][] {
    return Object.entries(table).flatMap(([name, value]): [string, string][] => (typeof value === 'string' ? [[`${prefix}${name}`, value]] : linesOf(value as object, `${prefix}${name}.`)));
}

describe('the section\'s placeholders', () => {
    it('are each a whole name the screen fills, no unit or letter glued to one, so the Greek keeps the same names', () => {
        const strays = linesOf({ page: ADMIN_LINES_EN.shifts.page, editor: ADMIN_LINES_EN.shifts.editor, pattern: ADMIN_LINES_EN.shifts.pattern }).flatMap(([line, value]) => placeholders(value).filter((name) => !FILLED.includes(name)).map((name) => `${line} :${name}`));

        expect(strays).toEqual([]);
        expect(placeholders(':hoursh :minutesm').filter((name) => !FILLED.includes(name))).toEqual(['hoursh', 'minutesm']);
    });

    it('spells a figure\'s unit through ICU in the language, never a letter in the language file', () => {
        const icu = (value: number, unit: 'hour' | 'minute', language: Language, display: 'narrow' | 'long') => new Intl.NumberFormat(localeOf(language), { style: 'unit', unit, unitDisplay: display }).format(value);

        expect([unitFigure(44, 'hour', 'en'), unitFigure(30, 'minute', 'en')]).toEqual([icu(44, 'hour', 'en', 'narrow'), icu(30, 'minute', 'en', 'narrow')]);
        expect([unitFigure(44, 'hour', 'el'), unitFigure(30, 'minute', 'el')]).toEqual([icu(44, 'hour', 'el', 'long'), icu(30, 'minute', 'el', 'long')]);
    });
});

describe('the week grid', () => {
    it('renders the heading, its sentence, the week in the tenant\'s date format in bold, and the legend with the footnote as the card\'s foot', () => {
        render();

        expect(q('h1')?.textContent).toBe(words.text('page.title'));
        expect(q('.admin-shifts__lead')?.textContent).toBe(words.text('page.lead'));
        expect(q('.admin-shifts__week')?.tagName).toBe('H2');
        expect(q('.admin-shifts__week')?.textContent).toBe(words.text('page.week', { from: '30/11/2026', to: '06/12/2026' }));
        expect(texts('.admin-shifts__legend-sample')).toEqual(['12:00-20:00', words.text('page.not_working'), words.text('page.legend_closed')]);
        expect(texts('.admin-shifts__legend li')).toEqual([
            words.text('page.legend_override', { sample: '12:00-20:00' }),
            words.text('page.legend_off', { sample: words.text('page.not_working') }),
            words.text('page.legend_closure', { sample: words.text('page.legend_closed') }),
        ]);
        expect(['admin-shifts__pill--alone', 'admin-shifts__pill--off', 'admin-shifts__pill--closure'].map((name, at) => document.querySelectorAll('.admin-shifts__legend-sample')[at]?.classList.contains(name))).toEqual([true, true, true]);
        expect(q('.admin-shifts .admin-shifts__legend .admin-shifts__footnote')?.textContent).toBe(words.text('page.footnote'));
        expect(q('.admin-shifts .admin-table-scroll table')).not.toBeNull();
        expect(q('.admin-shifts-readonly')).toBeNull();
    });

    it('heads the member column Staff member, and seven day columns from the week\'s first date with the weekday\'s ICU name and the day and month on one line, the day\'s total beneath', () => {
        render();

        expect(q('.admin-shifts__member-head')?.textContent).toBe(words.text('page.member'));
        expect(texts('.admin-shifts__day-name')).toEqual(DATES.map((date, index) => words.text('page.day_head', { weekday: weekdayName(index + 1, 'short', 'en'), date: formatDayMonth(date, CLOCK) })));
        expect(texts('.admin-shifts__day-total')).toEqual(['8h', '8h', '16h', '8h', '15h', '0h', '0h']);
        expect(q('.admin-shifts__head .admin-shifts__day-total')?.previousElementSibling?.classList.contains('admin-shifts__day-name')).toBe(true);
    });

    it('rings a member who picked a colour on the Staff screen in it, the others in the spread', () => {
        render(propsWith({ colours: [{ id: 3, colour: 'purple' }] }));

        expect([...document.querySelectorAll<HTMLElement>('.admin-shifts__initials')].map((initials) => initials.style.boxShadow)).toEqual([`0 0 0 2px ${hueOf(2, [])}`, '0 0 0 2px #cc79a7']);
    });

    it('heads each row with the initials, the name linking to the staff page and the week\'s hours with the cadence', () => {
        render(propsWith({ members: [...(propsWith().members as object[]), { id: 4, name: 'Maria', is_implicit: false }] }));

        expect(texts('.admin-shifts__initials')).toEqual(['EP', 'NG', 'M']);
        expect([...document.querySelectorAll<HTMLElement>('.admin-shifts__initials')].map((initials) => initials.style.boxShadow)).toEqual([2, 3, 4].map((id) => `0 0 0 2px ${hueOf(id, [])}`));
        expect(q('.admin-shifts__row[data-staff="2"] .admin-shifts__member > div')?.lastElementChild?.classList.contains('admin-shifts__pattern')).toBe(true);
        expect([...document.querySelectorAll('.admin-shifts__name')].map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
            ['Elena Papadaki', '/admin/staff/2'],
            ['Nikos Georgiou', '/admin/staff/3'],
            ['Maria', '/admin/staff/4'],
        ]);
        expect(texts('.admin-shifts__row-head')).toEqual([
            words.text('page.row_weekly', { hours: '40h' }),
            words.text('page.row_cadence', { hours: words.text('page.hours_minutes', { hours: '15h', minutes: '30m' }), weeks: formatCount(2, words.locale), from: '30/11/2026' }),
            words.text('page.row_none', { hours: '0h' }),
        ]);
    });

    it('shows each day\'s windows, Not working, This day only under a day edited alone, and the closures linking to the Staff page', () => {
        render();

        expect(cell(2, '2026-11-30')?.querySelectorAll('.admin-shifts__window').length).toBe(2);
        expect(cell(2, '2026-11-30')?.querySelector('.admin-shifts__day')?.textContent).toBe('09:00-13:0014:00-18:00');
        expect(cell(2, '2026-12-06')?.querySelector('.admin-shifts__off')?.textContent).toBe(words.text('page.not_working'));
        expect(cell(3, '2026-11-30')?.querySelector('.admin-shifts__day')?.textContent).toBe(words.text('page.not_working'));
        expect(cell(3, '2026-12-01')?.querySelector('.admin-shifts__day')?.textContent).toBe(`${words.text('page.not_working')}${words.text('page.this_day_only')}`);
        expect(cell(3, '2026-12-02')?.querySelector('.admin-shifts__day')?.textContent).toBe(`12:00-20:00${words.text('page.this_day_only')}`);
        expect(cell(3, '2026-12-04')?.getAttribute('data-source')).toBe('pattern');
        expect([...(cell(2, '2026-11-30')?.querySelectorAll('.admin-shifts__window') ?? [])].every((pill) => pill.classList.contains('admin-shifts__pill') && !pill.classList.contains('admin-shifts__pill--alone'))).toBe(true);
        expect(cell(3, '2026-12-02')?.querySelector('.admin-shifts__window')?.classList.contains('admin-shifts__pill--alone')).toBe(true);
        expect(cell(3, '2026-12-01')?.querySelector('.admin-shifts__off')?.classList.contains('admin-shifts__pill--alone')).toBe(true);
        expect(cell(2, '2026-12-06')?.querySelector('.admin-shifts__off')?.classList.contains('admin-shifts__pill--off')).toBe(true);
        expect(cell(3, '2026-12-04')?.querySelector('.admin-shifts__closure')?.classList.contains('admin-shifts__pill--closure')).toBe(true);

        const own = cell(3, '2026-12-04')?.querySelector('.admin-shifts__closure');
        const business = cell(3, '2026-12-05')?.querySelector('.admin-shifts__closure');

        expect([own?.textContent, own?.getAttribute('href')]).toEqual([words.text('page.closed_reason', { start: '14:00', end: '16:00', reason: 'Dentist' }), '/admin/staff/3']);
        expect([business?.textContent, business?.getAttribute('href')]).toEqual([words.text('page.closed_all_day'), '/admin/staff']);
    });

    it('names an editable cell by what it shows, the windows, Not working and This day only, the edit wording its description', () => {
        render();
        const button = cell(3, '2026-12-02')?.querySelector('.admin-shifts__edit');
        const described = button?.getAttribute('aria-describedby') ?? '';

        expect(button?.hasAttribute('aria-label')).toBe(false);
        expect(button?.textContent).toBe(`12:00-20:00${words.text('page.this_day_only')}`);
        expect(described).not.toBe('');
        expect(document.getElementById(described)?.textContent).toBe(words.text('page.edit_day', { name: 'Nikos Georgiou', date: '02/12/2026' }));
        expect(document.getElementById(described)?.classList.contains('sr-only')).toBe(true);
        expect(button?.contains(document.getElementById(described))).toBe(false);
        expect(cell(3, '2026-12-01')?.querySelector('.admin-shifts__edit')?.textContent).toBe(`${words.text('page.not_working')}${words.text('page.this_day_only')}`);
    });

    it('spells the windows in the tenant\'s twelve-hour format with no conversion', () => {
        render(propsWith({ settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'g:i A', colour_by: 'staff', messages: [] } }));

        expect(cell(3, '2026-12-02')?.querySelector('.admin-shifts__window')?.textContent).toBe('12:00 PM-8:00 PM');
    });

    it('shows no literal: every word on the page is a key of the section or a value', () => {
        render();
        edit(3, '2026-12-02');

        const values = ['Elena Papadaki', 'Nikos Georgiou', 'Dentist', 'EP', 'NG', ...DATES.map((_, index) => weekdayName(index + 1, 'short', 'en'))];
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const left: string[] = [];

        for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
            const keyless = keylessText(node);
            const stripped = values.reduce((text, value) => text.split(value).join(''), keyless).replace(/\b\d+[hm]\b/g, '');

            if (/[A-Za-z]/.test(stripped)) {
                left.push(stripped);
            }
        }

        expect(left).toEqual([]);
    });

    it('keeps two closures apart where a member\'s own and the tenant\'s share their times and reason', () => {
        const same = { start: '14:00', end: '16:00', reason: 'Inventory' };
        const twin: ShiftDay = { staff_id: 3, date: '2026-12-03', windows: [{ start: '10:00', end: '19:00' }], source: 'pattern', closures: [{ ...same, business: false }, { ...same, business: true }], pattern: [] };
        const errors = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        render(propsWith({ days: [twin] }));

        expect(cell(3, '2026-12-03')?.querySelectorAll('.admin-shifts__closure')).toHaveLength(2);
        expect(errors.mock.calls.map((call) => String(call[0])).filter((line) => line.includes('same key'))).toEqual([]);
    });

    it('says so where the roster has nobody to list', () => {
        render(propsWith({ members: [], days: [] }));

        expect(q('.admin-shifts__empty')?.textContent).toBe(words.text('page.empty'));
        expect(q('.admin-shifts')).toBeNull();
    });
});

describe('the week\'s controls', () => {
    it('move the week through a partial reload of the week\'s props, the URL replaced, busy while it reads', () => {
        render();

        expect([...(q('.admin-shifts__bar')?.children ?? [])].slice(0, 4).map((element) => element.className.split(' ').find((name) => name.startsWith('admin-shifts__')))).toEqual(['admin-shifts__this-week', 'admin-shifts__previous', 'admin-shifts__week', 'admin-shifts__next']);
        expect([q('.admin-shifts__previous')?.getAttribute('aria-label'), q('.admin-shifts__next')?.getAttribute('aria-label')]).toEqual([words.text('page.previous'), words.text('page.next')]);
        expect([q('.admin-shifts__previous svg'), q('.admin-shifts__next svg')].every((glyph) => glyph !== null)).toBe(true);
        expect(q('.admin-shifts__previous')?.textContent).toBe('');

        press(q('.admin-shifts__next'));
        const [url, query, visit] = lastVisit(inertia.get);

        expect([url, query, visit.only, visit.replace, visit.preserveState]).toEqual(['/admin/shifts', { week: '2026-12-07' }, ['week', 'days', 'totals'], true, true]);

        start(visit);
        expect(q('.admin-shifts__previous')?.hasAttribute('disabled')).toBe(true);
        expect(q('.admin-shifts__loading')?.textContent).toBe(words.text('page.loading'));
        succeed(visit);
        expect(q('.admin-shifts__loading')).toBeNull();

        press(q('.admin-shifts__previous'));
        expect(lastVisit(inertia.get).slice(0, 2)).toEqual(['/admin/shifts', { week: '2026-11-23' }]);

        press(q('.admin-shifts__this-week'));
        expect(lastVisit(inertia.get).slice(0, 2)).toEqual(['/admin/shifts', {}]);
    });

    it('says so where the week did not load, the grid left standing', () => {
        render();

        press(q('.admin-shifts__next'));
        const [, , visit] = lastVisit(inertia.get);

        start(visit);
        fail(visit);

        expect(q('.admin-shifts-error')?.textContent).toBe(words.text('page.read_error'));
        expect(cell(2, '2026-11-30')).not.toBeNull();
    });
});

describe('the day editor', () => {
    it('opens on a cell titled with the member, the weekday and the date, the day\'s windows, the hours and what the pattern gives', () => {
        render();
        edit(3, '2026-12-02');

        expect(q('.admin-modal__title')?.textContent).toBe(words.text('editor.title', { name: 'Nikos Georgiou', weekday: weekdayName(3, 'long', 'en'), date: '02/12/2026' }));
        expect(q('.admin-shift-day__help')?.textContent).toBe(words.text('editor.help'));
        expect([...document.querySelectorAll('.admin-shift-day__start, .admin-shift-day__end')].map((field) => field.getAttribute('value'))).toEqual(['12:00', '20:00']);
        expect(q('.admin-shift-day__hours')?.textContent).toBe(words.text('editor.hours', { hours: unitFigure(8, 'hour', 'en') }));
        expect(q('.admin-shift-day__pattern')?.textContent).toBe(words.text('editor.pattern_gives', { windows: '10:00-19:00' }));
        expect(q('.admin-shift-day__add')?.textContent).toBe(words.text('editor.add_second'));
        expect(q('.admin-shift-day__back')?.textContent).toBe(words.text('editor.back'));
    });

    it('is composed as a card: Remove and Not working this day destructive text actions, Add a second window outlined, Not working this day at the foot\'s start and Save last', () => {
        render();
        edit(3, '2026-12-02');

        expect(q('.admin-shift-day__remove')?.classList.contains('text-destructive')).toBe(true);
        expect(q('.admin-shift-day__off')?.classList.contains('text-destructive')).toBe(true);
        expect(q('.admin-shift-day__add')?.classList.contains('border-border')).toBe(true);

        const foot = q('.admin-shift-day__foot');
        const buttons = [...(foot?.querySelectorAll('button') ?? [])].map((button) => button.className.split(' ').find((name) => name.startsWith('admin-shift-day__')));

        expect(buttons).toEqual(['admin-shift-day__off', 'admin-shift-day__back', 'admin-shift-day__cancel', 'admin-shift-day__save']);
        expect(q('.admin-shift-day__summary')?.textContent).toBe(`${words.text('editor.hours', { hours: unitFigure(8, 'hour', 'en') })} ${words.text('editor.pattern_gives', { windows: '10:00-19:00' })}`);
    });

    it('spells the day\'s hours as the grid\'s figure, a whole-hour day an hour figure and never a spelled count', () => {
        expect(ADMIN_LINES_EN.shifts.editor.hours).toBe(':hours on this day.');
        expect(ADMIN_LINES_EN.shifts.pattern.hours.startsWith(':hours ')).toBe(true);
        expect(Object.keys(ADMIN_LINES_EN.shifts.editor)).not.toContain('hours_minutes');
        expect(Object.keys(ADMIN_LINES_EN.shifts.pattern)).not.toContain('hours_minutes');
    });

    it('offers Back to the pattern on an override alone, and says where the pattern gives no hours', () => {
        render();
        edit(2, '2026-12-06');

        expect(q('.admin-shift-day__back')).toBeNull();
        expect(q('.admin-shift-day__pattern')?.textContent).toBe(words.text('editor.pattern_none'));
        expect(q('.admin-shift-day__add')?.textContent).toBe(words.text('editor.add_first'));
        expect(q('.admin-shift-day__hours')?.textContent).toBe(words.text('editor.hours', { hours: unitFigure(0, 'hour', 'en') }));
    });

    it('saves a second window through PUT, busy while in flight, and closes on the answer the reload brings', () => {
        render();
        edit(3, '2026-12-02');

        press(q('.admin-shift-day__add'));
        const ends = document.querySelectorAll('.admin-shift-day__end');
        pick(ends[0] ?? null, '14:00');
        pick(document.querySelectorAll('.admin-shift-day__start')[1] ?? null, '15:00');
        pick(document.querySelectorAll('.admin-shift-day__end')[1] ?? null, '18:30');

        expect(q('.admin-shift-day__add')).toBeNull();
        expect(q('.admin-shift-day__hours')?.textContent).toBe(words.text('editor.hours', { hours: words.text('page.hours_minutes', { hours: '5h', minutes: '30m' }) }));

        press(q('.admin-shift-day__save'));
        const [url, body, visit] = lastVisit(inertia.put);

        expect([url, body]).toEqual([
            '/admin/shifts/3/days/2026-12-02',
            {
                windows: [
                    { start: '12:00', end: '14:00' },
                    { start: '15:00', end: '18:30' },
                ],
            },
        ]);

        start(visit);
        expect(q('.admin-shift-day__save')?.textContent).toBe(words.text('editor.saving'));
        expect(q('.admin-shift-day__save')?.getAttribute('aria-busy')).toBe('true');
        expect(q('.admin-shift-day__off')?.hasAttribute('disabled')).toBe(true);
        expect(q('.admin-shift-day__back')?.hasAttribute('disabled')).toBe(true);
        expect(q('.admin-shift-day__cancel')?.hasAttribute('disabled')).toBe(true);

        press(q('.admin-shift-day__off'));
        expect(inertia.put).toHaveBeenCalledTimes(1);

        succeed(visit);
        expect(q('.admin-shift-day')).toBeNull();
    });

    it('removes a window, and saves Not working as an empty override through PUT, busy while in flight', () => {
        render();
        edit(2, '2026-11-30');

        press(document.querySelectorAll('.admin-shift-day__remove')[0] ?? null);
        expect([...document.querySelectorAll('.admin-shift-day__start, .admin-shift-day__end')].map((field) => field.getAttribute('value'))).toEqual(['14:00', '18:00']);

        press(q('.admin-shift-day__off'));
        const [url, body, visit] = lastVisit(inertia.put);

        expect([url, body]).toEqual(['/admin/shifts/2/days/2026-11-30', { windows: [] }]);

        start(visit);
        expect(q('.admin-shift-day__off')?.textContent).toBe(words.text('editor.saving'));
        expect(q('.admin-shift-day__save')?.hasAttribute('disabled')).toBe(true);
        succeed(visit);
        expect(q('.admin-shift-day')).toBeNull();
    });

    it('drops the override through DELETE, busy while in flight', () => {
        render();
        edit(3, '2026-12-01');

        press(q('.admin-shift-day__back'));
        const [url, , visit] = lastVisit(inertia.delete);

        expect(url).toBe('/admin/shifts/3/days/2026-12-01');

        start(visit);
        expect(q('.admin-shift-day__back')?.textContent).toBe(words.text('editor.going_back'));
        expect(q('.admin-shift-day__save')?.hasAttribute('disabled')).toBe(true);
        succeed(visit);
        expect(q('.admin-shift-day')).toBeNull();
    });

    it('shows a refused body\'s sentence as an error toast and keeps the editor open with what was typed', () => {
        render();
        edit(3, '2026-12-02');

        pick(q('.admin-shift-day__end'), '11:00');
        press(q('.admin-shift-day__save'));
        const [, , visit] = lastVisit(inertia.put);

        start(visit);
        fail(visit, { status: 400, data: { code: 'invalid_request', data: { detail: KEYED_SHIFTS.validation.day_order } } });

        expect(q('.admin-toast')?.textContent).toContain(KEYED_SHIFTS.validation.day_order);
        expect(q('.admin-shift-day__end')?.getAttribute('value')).toBe('11:00');
        expect(q('.admin-shift-day__save')?.textContent).toBe(words.text('editor.save'));
    });

    it('words a failure the server did not word in the editor\'s own sentence', () => {
        render();
        edit(3, '2026-12-02');

        press(q('.admin-shift-day__save'));
        const [, , visit] = lastVisit(inertia.put);

        start(visit);
        fail(visit);

        expect(q('.admin-toast')?.textContent).toContain(words.text('editor.not_saved'));
    });

    it.each([
        ['Escape', () => key(q('.admin-shift-day'), 'Escape')],
        [
            'a backdrop press',
            () => {
                const backdrop = q('.admin-modal__backdrop');
                drag(backdrop, backdrop);
            },
        ],
    ])('stays open on %s while a write is in flight, and closes on it once the answer is in', (_path, dismiss) => {
        render();
        edit(3, '2026-12-02');

        press(q('.admin-shift-day__save'));
        const [, , visit] = lastVisit(inertia.put);

        start(visit);
        dismiss();
        expect(q('.admin-shift-day')).not.toBeNull();
        expect(q('.admin-shift-day__save')?.getAttribute('aria-busy')).toBe('true');

        fail(visit, { status: 400, data: { code: 'invalid_request', data: { detail: KEYED_SHIFTS.validation.day_overlap } } });
        dismiss();
        expect(q('.admin-shift-day')).toBeNull();
    });

    it('closes on Cancel, writing nothing', () => {
        render();
        edit(3, '2026-12-02');

        press(q('.admin-shift-day__cancel'));

        expect(q('.admin-shift-day')).toBeNull();
        expect(inertia.put).not.toHaveBeenCalled();
    });
});

describe('the pattern editor', () => {
    it('opens from the row head\'s Pattern button on the member\'s stored pattern: Repeats, Starts on, Ends, the weekdays in the week\'s order and the week\'s hours', () => {
        render();

        expect(q('.admin-shifts__row[data-staff="3"] .admin-shifts__pattern')?.getAttribute('aria-label')).toBe(words.text('page.edit_pattern', { name: 'Nikos Georgiou' }));
        openPatternEditor(3);

        expect(q('.admin-modal__title')?.textContent).toBe(words.text('pattern.title', { name: 'Nikos Georgiou' }));
        expect(q('.admin-shift-pattern__note')?.textContent).toBe(words.text('pattern.note'));
        expect((q('.admin-shift-pattern__repeats') as HTMLSelectElement | null)?.value).toBe('2');
        expect([...document.querySelectorAll('.admin-shift-pattern__repeats option')].map((option) => option.textContent)).toEqual([
            words.text('pattern.every_week'),
            words.text('pattern.every_weeks', { weeks: formatCount(2, words.locale) }),
            words.text('pattern.every_weeks', { weeks: formatCount(3, words.locale) }),
            words.text('pattern.every_weeks', { weeks: formatCount(4, words.locale) }),
        ]);
        expect(q('.admin-shift-pattern__starts')?.getAttribute('value')).toBe('2026-11-30');
        expect((q('.admin-shift-pattern__ends') as HTMLSelectElement | null)?.value).toBe('on');
        expect([...document.querySelectorAll('.admin-shift-pattern__ends option')].map((option) => option.textContent)).toEqual([words.text('pattern.never'), words.text('pattern.on_date')]);
        expect(q('.admin-shift-pattern__ends-on')?.getAttribute('value')).toBe('2027-02-26');
        expect(q('.admin-shift-pattern__help')?.textContent).toBe(words.text('pattern.help_cadence', { weeks: formatCount(2, words.locale) }));
        expect([...document.querySelectorAll('.admin-shift-pattern__day')].map((row) => row.getAttribute('data-weekday'))).toEqual(['1', '2', '3', '4', '5', '6', '7']);
        expect(texts('.admin-shift-pattern__day[data-weekday="1"] label')).toEqual([weekdayName(1, 'long', 'en')]);
        expect(ticked(1)).toBe(false);
        expect(inDay(1, '.admin-shift-pattern__off')?.textContent).toBe(words.text('pattern.not_working'));
        expect(inDay(1, '.admin-shift-pattern__day-total')).toBeNull();
        expect(ticked(2)).toBe(true);
        expect(dayTimes(2)).toEqual(['10:00', '19:00']);
        expect(inDay(2, '.admin-shift-pattern__day-total')?.textContent).toBe('9h');
        expect(inDay(2, '.admin-shift-pattern__span')?.textContent).toBe(words.text('pattern.window', { start: '10:00', end: '19:00' }));
        expect(inDay(2, '.admin-shift-pattern__span .admin-shift-pattern__start')).not.toBeNull();
        expect(inDay(2, '.admin-shift-pattern__span .admin-shift-pattern__end')).not.toBeNull();
        expect([inDay(2, '.admin-shift-pattern__start')?.getAttribute('aria-label'), inDay(2, '.admin-shift-pattern__end')?.getAttribute('aria-label')]).toEqual([words.text('pattern.start'), words.text('pattern.end')]);
        expect(inDay(2, '.admin-shift-pattern__add')?.getAttribute('aria-label')).toBe(words.text('pattern.add_second'));
        expect(inDay(2, '.admin-shift-pattern__add svg')).not.toBeNull();
        expect(inDay(2, '.admin-shift-pattern__remove')?.classList.contains('text-destructive')).toBe(true);
        expect(q('.admin-shift-pattern__summary')?.textContent).toBe(words.text('pattern.hours', { hours: unitFigure(45, 'hour', 'en') }));
    });

    it('orders the weekdays from the tenant\'s week start, as the grid\'s columns run', () => {
        render(propsWith({ week: { from: '2026-11-29', to: '2026-12-06' }, settings: { granularity: 15, week_start: 7, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'staff', messages: [] } }));

        openPatternEditor(2);

        expect([...document.querySelectorAll('.admin-shift-pattern__day')].map((row) => row.getAttribute('data-weekday'))).toEqual(['7', '1', '2', '3', '4', '5', '6']);
    });

    it('shows no Starts on for a weekly pattern, the server starting every one on the one start, and opens it on the shown week for a new cadence', () => {
        render();
        openPatternEditor(2);

        expect(q('.admin-shift-pattern__starts')).toBeNull();
        expect(q('.admin-shift-pattern__help')?.textContent).toBe(words.text('pattern.help_weekly'));
        expect((q('.admin-shift-pattern__ends') as HTMLSelectElement | null)?.value).toBe('never');
        expect(q('.admin-shift-pattern__ends-on')).toBeNull();
        expect(q('.admin-shift-pattern__summary')?.textContent).toBe(words.text('pattern.hours', { hours: unitFigure(40, 'hour', 'en') }));

        choose('.admin-shift-pattern__repeats', '3');

        expect(q('.admin-shift-pattern__starts')?.getAttribute('value')).toBe('2026-11-30');
        expect(q('.admin-shift-pattern__help')?.textContent).toBe(words.text('pattern.help_cadence', { weeks: formatCount(3, words.locale) }));
    });

    it('ticks a day open on the nearest working day\'s window, adds and removes a second window, and closes a day by its tick or its last window', () => {
        render();
        openPatternEditor(3);

        press(inDay(7, '.admin-shift-pattern__tick'));
        expect(dayTimes(7)).toEqual(['10:00', '19:00']);
        expect(q('.admin-shift-pattern__summary')?.textContent).toBe(words.text('pattern.hours', { hours: unitFigure(54, 'hour', 'en') }));

        press(inDay(1, '.admin-shift-pattern__tick'));
        expect(dayTimes(1)).toEqual(['09:00', '17:00']);

        press(inDay(1, '.admin-shift-pattern__add'));
        expect(allInDay(1, '.admin-shift-pattern__window')).toHaveLength(2);
        expect(inDay(1, '.admin-shift-pattern__add')).toBeNull();
        pick(allInDay(1, '.admin-shift-pattern__end')[1] ?? null, '18:30');
        expect(q('.admin-shift-pattern__summary')?.textContent).toBe(words.text('pattern.hours', { hours: words.text('page.hours_minutes', { hours: '62h', minutes: '30m' }) }));

        press(inDay(1, '.admin-shift-pattern__remove'));
        press(inDay(1, '.admin-shift-pattern__remove'));
        expect(ticked(1)).toBe(false);

        press(inDay(7, '.admin-shift-pattern__tick'));
        expect(inDay(7, '.admin-shift-pattern__window')).toBeNull();
        expect(q('.admin-shift-pattern__summary')?.textContent).toBe(words.text('pattern.hours', { hours: unitFigure(45, 'hour', 'en') }));
    });

    it('saves the cadence, the start as entered, the end and the windows through PUT, busy while in flight, and closes on the answer the reload brings', () => {
        render();
        openPatternEditor(3);

        press(q('.admin-shift-pattern__starts'));
        press(q('button[data-date="2026-11-23"]'));
        press(inDay(6, '.admin-shift-pattern__tick'));
        press(q('.admin-shift-pattern__save'));
        const [url, body, visit] = lastVisit(inertia.put);

        expect([url, body]).toEqual([
            '/admin/shifts/3/pattern',
            {
                cadence_weeks: 2,
                starts_on: '2026-11-23',
                ends_on: '2027-02-26',
                windows: [2, 3, 4, 5].map((day) => ({ weekday: day, start: '10:00', end: '19:00' })),
            },
        ]);

        start(visit);
        expect(q('.admin-shift-pattern__save')?.textContent).toBe(words.text('pattern.saving'));
        expect(q('.admin-shift-pattern__save')?.getAttribute('aria-busy')).toBe('true');
        expect(q('.admin-shift-pattern__cancel')?.hasAttribute('disabled')).toBe(true);
        expect(q('.admin-shift-pattern__repeats')?.hasAttribute('disabled')).toBe(true);
        expect(inDay(2, '.admin-shift-pattern__tick')?.hasAttribute('disabled')).toBe(true);

        key(q('.admin-shift-pattern'), 'Escape');
        press(q('.admin-shift-pattern__save'));
        expect(q('.admin-shift-pattern')).not.toBeNull();
        expect(inertia.put).toHaveBeenCalledTimes(1);

        succeed(visit);
        expect(q('.admin-shift-pattern')).toBeNull();
    });

    it('saves a pattern that never ends as a null end, and holds Save while On a date names none', () => {
        render();
        openPatternEditor(2);

        choose('.admin-shift-pattern__ends', 'on');
        expect(q('.admin-shift-pattern__ends-on')?.getAttribute('value')).toBe('');
        expect(q('.admin-shift-pattern__save')?.hasAttribute('disabled')).toBe(true);

        choose('.admin-shift-pattern__ends', 'never');
        press(q('.admin-shift-pattern__save'));
        const [, body] = lastVisit(inertia.put);

        expect(body).toMatchObject({ cadence_weeks: 1, ends_on: null });
        expect((body as { windows: unknown[] }).windows).toHaveLength(10);
    });

    it('shows a refused body\'s sentence as an error toast and keeps the editor open with what was entered, a silent failure in its own sentence', () => {
        render();
        openPatternEditor(3);

        choose('.admin-shift-pattern__repeats', '4');
        press(q('.admin-shift-pattern__save'));
        let [, , visit] = lastVisit(inertia.put);

        start(visit);
        fail(visit, { status: 400, data: { code: 'invalid_request', data: { detail: KEYED_SHIFTS.validation.ends_on } } });

        expect(q('.admin-toast')?.textContent).toContain(KEYED_SHIFTS.validation.ends_on);
        expect((q('.admin-shift-pattern__repeats') as HTMLSelectElement | null)?.value).toBe('4');
        expect(q('.admin-shift-pattern__save')?.textContent).toBe(words.text('pattern.save'));

        press(q('.admin-shift-pattern__save'));
        [, , visit] = lastVisit(inertia.put);
        start(visit);
        fail(visit);

        expect(texts('.admin-toast').join(' ')).toContain(words.text('pattern.not_saved'));
    });

    it('closes on Cancel, writing nothing', () => {
        render();
        openPatternEditor(3);

        press(q('.admin-shift-pattern__cancel'));

        expect(q('.admin-shift-pattern')).toBeNull();
        expect(inertia.put).not.toHaveBeenCalled();
    });

    it('shows no literal: every word of the editor is a key of the section or a value', () => {
        render();
        openPatternEditor(3);

        const values = ['Elena Papadaki', 'Nikos Georgiou', 'Dentist', 'EP', 'NG', ...[1, 2, 3, 4, 5, 6, 7].flatMap((day) => [weekdayName(day, 'long', 'en'), weekdayName(day, 'short', 'en')])];
        const modal = q('.admin-shift-pattern');
        const walker = document.createTreeWalker(modal ?? document.body, NodeFilter.SHOW_TEXT);
        const left: string[] = [];

        for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
            const keyless = keylessText(node);
            const stripped = values.reduce((text, value) => text.split(value).join(''), keyless).replace(/\b\d+[hm]\b/g, '');

            if (/[A-Za-z]/.test(stripped)) {
                left.push(stripped);
            }
        }

        expect(modal).not.toBeNull();
        expect(left).toEqual([]);
    });
});

describe('without the staff feature', () => {
    it('reads on as plain text under the narrowing notice, the editor never opening, and leaves the sentence to the banner when the record names it', () => {
        render(propsWith({ editable: false, entitlements: [] }));

        expect(q('.admin-shifts-readonly')).not.toBeNull();
        expect(texts('.admin-shifts__name')).toEqual(['Elena Papadaki', 'Nikos Georgiou']);
        expect(q('.admin-shifts__edit')).toBeNull();
        expect(cell(2, '2026-11-30')?.querySelector('.admin-shifts__day')?.textContent).toBe('09:00-13:0014:00-18:00');

        press(cell(2, '2026-11-30')?.querySelector('.admin-shifts__day') ?? null);
        expect(q('.admin-shift-day')).toBeNull();
        expect(q('.admin-shifts__pattern')).toBeNull();

        render(propsWith({ editable: false, entitlements: [], narrowed: ['staff'] }));

        expect(q('.admin-shifts-readonly')).toBeNull();
    });

    it('says nothing went to a tenant that never had the feature, its read-only week the business\'s own row', () => {
        render(propsWith({ editable: false, entitlements: [], members: [{ id: 1, name: 'Luna Hair Studio', is_implicit: true }], patterns: [], days: [] }));

        expect(q('.admin-shifts-readonly')).toBeNull();
        expect(texts('.admin-shifts__name')).toEqual(['Luna Hair Studio']);
        expect(q('.admin-shifts__edit')).toBeNull();
        expect(q('.admin-shifts__pattern')).toBeNull();
    });
});
