import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { type Language, localeOf } from '../../booking/language';
import { key, mount, type Mounted, onPhone, press, settle } from '@/components/ui/harness';
import { keyedCalendar, keyedShell, keyedShifts, WithCalendar } from '@/testing/keyed';
import { Legend } from './Chrome';
import { CLOSURE_HERE, type ColumnActions, ColumnHead, EDIT_THIS_DAYS_HOURS, NEW_BOOKING_HERE, OPEN_STAFF_PAGE } from './ColumnHead';
import { Grid, heldEdgeOf, type HeldEdge } from './Grid';
import { type Block, blockFillOf, categoryHueOf, type Closure, type DayHours, type HoursWindow, type Colouring, type DayColumn, fillOf, GAP_TITLE, NO_CATEGORY, NEXT_WORKING_THIS_DAY, NO_HOURS_AHEAD, NOT_WORKING_THIS_DAY, OTHER_BOOKINGS, statusHueOf, swatchesOf } from './model';
import { hueOf, NEUTRAL } from '@/lib/hue';

/*
 * The grid as rendered, proven against the DOM and the computed styles of the real time grid
 * rather than the library's options: a block per item with its own id, the fill mixed and never
 * alpha, the gap hatched inside a dashed border, the same member the same hue, the neutral grey
 * where the staff choice is not real, the two badges and the time range by their rules, the axis
 * at the granularity, the columns from the week start, and the scroll the wrapper carries. The
 * column heads name each weekday by ICU in the page's language, held to Intl's own output; the
 * payment badge reads the kit's word, through the keyed table; the calendar's own words and a
 * closure's read through the keyed calendar and shifts sections the grid sits in.
 */

const shell = keyedShell();

/** The calendar section and the shifts section beside it, keyed, which the grid and its heads read. */
const cal = keyedCalendar();

const shifts = keyedShifts();

