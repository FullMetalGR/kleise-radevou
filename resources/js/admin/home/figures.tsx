import type { ReactNode } from 'react';
import { Segmented } from '@/components/ui/segmented';
import { TableScroll } from '@/components/ui/table-scroll';
import { FigureLine, FigureTile, Strip } from '@/figures/kit';
import { formatAmount, formatCount, formatDate, formatPercent, type TenantClock } from '@/lib/format';
import { unitFigure } from '@/lib/names';
import type { HomeWords } from './words';

/*
 * Home's range and its figures: the range card, the six tiles, the footnote and the two Top
 * tables, every figure the server's range_figures prop. Nothing is counted here; the screen only
 * words the facts.
 *
 * A tile's comparison is one whole sentence per kind of range, the day before for today and
 * yesterday, the week before, the month before, so a language inflects the period itself: a rise
 * or a fall by its signed percentage, no change, or up from nought. Nought against nought is no
 * change; a period with nothing to compare, an occupancy with no hours offered on either side,
 * says nothing. A rise and a fall take no colour of their own: the sign and the words carry them.
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

/** The range card: the four ranges, the one shown pressed, and the range's line with its dates. */
export function RangeCard({
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
        <div className="admin-home-range flex flex-col gap-3 rounded-xl border p-4" aria-busy={busy}>
            <Segmented
                label={words.text('range.label')}
                options={WORDS.map((word) => ({ value: word, label: words.text(`range.${word}`) }))}
                value={range.word}
                onChange={(word) => {
                    if (word !== range.word) {
                        onChoose(word);
                    }
                }}
            />
            <p className="admin-home-range__line text-sm text-muted-foreground">{line}</p>
            {failed && (
                <p role="alert" className="admin-home-range__error text-sm text-destructive">
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

/** The six tiles, three a row where the screen is wide, then the footnote on what is counted. */
export function Tiles({ figures, words }: { figures: RangeFigures; words: HomeWords }) {
    const { tiles } = figures;
    const word = figures.range.word;
    const counted = (id: string, label: string, count: Count, children?: ReactNode) => (
        <FigureTile id={id} title={label} value={formatCount(count.value, words.locale)} change={comparison(count.change, count.value === 0 && count.previous === 0, word, words)}>
            {children}
        </FigureTile>
    );
    const { occupancy, revenue } = tiles;

    return (
        <>
            <div className="admin-home-figures grid gap-4 desktop:grid-cols-2 lg:grid-cols-3">
                {counted('bookings', words.text('tiles.bookings'), tiles.bookings)}
                <FigureTile
                    id="revenue"
                    title={words.text('tiles.revenue')}
                    value={formatAmount(revenue.value.minor, revenue.value.currency, words.locale)}
                    change={comparison(revenue.change, revenue.value.minor === 0 && revenue.previous.minor === 0, word, words)}
                >
                    {revenue.elsewhere.map((line) => (
                        <FigureLine key={line.currency}>{formatAmount(line.minor, line.currency, words.locale)}</FigureLine>
                    ))}
                </FigureTile>
                {occupancy.value === null ? (
                    <FigureTile id="occupancy" title={words.text('tiles.occupancy')}>
                        <FigureLine>{words.text('tiles.no_hours')}</FigureLine>
                    </FigureTile>
                ) : (
                    <FigureTile id="occupancy" title={words.text('tiles.occupancy')} value={formatPercent(occupancy.value, words.locale)} change={comparison(occupancy.change, occupancy.value === 0 && occupancy.previous === 0, word, words)}>
                        <FigureLine>{words.text('tiles.filled', { filled: duration(occupancy.filled_minutes, words), offered: duration(occupancy.offered_minutes, words) })}</FigureLine>
                    </FigureTile>
                )}
                {counted('new_customers', words.text('tiles.new_customers'), tiles.new_customers, <FigureLine>{words.choice('tiles.returning', tiles.new_customers.returning, { count: formatCount(tiles.new_customers.returning, words.locale) })}</FigureLine>)}
                {counted('cancelled', words.text('tiles.cancelled'), tiles.cancelled)}
                {counted('no_shows', words.text('tiles.no_shows'), tiles.no_shows)}
            </div>
            <p className="admin-home-footnote text-xs text-muted-foreground">{words.text('footnote')}</p>
        </>
    );
}

type TopRow = { id: number; name: string; bookings: number; revenue: Money; business?: boolean };

function Top({ id, title, rows, words }: { id: 'services' | 'staff'; title: string; rows: TopRow[]; words: HomeWords }) {
    return (
        <section className={`admin-home-top admin-home-top--${id} rounded-xl border`} aria-labelledby={`home-top-${id}-title`}>
            <Strip titleId={`home-top-${id}-title`} title={title} />
            {rows.length === 0 ? (
                <p className="admin-home-top__empty px-4 py-3 text-sm text-muted-foreground">{words.text('top.empty')}</p>
            ) : (
                <TableScroll>
                    <table className="admin-table w-full text-sm">
                        <thead>
                            <tr className="text-left">
                                <th className="px-4 py-2 font-semibold">{words.text('top.name')}</th>
                                <th className="px-4 py-2 text-right font-semibold">{words.text('top.bookings')}</th>
                                <th className="px-4 py-2 text-right font-semibold">{words.text('top.revenue')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id} className="admin-home-top__row border-t" data-business={row.business === true ? 'true' : undefined}>
                                    <td className="px-4 py-2">{row.name}</td>
                                    <td className="px-4 py-2 text-right font-mono">{formatCount(row.bookings, words.locale)}</td>
                                    <td className="px-4 py-2 text-right font-mono">{formatAmount(row.revenue.minor, row.revenue.currency, words.locale)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableScroll>
            )}
        </section>
    );
}

/** Top services beside Top staff for the range. */
export function TopTables({ figures, words }: { figures: RangeFigures; words: HomeWords }) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Top id="services" title={words.text('top.services')} rows={figures.top_services} words={words} />
            <Top id="staff" title={words.text('top.staff')} rows={figures.top_staff} words={words} />
        </div>
    );
}
