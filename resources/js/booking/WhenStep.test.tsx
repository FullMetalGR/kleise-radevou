import { act, useState, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import { ENGLISH_WORDS, keyed, KEYED_WORDS, said, saidBy } from '../bookingWords';
import { ApiError, TRANSPORT_SENTENCE, type Notice } from './api';
import { parseAvailability, type Availability } from './availability';
import { BOOKING_LINES_EN } from './lines.generated';
import type { PeriodName } from './periods';
import { ZONE_SENTENCE } from './Summary';
import { formatDay as formatDayIn, formatDayFace as formatDayFaceIn, formatTime as formatTimeIn, wallClockOf, type WallClock } from './time';
import {
    CLOSED_DAY,
    DATE_CAPTION,
    EMPTY_DAY_SENTENCE,
    HINT_SENTENCE,
    LOADING_TIMES_SENTENCE,
    NEXT_AVAILABLE_LABEL,
    NEXT_WEEK_LABEL,
    NO_TIMES,
    PERIOD_NAMES,
    PERIODS_LABEL,
    PREVIOUS_WEEK_LABEL,
    SlotList,
    STALE_TIMES_SENTENCE,
    TIME_CAPTION,
    TIMES_COUNT,
    WhenStep,
    type AvailabilityState,
} from './WhenStep';
import { choose } from './choose';
import type { Words } from './words';

/*
 * Step when: the DOM order held in every state, the date caption over the time zone line and the
 * time caption once a day is shown, the week row built from the keys the answer carries and
 * nothing else, between its two arrows, days carrying aria-pressed, a day with no starts a
 * disabled button saying so, the first day with starts selected when the answer carries no
 * selected day and a day the customer chose kept, an empty day as the sentence and no buttons,
 * the hint only for a week whose every day is empty, the period control over the shown day's
 * starts and the grid holding the chosen period's, times as the tenant's hour in the page's
 * language, the pick reporting the wire value whole, and the arrows moving in whole weeks.
 * Rendered with the keyed table, each day and time in its locale and each period read back to
 * its identifier through its display key, and once with the English file.
 */

const TIMEZONE = 'Pacific/Auckland';

// 22:30 UTC on the 6th is 10:30 on the 7th in Auckland, so the server keyed it under the 7th.
const WIRE = {
    '2026-04-07': ['2026-04-06T22:30:00+00:00', '2026-04-06T22:35:00+00:00', '2026-04-07T01:00:00+00:00'],
    '2026-04-08': [],
    '2026-04-09': ['2026-04-08T21:00:00+00:00'],
    '2026-04-10': ['2026-04-09T21:00:00+00:00', '2026-04-09T21:30:00+00:00'],
    '2026-04-11': [],
    '2026-04-12': [],
    '2026-04-13': ['2026-04-12T23:00:00+00:00'],
};

const WEEK = Object.keys(WIRE);

const DATA = parseAvailability(WIRE) as Availability;

/** The same week after a refetch in which the 9th's one start went, as after a 409. */
const TAKEN = parseAvailability({ ...WIRE, '2026-04-09': [] }) as Availability;

/** A week whose first two days are empty, so the first day with starts is the third. */
const LATE = parseAvailability({ ...WIRE, '2026-04-07': [], '2026-04-08': [] }) as Availability;

/** The week after, reached by Next week, whose first day is empty. */
const NEXT_WEEK = parseAvailability({
    '2026-04-14': [],
    '2026-04-15': ['2026-04-14T22:00:00+00:00'],
    '2026-04-16': [],
    '2026-04-17': ['2026-04-16T22:00:00+00:00'],
    '2026-04-18': [],
    '2026-04-19': [],
    '2026-04-20': [],
}) as Availability;

/** A week in which no day holds a start. */
const EMPTY_WEEK = parseAvailability(Object.fromEntries(WEEK.map((date) => [date, []]))) as Availability;

/**
 * A week for the period control, in Auckland at twelve hours ahead of UTC: the 7th holds three
 * morning starts, the last at 11:59, one at 12:00 and two from 17:00; the 8th mornings alone;
 * the 9th a morning and an evening and no afternoon.
 */
const SPLIT_WIRE = {
    '2026-04-07': ['2026-04-06T21:00:00+00:00', '2026-04-06T21:30:00+00:00', '2026-04-06T23:59:00+00:00', '2026-04-07T00:00:00+00:00', '2026-04-07T05:00:00+00:00', '2026-04-07T06:00:00+00:00'],
    '2026-04-08': ['2026-04-07T22:00:00+00:00'],
    '2026-04-09': ['2026-04-08T20:00:00+00:00', '2026-04-09T07:00:00+00:00'],
    '2026-04-10': [],
    '2026-04-11': [],
    '2026-04-12': [],
    '2026-04-13': [],
};

const SPLIT: AvailabilityState = { data: parseAvailability(SPLIT_WIRE) as Availability, error: null, isFetching: false };

const MORNING = SPLIT_WIRE['2026-04-07'].slice(0, 3);
const AFTERNOON = SPLIT_WIRE['2026-04-07'].slice(3, 4);
const EVENING = SPLIT_WIRE['2026-04-07'].slice(4);

const IDLE: AvailabilityState = { data: undefined, error: null, isFetching: false };
const PENDING: AvailabilityState = { data: undefined, error: null, isFetching: true };
const READY: AvailabilityState = { data: DATA, error: null, isFetching: false };
const REFRESHING: AvailabilityState = { data: DATA, error: null, isFetching: true };
const REFUSED: AvailabilityState = { data: undefined, error: new ApiError(429, 'Too many requests. Please wait a moment.'), isFetching: false };
const DROPPED: AvailabilityState = { data: undefined, error: new TypeError('Failed to fetch'), isFetching: false };
const STALE: AvailabilityState = { data: DATA, error: new ApiError(503, 'Temporarily unavailable.'), isFetching: false };
const NOTHING: AvailabilityState = { data: EMPTY_WEEK, error: null, isFetching: false };

/** The empty week as the public read answers it when a later day holds a start. */
const NAMED: AvailabilityState = { data: { ...EMPTY_WEEK, nextAvailableFrom: '2026-04-22' }, error: null, isFetching: false };

/** The empty week as the public read answers it when no later day inside the horizon does. */
const NONE_LATER: AvailabilityState = { data: { ...EMPTY_WEEK, nextAvailableFrom: null }, error: null, isFetching: false };

interface Overrides {
    readonly availability?: AvailabilityState;
    readonly window?: number;
    readonly selectedDay?: string | null;
    readonly chosenStart?: string | null;
    readonly conflict?: Notice | null;
    readonly words?: Words;
}

/** A hold 409's notice, the server's sentence as it came. */
const CONFLICT: Notice = { text: 'That time was taken a moment ago.', tone: 'polite' };

/** The day and time formatters as the keyed words format, in the page's language on this machine. */
function formatDay(day: string): string {
    return formatDayIn(day, KEYED_WORDS.locale);
}

function formatDayFace(day: string): { readonly weekday: string; readonly date: string } {
    return formatDayFaceIn(day, KEYED_WORDS.locale);
}

function formatTime(wall: WallClock): string {
    return formatTimeIn(wall, KEYED_WORDS.locale);
}

/** Each period by the name its control shows, so a case reads the period it means. */
const PERIOD_SHOWN: Readonly<Record<string, PeriodName>> = Object.fromEntries(Object.entries(PERIOD_NAMES).map(([name, key]) => [said(key), name as PeriodName]));

let container: HTMLDivElement;
let root: Root;
let onWindow: Mock<(index: number) => void>;
let onSelectDay: Mock<(day: string) => void>;
let onPick: Mock<(start: string) => void>;
let onNextAvailable: Mock<(day: string) => void>;

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    onWindow = vi.fn<(index: number) => void>();
    onSelectDay = vi.fn<(day: string) => void>();
    onPick = vi.fn<(start: string) => void>();
    onNextAvailable = vi.fn<(day: string) => void>();
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
});

