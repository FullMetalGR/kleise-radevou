import { CHANNEL_WORDS, inWords, SOURCE_WORDS } from '@/bookings/model';
import { formatCount, formatDate, formatPercent, type TenantClock } from '@/lib/format';
import { unitFigure } from '@/lib/names';
import type { ShellWords } from '@/lib/strings';
import type { ReportsKey, ReportsWords } from './words';

/*
 * The Reports screen's data as the server sends it, ReportController, RangeTiles and
 * ReportPayload. The range and the filters are what the server answered, never what was asked
 * for: a bad custom range reads as this week and a member the screen does not offer as all of
 * them, and the screen restates what it was given. Every amount is minor units beside the
 * currency that line carries, formatted in that currency at its scale and never in the tenant's
 * default.
 */

export type RangeWord = 'today' | 'yesterday' | 'week' | 'last_7_days' | 'last_30_days' | 'month' | 'last_month' | 'year' | 'custom';

/** The Range's choices in the order it lists them, the custom range last. */
export const RANGE_WORDS: readonly RangeWord[] = ['today', 'yesterday', 'week', 'last_7_days', 'last_30_days', 'month', 'last_month', 'year', 'custom'];

/** The most days a custom range holds, FigureRange::MOST_DAYS. */
export const MOST_DAYS = 366;

/** A range the server answered: its word and its two dates, the To date inclusive. */
export type NamedRange = { word: RangeWord; from: string; to: string };

export type ReportFilters = { staff: number | null; service: number | null };

export type ReportChoice = { id: number; name: string };

export type ReportOptions = { staff: ReportChoice[]; services: ReportChoice[] };

export type MoneyLine = { currency: string; minor: number };

/** A tile's change against the period before, FigureChange. */
export type FigureChange = { kind: 'up' | 'down' | 'same' | 'from_zero' | 'none'; percent: number | null };

type CountTile = { value: number; previous: number; change: FigureChange };

/** The six tiles over a range, the shape Home's and Reports' share. */
export type RangeFigures = {
    range: NamedRange;
    before: { from: string; to: string };
    tiles: {
        bookings: CountTile;
        revenue: { value: MoneyLine; previous: MoneyLine; change: FigureChange; elsewhere: MoneyLine[] };
        occupancy: { value: number | null; previous: number | null; change: FigureChange; filled_minutes: number; offered_minutes: number };
        new_customers: CountTile & { returning: number };
        cancelled: CountTile;
        no_shows: CountTile;
    };
};

export type ReportRow = { bookings: number; revenue: MoneyLine[] };

type StatusRow = { status: string; bookings: number };

/** Over time's bucket unit, which its headings follow. */
export type OverTimeUnit = 'day' | 'week' | 'month';

/** One bucket of Over time: two dates, the To inclusive, its counted bookings and their revenue in the tenant's currency. */
export type OverTimeRow = { from: string; to: string; bookings: number; revenue: MoneyLine };

export type Report = {
    statuses: StatusRow[];
    revenue: { confirmed: MoneyLine[]; completed: MoneyLine[] };
    no_show: { no_show: number; completed: number };
    services: (ReportRow & { id: number; name: string })[];
    staff: (ReportRow & { id: number; name: string })[];
    sources: (ReportRow & { source: string })[];
    channels: (ReportRow & { channel: string | null })[];
    cancelled_by: { customer: number; business: number; unrecorded: number };
    over_time: { unit: OverTimeUnit; rows: OverTimeRow[] };
    /** Occupancy by staff: a member's booked and available minutes and the percentage to one decimal, null where nothing is available. */
    occupancy: { id: number; name: string; booked_minutes: number; available_minutes: number; occupancy: number | null }[];
};

/**
 * The query string of a range and the filters, the defaults left out, so this week over every
 * member and service is the bare screen: a word, for a custom range its two dates, the To date
 * inclusive, and each filter by its id.
 */
export function reportQuery(range: NamedRange, filters: ReportFilters): Record<string, string> {
    const query: Record<string, string> = {};

    if (range.word !== 'week') {
        query.range = range.word;
    }

    if (range.word === 'custom') {
        query.from = range.from;
        query.to = range.to;
    }

    if (filters.staff !== null) {
        query.staff = String(filters.staff);
    }

    if (filters.service !== null) {
        query.service = String(filters.service);
    }

    return query;
}

