import type { ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Initials } from '@/components/initials';
import { Segmented } from '@/components/ui/segmented';
import { Arcs } from '@/figures/arcs';
import { type ChangeKind, FigureLine, FigureTile, Gauge, Strip } from '@/figures/kit';
import { formatAmount, formatCount, formatDate, formatPercent, type TenantClock } from '@/lib/format';
import { unitFigure } from '@/lib/names';
import { navIcon } from '@/shell/icons';
import type { HomeWords } from './words';

/*
 * Home's range and its figures: the range row, the six tiles, the footnote and the two Top
 * lists, every figure the server's range_figures prop. Nothing is counted here; the screen only
 * words the facts.
 *
 * The tiles: revenue the hero on the strong fill, the arcs in its lower corner, bookings and
 * occupancy large, occupancy with the
 * ring gauge beside its figure, cancelled on the attention fill, the rest regular. In the screen's
 * widest box revenue runs down the left beside the other five; narrower, it spans the top; on a
 * phone bookings and new customers share a line and the other three take a line each.
 *
 * A tile's comparison is one whole sentence per kind of range, the day before for today and
 * yesterday, the week before, the month before, so a language inflects the period itself: a rise
 * or a fall by its signed percentage, no change, or up from nought. Nought against nought is no
 * change; a period with nothing to compare, an occupancy with no hours offered on either side,
 * says nothing and draws no chip. The chip is green for a rise in a figure where more is better,
 * bookings, revenue, occupancy and new customers, and neutral otherwise; the words carry the
 * change either way.
 *
 * Every figure, a count, an amount or a percentage, is spelled by Intl in the user's language,
 * the locale the words carry, never the machine's.
 */

type Money = { currency: string; minor: number };

type Change = { kind: 'up' | 'down' | 'same' | 'from_zero' | 'none'; percent: number | null };

type Count = { value: number; previous: number; change: Change };

type RangeWord = 'today' | 'yesterday' | 'week' | 'month';

/** Home's range_figures prop. */
export type RangeFigures = {
    range: { word: RangeWord; from: string; to: string };
    before: { from: string; to: string };
    tiles: {
        bookings: Count;
        revenue: { value: Money; previous: Money; change: Change; elsewhere: Money[] };
        occupancy: { value: number | null; previous: number | null; change: Change; filled_minutes: number; offered_minutes: number };
        new_customers: Count & { returning: number };
        cancelled: Count;
        no_shows: Count;
    };
    top_services: { id: number; name: string; bookings: number; revenue: Money }[];
    top_staff: { id: number; name: string; business: boolean; bookings: number; revenue: Money }[];
};

const WORDS: readonly RangeWord[] = ['today', 'yesterday', 'week', 'month'];

/** The period a range is compared with, by the kind of range. */
const PERIOD: Record<RangeWord, 'day' | 'week' | 'month'> = { today: 'day', yesterday: 'day', week: 'week', month: 'month' };

/**
 * The range row: the four ranges as wide as their labels, the one shown pressed, and the range's
 * line with its dates beside them where there is room; on a phone the four two by two across the
 * full width.
 */
export function RangeRow({
    range,
    clock,
    words,
    busy,
    failed,
    onChoose,
}: {
    range: RangeFigures['range'];
    clock: TenantClock;
    words: HomeWords;
    /** Whether a range's figures are being read. */
    busy: boolean;
    /** Whether the last read failed, said under the line in Home's own sentence. */
    failed: boolean;
    onChoose: (word: RangeWord) => void;
}) {
    const line =
        range.word === 'today' || range.word === 'yesterday'
            ? words.text(`range.line.${range.word}`, { date: formatDate(range.from, clock) })
            : words.text(`range.line.${range.word}`, { from: formatDate(range.from, clock), to: formatDate(range.to, clock) });

    return (
        <div className="admin-home-range flex flex-wrap items-center gap-x-4 gap-y-2" aria-busy={busy}>
            <Segmented
                className="max-desktop:grid max-desktop:w-full max-desktop:grid-cols-2"
                label={words.text('range.label')}
                options={WORDS.map((word) => ({ value: word, label: words.text(`range.${word}`) }))}
                value={range.word}
                onChange={(word) => {
                    if (word !== range.word) {
                        onChoose(word);
                    }
                }}
            />
            <p className="admin-home-range__line text-body-sm text-muted-foreground">{line}</p>
            {failed && (
                <p role="alert" className="admin-home-range__error basis-full text-body-sm text-destructive">
                    {words.text('range.error')}
                </p>
            )}
        </div>
    );
}