async function render(overrides: Overrides = {}): Promise<void> {
    await act(() => {
        root.render(
            keyed(
                <WhenStep
                timezone={TIMEZONE}
                availability={overrides.availability ?? READY}
                window={overrides.window ?? 0}
                selectedDay={overrides.selectedDay ?? null}
                chosenStart={overrides.chosenStart ?? null}
                conflict={overrides.conflict ?? null}
                onWindow={onWindow}
                onSelectDay={onSelectDay}
                onPick={onPick}
                onNextAvailable={onNextAvailable}
                />,
                overrides.words,
            ),
        );
    });
}

/**
 * The step as its parent drives it: the window and the selected day held in state, each
 * report spied on before it lands, and the read's state for each window handed in, so a
 * refetch is a render with a new list. The chosen start is the flow's, handed in as it stands
 * on the way Back from details.
 */
function Harness({ weeks, start = 0, chosenStart = null }: { readonly weeks: readonly AvailabilityState[]; readonly start?: number; readonly chosenStart?: string | null }): ReactNode {
    const [window, setWindow] = useState(start);
    const [selectedDay, setSelectedDay] = useState<string | null>(null);

    return (
        <WhenStep
            timezone={TIMEZONE}
            availability={weeks[window] ?? PENDING}
            window={window}
            selectedDay={selectedDay}
            chosenStart={chosenStart}
            conflict={null}
            onWindow={(index) => {
                onWindow(index);
                setWindow(index);
            }}
            onSelectDay={(day) => {
                onSelectDay(day);
                setSelectedDay(day);
            }}
            onPick={onPick}
            onNextAvailable={onNextAvailable}
        />
    );
}

async function drive(weeks: readonly AvailabilityState[], start = 0, chosenStart: string | null = null): Promise<void> {
    await act(() => {
        root.render(keyed(<Harness weeks={weeks} start={start} chosenStart={chosenStart} />));
    });
}

/**
 * The step's children in order, each named by what it is, so the order of 2.3 is one array.
 */
function kinds(): string[] {
    const step = container.querySelector('.kr-booking__step') as HTMLElement;

    return Array.from(step.children).map((child) => {
        const role = child.getAttribute('role');

        if (role !== null) {
            return role;
        }

        for (const kind of ['loading', 'caption', 'timezone', 'week', 'hint', 'grid', 'empty', 'jump', 'skeleton']) {
            if (child.classList.contains(`kr-booking__${kind}`)) {
                return kind;
            }
        }

        return child.tagName.toLowerCase();
    });
}

function statuses(): HTMLElement[] {
    return Array.from(container.querySelectorAll<HTMLElement>('[role="status"]'));
}

function alert(): HTMLElement | null {
    return container.querySelector('[role="alert"]');
}

function days(): HTMLButtonElement[] {
    return Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__day'));
}

function day(date: string): HTMLButtonElement | undefined {
    return days().find((candidate) => candidate.value === date);
}

function pressed(): string[] {
    return days()
        .filter((candidate) => candidate.getAttribute('aria-pressed') === 'true')
        .map((candidate) => candidate.value);
}

function slots(): HTMLButtonElement[] {
    return Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__slot'));
}

function periods(): HTMLButtonElement[] {
    return Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__period'));
}