/** Download CSV's address: the file of the range and the filters on screen, by the page's own query. */
export function csvUrl(range: NamedRange, filters: ReportFilters): string {
    const query = new URLSearchParams(reportQuery(range, filters)).toString();

    return query === '' ? '/admin/reports/csv' : `/admin/reports/csv?${query}`;
}

/** A figure of minutes as hours, with the minutes where there are any, each unit ICU's: the Occupancy tile's and Occupancy by staff's. */
export function hoursOf(minutes: number, words: ReportsWords): string {
    const hours = unitFigure(Math.floor(minutes / 60), 'hour', words.language);
    const rest = minutes % 60;

    return rest === 0 ? hours : words.text('tiles.hours_minutes', { hours, minutes: unitFigure(rest, 'minute', words.language) });
}

/** An occupancy, a percentage the server gave to one decimal, written with that decimal always, in the viewer's locale. */
export function formatOccupancy(value: number, locale: string): string {
    return new Intl.NumberFormat(locale, { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value / 100);
}

/** A currency's name in words as the locale writes an amount of it ("euros"), or its code where the locale has none. */
export function currencyName(currency: string, locale: string): string {
    try {
        const parts = new Intl.NumberFormat(locale, { style: 'currency', currency, currencyDisplay: 'name' }).formatToParts(2);

        return parts.find((part) => part.type === 'currency')?.value.trim() || currency;
    } catch {
        return currency;
    }
}

/**
 * The range restated in one whole sentence of its word with its dates in the tenant's date
 * format, a single day once: today and yesterday by their day, a custom range of one day by its
 * own sentence, every other range by its two dates.
 */
export function rangeLine(range: NamedRange, clock: TenantClock, words: ReportsWords): string {
    const date = formatDate(range.from, clock);

    if (range.word === 'today' || range.word === 'yesterday') {
        return words.text(`filters.stated.${range.word}`, { date });
    }

    if (range.word === 'custom' && range.from === range.to) {
        return words.text('filters.stated.custom_day', { date });
    }

    return words.text(`filters.stated.${range.word}`, { from: date, to: formatDate(range.to, clock) });
}

/** A day's number since the epoch, read from its Y-m-d in UTC. */
function epochDay(day: string): number {
    const [year = 0, month = 1, date = 1] = day.split('-').map(Number);

    return Date.UTC(year, month - 1, date) / 86_400_000;
}

/** How many dates a pair holds, both ends counted. */
export function spanDays(from: string, to: string): number {
    return epochDay(to) - epochDay(from) + 1;
}

/** What keeps a custom pair from being read: the To date before the From, or more than MOST_DAYS days. */
export function customProblem(from: string, to: string): 'to_before_from' | 'too_long' | null {
    if (to < from) {
        return 'to_before_from';
    }

    return spanDays(from, to) > MOST_DAYS ? 'too_long' : null;
}

/** The kind of range a comparison names its period before by. */
const COMPARED: Record<Exclude<RangeWord, 'custom'>, 'day' | 'week' | 'last_7_days' | 'last_30_days' | 'month' | 'year'> = {
    today: 'day',
    yesterday: 'day',
    week: 'week',
    last_7_days: 'last_7_days',
    last_30_days: 'last_30_days',
    month: 'month',
    last_month: 'month',
    year: 'year',
};

/**
 * A change against the period before as one whole sentence of its kind of range ("+15% vs the
 * week before"), a custom range's chosen by its days; nothing to compare reads as no change. A
 * rise or a fall is one figure, :change, its sign and its percent mark written by ICU in the
 * user's language, a fall the server rounded to nought still signed as a fall, as Home's is.
 */
export function changeSentence(change: FigureChange, range: NamedRange, words: ReportsWords): string {
    const kind = change.kind === 'none' ? 'same' : change.kind === 'up' || change.kind === 'down' ? 'by' : change.kind;
    const percent = Math.abs(change.percent ?? 0);
    const values: Record<string, string> = kind === 'by' ? { change: formatPercent(change.kind === 'down' ? -percent : percent, words.locale, true) } : {};

    if (range.word === 'custom') {
        const days = spanDays(range.from, range.to);

        return words.choice(`compare.custom.${kind}`, days, { ...values, count: formatCount(days, words.locale) });
    }

    const key: ReportsKey = `compare.${COMPARED[range.word]}.${kind}`;

    return words.text(key, values);
}

/** The no-show rate, no-shows over completed plus no-shows, or null where both are zero. */
export function noShowRate(noShows: number, completed: number): number | null {
    const decided = noShows + completed;

    return decided === 0 ? null : noShows / decided;
}

/**
 * A rate as a percentage in the user's language, the locale its words carry, to one decimal
 * place at most.
 */
export function formatRate(rate: number, locale: string): string {
    return new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 1 }).format(rate);
}