/** A span of minutes as hours and minutes, the minutes left out where there are none. */
function duration(minutes: number, words: HomeWords): string {
    const rest = minutes % 60;
    const hours = unitFigure(Math.floor(minutes / 60), 'hour', words.language);

    return rest === 0 ? hours : words.text('tiles.duration', { hours, minutes: unitFigure(rest, 'minute', words.language) });
}

/** A tile's comparison sentence, or null where there is nothing to compare. */
function comparison(change: Change, noughts: boolean, word: RangeWord, words: HomeWords): string | null {
    const period = PERIOD[word];

    switch (change.kind) {
        case 'up':
        case 'down': {
            const percent = Math.abs(change.percent ?? 0);

            return words.text(`change.${period}.by`, { change: formatPercent(change.kind === 'down' ? -percent : percent, words.locale, true) });
        }
        case 'same':
            return words.text(`change.${period}.same`);
        case 'from_zero':
            return words.text(`change.${period}.from_zero`);
        default:
            return noughts ? words.text(`change.${period}.same`) : null;
    }
}

/** The chip's direction for a comparison said in words: nought against nought is no change, and nothing said draws no chip. */
function kindOf(change: Change, said: string | null): ChangeKind | null {
    if (said === null) {
        return null;
    }

    return change.kind === 'none' ? 'same' : change.kind;
}

type TileId = 'bookings' | 'revenue' | 'occupancy' | 'new_customers' | 'cancelled' | 'no_shows';

/**
 * Where each tile sits on the six columns, filled densely so a later tile takes a gap an earlier
 * wide one left: revenue alone on the first line, then bookings and occupancy, then the other
 * three; in the widest box revenue down the left over two lines beside the rest. Below the
 * desktop breakpoint every tile takes a line of its own, and bookings stands beside new customers
 * only once the screen's box is 32rem wide: a tile's comparison is a whole sentence, and in a
 * half of anything narrower the longest one, in Greek, runs past three lines.
 */
const PLACES: Record<TileId, string> = {
    revenue: 'col-span-6 row-start-1 @5xl:col-[1/2] @5xl:row-[1/span_2]',
    bookings: 'col-span-3 max-desktop:col-span-6 max-desktop:@lg:col-span-3',
    occupancy: 'col-span-3 max-desktop:col-span-6',
    new_customers: 'col-span-2 max-desktop:col-span-6 max-desktop:@lg:col-span-3',
    cancelled: 'col-span-2 max-desktop:col-span-6',
    no_shows: 'col-span-2 max-desktop:col-span-6',
};