/** The period a control shows, by the name it shows it under. */
function periodName(control: HTMLButtonElement): string | null | undefined {
    const shown = control.querySelector('.kr-booking__period-name')?.textContent;

    return shown === null || shown === undefined ? shown : (PERIOD_SHOWN[shown] ?? shown);
}

function period(name: string): HTMLButtonElement | undefined {
    return periods().find((candidate) => periodName(candidate) === name);
}

function pressedPeriods(): (string | null | undefined)[] {
    return periods()
        .filter((candidate) => candidate.getAttribute('aria-pressed') === 'true')
        .map(periodName);
}

/**
 * A control's name as the accessible-name rules compute it for markup that carries no
 * aria-label or aria-labelledby: the text of its descendants, a subtree under
 * aria-hidden="true" skipped, whitespace collapsed.
 */
function nameOf(control: Node): string {
    const textOf = (node: Node): string => {
        if (node instanceof Element && node.getAttribute('aria-hidden') === 'true') {
            return '';
        }

        return node.nodeType === Node.TEXT_NODE ? (node.textContent ?? '') : Array.from(node.childNodes).map(textOf).join('');
    };

    return textOf(control).replace(/\s+/g, ' ').trim();
}

function starts(): string[] {
    return slots().map((slot) => slot.value);
}

function button(name: string): HTMLButtonElement | undefined {
    return Array.from(container.querySelectorAll('button')).find((candidate) => candidate.textContent === name);
}

async function press(el: HTMLElement | undefined): Promise<void> {
    if (el === undefined) {
        throw new Error('No such control.');
    }

    await act(() => {
        el.focus();
        el.click();
    });
}

describe('the DOM order', () => {
    it.each<[string, Overrides, string[]]>([
        ['idle with nothing yet', { availability: IDLE }, ['status', 'status', 'caption', 'timezone', 'week']],
        ['loading with no data', { availability: PENDING }, ['status', 'status', 'loading', 'caption', 'timezone', 'week', 'skeleton']],
        ['refused with no data', { availability: REFUSED }, ['status', 'status', 'caption', 'timezone', 'week']],
        ['dropped with no data', { availability: DROPPED }, ['status', 'alert', 'status', 'caption', 'timezone', 'week']],
        ['data and no day picked', {}, ['status', 'status', 'caption', 'timezone', 'week', 'caption', 'group', 'grid']],
        ['data and a day with times', { selectedDay: '2026-04-09' }, ['status', 'status', 'caption', 'timezone', 'week', 'caption', 'grid']],
        ['data and a day with times in two periods', { selectedDay: '2026-04-07' }, ['status', 'status', 'caption', 'timezone', 'week', 'caption', 'group', 'grid']],
        ['data and an empty day', { selectedDay: '2026-04-08' }, ['status', 'status', 'caption', 'timezone', 'week', 'caption', 'empty', 'jump']],
        ['data and a day outside the window', { selectedDay: '2026-05-01' }, ['status', 'status', 'caption', 'timezone', 'week', 'caption', 'group', 'grid']],
        ['a week whose every day is empty', { availability: NOTHING }, ['status', 'status', 'caption', 'timezone', 'week', 'hint']],
        ['a week whose every day is empty and a later day named', { availability: NAMED }, ['status', 'status', 'caption', 'timezone', 'week', 'hint', 'jump']],
        ['a week whose every day is empty, one of them picked', { availability: NOTHING, selectedDay: '2026-04-09' }, ['status', 'status', 'caption', 'timezone', 'week', 'caption', 'empty']],
        ['refreshing with data', { availability: REFRESHING, selectedDay: '2026-04-07' }, ['status', 'status', 'loading', 'caption', 'timezone', 'week', 'caption', 'group', 'grid']],
        ['stale data', { availability: STALE, selectedDay: '2026-04-07' }, ['status', 'status', 'caption', 'timezone', 'week', 'caption', 'group', 'grid']],
        ['a conflict over data', { conflict: CONFLICT, selectedDay: '2026-04-07' }, ['status', 'status', 'caption', 'timezone', 'week', 'caption', 'group', 'grid']],
        ['a conflict over stale data', { availability: STALE, conflict: CONFLICT }, ['status', 'status', 'caption', 'timezone', 'week', 'caption', 'group', 'grid']],
    ])('holds for %s', async (_name, overrides, expected) => {
        await render(overrides);

        expect(kinds()).toEqual(expected);
    });

    it('reads the English file\'s captions and period names', async () => {
        await render({ selectedDay: '2026-04-07', words: ENGLISH_WORDS });

        expect(Array.from(container.querySelectorAll('.kr-booking__caption')).map((caption) => caption.textContent)).toEqual([BOOKING_LINES_EN.when.date_caption, BOOKING_LINES_EN.when.time_caption]);
        expect(container.querySelector('.kr-booking__period-name')?.textContent).toBe(BOOKING_LINES_EN.when.period.morning);
        expect(container.querySelector('.kr-booking__periods')?.getAttribute('aria-label')).toBe(BOOKING_LINES_EN.when.periods);
        expect(container.querySelector('.kr-booking__timezone')?.textContent).toBe(BOOKING_LINES_EN.zone.replace(':zone', TIMEZONE));
        expect(Array.from(container.querySelectorAll('.kr-booking__count')).map((count) => count.textContent)).toEqual([
            choose(BOOKING_LINES_EN.when.times, 2).replace(':count', '2'),
            choose(BOOKING_LINES_EN.when.times, 1).replace(':count', '1'),
        ]);
        expect(container.querySelector('.kr-booking__next .kr-booking__arrow-label')?.textContent).toBe(BOOKING_LINES_EN.when.next_week);
        expect(container.querySelector('.kr-booking__day[value="2026-04-08"]')?.getAttribute('aria-label')).toBe(BOOKING_LINES_EN.when.closed_day.replace(':date', formatDayIn('2026-04-08', ENGLISH_WORDS.locale)));
    });

    it('captions the week Select a date and the shown day\'s times Pick a time, as plain lines outside every region', async () => {
        await render({ selectedDay: '2026-04-08' });

        const captions = Array.from(container.querySelectorAll('.kr-booking__caption'));

        expect(captions.map((caption) => caption.textContent)).toEqual([said(DATE_CAPTION), said(TIME_CAPTION)]);
        expect(captions.every((caption) => caption.tagName === 'P' && caption.getAttribute('role') === null && caption.getAttribute('aria-live') === null)).toBe(true);

        await render({ availability: NOTHING });

        expect(Array.from(container.querySelectorAll('.kr-booking__caption')).map((caption) => caption.textContent)).toEqual([said(DATE_CAPTION)]);
    });
});