/** Intl's short name of the weekday of a UTC date in a language. */
function intlWeekday(date: string, language: Language = 'en'): string {
    return new Intl.DateTimeFormat(localeOf(language), { weekday: 'short', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
}

/** The week of 2026-11-02, Monday to Sunday. */
const WEEK = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'];

const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

function block(overrides: Partial<Block>): Block {
    return { id: 'b1:0', kind: 'booking', uuid: 'b1', staffId: 2, start: '2026-11-03T10:00:00', end: '2026-11-03T11:00:00', minutes: 60, title: 'Ada North, Haircut', badge: null, category: null, status: 'confirmed', segment: 0, movable: false, inside: false, ...overrides };
}

const ELENA = block({});
const NIKOS = block({ id: 'b2:0', uuid: 'b2', staffId: 4, start: '2026-11-04T10:00:00', end: '2026-11-04T10:30:00', minutes: 30, title: 'Bo South, Beard trim' });
const GAP = block({ id: 'b2:0:gap', kind: 'gap', uuid: 'b2', staffId: 4, start: '2026-11-04T10:30:00', end: '2026-11-04T11:00:00', minutes: 30, title: cal.text(GAP_TITLE) });
const REQUEST = block({ id: 'b3:0', uuid: 'b3', start: '2026-11-05T14:00:00', end: '2026-11-05T14:45:00', minutes: 45, title: 'Rita Request, Bridal trial', badge: 'awaiting_approval' });
const UNPAID = block({ id: 'b4:0', uuid: 'b4', staffId: 4, start: '2026-11-06T09:00:00', end: '2026-11-06T09:30:00', minutes: 30, title: 'Una Paid, Haircut', badge: 'awaiting_payment' });

/** Colour by Staff member on a tenant where the staff choice is real: today's rule. */
const STAFF: Colouring = { by: 'staff', palette: true, categories: [], own: [] };

let view: Mounted;

/** A render inside the keyed calendar and shifts sections, as the Calendar provides them. */
function show(node: ReactNode) {
    view.render(<WithCalendar>{node}</WithCalendar>);
}

/** A palette hex as the DOM spells a colour it parsed. */
function rgb(hex: string): string {
    return `rgb(${[1, 3, 5].map((at) => parseInt(hex.slice(at, at + 2), 16)).join(', ')})`;
}

/** What a screen reader reads of an element: its text without the parts kept from readers. */
function heard(element: Element): string {
    return [...element.childNodes].map((node) => (node instanceof Element ? (node.getAttribute('aria-hidden') === 'true' ? '' : heard(node)) : (node.textContent ?? ''))).join('');
}

function eventOf(id: string): HTMLElement {
    const element = view.host.querySelector<HTMLElement>(`.fc-event[data-block-id="${id}"]`) ?? [...view.host.querySelectorAll<HTMLElement>('.fc-event')].find((el) => el.dataset['blockId'] === id) ?? null;

    if (element === null) {
        throw new Error(`No block ${id} on the grid.`);
    }

    return element;
}

function render(props: Partial<Parameters<typeof Grid>[0]> = {}) {
    show(<Grid view="week" date="2026-11-03" blocks={[ELENA, NIKOS, GAP, REQUEST, UNPAID]} colouring={STAFF} granularity={15} weekStart={1} clock={CLOCK} onBlock={() => undefined} {...props} />);
}

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

describe('the grid', () => {
    it('draws a block per item with its own id, and a gap beside its parent', () => {
        render();

        const ids = [...view.host.querySelectorAll<HTMLElement>('.fc-event')].map((el) => el.dataset['blockId']);

        expect(ids).toEqual(expect.arrayContaining(['b1:0', 'b2:0', 'b2:0:gap', 'b3:0', 'b4:0']));
        expect(new Set(ids).size).toBe(ids.length);
        expect(eventOf('b2:0:gap').classList.contains('admin-calendar-gap')).toBe(true);
        expect(eventOf('b2:0').classList.contains('admin-calendar-block')).toBe(true);
    });

    it('fills a block with the hue mixed toward the surface and never an alpha, borders it in the full hue on the left alone, four pixels, and hatches a gap in the hue inside a dashed border', () => {
        render();

        const elena = getComputedStyle(eventOf('b1:0'));
        const nikos = getComputedStyle(eventOf('b2:0'));
        const gap = getComputedStyle(eventOf('b2:0:gap'));

        expect(elena.backgroundColor).toBe(fillOf(hueOf(2, [])));
        expect(elena.backgroundColor).toContain('color-mix(');
        expect(elena.backgroundColor).not.toMatch(/rgba|opacity/);
        expect(eventOf('b1:0').style.borderLeft).toContain(rgb(hueOf(2, [])));
        expect(eventOf('b1:0').style.borderLeft).toContain('4px');
        expect(eventOf('b1:0').style.borderLeft).not.toContain('3px');
        expect(elena.color).toBe('var(--foreground)');
        expect(nikos.backgroundColor).toBe(fillOf(hueOf(4, [])));
        expect(nikos.backgroundColor).not.toBe(elena.backgroundColor);
        expect(['transparent', 'rgba(0, 0, 0, 0)']).toContain(gap.backgroundColor);
        expect(gap.backgroundImage).toContain('repeating-linear-gradient');
        expect(gap.backgroundImage).toContain(rgb(hueOf(4, [])));
        expect(eventOf('b2:0:gap').style.border).toContain('dashed');
        expect(eventOf('b2:0:gap').style.border).toContain(rgb(hueOf(4, [])));
        expect(eventOf('b2:0:gap').style.border).toContain('1px');
        expect(eventOf('b2:0:gap').style.backgroundImage).toContain('135deg');
        expect(eventOf('b2:0:gap').style.backgroundImage).toContain('40%');
        expect(eventOf('b1:0').style.borderTopWidth).toBe('0px');
        expect(eventOf('b1:0').style.borderRightWidth).toBe('0px');
    });

    it('wears one neutral grey on every block where the staff choice is not real', () => {
        render({ colouring: { ...STAFF, palette: false } });

        expect(getComputedStyle(eventOf('b1:0')).backgroundColor).toBe(fillOf(NEUTRAL));
        expect(getComputedStyle(eventOf('b2:0')).backgroundColor).toBe(fillOf(NEUTRAL));
        expect(eventOf('b2:0:gap').style.border).toContain(rgb(NEUTRAL));
    });

    it('badges a booking awaiting approval Pending and one awaiting payment in its chip\'s word before the title, and no other, and shows the time range on a sixty-minute block and not a thirty-minute one', () => {
        render();

        const badges = (id: string) => [...eventOf(id).querySelectorAll('.admin-calendar-badge')].map((badge) => badge.textContent);

        expect(badges('b3:0')).toEqual([cal.text('badges.pending')]);
        expect(badges('b4:0')).toEqual([shell.text('kit.statuses.awaiting_payment')]);
        expect(eventOf('b4:0').querySelector('.admin-calendar-block__title')?.textContent).toBe(`${shell.text('kit.statuses.awaiting_payment')}Una Paid, Haircut`);
        expect(eventOf('b4:0').querySelector('.admin-calendar-block__title')?.firstElementChild?.classList.contains('admin-calendar-badge--payment')).toBe(true);
        expect(eventOf('b3:0').querySelector('.admin-calendar-badge')?.classList.contains('admin-calendar-badge--approval')).toBe(true);
        expect(eventOf('b4:0').classList.contains('admin-calendar-block--awaiting_payment')).toBe(true);
        expect(badges('b1:0')).toEqual([]);
        expect(badges('b2:0')).toEqual([]);
        expect(badges('b2:0:gap')).toEqual([]);
        expect(eventOf('b1:0').querySelector('.admin-calendar-block__title')?.textContent).toBe('Ada North, Haircut');
        expect(eventOf('b1:0').querySelector('.admin-calendar-block__time')?.textContent).toBe('10:00-11:00');
        expect(eventOf('b3:0').querySelector('.admin-calendar-block__time')?.textContent).toBe('14:00-14:45');
        expect(eventOf('b2:0').querySelector('.admin-calendar-block__time')).toBeNull();
    });

    it('marks a block a closure holds "Inside a closure" first on its line, before its badge and its title, the words read whichever drawing shows and the lone mark kept from a reader, and no other block', () => {
        render({ blocks: [ELENA, { ...REQUEST, inside: true }, GAP] });

        const title = eventOf('b3:0').querySelector('.admin-calendar-block__title');
        const marker = eventOf('b3:0').querySelector('.admin-calendar-inside');
        const mark = marker?.querySelector('.admin-calendar-inside__mark');

        expect(marker?.textContent).toBe(`!${cal.text('badges.closure')}`);
        expect(marker instanceof Element ? heard(marker) : null).toBe(cal.text('badges.closure'));
        expect(marker?.getAttribute('title')).toBe(cal.text('badges.closure'));
        expect([mark?.textContent, mark?.getAttribute('aria-hidden')]).toEqual(['!', 'true']);
        expect([...(title?.children ?? [])].map((pill) => pill.classList.item(0))).toEqual(['admin-calendar-inside', 'admin-calendar-badge']);
        expect(title?.lastChild?.textContent).toBe('Rita Request, Bridal trial');
        expect(title instanceof Element ? heard(title) : null).toBe(`${cal.text('badges.closure')}${cal.text('badges.pending')}Rita Request, Bridal trial`);
        expect(eventOf('b3:0').querySelector('.admin-calendar-block__body')?.classList.contains('@container')).toBe(true);
        expect(eventOf('b3:0').classList.contains('admin-calendar-block--inside')).toBe(true);
        expect(eventOf('b1:0').querySelector('.admin-calendar-inside')).toBeNull();
        expect(eventOf('b1:0').classList.contains('admin-calendar-block--inside')).toBe(false);
    });

    it('draws the marker beside a badge by a width of its own, the lone mark coming sooner where two pills share the line, and a badge by one width whatever shares its line, its word kept for a reader under it and every title\'s line ellipsised at any width', () => {
        render({ blocks: [{ ...ELENA, inside: true }, { ...REQUEST, inside: true }, UNPAID] });

        const drawn = (id: string) => eventOf(id).querySelector('.admin-calendar-inside__mark')?.className;
        const badge = (id: string) => eventOf(id).querySelector('.admin-calendar-badge');
        const title = (id: string) => eventOf(id).querySelector('.admin-calendar-block__title');

        expect(drawn('b1:0')).toContain('admin-calendar-inside__mark');
        expect(drawn('b3:0')).toContain('admin-calendar-inside__mark');
        expect(drawn('b1:0')).not.toBe(drawn('b3:0'));

        // A title's line wears no class by its body's width: it is ellipsised however narrow.
        for (const id of ['b1:0', 'b3:0', 'b4:0']) {
            expect(title(id)?.className).toContain('truncate');
            expect(title(id)?.className).not.toContain('@max-');
        }

        // Under its width a badge is its word for a reader alone, as the marker's words are.
        for (const id of ['b3:0', 'b4:0']) {
            expect(badge(id)?.className).toMatch(/@max-\[\d+px\]:sr-only/);
            expect(badge(id)?.getAttribute('aria-hidden')).toBeNull();
        }

        const unpaid = title('b4:0');

        expect(unpaid instanceof Element ? heard(unpaid) : null).toBe(`${shell.text('kit.statuses.awaiting_payment')}Una Paid, Haircut`);

        // The widths, read off the classes: the lone mark under 241px of body and under 385px
        // beside a badge, and a badge drawn from 195px, less than both, beside the mark or alone.
        const width = (classes: string | undefined) => Number(/@max-\[(\d+)px\]/.exec(classes ?? '')?.[1]);

        expect([width(drawn('b1:0')), width(drawn('b3:0')), width(badge('b3:0')?.className), width(badge('b4:0')?.className)]).toEqual([241, 385, 195, 195]);
    });

    it('steps the slots at the granularity, labels the axis on the hour alone in the tenant time format, lays the week from the tenant week start, and shows one column in Day', () => {
        render({ granularity: 15 });

        const slots = [...view.host.querySelectorAll('.fc-timegrid-slot-lane[data-time]')].map((el) => el.getAttribute('data-time'));
        const labels = [...view.host.querySelectorAll('.fc-timegrid-slot-label-cushion')].map((el) => el.textContent);

        expect(slots.slice(0, 3)).toEqual(['00:00:00', '00:15:00', '00:30:00']);
        expect(slots).toHaveLength(96);
        expect(labels.filter((label) => label !== '')).toHaveLength(24);
        expect(labels.filter((label) => label !== '').slice(0, 2)).toEqual(['00:00', '01:00']);

        render({ granularity: 30 });

        const headers = [...view.host.querySelectorAll('.fc-col-header-cell-cushion')].map((el) => el.textContent);

        expect([...view.host.querySelectorAll('.fc-timegrid-slot-lane[data-time]')]).toHaveLength(48);
        expect(headers).toEqual(WEEK.map((day) => `${intlWeekday(day)} ${day.slice(8)}/11/2026`));

        render({ granularity: 30, view: 'day' });

        expect([...view.host.querySelectorAll('.fc-col-header-cell-cushion')].map((el) => el.textContent)).toEqual([`${intlWeekday('2026-11-03')} 03/11/2026`]);
        expect(view.host.querySelectorAll('.fc-event')).toHaveLength(1);

        render({ granularity: 30, view: 'week', weekStart: 7 });

        expect([...view.host.querySelectorAll('.fc-col-header-cell-cushion')].map((el) => el.textContent)[0]).toBe(`${intlWeekday('2026-11-01')} 01/11/2026`);
    });

    it('names the column heads\' weekdays by ICU in the page\'s language, the dates in the tenant format whatever the language', () => {
        view.unmount();
        view = mount('el');
        render();

        expect([...view.host.querySelectorAll('.fc-col-header-cell-cushion')].map((el) => el.textContent)).toEqual(WEEK.map((day) => `${intlWeekday(day, 'el')} ${day.slice(8)}/11/2026`));
        expect(intlWeekday('2026-11-02', 'el')).not.toBe(intlWeekday('2026-11-02', 'en'));
    });

    it('hands the library the page\'s language, so the labels it writes for assistive technology on Week\'s column heads are Intl\'s in that language, and English\'s as before at en', () => {
        /** Intl's form of a UTC date in a language's code alone, the locale the grid hands the library. */
        const intlIn = (date: string, language: Language, options: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(language, { ...options, timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
        const longDate = { year: 'numeric', month: 'long', day: 'numeric' } as const;
        const labels = () => [...view.host.querySelectorAll('.fc-col-header-cell-cushion')].map((el) => el.getAttribute('aria-label'));

        view.unmount();
        view = mount('el');
        render();

        expect(labels()).toEqual(WEEK.map((day) => intlIn(day, 'el', longDate)));
        expect(labels()[0]).not.toBe(intlIn(WEEK[0] ?? '', 'en', longDate));

        view.unmount();
        view = mount('en');
        render();

        expect(labels()).toEqual(WEEK.map((day) => intlIn(day, 'en', longDate)));
    });

    it('bounds Week to the span the screen hands, every block of the week inside it, and keeps the scroll it carries', () => {
        render({ granularity: 30, span: { min: '08:00:00', max: '21:00:00' } });

        const lanes = [...view.host.querySelectorAll('.fc-timegrid-slot-lane[data-time]')].map((el) => el.getAttribute('data-time'));

        expect(lanes[0]).toBe('08:00:00');
        expect(lanes.at(-1)).toBe('20:30:00');
        expect(lanes).toHaveLength(26);
        expect(view.host.querySelector('.admin-calendar-grid')?.getAttribute('data-scroll-time')).toBe('09:00:00');

        render({ granularity: 30 });
        expect(view.host.querySelectorAll('.fc-timegrid-slot-lane[data-time]')).toHaveLength(48);
    });

    it('draws an hour sixty pixels tall whatever the step: each slot\'s height a variable on the grid, in Week, Day and Day\'s columns alike', () => {
        const height = () => view.host.querySelector<HTMLElement>('.admin-calendar-grid')?.style.getPropertyValue('--admin-calendar-slot-height');

        render({ granularity: 15 });
        expect(height()).toBe('15px');

        render({ granularity: 30, view: 'day' });
        expect(height()).toBe('30px');

        render({ granularity: 5, view: 'day', columns: [{ key: 'member-2', member: { id: 2, name: 'Elena Papadaki' }, hours: null, next: undefined, blocks: [] }] });
        expect(view.host.querySelector('.admin-calendar-columns')).not.toBeNull();
        expect(height()).toBe('5px');
    });

    it('carries the scroll one hour before the day shown\'s earliest block, clamped at midnight, and the morning hour on an empty day', () => {
        render();
        expect(view.host.querySelector('.admin-calendar-grid')?.getAttribute('data-scroll-time')).toBe('09:00:00');

        render({ blocks: [block({ start: '2026-11-03T00:30:00', end: '2026-11-03T01:00:00' })] });
        expect(view.host.querySelector('.admin-calendar-grid')?.getAttribute('data-scroll-time')).toBe('00:00:00');

        render({ blocks: [NIKOS] });
        expect(view.host.querySelector('.admin-calendar-grid')?.getAttribute('data-scroll-time')).toBe('08:00:00');
    });

    it('hands a click on a block to the screen with the booking, a gap naming its parent', () => {
        const onBlock = vi.fn();
        render({ onBlock });

        press(eventOf('b1:0'));
        press(eventOf('b2:0:gap'));

        expect(onBlock.mock.calls).toEqual([['b1'], ['b2']]);
    });

    it('sets a block\'s times in the text face\'s even-width figures, never the mono face, the title and the time keeping their text', () => {
        render();

        const time = eventOf('b1:0').querySelector('.admin-calendar-block__time');

        expect(time?.classList.contains('tabular-nums')).toBe(true);
        expect(time?.className).not.toMatch(/font-mono/);
        expect(time?.textContent).toBe('10:00-11:00');
        expect(eventOf('b1:0').querySelector('.admin-calendar-block__title')?.textContent).toBe('Ada North, Haircut');
    });

    it('sets a block\'s time, a gap\'s words, a badge and the closure\'s mark at the small text size, none under it, each on the line a quarter of an hour holds', () => {
        render({ blocks: [ELENA, { ...REQUEST, inside: true }, GAP] });

        const parts = [
            eventOf('b1:0').querySelector('.admin-calendar-block__time'),
            eventOf('b2:0:gap').querySelector('.admin-calendar-block__body'),
            eventOf('b3:0').querySelector('.admin-calendar-badge'),
            eventOf('b3:0').querySelector('.admin-calendar-inside'),
        ];

        for (const part of parts) {
            expect(part?.classList.contains('text-xs')).toBe(true);
            expect(part?.classList.contains('leading-[14px]')).toBe(true);
            // No size written out in pixels takes the small size's place.
            expect(part?.className).not.toMatch(/text-\[\d+px\]/);
        }
    });

    it('marks a block of 22 minutes or less short, its title on the 14px line, and a longer block neither, its title on the small size\'s own line', () => {
        const QUARTER = block({ id: 'q:0', uuid: 'q', start: '2026-11-04T09:00:00', end: '2026-11-04T09:15:00', minutes: 15, title: 'Quinn Quick, Fringe' });
        const BRIEF = block({ id: 'f:0', uuid: 'f', start: '2026-11-05T09:00:00', end: '2026-11-05T09:05:00', minutes: 5, title: 'Fen Five, Brow' });
        const LONGEST = block({ id: 'l:0', uuid: 'l', start: '2026-11-05T11:00:00', end: '2026-11-05T11:22:00', minutes: 22, title: 'Lia Long, Brow' });
        const NEXT = block({ id: 'n:0', uuid: 'n', start: '2026-11-05T12:00:00', end: '2026-11-05T12:23:00', minutes: 23, title: 'Ned Next, Brow' });
        const BRIEF_GAP = block({ id: 's:0:gap', kind: 'gap', uuid: 's', start: '2026-11-06T09:00:00', end: '2026-11-06T09:10:00', minutes: 10, title: cal.text(GAP_TITLE) });
        const lineOf = (id: string) => [...(eventOf(id).querySelector('.admin-calendar-block__title')?.classList ?? [])].filter((name) => name.startsWith('leading-'));
        const short = (id: string) => eventOf(id).classList.contains('admin-calendar-block--short');

        render({ blocks: [ELENA, NIKOS, QUARTER, BRIEF, LONGEST, NEXT, GAP, BRIEF_GAP] });

        for (const id of ['q:0', 'f:0', 'l:0', 's:0:gap']) {
            expect(lineOf(id)).toEqual(['leading-[14px]']);
            expect(short(id)).toBe(true);
        }

        for (const id of ['n:0', 'b1:0', 'b2:0', 'b2:0:gap']) {
            expect(lineOf(id)).toEqual([]);
            expect(short(id)).toBe(false);
        }
    });

    it('dashes a short processing gap at its sides alone, so its title\'s line has the block\'s whole height, and a longer gap all round', () => {
        const BRIEF_GAP = block({ id: 's:0:gap', kind: 'gap', uuid: 's', start: '2026-11-06T09:00:00', end: '2026-11-06T09:10:00', minutes: 10, title: cal.text(GAP_TITLE) });

        render({ blocks: [ELENA, NIKOS, GAP, BRIEF_GAP] });

        const brief = eventOf('s:0:gap').style;
        const long = eventOf('b2:0:gap').style;

        expect([brief.borderTopWidth, brief.borderBottomWidth]).toEqual(['0px', '0px']);
        expect([brief.borderLeftStyle, brief.borderRightStyle]).toEqual(['dashed', 'dashed']);
        expect([brief.borderLeftWidth, brief.borderRightWidth]).toEqual(['1px', '1px']);
        expect([long.borderTopWidth, long.borderBottomWidth, long.borderLeftWidth, long.borderRightWidth]).toEqual(['1px', '1px', '1px', '1px']);
    });

    it('strikes the title of a booking cancelled, rejected, missed or expired and fills it softer in the tertiary ink, its bar still its hue, and strikes nothing else', () => {
        const CANCELLED = block({ id: 'c:0', uuid: 'c', status: 'cancelled', title: 'Cy Cancel, Haircut' });
        const REJECTED = block({ id: 'j:0', uuid: 'j', staffId: 4, start: '2026-11-06T12:00:00', end: '2026-11-06T13:00:00', status: 'rejected', title: 'Jo Refused, Haircut' });
        const MISSED = block({ id: 'm:0', uuid: 'm', staffId: 4, start: '2026-11-04T12:00:00', end: '2026-11-04T13:00:00', status: 'no_show', title: 'Mo Missed, Haircut' });
        const EXPIRED = block({ id: 'x:0', uuid: 'x', start: '2026-11-05T09:00:00', end: '2026-11-05T10:00:00', status: 'expired', title: 'Ex Pired, Haircut' });
        render({ blocks: [ELENA, CANCELLED, REJECTED, MISSED, EXPIRED, REQUEST] });

        for (const [id, status, staffId] of [['c:0', 'cancelled', 2], ['j:0', 'rejected', 4], ['m:0', 'no_show', 4], ['x:0', 'expired', 2]] as const) {
            expect(eventOf(id).classList.contains('admin-calendar-block--struck')).toBe(true);
            expect(eventOf(id).querySelector('.admin-calendar-block__title')?.classList.contains('line-through')).toBe(true);
            expect(getComputedStyle(eventOf(id)).backgroundColor).toBe(blockFillOf(hueOf(staffId, []), status));
            expect(getComputedStyle(eventOf(id)).backgroundColor).toContain('var(--ink-tertiary)');
            expect(eventOf(id).style.borderLeft).toContain(rgb(hueOf(staffId, [])));
            expect(eventOf(id).style.borderLeft).toContain('4px');
        }

        for (const id of ['b1:0', 'b3:0']) {
            expect(eventOf(id).classList.contains('admin-calendar-block--struck')).toBe(false);
            expect(eventOf(id).querySelector('.admin-calendar-block__title')?.classList.contains('line-through')).toBe(false);
        }
    });

    it('sets today\'s day number in Week\'s day head on the highlight, and no other day\'s', () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-11-03T10:00:00Z'));

        try {
            render();

            const today = [...view.host.querySelectorAll<HTMLElement>('.admin-calendar-grid__today')];

            expect(today).toHaveLength(1);
            expect(today[0]?.closest<HTMLElement>('.fc-col-header-cell')?.dataset['date']).toBe('2026-11-03');
            expect(today[0]?.classList.contains('admin-calendar-grid__day')).toBe(true);
            expect(today[0]?.className.split(' ')).toEqual(expect.arrayContaining(['bg-spark', 'text-spark-foreground']));
            expect(today[0]?.dataset['day']).toBe('3');
            expect(today[0]?.closest('.fc-col-header-cell')?.textContent).toBe(`${intlWeekday('2026-11-03')} 03/11/2026`);
        } finally {
            vi.useRealTimers();
        }
    });

    it('sets each day head as the weekday over a large day number at every width, smaller where the head is narrow, the number drawn from an attribute and kept from readers so the head reads as the weekday and the whole date', () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-11-03T10:00:00Z'));

        try {
            render();

            const heads = [...view.host.querySelectorAll<HTMLElement>('.fc-col-header-cell')];

            expect(heads).toHaveLength(7);

            for (const head of heads) {
                const date = head.dataset['date'] ?? '';
                const words = head.querySelector<HTMLElement>('.admin-calendar-grid__head > span');
                const [weekday, full, number] = [...(words?.children ?? [])] as HTMLElement[];
                const own = number?.className.split(' ') ?? [];

                expect(head.querySelector('.admin-calendar-grid__head')?.classList.contains('@container')).toBe(true);
                expect(['flex', 'flex-col', 'items-center'].every((name) => words?.classList.contains(name))).toBe(true);
                expect(words?.className.split(' ').filter((name) => name.startsWith('@'))).toEqual([]);
                expect(weekday?.textContent).toBe(intlWeekday(date));
                expect(weekday?.classList.contains('text-label')).toBe(true);
                expect(full?.textContent).toBe(`${date.slice(8)}/11/2026`);
                expect(full?.classList.contains('sr-only')).toBe(true);
                expect(number?.classList.contains('admin-calendar-grid__day')).toBe(true);
                expect(number?.getAttribute('aria-hidden')).toBe('true');
                expect(number?.dataset['day']).toBe(String(Number(date.slice(8))));
                expect(number?.textContent).toBe('');
                expect(own.filter((name) => name.startsWith('after:content-'))).toHaveLength(1);
                expect(own).toEqual(expect.arrayContaining(['font-display', 'text-tile-title', 'tabular-nums', 'rounded-full', 'px-2.5', 'py-px']));
                // Never narrower than tall, its line and its one pixel above and below, the digits
                // centred, so a day of one digit stands on a round pill at either size.
                expect(own).toEqual(expect.arrayContaining(['min-w-[calc(1lh+2px)]', 'text-center']));
                expect(own.filter((name) => /^@max-\[116px\]:text-/.test(name))).toHaveLength(1);
                expect(weekday?.className.split(' ').filter((name) => /^@max-\[116px\]:text-/.test(name))).toHaveLength(1);
                expect(number?.classList.contains('bg-spark')).toBe(date === '2026-11-03');
                expect(head.textContent).toBe(`${intlWeekday(date)} ${date.slice(8)}/11/2026`);
                expect(heard(head)).toBe(`${intlWeekday(date)} ${date.slice(8)}/11/2026`);
            }
        } finally {
            vi.useRealTimers();
        }
    });

    it('bounds the one time grid\'s box at every width by the window and by the grid\'s own height, the library scrolling its slots under its day header inside it', () => {
        render();

        expect(view.host.querySelector('.admin-calendar-grid')?.classList.contains('h-[min(var(--admin-calendar-box),var(--admin-calendar-fit,var(--admin-calendar-box)))]')).toBe(true);
        expect(view.host.querySelector<HTMLElement>('.admin-calendar-grid > .fc')?.style.height).toBe('100%');
    });

    it('keeps the one time grid in its box on a phone too, its slots scrolling inside it and not down the page', () => {
        const undo = onPhone();

        try {
            render();

            expect(view.host.querySelector('.admin-calendar-grid')?.classList.contains('h-[min(var(--admin-calendar-box),var(--admin-calendar-fit,var(--admin-calendar-box)))]')).toBe(true);
            expect(view.host.querySelector<HTMLElement>('.admin-calendar-grid > .fc')?.style.height).toBe('100%');
        } finally {
            undo();
        }
    });
});

describe('dragging a booking', () => {
    const MOVABLE = { ...ELENA, movable: true };
    const MOVABLE_GAP = { ...GAP, movable: true };

    it('lets a block drag in Week exactly where the screen takes a drop and the server says the booking is movable, never a gap, and resizes nothing', () => {
        render({ blocks: [MOVABLE, NIKOS, MOVABLE_GAP], onDrop: () => undefined });

        expect(eventOf('b1:0').classList.contains('fc-event-draggable')).toBe(true);
        expect(eventOf('b2:0').classList.contains('fc-event-draggable')).toBe(false);
        expect(eventOf('b2:0:gap').classList.contains('fc-event-draggable')).toBe(false);
        expect(view.host.querySelectorAll('.fc-event-resizable')).toHaveLength(0);
    });

    it('lets nothing drag where the screen takes no drop', () => {
        render({ blocks: [MOVABLE] });
        expect(view.host.querySelectorAll('.fc-event-draggable')).toHaveLength(0);
    });

    it('lets a movable block drag in Day as the one time grid', () => {
        render({ view: 'day', blocks: [MOVABLE], onDrop: () => undefined });

        expect(eventOf('b1:0').classList.contains('fc-event-draggable')).toBe(true);
    });
});

describe('Colour by on the grid', () => {
    const HAIR = block({ category: 'Hair' });
    const COLOURING = block({ id: 'b2:0', uuid: 'b2', staffId: 4, start: '2026-11-04T10:00:00', end: '2026-11-04T11:00:00', category: 'Colouring', status: 'awaiting_approval' });
    const PLAIN = block({ id: 'b3:0', uuid: 'b3', staffId: 2, start: '2026-11-05T10:00:00', end: '2026-11-05T11:00:00', category: null, status: 'awaiting_payment' });
    const CATEGORIES = ['Colouring', 'Hair'];
    const hueOn = (id: string) => eventOf(id).style.getPropertyValue('--admin-block-hue');

    it('colours each block by its member under Staff member, the same member the same hue', () => {
        render({ blocks: [HAIR, COLOURING, PLAIN], colouring: { by: 'staff', palette: true, categories: CATEGORIES, own: [] } });

        expect([hueOn('b1:0'), hueOn('b2:0'), hueOn('b3:0')]).toEqual([hueOf(2, []), hueOf(4, []), hueOf(2, [])]);
    });

    it('colours each block by its service category under Service category, its place in the catalogue\'s sorted categories, a blank one grey, whoever performs it', () => {
        render({ blocks: [HAIR, COLOURING, PLAIN], colouring: { by: 'category', palette: false, categories: CATEGORIES, own: [] } });

        expect(hueOn('b1:0')).toBe(hueOf(1, []));
        expect(hueOn('b2:0')).toBe(hueOf(0, []));
        expect(hueOn('b3:0')).toBe(NEUTRAL);
        expect(getComputedStyle(eventOf('b1:0')).backgroundColor).toBe(fillOf(categoryHueOf('Hair', CATEGORIES)));
    });

    it('colours each block by its status under Status, the chips\' tones, and keeps the grey nowhere a status is known', () => {
        render({ blocks: [HAIR, COLOURING, PLAIN], colouring: { by: 'status', palette: true, categories: CATEGORIES, own: [] } });

        expect(hueOn('b1:0')).toBe(statusHueOf('confirmed'));
        expect(hueOn('b2:0')).toBe(statusHueOf('awaiting_approval'));
        expect(hueOn('b3:0')).toBe(statusHueOf('awaiting_payment'));
        expect(new Set([hueOn('b1:0'), hueOn('b2:0'), hueOn('b3:0')]).size).toBe(3);
        expect(eventOf('b1:0').style.borderLeft).toContain(statusHueOf('confirmed'));
        expect(eventOf('b1:0').style.borderLeft).toContain('4px');
    });

    it('keeps the Staff choice grey where the staff choice is not real, the other two coloured', () => {
        render({ blocks: [HAIR, COLOURING], colouring: { by: 'staff', palette: false, categories: CATEGORIES, own: [] } });
        expect([hueOn('b1:0'), hueOn('b2:0')]).toEqual([NEUTRAL, NEUTRAL]);

        render({ blocks: [HAIR, COLOURING], colouring: { by: 'status', palette: false, categories: CATEGORIES, own: [] } });
        expect([hueOn('b1:0'), hueOn('b2:0')]).toEqual([statusHueOf('confirmed'), statusHueOf('awaiting_approval')]);
    });

    describe('the legend following the choice', () => {
        const members = [
            { id: 2, name: 'Elena Papadaki' },
            { id: 4, name: 'Nikos Georgiou' },
        ];
        const name = (status: string) => `status ${status}`;
        const legend = (colouring: Colouring) => {
            show(<Legend by={colouring.by} swatches={swatchesOf(colouring, members, [HAIR, COLOURING, PLAIN], name, cal)} />);

            return [...view.host.querySelectorAll<HTMLElement>('.admin-calendar-legend__member')].map((item) => [item.textContent, item.querySelector<HTMLElement>('.admin-calendar-legend__swatch')?.style.backgroundColor]);
        };

        it('lists the members shown under Staff member', () => {
            expect(legend({ by: 'staff', palette: true, categories: CATEGORIES, own: [] })).toEqual([
                ['Elena Papadaki', rgb(hueOf(2, []))],
                ['Nikos Georgiou', rgb(hueOf(4, []))],
            ]);
            expect(view.host.querySelector('.admin-calendar-legend ul')?.getAttribute('aria-label')).toBe(cal.text('legend.staff'));
        });

        it('lists the categories drawn, sorted, the blank bucket last, under Service category', () => {
            expect(legend({ by: 'category', palette: true, categories: CATEGORIES, own: [] })).toEqual([
                ['Colouring', rgb(hueOf(0, []))],
                ['Hair', rgb(hueOf(1, []))],
                [cal.text(NO_CATEGORY), rgb(NEUTRAL)],
            ]);
        });

        it('lists the statuses drawn in the filter\'s order under Status, the badges after them', () => {
            expect(legend({ by: 'status', palette: true, categories: CATEGORIES, own: [] })).toEqual([
                ['status awaiting_approval', statusHueOf('awaiting_approval')],
                ['status awaiting_payment', statusHueOf('awaiting_payment')],
                ['status confirmed', statusHueOf('confirmed')],
            ]);
            expect(view.host.querySelectorAll('.admin-calendar-legend__badge')).toHaveLength(2);
        });

        it('lists the mark of a booking inside a closure among the badges, the lone mark a narrow block draws beside its words, the mark kept from a reader who hears the words once', () => {
            show(<Legend by="staff" swatches={swatchesOf(STAFF, members, [], name, cal)} />);

            const entry = view.host.querySelector('.admin-calendar-legend__inside');
            const mark = entry?.querySelector('.admin-calendar-legend__mark');

            expect([mark?.textContent, mark?.getAttribute('aria-hidden')]).toEqual(['!', 'true']);
            expect(entry?.textContent).toBe(`!${cal.text('badges.closure')}`);
            expect(entry instanceof Element ? heard(entry) : null).toBe(cal.text('badges.closure'));
            expect(entry?.closest('ul')?.getAttribute('aria-label')).toBe(cal.text('legend.badges'));
            expect(entry?.querySelector('.admin-calendar-inside')).toBeNull();
        });

        it('draws the gap\'s entry after the swatches, Closure and Outside the member\'s hours in Day, and the hint on an empty slot last where a slot starts a booking', () => {
            show(<Legend by="staff" swatches={swatchesOf(STAFF, members, [], name, cal)} day={true} hint={true} />);

            const entries = [...view.host.querySelectorAll<HTMLElement>('.admin-calendar-legend li')].map((item) => item.className.match(/admin-calendar-legend__[a-z]+/)?.[0]);

            expect(entries).toEqual(['member', 'member', 'badge', 'badge', 'inside', 'gap', 'closure', 'outside'].map((entry) => `admin-calendar-legend__${entry}`));
            expect(view.host.querySelector('.admin-calendar-legend__gap')?.textContent).toBe(cal.text('legend.gap'));
            expect(view.host.querySelector('.admin-calendar-legend__gap .admin-calendar-gap-swatch')).not.toBeNull();
            expect(view.host.querySelector('.admin-calendar-legend')?.lastElementChild?.classList.contains('admin-calendar-legend__hint')).toBe(true);
            expect(view.host.querySelector('.admin-calendar-legend__hint')?.textContent).toBe(cal.text('legend.hint'));

            show(<Legend by="staff" swatches={swatchesOf(STAFF, members, [], name, cal)} />);

            expect(view.host.querySelector('.admin-calendar-legend__gap')).not.toBeNull();
            expect(view.host.querySelector('.admin-calendar-legend__closure')).toBeNull();
            expect(view.host.querySelector('.admin-calendar-legend__hint')).toBeNull();
        });
    });
});

describe('Day as member columns', () => {
    const DAY = '2026-11-03';
    const ELENA_ROW = { id: 2, name: 'Elena Papadaki' };
    const NIKOS_ROW = { id: 4, name: 'Nikos Georgiou' };
    const MARIA_ROW = { id: 6, name: 'Maria Lambrou' };
    const SPLIT = [
        { start: '09:00', end: '13:00' },
        { start: '14:00', end: '18:00' },
    ];
    const DAY_HOURS = (windows: HoursWindow[], closures: Closure[] = []): DayHours => ({ windows, closures, source: 'pattern', pattern: windows });
    const ELENA_DAY = block({ id: 'e:0', uuid: 'e', staffId: 2, start: `${DAY}T10:00:00`, end: `${DAY}T11:00:00` });
    const NIKOS_DAY = block({ id: 'n:0', uuid: 'n', staffId: 4, start: `${DAY}T12:00:00`, end: `${DAY}T12:30:00`, minutes: 30, title: 'Bo South, Beard trim' });
    const OTHER_DAY = block({ id: 'o:0', uuid: 'o', staffId: 1, start: `${DAY}T07:00:00`, end: `${DAY}T07:45:00`, minutes: 45, title: 'Ivy Implicit, Haircut' });

    /*
     * The library places its fills, the hatch of non-business time and the background events, by
     * the slats' measured coordinates, and measures them only on an element that is not hidden,
     * one whose offsetHeight is not zero (timegrid's TimeColsSlats.updateSizing). jsdom lays out
     * nothing, so every element is given a height here; every coordinate still reads zero, which
     * is all a fill's presence needs. The now line, which needs real coordinates, stays unproven.
     */
    const offsetHeight = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetHeight');

    beforeEach(() => {
        Object.defineProperty(HTMLElement.prototype, 'offsetHeight', { configurable: true, get: () => 10 });
    });

    afterEach(() => {
        if (offsetHeight !== undefined) {
            Object.defineProperty(HTMLElement.prototype, 'offsetHeight', offsetHeight);
        }
    });

    function columnsOf(): DayColumn[] {
        return [
            { key: 'member-2', member: ELENA_ROW, hours: DAY_HOURS(SPLIT, [{ start: '14:00', end: '16:00', reason: 'Dentist', business: false }]), next: undefined, blocks: [ELENA_DAY] },
            { key: 'member-4', member: NIKOS_ROW, hours: DAY_HOURS([{ start: '10:00', end: '21:00' }]), next: undefined, blocks: [NIKOS_DAY] },
            { key: 'member-6', member: MARIA_ROW, hours: DAY_HOURS([]), next: { date: '2026-11-05', windows: [{ start: '10:00', end: '15:00' }] }, blocks: [] },
            { key: 'other', member: null, hours: null, next: undefined, blocks: [OTHER_DAY] },
        ];
    }

    function day(props: Partial<Parameters<typeof Grid>[0]> = {}) {
        render({ view: 'day', date: DAY, blocks: [ELENA_DAY, NIKOS_DAY, OTHER_DAY], columns: columnsOf(), head: (column) => <span className="stub-head">{column.member?.name ?? cal.text(OTHER_BOOKINGS)}</span>, ...props });
    }

    const columns = () => [...view.host.querySelectorAll<HTMLElement>('.admin-calendar-column')];

    const labels = (at: number) => [...(columns()[at]?.querySelectorAll('.fc-timegrid-slot-label-cushion') ?? [])].map((el) => el.textContent);
    const lanes = (at: number) => [...(columns()[at]?.querySelectorAll('.fc-timegrid-slot-lane[data-time]') ?? [])].map((el) => el.getAttribute('data-time'));
    const ids = (at: number) => [...(columns()[at]?.querySelectorAll<HTMLElement>('.fc-event:not(.fc-bg-event)') ?? [])].map((el) => el.dataset['blockId']);
    // The hatch and the background events are fills, which the library draws once its slats are
    // measured.
    const nonBusiness = (at: number) => columns()[at]?.querySelectorAll('.fc-non-business').length ?? 0;
    const closures = (at: number) => [...(columns()[at]?.querySelectorAll('.fc-bg-event.admin-calendar-closure') ?? [])].map((el) => el.textContent);

    it('draws one time-grid instance per column side by side, every one on the one axis from the earliest start to the latest end rounded to the hour, the labels on the first alone', () => {
        day();

        expect(columns().map((column) => column.dataset['column'])).toEqual(['member-2', 'member-4', 'member-6', 'other']);
        expect(view.host.querySelectorAll('.admin-calendar-column .fc')).toHaveLength(4);
        expect(view.host.querySelector('.admin-calendar-columns__row')?.classList.contains('overflow-x-auto')).toBe(true);
        // A member's column is 240px below the desktop breakpoint and 230px from it; the first
        // carries the 56px axis besides, so its minimum is the others' and the axis's.
        const widths = (column: HTMLElement | undefined) => column?.className.split(' ').filter((name) => /^(desktop:)?min-w-/.test(name));
        expect(widths(columns()[0])).toEqual(['min-w-74', 'desktop:min-w-71.5']);
        expect(columns().slice(1).map(widths)).toEqual([0, 1, 2].map(() => ['min-w-60', 'desktop:min-w-57.5']));
        // The other bookings' 07:00 and Nikos's 21:00 widen the axis past 08:00 to 20:00.
        expect(view.host.querySelector('.admin-calendar-columns')?.getAttribute('data-axis')).toBe('07:00:00-21:00:00');

        for (const at of [0, 1, 2, 3]) {
            expect(lanes(at)[0]).toBe('07:00:00');
            expect(lanes(at)).toHaveLength(56);
        }

        expect(labels(0).filter((label) => label !== '').slice(0, 2)).toEqual(['07:00', '08:00']);
        expect([1, 2, 3].flatMap(labels).every((label) => label === '')).toBe(true);
        expect(view.host.querySelectorAll('.admin-calendar-column .fc-col-header')).toHaveLength(0);
        expect(view.host.querySelectorAll('.stub-head')).toHaveLength(4);
        expect([...view.host.querySelectorAll('.stub-head')].map((head) => head.textContent)).toEqual(['Elena Papadaki', 'Nikos Georgiou', 'Maria Lambrou', cal.text(OTHER_BOOKINGS)]);
    });

    it('makes the columns\' row the one box that scrolls both ways at every width, each column\'s head pinned at its top over the blocks on an opaque ground', () => {
        day();

        const row = view.host.querySelector('.admin-calendar-columns__row');
        const classes = row?.className.split(' ') ?? [];

        expect(classes).toEqual(expect.arrayContaining(['overflow-x-auto', 'overflow-y-auto', 'max-h-(--admin-calendar-box)']));
        // No breakpoint holds the box back: a phone's row scrolls inside it as a laptop's does.
        expect(classes.filter((name) => /^desktop:(max-h|overflow-y)/.test(name))).toEqual([]);

        for (const head of view.host.querySelectorAll('.admin-calendar-column__head')) {
            const own = head.className.split(' ');

            expect(own).toEqual(expect.arrayContaining(['sticky', 'top-0', 'z-20', 'bg-card']));
            expect(own.filter((name) => /^desktop:(sticky|top|z)/.test(name))).toEqual([]);
        }
    });

    it('names the columns\' row a scroller as the library names its own, so a block dragged to the row\'s edge scrolls it, and leaves the row\'s scroll unpadded, a padding being one a head\'s own button could never clear', () => {
        day();

        const row = view.host.querySelector('.admin-calendar-columns__row');

        expect(row?.classList.contains('fc-scroller')).toBe(true);
        expect(row?.className.split(' ').filter((name) => /scroll-p/.test(name))).toEqual([]);
        // The heads are 100px tall, the height a block's scroll margin clears with eight pixels over.
        expect(view.host.querySelector('.admin-calendar-column__head')?.classList.contains('h-25')).toBe(true);
    });

    it('takes the scroller name off the columns\' row while a block is pressed, so the library\'s drag of the block leaves the row to its own scroll, and gives it back when the press ends or is cancelled, a press on an empty slot or a closure leaving it on', () => {
        day();

        const row = view.host.querySelector<HTMLElement>('.admin-calendar-columns__row');
        const named = () => row?.classList.contains('fc-scroller');
        const send = (target: EventTarget | null | undefined, kind: string) => target?.dispatchEvent(new Event(kind, { bubbles: true, cancelable: true }));

        send(eventOf('e:0'), 'pointerdown');
        expect(named()).toBe(false);
        send(document, 'pointerup');
        expect(named()).toBe(true);

        send(eventOf('e:0').querySelector('.fc-event-main') ?? eventOf('e:0'), 'pointerdown');
        expect(named()).toBe(false);
        send(document, 'pointercancel');
        expect(named()).toBe(true);

        send(row?.querySelector('.fc-timegrid-slot-lane[data-time="09:00:00"]'), 'pointerdown');
        expect(named()).toBe(true);
        send(row?.querySelector('.fc-bg-event.admin-calendar-closure'), 'pointerdown');
        expect(named()).toBe(true);
        send(document, 'pointerup');
        expect(named()).toBe(true);
    });

    it('scrolls the row under a held block only where its edge is nearer than every window edge that can scroll, the window winning a tie as the library\'s own pick does', () => {
        const edge = (owner: HeldEdge['owner'], distance: number, can = true): HeldEdge => ({ owner, depth: 50, distance, can, axis: 'top', sign: 1 });

        // The row's foot ten pixels off and the window's forty: the row scrolls.
        expect(heldEdgeOf([edge('window', 40), edge('row', 10)])?.owner).toBe('row');
        // The window's foot nearer, or as near: the window, which the library scrolls, and the row is left still.
        expect(heldEdgeOf([edge('window', 10), edge('row', 30)])?.owner).toBe('window');
        expect(heldEdgeOf([edge('window', 20), edge('row', 20)])?.owner).toBe('window');
        // A window edge that cannot scroll, or the pointer outside its band, does not count.
        expect(heldEdgeOf([edge('window', 10, false), edge('row', 30)])?.owner).toBe('row');
        expect(heldEdgeOf([edge('window', 60), edge('row', 30)])?.owner).toBe('row');
        // A band deeper than the library's, the heads' along the row's top, counts its whole depth.
        expect(heldEdgeOf([{ ...edge('row', 80), depth: 100 }])?.owner).toBe('row');
        expect(heldEdgeOf([edge('window', 60), edge('row', -1)])).toBeUndefined();
    });

    it('spans at least 08:00 to 20:00 where the hours and the blocks sit inside them', () => {
        day({ columns: [{ key: 'member-2', member: ELENA_ROW, hours: DAY_HOURS(SPLIT), next: undefined, blocks: [ELENA_DAY] }] });

        expect(view.host.querySelector('.admin-calendar-columns')?.getAttribute('data-axis')).toBe('08:00:00-20:00:00');
        expect(lanes(0)[0]).toBe('08:00:00');
    });

    it('hatches each column outside its member\'s windows of the day, the whole column for a member with none, and nothing on the other bookings\' column', () => {
        day();

        // The axis runs 07:00 to 21:00: Elena's split day leaves 07-09, 13-14 and 18-21 outside,
        // Nikos's 10-21 leaves 07-10.
        expect(nonBusiness(0)).toBe(3);
        expect(nonBusiness(1)).toBe(1);
        // Business hours on another weekday alone: the Tuesday shown is one hatch, top to bottom.
        expect(nonBusiness(2)).toBe(1);
        expect(nonBusiness(3)).toBe(0);
        expect(columns().every((column) => column.querySelector('[data-business-hours], [data-closures]') === null)).toBe(true);
    });

    it('draws each member\'s blocks on their own column as before and the other bookings on the trailing one, with the member\'s closures in their words behind them', () => {
        day();

        expect(ids(0)).toEqual(['e:0']);
        expect(ids(1)).toEqual(['n:0']);
        expect(ids(2)).toEqual([]);
        expect(ids(3)).toEqual(['o:0']);
        expect(getComputedStyle(eventOf('e:0')).backgroundColor).toBe(fillOf(hueOf(2, [])));
        expect(eventOf('o:0').querySelector('.admin-calendar-block__time')?.textContent).toBe('07:00-07:45');
        expect(closures(0)).toEqual([shifts.text('page.closed_reason', { start: '14:00', end: '16:00', reason: 'Dentist' })]);
        expect([1, 2, 3].map(closures)).toEqual([[], [], []]);
    });

    it('draws every case of closure in its own words, the tenant-wide ones on each column they reach', () => {
        const wide: Closure = { start: null, end: null, reason: 'Christmas Day', business: true };
        const [elena, nikos] = columnsOf();

        if (elena === undefined || nikos === undefined) {
            throw new Error('The fixture lost its columns.');
        }

        day({
            columns: [
                { ...elena, hours: DAY_HOURS(SPLIT, [{ start: '14:00', end: '16:00', reason: null, business: false }, wide]) },
                { ...nikos, hours: DAY_HOURS([{ start: '10:00', end: '21:00' }], [{ start: null, end: null, reason: null, business: false }, wide]) },
            ],
        });

        expect(closures(0)).toEqual([shifts.text('page.closed', { start: '14:00', end: '16:00' }), shifts.text('page.closed_all_day_reason', { reason: 'Christmas Day' })]);
        expect(closures(1)).toEqual([shifts.text('page.closed_all_day'), shifts.text('page.closed_all_day_reason', { reason: 'Christmas Day' })]);
    });

    it('moves every column to the date shown on Previous, Next or Today, drawing the new day\'s blocks, hatch and closures', () => {
        day();
        const next = '2026-11-04';
        const moved = block({ id: 'e2:0', uuid: 'e2', staffId: 2, start: `${next}T15:00:00`, end: `${next}T16:00:00` });
        const [elena, nikos, maria] = columnsOf();

        if (elena === undefined || nikos === undefined || maria === undefined) {
            throw new Error('The fixture lost its columns.');
        }

        day({
            date: next,
            blocks: [moved],
            columns: [
                { ...elena, hours: DAY_HOURS([{ start: '12:00', end: '20:00' }], [{ start: '17:00', end: '18:00', reason: 'Training', business: false }]), blocks: [moved] },
                { ...nikos, hours: DAY_HOURS([]), next: null, blocks: [] },
                { ...maria, hours: DAY_HOURS([{ start: '10:00', end: '15:00' }]), blocks: [] },
            ],
        });

        expect(columns().map((column) => column.querySelector<HTMLElement>('.fc-timegrid-col[data-date]')?.dataset['date'])).toEqual([next, next, next]);
        expect(ids(0)).toEqual(['e2:0']);
        expect([1, 2].map(ids)).toEqual([[], []]);
        expect(closures(0)).toEqual([shifts.text('page.closed_reason', { start: '17:00', end: '18:00', reason: 'Training' })]);
        // 08:00-20:00: Elena outside before 12:00 alone, Nikos whole, Maria before 10:00 and after
        // 15:00.
        expect([0, 1, 2].map(nonBusiness)).toEqual([1, 1, 2]);
        expect([...view.host.querySelectorAll('.admin-calendar-column__next')].map((pill) => pill.closest<HTMLElement>('.admin-calendar-column')?.dataset['column'])).toEqual(['member-4']);
    });

    it('draws a column whose date the window does not yet cover with no hatch, no closure and no pill until its hours land', () => {
        const [elena, nikos, maria] = columnsOf();

        if (elena === undefined || nikos === undefined || maria === undefined) {
            throw new Error('The fixture lost its columns.');
        }

        day({ columns: [{ ...elena, hours: null }, { ...nikos, hours: null }, { ...maria, hours: null, next: undefined }] });

        expect([0, 1, 2].map(nonBusiness)).toEqual([0, 0, 0]);
        expect([0, 1, 2].map(closures)).toEqual([[], [], []]);
        expect(view.host.querySelector('.admin-calendar-column__next')).toBeNull();
        expect(columns().map((column) => column.classList.contains('admin-calendar-column--other'))).toEqual([false, false, false]);
    });

    it('carries the pill naming the next working day on a column with no hours, or the eight weeks with none, and on no other column', () => {
        day();

        expect([...view.host.querySelectorAll('.admin-calendar-column__next')].map((pill) => pill.closest<HTMLElement>('.admin-calendar-column')?.dataset['column'])).toEqual(['member-6']);
        expect(view.host.querySelector('.admin-calendar-column__next')?.textContent).toBe(
            cal.text(NEXT_WORKING_THIS_DAY, { weekday: new Intl.DateTimeFormat('en', { weekday: 'long', timeZone: 'UTC' }).format(new Date('2026-11-05T00:00:00Z')), date: '05/11/2026', hours: '10:00-15:00' }),
        );

        day({ columns: columnsOf().map((column) => (column.key === 'member-6' ? { ...column, next: null } : column)) });

        expect(view.host.querySelector('.admin-calendar-column__next')?.textContent).toBe(cal.text(NO_HOURS_AHEAD));
    });

    it('lets a movable booking\'s block drag on every member\'s column and the other bookings\' column where the screen takes a drop, never a gap, a closure or a block the server says cannot move, and resizes nothing', () => {
        const movable = { ...ELENA_DAY, movable: true };
        const other = { ...OTHER_DAY, movable: true };
        const fixed = columnsOf();
        const handed: DayColumn[] = fixed.map((column) => (column.key === 'member-2' ? { ...column, blocks: [movable] } : column.key === 'other' ? { ...column, blocks: [other] } : column));

        day({ blocks: [movable, NIKOS_DAY, other], columns: handed, onDrop: () => undefined });

        expect(eventOf('e:0').classList.contains('fc-event-draggable')).toBe(true);
        expect(eventOf('o:0').classList.contains('fc-event-draggable')).toBe(true);
        expect(eventOf('n:0').classList.contains('fc-event-draggable')).toBe(false);
        expect(view.host.querySelectorAll('.admin-calendar-closure.fc-event-draggable')).toHaveLength(0);
        expect(view.host.querySelectorAll('.fc-event-resizable')).toHaveLength(0);

        day({ blocks: [movable, NIKOS_DAY, other], columns: handed });

        expect(view.host.querySelectorAll('.fc-event-draggable')).toHaveLength(0);
    });

    it('stays the one time grid in Day with no columns handed, and in Week whatever is handed', () => {
        render({ view: 'day', date: DAY, blocks: [ELENA_DAY] });
        expect(view.host.querySelectorAll('.admin-calendar-column')).toHaveLength(0);
        expect(view.host.querySelectorAll('.fc')).toHaveLength(1);

        render({ view: 'day', date: DAY, blocks: [ELENA_DAY], columns: [] });
        expect(view.host.querySelectorAll('.fc')).toHaveLength(1);

        render({ columns: columnsOf() });
        expect(view.host.querySelectorAll('.admin-calendar-column')).toHaveLength(0);
        expect(view.host.querySelectorAll('.fc-col-header-cell-cushion')).toHaveLength(7);
    });
});

describe('a Day column\'s head', () => {
    const ELENA_ROW = { id: 2, name: 'Elena Papadaki' };
    const WORKING: DayColumn = { key: 'member-2', member: ELENA_ROW, hours: { windows: [{ start: '09:00', end: '13:00' }, { start: '14:00', end: '18:00' }], closures: [], source: 'pattern', pattern: [] }, next: undefined, blocks: [] };
    const IDLE: DayColumn = { ...WORKING, hours: { windows: [], closures: [], source: 'none', pattern: [] } };
    const OTHER: DayColumn = { key: 'other', member: null, hours: null, next: undefined, blocks: [] };
    const trigger = () => view.host.querySelector<HTMLElement>('.admin-calendar-head__menu');
    const HUE = '#cc79a7';
    /** The menu's title: the name, then the day as Day's title names it, its weekday first. */
    const TITLE = cal.text('day.menu', { staff: 'Elena Papadaki', date: cal.text('toolbar.day_title', { weekday: new Intl.DateTimeFormat('en', { weekday: 'long', timeZone: 'UTC' }).format(new Date('2026-11-03T00:00:00Z')), date: '03/11/2026' }) });

    function head(column: DayColumn, actions: ColumnActions) {
        show(<ColumnHead column={column} date="2026-11-03" clock={CLOCK} hue={HUE} actions={actions} />);
    }

    async function open() {
        trigger()?.focus();
        key(trigger(), 'Enter');
        await settle();
    }

    afterEach(async () => {
        await settle();
    });

    it('carries the initials ringed in the member\'s hue, the name and the day\'s hours, or Not working this day', () => {
        head(WORKING, {});

        expect(view.host.querySelector('.admin-calendar-head__initials')?.textContent).toBe('EP');
        expect(view.host.querySelector<HTMLElement>('.admin-calendar-head__initials')?.style.boxShadow).toContain(HUE);
        expect(view.host.querySelector('.admin-calendar-head__hours')?.classList.contains('tabular-nums')).toBe(true);
        expect(view.host.querySelector('.admin-calendar-head__hours')?.className).not.toMatch(/font-mono/);
        expect(view.host.querySelector('.admin-calendar-head__name')?.textContent).toBe('Elena Papadaki');
        expect(view.host.querySelector('.admin-calendar-head__hours')?.textContent).toBe('09:00-13:00, 14:00-18:00');

        head(IDLE, {});
        expect(view.host.querySelector('.admin-calendar-head__hours')?.textContent).toBe(cal.text(NOT_WORKING_THIS_DAY));
    });

    it('heads a member\'s column whose day is not yet read by the initials and the name alone, no hours line', () => {
        head({ ...WORKING, hours: null }, { book: vi.fn() });

        expect(view.host.querySelector('.admin-calendar-head__name')?.textContent).toBe('Elena Papadaki');
        expect(view.host.querySelector('.admin-calendar-head__initials')?.textContent).toBe('EP');
        expect(view.host.querySelector('.admin-calendar-head__hours')).toBeNull();
        expect(view.host.textContent).not.toContain(cal.text(NOT_WORKING_THIS_DAY));
        expect(view.host.textContent).not.toContain(cal.text(OTHER_BOOKINGS));
    });

    it('is itself the menu\'s button, the initials, the name and the hours its face and no button of its own beside them', () => {
        head(WORKING, { book: vi.fn() });

        const button = trigger();

        expect(button?.tagName).toBe('BUTTON');
        expect(button?.classList.contains('admin-calendar-head')).toBe(true);
        expect(button?.getAttribute('aria-haspopup')).toBe('menu');
        expect(button?.getAttribute('aria-expanded')).toBe('false');
        expect(button?.querySelector('.admin-calendar-head__name')?.textContent).toBe('Elena Papadaki');
        expect(button?.querySelector('.admin-calendar-head__hours')).not.toBeNull();
        expect(view.host.querySelectorAll('button')).toHaveLength(1);
    });

    it('opens the menu under the head titled with the name and the day, its weekday first, on the four actions, each wired with the member', async () => {
        const book = vi.fn();
        const close = vi.fn();
        const edit = vi.fn();
        head(WORKING, { book, close, edit, staffPage: (member) => `/admin/staff/${member.id}` });

        await open();

        const menu = document.querySelector<HTMLElement>('.admin-menu');
        expect(trigger()?.getAttribute('aria-expanded')).toBe('true');
        expect(menu?.getAttribute('aria-label')).toBe(TITLE);
        // 240px, never the column's width.
        expect(menu?.className.split(' ')).toEqual(expect.arrayContaining(['w-60', 'min-w-60']));
        expect(menu?.querySelector('.admin-menu__title')?.textContent).toBe(TITLE);
        expect([...document.querySelectorAll('.admin-menu__item')].map((item) => item.textContent)).toEqual([NEW_BOOKING_HERE, CLOSURE_HERE, EDIT_THIS_DAYS_HOURS, OPEN_STAFF_PAGE].map((key) => cal.text(key)));
        expect(document.querySelector('.admin-calendar-column__staff')?.getAttribute('href')).toBe('/admin/staff/2');

        press(document.querySelector('.admin-calendar-column__book'));
        await settle();
        await open();
        press(document.querySelector('.admin-calendar-column__close'));
        await settle();
        await open();
        press(document.querySelector('.admin-calendar-column__edit'));

        expect(book.mock.calls).toEqual([[ELENA_ROW]]);
        expect(close.mock.calls).toEqual([[ELENA_ROW]]);
        expect(edit.mock.calls).toEqual([[ELENA_ROW]]);
    });

    it('leaves out an item its viewer may not take, not disabled, and the button where none is left', async () => {
        head(WORKING, { book: vi.fn(), staffPage: (member) => `/admin/staff/${member.id}` });
        await open();
        expect([...document.querySelectorAll('.admin-menu__item')].map((item) => item.textContent)).toEqual([NEW_BOOKING_HERE, OPEN_STAFF_PAGE].map((key) => cal.text(key)));
        key(document.activeElement, 'Escape');
        await settle();

        head(WORKING, {});
        expect(trigger()).toBeNull();
        expect(view.host.querySelector('.admin-calendar-head__name')?.textContent).toBe('Elena Papadaki');
    });

    it('heads the other bookings\' column by its name alone, with no menu and no hours', () => {
        head(OTHER, { book: vi.fn() });

        expect(view.host.querySelector('.admin-calendar-head__name')?.textContent).toBe(cal.text(OTHER_BOOKINGS));
        expect(trigger()).toBeNull();
        expect(view.host.querySelector('.admin-calendar-head__hours')).toBeNull();
    });
});