/** The six tiles, then the footnote on what is counted. */
export function Tiles({ figures, words }: { figures: RangeFigures; words: HomeWords }) {
    const { tiles } = figures;
    const word = figures.range.word;
    const compared = (change: Change, noughts: boolean) => {
        const said = comparison(change, noughts, word, words);

        return { change: said, kind: kindOf(change, said) };
    };
    const counted = (id: TileId, label: string, count: Count, moreIsBetter: boolean, rest: { size?: 'large'; fill?: 'attention'; children?: ReactNode } = {}) => (
        <FigureTile id={id} title={label} value={formatCount(count.value, words.locale)} {...compared(count.change, count.value === 0 && count.previous === 0)} moreIsBetter={moreIsBetter} className={PLACES[id]} {...rest} />
    );
    const { occupancy, revenue } = tiles;

    return (
        <>
            <div className="admin-home-figures grid grid-flow-row-dense grid-cols-6 gap-4.5 max-wide:gap-3.5 @5xl:grid-cols-[minmax(0,7fr)_repeat(6,minmax(0,2fr))]">
                {counted('bookings', words.text('tiles.bookings'), tiles.bookings, true, { size: 'large' })}
                {/* In dark mode the title and the lines take the full ink: the quiet ink sits too
                    close to the strong fill there to keep its contrast where a ring runs under it. */}
                <FigureTile
                    id="revenue"
                    title={words.text('tiles.revenue')}
                    value={formatAmount(revenue.value.minor, revenue.value.currency, words.locale)}
                    {...compared(revenue.change, revenue.value.minor === 0 && revenue.previous.minor === 0)}
                    moreIsBetter
                    size="hero"
                    fill="strong"
                    className={`relative isolate dark:text-foreground ${PLACES.revenue}`}
                >
                    {revenue.elsewhere.map((line) => (
                        <FigureLine key={line.currency}>{formatAmount(line.minor, line.currency, words.locale)}</FigureLine>
                    ))}
                    <Arcs edges={{ right: -90, bottom: -110 }} width={400} tone="primary" strength={22} />
                </FigureTile>
                {occupancy.value === null ? (
                    <FigureTile id="occupancy" title={words.text('tiles.occupancy')} size="large" className={PLACES.occupancy}>
                        <FigureLine>{words.text('tiles.no_hours')}</FigureLine>
                    </FigureTile>
                ) : (
                    <FigureTile
                        id="occupancy"
                        title={words.text('tiles.occupancy')}
                        value={formatPercent(occupancy.value, words.locale)}
                        {...compared(occupancy.change, occupancy.value === 0 && occupancy.previous === 0)}
                        moreIsBetter
                        size="large"
                        aside={<Gauge percent={occupancy.value} />}
                        className={PLACES.occupancy}
                    >
                        <FigureLine>{words.text('tiles.filled', { filled: duration(occupancy.filled_minutes, words), offered: duration(occupancy.offered_minutes, words) })}</FigureLine>
                    </FigureTile>
                )}
                {counted('new_customers', words.text('tiles.new_customers'), tiles.new_customers, true, {
                    children: <FigureLine>{words.choice('tiles.returning', tiles.new_customers.returning, { count: formatCount(tiles.new_customers.returning, words.locale) })}</FigureLine>,
                })}
                {counted('cancelled', words.text('tiles.cancelled'), tiles.cancelled, false, { fill: 'attention' })}
                {counted('no_shows', words.text('tiles.no_shows'), tiles.no_shows, false)}
            </div>
            <p className="admin-home-footnote text-small text-muted-foreground">{words.text('footnote')}</p>
        </>
    );
}

type TopRow = { id: number; name: string; bookings: number; revenue: Money; business?: boolean };

/**
 * A row's share of the first row's figure, as a width: the server ranks the rows by revenue, then
 * by bookings, so the first row's revenue is the longest bar and every other row its share of it.
 * Where the first row earned nothing, every row did, and their bookings alone ranked them.
 */
function shareOf(row: TopRow, first: TopRow): string {
    const [part, whole] = first.revenue.minor > 0 ? [row.revenue.minor, first.revenue.minor] : [row.bookings, first.bookings];
    const share = whole > 0 ? Math.min(1, Math.max(0, part / whole)) : 0;

    return `${Math.round(share * 1000) / 10}%`;
}

/**
 * Top services as a ranked list: each row the icon Services carries in the navigation in a soft
 * circle, the name and its revenue, then a bar of the row's share of the first row's figure and
 * the row's bookings in words. The bar and the icon are decoration; the figures are read as text.
 */