describe('the notices', () => {
    it('renders the availability error politely in the primary region when there is no data, and nothing in the stale region', async () => {
        await render({ availability: REFUSED });

        expect(statuses()[0]?.textContent).toBe('Too many requests. Please wait a moment.');
        expect(statuses()[1]?.textContent).toBe('');
        expect(alert()).toBeNull();
    });

    it('renders a transport failure as the fixed sentence in the alert paragraph of the primary region', async () => {
        await render({ availability: DROPPED });

        expect(alert()?.textContent).toBe(said(TRANSPORT_SENTENCE));
        expect(statuses()[0]?.textContent).toBe('');
    });

    it('keeps the list and adds the stale line when a refetch failed over data, with no alert whatever the status', async () => {
        await render({ availability: STALE, selectedDay: '2026-04-07' });

        expect(statuses()[0]?.textContent).toBe('');
        expect(statuses()[1]?.textContent).toBe(said(STALE_TIMES_SENTENCE));
        expect(alert()).toBeNull();
        expect(days()).toHaveLength(7);
        expect(starts()).toEqual(WIRE['2026-04-07'].slice(0, 2));
    });

    it('renders the conflict sentence politely, verbatim, before any availability error, beside the stale line when both apply', async () => {
        await render({ conflict: CONFLICT, availability: STALE });

        expect(statuses()[0]?.textContent).toBe('That time was taken a moment ago.');
        expect(statuses()[1]?.textContent).toBe(said(STALE_TIMES_SENTENCE));
        expect(alert()).toBeNull();

        await render({ conflict: CONFLICT, availability: DROPPED });

        expect(statuses()[0]?.textContent).toBe('That time was taken a moment ago.');
        expect(alert()).toBeNull();
    });

    it('shows the loading line only while the query is in flight, and marks the step busy', async () => {
        await render({ availability: PENDING });

        expect(container.textContent).toContain(said(LOADING_TIMES_SENTENCE));
        expect(container.querySelector('.kr-booking__step')?.getAttribute('aria-busy')).toBe('true');

        await render({ availability: READY });

        expect(container.textContent).not.toContain(said(LOADING_TIMES_SENTENCE));
        expect(container.querySelector('.kr-booking__step')?.getAttribute('aria-busy')).toBe('false');
    });

    it('names the tenant time zone in one line above the week row, under the date caption', async () => {
        await render();

        const line = container.querySelector('.kr-booking__timezone');

        expect(line?.textContent).toBe(said(ZONE_SENTENCE, { zone: 'Pacific/Auckland' }));
        expect(line?.nextElementSibling?.classList.contains('kr-booking__week')).toBe(true);
        expect(line?.previousElementSibling?.textContent).toBe(said(DATE_CAPTION));
    });
});