/** A source in the dialog's words, in the shell's language; a value outside the three shows verbatim. */
export function sourceLabel(source: string, shell: ShellWords): string {
    return inWords(SOURCE_WORDS, source, shell);
}

/** A channel in the dialog's words, None for the bookings no tagged link brought; another value shows verbatim. */
export function channelLabel(channel: string | null, shell: ShellWords, words: ReportsWords): string {
    return channel === null ? words.text('tables.no_channel') : inWords(CHANNEL_WORDS, channel, shell);
}

/** The statuses Bookings by status always shows, in their fixed order. */
const ALWAYS: readonly string[] = ['confirmed', 'completed', 'awaiting_approval', 'cancelled', 'no_show'];

/** The statuses it shows beneath them, in this order, only where a booking holds one. */
const WHEN_ANY: readonly string[] = ['awaiting_payment', 'pending', 'rejected', 'expired'];

/** Bookings by status's rows: the five always shown, then the other four above nought. */
export function statusRows(statuses: StatusRow[]): StatusRow[] {
    const count = (status: string): number => statuses.find((row) => row.status === status)?.bookings ?? 0;

    return [...ALWAYS.map((status) => ({ status, bookings: count(status) })), ...WHEN_ANY.filter((status) => count(status) > 0).map((status) => ({ status, bookings: count(status) }))];
}

/**
 * A bar chart's value axis: about three steps of one, two or five times a power of ten, whole
 * numbers only, to the first step at or above the largest value; one step to one where every
 * value is zero.
 */
export function chartAxis(max: number): { top: number; ticks: number[] } {
    if (max <= 0) {
        return { top: 1, ticks: [0, 1] };
    }

    const raw = max / 3;
    const power = 10 ** Math.floor(Math.log10(raw));
    const scaled = raw / power;
    const step = Math.max(1, (scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10) * power);
    const top = Math.ceil(max / step) * step;
    const ticks: number[] = [];

    for (let value = 0; value <= top; value += step) {
        ticks.push(value);
    }

    return { top, ticks };
}

/** The chart's geometry in its own units. */
export const CHART = { left: 36, base: 136, height: 120, pitch: 32, room: 352 } as const;

type ChartBar = { index: number; x: number; y: number; width: number; height: number };

/**
 * A bar chart laid out: the axis's labels in the first 36 units, a slot of 32 units a bucket with
 * a bar three quarters of it, narrower slots past eleven buckets so the chart stays near 388
 * units wide, the base at 136 and the top 120 above it; a zero draws no bar.
 */
export function chartBars(values: number[]): { width: number; pitch: number; bars: ChartBar[]; centers: number[]; ticks: { value: number; y: number }[] } {
    const pitch = Math.min(CHART.pitch, CHART.room / Math.max(1, values.length));
    const bar = pitch * 0.75;
    const axis = chartAxis(Math.max(0, ...values));
    const heightOf = (value: number): number => (value / axis.top) * CHART.height;
    const bars = values.flatMap((value, index) =>
        value > 0 ? [{ index, x: CHART.left + pitch * index + (pitch - bar) / 2, y: CHART.base - heightOf(value), width: bar, height: heightOf(value) }] : [],
    );

    return {
        width: CHART.left + pitch * values.length,
        pitch,
        bars,
        centers: values.map((_, index) => CHART.left + pitch * index + pitch / 2),
        ticks: axis.ticks.map((value) => ({ value, y: CHART.base - heightOf(value) })),
    };
}