function ServiceRows({ rows, words }: { rows: TopRow[]; words: HomeWords }) {
    const Icon = navIcon('services');
    const first = rows[0];

    return (
        <ol className="admin-home-top__list flex flex-col gap-4.5 p-4">
            {rows.map((row) => (
                <li key={row.id} className="admin-home-top__row flex items-center gap-3.5" data-business={row.business === true ? 'true' : undefined}>
                    <span className="admin-home-top__icon grid size-11.5 shrink-0 place-items-center rounded-full bg-muted text-primary-deep" aria-hidden="true">
                        <Icon className="size-5" aria-hidden="true" />
                    </span>
                    <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                        <div className="flex items-baseline justify-between gap-3">
                            <span className="admin-home-top__name min-w-0 font-display text-base font-semibold wrap-anywhere text-foreground">{row.name}</span>
                            <span className="admin-home-top__revenue shrink-0 font-bold text-foreground tabular-nums">{formatAmount(row.revenue.minor, row.revenue.currency, words.locale)}</span>
                        </div>
                        <span className="admin-home-top__bar block h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                            <span className="block h-full rounded-full bg-primary" style={{ width: first === undefined ? '0%' : shareOf(row, first) }} />
                        </span>
                        <span className="admin-home-top__bookings text-[0.84375rem] text-muted-foreground">{words.text('top.bookings_line', { count: formatCount(row.bookings, words.locale) })}</span>
                    </div>
                </li>
            ))}
        </ol>
    );
}

/**
 * Top staff as cards on the panel fill: each the member's initials, the name, then the bookings
 * and the revenue as terms over their values, the two pairs side by side where the card holds both
 * and one under the other where it does not, so a figure never breaks inside itself. The cards
 * stand one under another in the column the screen gives them, and side by side, wrapping, on a
 * phone.
 */
function StaffCards({ rows, words }: { rows: TopRow[]; words: HomeWords }) {
    return (
        <ol className="admin-home-top__list flex flex-col gap-3 p-4 max-desktop:flex-row max-desktop:flex-wrap">
            {rows.map((row) => (
                <li key={row.id} className="admin-home-top__row flex min-w-0 flex-col gap-2.5 rounded-inner bg-muted p-4 max-desktop:min-w-[9.5rem] max-desktop:flex-1" data-business={row.business === true ? 'true' : undefined}>
                    <Initials name={row.name} size="person" className="admin-home-top__avatar size-13 text-base" />
                    <span className="admin-home-top__name font-display text-base font-semibold wrap-anywhere text-foreground">{row.name}</span>
                    <dl className="flex flex-wrap gap-x-4.5 gap-y-2">
                        <div className="min-w-0">
                            <dt className="text-small text-muted-foreground">{words.text('top.bookings')}</dt>
                            <dd className="admin-home-top__bookings font-display text-lg font-semibold text-foreground tabular-nums">{formatCount(row.bookings, words.locale)}</dd>
                        </div>
                        <div className="min-w-0">
                            <dt className="text-small text-muted-foreground">{words.text('top.revenue')}</dt>
                            <dd className="admin-home-top__revenue font-display text-lg font-semibold text-foreground tabular-nums">{formatAmount(row.revenue.minor, row.revenue.currency, words.locale)}</dd>
                        </div>
                    </dl>
                </li>
            ))}
        </ol>
    );
}

/** A Top list on the kit's card, under its header strip, or a line saying nothing was counted. */
function Top({ id, title, rows, words }: { id: 'services' | 'staff'; title: string; rows: TopRow[]; words: HomeWords }) {
    return (
        <Card flush className={`admin-home-top admin-home-top--${id}`} aria-labelledby={`home-top-${id}-title`}>
            <Strip titleId={`home-top-${id}-title`} title={title} />
            {rows.length === 0 ? <EmptyState className="admin-home-top__empty py-8">{words.text('top.empty')}</EmptyState> : id === 'services' ? <ServiceRows rows={rows} words={words} /> : <StaffCards rows={rows} words={words} />}
        </Card>
    );
}

/** Top services, then Top staff for the range, each a card of the row the screen places them in. */
export function TopTables({ figures, words }: { figures: RangeFigures; words: HomeWords }) {
    return (
        <>
            <Top id="services" title={words.text('top.services')} rows={figures.top_services} words={words} />
            <Top id="staff" title={words.text('top.staff')} rows={figures.top_staff} words={words} />
        </>
    );
}