describe('the week row', () => {
    it('renders seven days, one per key in the answer, in order, and no other, each carrying its date', async () => {
        await render();

        expect(days()).toHaveLength(7);
        expect(days().map((candidate) => candidate.value)).toEqual(WEEK);
    });

    it('shows each day as the short weekday over the day of the month in the customer locale, named by the full date', async () => {
        await render();

        for (const { date, starts } of DATA.days) {
            const face = formatDayFace(date);
            const shown = day(date);

            expect(shown?.querySelector('.kr-booking__weekday')?.textContent).toBe(face.weekday);
            expect(shown?.querySelector('.kr-booking__date')?.textContent).toBe(face.date);
            expect(shown?.textContent).toBe(face.weekday + face.date + (starts.length > 0 ? '' : said(NO_TIMES)));
            expect(shown?.getAttribute('aria-label')).toBe(starts.length > 0 ? formatDay(date) : said(CLOSED_DAY, { date: formatDay(date) }));
        }

        expect(day('2026-04-09')?.getAttribute('aria-label')).toBe(formatDay('2026-04-09'));
        expect(day('2026-04-11')?.getAttribute('aria-label')).toBe(said(CLOSED_DAY, { date: formatDay('2026-04-11') }));

        expect(day('2026-04-07')?.querySelector('.kr-booking__date')?.textContent).toBe(formatDayFace('2026-04-07').date);
        expect(day('2026-04-07')?.textContent).toMatch(/7/);
    });

    it('makes a day with no starts a real disabled button saying No times in text, which reports nothing when pressed', async () => {
        await render();

        const closed = day('2026-04-08');

        expect(closed?.disabled).toBe(true);
        expect(closed?.querySelector('.kr-booking__no-times')?.textContent).toBe(said(NO_TIMES));
        expect(closed?.getAttribute('aria-label')).toBe(said(CLOSED_DAY, { date: formatDay('2026-04-08') }));
        expect(days().filter((candidate) => candidate.disabled).map((candidate) => candidate.value)).toEqual(['2026-04-08', '2026-04-11', '2026-04-12']);
        expect(day('2026-04-09')?.querySelector('.kr-booking__no-times')).toBeNull();

        onSelectDay.mockClear();
        await press(closed);

        expect(onSelectDay).not.toHaveBeenCalled();
    });

    it('renders no day at all without data', async () => {
        await render({ availability: PENDING });

        expect(days()).toHaveLength(0);
        expect(container.querySelector('.kr-booking__days')).toBeNull();
    });

    it('states the selected day in aria-pressed and reports a press by its date', async () => {
        await render({ selectedDay: '2026-04-09' });

        expect(pressed()).toEqual(['2026-04-09']);
        expect(days().map((candidate) => candidate.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true', 'false', 'false', 'false', 'false']);

        await press(day('2026-04-10'));

        expect(onSelectDay).toHaveBeenCalledWith('2026-04-10');
        expect(onPick).not.toHaveBeenCalled();
    });

    it('lays out Previous week, the days, then Next week, each arrow a drawing hidden from assistive technology beside its name', async () => {
        await render({ window: 1 });

        const row = Array.from(container.querySelector('.kr-booking__week')?.children ?? []);

        expect(row.map((child) => child.className)).toEqual(['kr-booking__button kr-booking__previous', 'kr-booking__list kr-booking__days', 'kr-booking__button kr-booking__next']);

        for (const [arrow, name] of [[button(said(PREVIOUS_WEEK_LABEL)), said(PREVIOUS_WEEK_LABEL)], [button(said(NEXT_WEEK_LABEL)), said(NEXT_WEEK_LABEL)]] as const) {
            const drawing = arrow?.querySelector('svg.kr-booking__icon');

            expect(arrow?.textContent).toBe(name);
            expect(drawing?.getAttribute('aria-hidden')).toBe('true');
            expect(drawing?.getAttribute('focusable')).toBe('false');
            expect(drawing?.textContent).toBe('');
            expect(arrow?.querySelector('.kr-booking__chevron')).toBeNull();
            expect(arrow?.querySelector('.kr-booking__arrow-label')?.textContent).toBe(name);
        }

        expect(button(said(PREVIOUS_WEEK_LABEL))?.querySelector('path')?.getAttribute('d')).not.toBe(button(said(NEXT_WEEK_LABEL))?.querySelector('path')?.getAttribute('d'));
    });

    it('offers next on the first week and never previous, both on a later one, moving in whole weeks', async () => {
        await render({ window: 0 });

        expect(button(said(PREVIOUS_WEEK_LABEL))).toBeUndefined();
        expect(container.querySelector('.kr-booking__previous')).toBeNull();
        await press(button(said(NEXT_WEEK_LABEL)));
        expect(onWindow).toHaveBeenLastCalledWith(1);

        await render({ window: 3 });

        await press(button(said(PREVIOUS_WEEK_LABEL)));
        expect(onWindow).toHaveBeenLastCalledWith(2);
        await press(button(said(NEXT_WEEK_LABEL)));
        expect(onWindow).toHaveBeenLastCalledWith(4);
    });

    it('keeps both arrows while the next week loads, with no day between them', async () => {
        await render({ availability: PENDING, window: 1 });

        expect(button(said(PREVIOUS_WEEK_LABEL))).toBeDefined();
        expect(button(said(NEXT_WEEK_LABEL))).toBeDefined();
        expect(days()).toHaveLength(0);
    });

    it('re-seats focus on next when previous unmounts on reaching the first week', async () => {
        await drive([READY, READY], 1);

        await press(button(said(PREVIOUS_WEEK_LABEL)));

        expect(button(said(PREVIOUS_WEEK_LABEL))).toBeUndefined();
        expect(document.activeElement).toBe(button(said(NEXT_WEEK_LABEL)));
    });
});

describe('auto-selection', () => {
    it('selects the first day holding starts on arrival, rendering it in the same pass, with no hint and no focus moved', async () => {
        await render({ availability: { data: LATE, error: null, isFetching: false } });

        expect(onSelectDay).toHaveBeenCalledTimes(1);
        expect(onSelectDay).toHaveBeenCalledWith('2026-04-09');
        expect(pressed()).toEqual(['2026-04-09']);
        expect(slots().map((slot) => slot.value)).toEqual(WIRE['2026-04-09']);
        expect(container.querySelector('.kr-booking__hint')).toBeNull();
        expect(document.activeElement).toBe(document.body);
    });

    it('selects nothing while there is no answer, and nothing when the answer carries the selected day', async () => {
        await render({ availability: PENDING });
        await render({ availability: DROPPED });
        await render({ selectedDay: '2026-04-10' });
        await render({ selectedDay: '2026-04-08' });

        expect(onSelectDay).not.toHaveBeenCalled();
        expect(pressed()).toEqual(['2026-04-08']);
    });

    it('selects the first day holding starts of the next week on an arrow press, keeping focus on the arrow', async () => {
        await drive([READY, { data: NEXT_WEEK, error: null, isFetching: false }]);

        expect(pressed()).toEqual(['2026-04-07']);

        await press(day('2026-04-10'));
        await press(button(said(NEXT_WEEK_LABEL)));

        expect(onSelectDay.mock.calls.map(([date]) => date)).toEqual(['2026-04-07', '2026-04-10', '2026-04-15']);
        expect(pressed()).toEqual(['2026-04-15']);
        expect(slots().map((slot) => slot.value)).toEqual(['2026-04-14T22:00:00+00:00']);
        expect(document.activeElement).toBe(button(said(NEXT_WEEK_LABEL)));
    });

    it('waits for the next week to answer, then selects in it', async () => {
        await drive([READY, PENDING]);
        await press(button(said(NEXT_WEEK_LABEL)));

        expect(onSelectDay.mock.calls.map(([date]) => date)).toEqual(['2026-04-07']);
        expect(days()).toHaveLength(0);

        await act(() => {
            root.render(keyed(<Harness weeks={[READY, { data: NEXT_WEEK, error: null, isFetching: false }]} />));
        });

        expect(onSelectDay.mock.calls.map(([date]) => date)).toEqual(['2026-04-07', '2026-04-15']);
        expect(pressed()).toEqual(['2026-04-15']);
    });

    it('keeps the day the customer chose across a refetch, even one that emptied it: pressed, disabled and the empty-day sentence', async () => {
        await drive([READY]);
        await press(day('2026-04-09'));

        await act(() => {
            root.render(keyed(<Harness weeks={[{ data: DATA, error: null, isFetching: false }]} />));
        });

        expect(pressed()).toEqual(['2026-04-09']);
        expect(slots().map((slot) => slot.value)).toEqual(WIRE['2026-04-09']);

        await act(() => {
            root.render(keyed(<Harness weeks={[{ data: TAKEN, error: null, isFetching: false }]} />));
        });

        expect(onSelectDay.mock.calls.map(([date]) => date)).toEqual(['2026-04-07', '2026-04-09']);
        expect(pressed()).toEqual(['2026-04-09']);
        expect(day('2026-04-09')?.disabled).toBe(true);
        expect(container.querySelector('.kr-booking__empty')?.textContent).toBe(said(EMPTY_DAY_SENTENCE));
        expect(container.querySelector('.kr-booking__hint')).toBeNull();
        expect(slots()).toHaveLength(0);
    });

    it('selects afresh in a week the customer comes back to, the one selected day having gone on with them', async () => {
        await drive([READY, { data: NEXT_WEEK, error: null, isFetching: false }]);
        await press(day('2026-04-13'));
        await press(button(said(NEXT_WEEK_LABEL)));
        await press(day('2026-04-17'));
        await press(button(said(PREVIOUS_WEEK_LABEL)));

        expect(onSelectDay.mock.calls.map(([date]) => date)).toEqual(['2026-04-07', '2026-04-13', '2026-04-15', '2026-04-17', '2026-04-07']);
        expect(pressed()).toEqual(['2026-04-07']);
    });
});

describe('the grid', () => {
    it('renders the selected day\'s starts as buttons carrying the wire value, labelled with the tenant hour, a period at a time', async () => {
        await render({ selectedDay: '2026-04-07' });

        expect(starts()).toEqual(WIRE['2026-04-07'].slice(0, 2));
        expect(slots()[0]?.textContent).toBe(formatTime(wallClockOf('2026-04-06T22:30:00+00:00', TIMEZONE)));
        expect(slots()[0]?.textContent).toMatch(/10:30/);
        expect(slots().every((slot) => slot.getAttribute('aria-pressed') === null)).toBe(true);

        await press(period('Afternoon'));

        expect(starts()).toEqual(WIRE['2026-04-07'].slice(2));
        expect(slots()[0]?.textContent).toMatch(/1:00/);
        expect(slots().every((slot) => slot.getAttribute('aria-pressed') === null)).toBe(true);
    });

    it('renders a start at 22:30 UTC under the next calendar day for a tenant in Pacific/Auckland', async () => {
        await render({ selectedDay: '2026-04-07' });

        expect(pressed()).toEqual(['2026-04-07']);
        expect(slots()[0]?.value).toBe('2026-04-06T22:30:00+00:00');
        expect(slots()[0]?.textContent).toMatch(/10:30/);
        expect(slots()[0]?.textContent).not.toMatch(/22:30|10:30 PM/);
    });

    it('reports the pick as the wire value whole, byte for byte', async () => {
        await render({ selectedDay: '2026-04-07' });

        await press(slots()[1]);

        expect(onPick).toHaveBeenCalledTimes(1);
        expect(onPick).toHaveBeenCalledWith('2026-04-06T22:35:00+00:00');
        expect(onSelectDay).not.toHaveBeenCalled();
    });

    it('renders the sentence and no button at all for an empty day', async () => {
        await render({ selectedDay: '2026-04-08' });

        expect(container.querySelector('.kr-booking__empty')?.textContent).toBe(said(EMPTY_DAY_SENTENCE));
        expect(slots()).toHaveLength(0);
        expect(container.querySelector('.kr-booking__grid')).toBeNull();
        expect(container.querySelector('.kr-booking__empty button')).toBeNull();
    });

    it('renders no hint while a day of the week has starts', async () => {
        await render();

        expect(container.querySelector('.kr-booking__hint')).toBeNull();
        expect(slots().length).toBeGreaterThan(0);
    });

    it('renders the hint beside seven disabled days when every day of the week is empty, selecting nothing', async () => {
        await render({ availability: NOTHING });

        expect(container.querySelector('.kr-booking__hint')?.textContent).toBe(said(HINT_SENTENCE));
        expect(days()).toHaveLength(7);
        expect(days().every((candidate) => candidate.disabled)).toBe(true);
        expect(pressed()).toEqual([]);
        expect(slots()).toHaveLength(0);
        expect(onSelectDay).not.toHaveBeenCalled();
    });
});

describe('the loading state', () => {
    function skeleton(): HTMLElement | null {
        return container.querySelector<HTMLElement>('.kr-booking__skeleton');
    }

    it('shapes a window\'s first read as the grid it waits for: a grid of blanks under the busy step, hidden from assistive technology, the loading line kept above', async () => {
        await render({ availability: PENDING });

        const step = container.querySelector('.kr-booking__step');

        expect(step?.getAttribute('aria-busy')).toBe('true');
        expect(skeleton()?.getAttribute('aria-hidden')).toBe('true');
        expect(skeleton()?.classList.contains('kr-booking__skeleton--grid')).toBe(true);
        expect(skeleton()?.querySelectorAll('.kr-booking__blank')).toHaveLength(6);
        expect(skeleton()?.textContent).toBe('');
        expect(skeleton()?.querySelector('button, [role], [aria-live]')).toBeNull();
        expect(container.querySelector('.kr-booking__loading')?.textContent).toBe(said(LOADING_TIMES_SENTENCE));
        expect(slots()).toHaveLength(0);
    });

    it('shows no blanks over a list it already holds while that list refreshes, nor once a read has failed or answered', async () => {
        await render({ availability: REFRESHING, selectedDay: '2026-04-07' });

        expect(skeleton()).toBeNull();
        expect(slots().length).toBeGreaterThan(0);

        await render({ availability: REFUSED });

        expect(skeleton()).toBeNull();

        await render({ availability: IDLE });

        expect(skeleton()).toBeNull();

        await render();

        expect(skeleton()).toBeNull();
    });
});

describe('Next available date', () => {
    function jump(): HTMLButtonElement | null {
        return container.querySelector<HTMLButtonElement>('.kr-booking__jump');
    }

    it('follows the hint of a week whose every day is empty when the answer names a later day, and reports that day on a press', async () => {
        await render({ availability: NAMED });

        expect(container.querySelector('.kr-booking__hint')?.textContent).toBe(said(HINT_SENTENCE));
        expect(jump()?.textContent).toBe(said(NEXT_AVAILABLE_LABEL));
        expect(jump()?.value).toBe('2026-04-22');
        expect(jump()?.disabled).toBe(false);

        await press(jump() as HTMLButtonElement);

        expect(onNextAvailable).toHaveBeenCalledTimes(1);
        expect(onNextAvailable).toHaveBeenCalledWith('2026-04-22');
        expect(onWindow).not.toHaveBeenCalled();
        expect(onSelectDay).not.toHaveBeenCalled();
    });

    it('renders none where the answer names no later day, or carries no such key, the hint alone', async () => {
        await render({ availability: NONE_LATER });

        expect(container.querySelector('.kr-booking__hint')).not.toBeNull();
        expect(jump()).toBeNull();

        await render({ availability: NOTHING });

        expect(jump()).toBeNull();
    });

    it('follows the sentence of an empty selected day in a week with starts elsewhere, reporting the first such day with no request of its own', async () => {
        await render({ availability: { data: TAKEN, error: null, isFetching: false }, selectedDay: '2026-04-09' });

        expect(container.querySelector('.kr-booking__empty')?.textContent).toBe(said(EMPTY_DAY_SENTENCE));
        expect(jump()?.value).toBe('2026-04-07');

        await press(jump() as HTMLButtonElement);

        expect(onNextAvailable).toHaveBeenCalledWith('2026-04-07');
        expect(onWindow).not.toHaveBeenCalled();
    });

    it('follows the sentence of an empty selected day in a week emptied whole with the later day the answer names', async () => {
        await render({ availability: NAMED, selectedDay: '2026-04-09' });

        expect(container.querySelector('.kr-booking__empty')).not.toBeNull();
        expect(jump()?.value).toBe('2026-04-22');
    });

    it('renders none beside a day holding starts, nor while no answer has come', async () => {
        await render({ selectedDay: '2026-04-07' });

        expect(jump()).toBeNull();

        await render({ availability: PENDING });

        expect(jump()).toBeNull();
    });

    it('renders none in a list whose caller takes no such report, as the reschedule dialog\'s does', async () => {
        await act(() => {
            root.render(keyed(<SlotList timezone={TIMEZONE} availability={NAMED} window={0} selectedDay={null} onWindow={onWindow} onSelectDay={onSelectDay} onPick={onPick} />));
        });

        expect(container.querySelector('.kr-booking__hint')).not.toBeNull();
        expect(jump()).toBeNull();
    });
});

describe('the period control', () => {
    it('offers only the periods holding starts, each a real button whose accessible name carries its count, in a group named for the time of day', async () => {
        await render({ availability: SPLIT, selectedDay: '2026-04-07' });

        const group = container.querySelector('.kr-booking__periods');

        expect(group?.getAttribute('role')).toBe('group');
        expect(group?.getAttribute('aria-label')).toBe(said(PERIODS_LABEL));
        expect(group?.previousElementSibling?.textContent).toBe(said(TIME_CAPTION));
        expect(group?.previousElementSibling?.previousElementSibling?.classList.contains('kr-booking__week')).toBe(true);
        expect(group?.nextElementSibling?.classList.contains('kr-booking__grid')).toBe(true);
        expect(periods().map(periodName)).toEqual(['Morning', 'Afternoon', 'Evening']);
        expect(periods().map((control) => control.querySelector('.kr-booking__count')?.textContent)).toEqual([saidBy(TIMES_COUNT, 3), saidBy(TIMES_COUNT, 1), saidBy(TIMES_COUNT, 2)]);
        expect(periods().map((control) => control.textContent)).toEqual([`${said(PERIOD_NAMES.Morning)} ${saidBy(TIMES_COUNT, 3)}`, `${said(PERIOD_NAMES.Afternoon)} ${saidBy(TIMES_COUNT, 1)}`, `${said(PERIOD_NAMES.Evening)} ${saidBy(TIMES_COUNT, 2)}`]);
        expect(periods().every((control) => control.type === 'button' && !control.disabled && !control.hasAttribute('aria-label') && !control.hasAttribute('aria-labelledby'))).toBe(true);
        expect(periods().map(nameOf)).toEqual([`${said(PERIOD_NAMES.Morning)} ${saidBy(TIMES_COUNT, 3)}`, `${said(PERIOD_NAMES.Afternoon)} ${saidBy(TIMES_COUNT, 1)}`, `${said(PERIOD_NAMES.Evening)} ${saidBy(TIMES_COUNT, 2)}`]);

        await render({ availability: SPLIT, selectedDay: '2026-04-09' });

        expect(periods().map(periodName)).toEqual(['Morning', 'Evening']);
        expect(periods().map((control) => control.querySelector('.kr-booking__count')?.textContent)).toEqual([saidBy(TIMES_COUNT, 1), saidBy(TIMES_COUNT, 1)]);
        expect(periods().map(nameOf)).toEqual([`${said(PERIOD_NAMES.Morning)} ${saidBy(TIMES_COUNT, 1)}`, `${said(PERIOD_NAMES.Evening)} ${saidBy(TIMES_COUNT, 1)}`]);
    });

    it('presses the first period and shows its starts alone, and a press moves both, keeping the focus and reporting nothing', async () => {
        await drive([SPLIT]);

        expect(pressedPeriods()).toEqual(['Morning']);
        expect(periods().map((control) => control.getAttribute('aria-pressed'))).toEqual(['true', 'false', 'false']);
        expect(starts()).toEqual(MORNING);

        const evening = period('Evening');

        onSelectDay.mockClear();
        await press(evening);

        expect(periods().map((control) => control.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true']);
        expect(starts()).toEqual(EVENING);
        expect(document.activeElement).toBe(evening);
        expect(onSelectDay).not.toHaveBeenCalled();
        expect(onPick).not.toHaveBeenCalled();

        await press(period('Afternoon'));

        expect(pressedPeriods()).toEqual(['Afternoon']);
        expect(starts()).toEqual(AFTERNOON);

        await press(slots()[0]);

        expect(onPick).toHaveBeenCalledWith('2026-04-07T00:00:00+00:00');
    });

    it('renders no control for a day whose starts fall in one period, only the grid', async () => {
        await render({ availability: SPLIT, selectedDay: '2026-04-08' });

        expect(container.querySelector('.kr-booking__periods')).toBeNull();
        expect(periods()).toHaveLength(0);
        expect(starts()).toEqual(SPLIT_WIRE['2026-04-08']);
    });

    it('chooses the first period with starts afresh whenever the shown day changes', async () => {
        await drive([SPLIT]);
        await press(period('Evening'));
        await press(day('2026-04-09'));

        expect(pressedPeriods()).toEqual(['Morning']);
        expect(starts()).toEqual(['2026-04-08T20:00:00+00:00']);

        await press(period('Evening'));
        await press(day('2026-04-07'));

        expect(pressedPeriods()).toEqual(['Morning']);
        expect(starts()).toEqual(MORNING);
    });

    it('opens on the period of the chosen start when it lies on the shown day, as on Back from details', async () => {
        await render({ availability: SPLIT, selectedDay: '2026-04-07', chosenStart: '2026-04-07T00:00:00+00:00' });

        expect(pressedPeriods()).toEqual(['Afternoon']);
        expect(starts()).toEqual(AFTERNOON);
        expect(document.activeElement).toBe(document.body);
    });

    it('opens on the first period when the chosen start lies on another day', async () => {
        await render({ availability: SPLIT, selectedDay: '2026-04-07', chosenStart: '2026-04-09T07:00:00+00:00' });

        expect(pressedPeriods()).toEqual(['Morning']);
        expect(starts()).toEqual(MORNING);
    });

    it('reads the chosen start on mount alone, the first period ruling once the day has changed', async () => {
        await drive([SPLIT], 0, '2026-04-07T06:00:00+00:00');

        expect(pressedPeriods()).toEqual(['Evening']);
        expect(starts()).toEqual(EVENING);

        await press(day('2026-04-09'));
        await press(day('2026-04-07'));

        expect(pressedPeriods()).toEqual(['Morning']);
        expect(starts()).toEqual(MORNING);
    });

    it('keeps the chosen period across a refetch that leaves it starts, and falls back to the first when one empties it', async () => {
        await drive([SPLIT]);
        await press(period('Evening'));

        await act(() => {
            root.render(keyed(<Harness weeks={[{ data: parseAvailability(SPLIT_WIRE) as Availability, error: null, isFetching: false }]} />));
        });

        expect(pressedPeriods()).toEqual(['Evening']);

        await act(() => {
            root.render(keyed(<Harness weeks={[{ data: parseAvailability({ ...SPLIT_WIRE, '2026-04-07': [...MORNING, ...AFTERNOON] }) as Availability, error: null, isFetching: false }]} />));
        });

        expect(periods().map(periodName)).toEqual(['Morning', 'Afternoon']);
        expect(pressedPeriods()).toEqual(['Morning']);
        expect(starts()).toEqual(MORNING);

        await act(() => {
            root.render(keyed(<Harness weeks={[SPLIT]} />));
        });

        expect(pressedPeriods()).toEqual(['Morning']);
        expect(starts()).toEqual(MORNING);
    });
});
