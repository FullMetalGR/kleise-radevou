import type { ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Segmented } from '@/components/ui/segmented';
import { Table, TableBody, TableHead, TableRow, Td, Th } from '@/components/ui/table';
import { type ChangeKind, FigureLine, FigureTile, Gauge, Strip } from '@/figures/kit';
import { formatAmount, formatCount, formatDate, formatPercent, type TenantClock } from '@/lib/format';
import { unitFigure } from '@/lib/names';
import type { HomeWords } from './words';

/*
 * Home's range and its figures: the range row, the six tiles, the footnote and the two Top
 * tables, every figure the server's range_figures prop. Nothing is counted here; the screen only
 * words the facts.
 *
 * The tiles: revenue the hero on the strong fill, bookings and occupancy large, occupancy with the
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
                <FigureTile
                    id="revenue"
                    title={words.text('tiles.revenue')}
                    value={formatAmount(revenue.value.minor, revenue.value.currency, words.locale)}
                    {...compared(revenue.change, revenue.value.minor === 0 && revenue.previous.minor === 0)}
                    moreIsBetter
                    size="hero"
                    fill="strong"
                    className={PLACES.revenue}
                >
                    {revenue.elsewhere.map((line) => (
                        <FigureLine key={line.currency}>{formatAmount(line.minor, line.currency, words.locale)}</FigureLine>
                    ))}
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
 * A Top table on the kit's card. It sits in a column of four or three twelfths in the screen's
 * widest box, too narrow for its grid, so it reads as rows wherever its own box is narrow as well
 * as below the wide breakpoint.
 */
function Top({ id, title, rows, words }: { id: 'services' | 'staff'; title: string; rows: TopRow[]; words: HomeWords }) {
    return (
        <Card flush className={`admin-home-top admin-home-top--${id}`} aria-labelledby={`home-top-${id}-title`}>
            <Strip titleId={`home-top-${id}-title`} title={title} />
            {rows.length === 0 ? (
                <EmptyState className="admin-home-top__empty py-8">{words.text('top.empty')}</EmptyState>
            ) : (
                <Table compact="rows">
                    <TableHead>
                        <TableRow>
                            <Th>{words.text('top.name')}</Th>
                            <Th className="text-right">{words.text('top.bookings')}</Th>
                            <Th className="text-right">{words.text('top.revenue')}</Th>
                        </TableRow>
                    </TableHead>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.id} className="admin-home-top__row" data-business={row.business === true ? 'true' : undefined}>
                                <Td label={words.text('top.name')} header>
                                    {row.name}
                                </Td>
                                <Td label={words.text('top.bookings')} className="text-right">
                                    {formatCount(row.bookings, words.locale)}
                                </Td>
                                <Td label={words.text('top.revenue')} className="text-right">
                                    {formatAmount(row.revenue.minor, row.revenue.currency, words.locale)}
                                </Td>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
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
