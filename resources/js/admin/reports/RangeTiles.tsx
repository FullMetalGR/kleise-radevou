import { FigureLine, FigureTile } from '@/figures/kit';
import { formatAmount, formatCount } from '@/lib/format';
import { changeSentence, type FigureChange, formatOccupancy, hoursOf, type RangeFigures } from './model';
import type { ReportsWords } from './words';

/*
 * The six tiles over a range: Bookings, Revenue, Occupancy, New customers, Cancelled and
 * No-shows, each its title, its figure and its change against the period before in its kind of
 * range's sentence (model's changeSentence), then the footnote, each tile the figures kit's, as
 * Home's are.
 *
 * Nothing to compare reads "No change", but for Occupancy: where the range offered no minute the
 * figure is a short phrase with no comparison and no hours, and where the period before offered
 * none there is no comparison line. Occupancy never passes 100 percent, the minutes booked
 * outside the hours being offered too; revenue in another currency is a quiet line beneath
 * Revenue, uncompared.
 */

type Props = { figures: RangeFigures; words: ReportsWords; busy: boolean };

export function RangeTiles({ figures, words, busy }: Props) {
    const { range, tiles } = figures;
    const { occupancy } = tiles;
    const count = (value: number): string => formatCount(value, words.locale);
    const change = (figure: FigureChange): string => changeSentence(figure, range, words);

    return (
        <>
            <div className="admin-reports-tiles grid grid-cols-[repeat(auto-fit,minmax(min(300px,100%),1fr))] gap-4" aria-busy={busy}>
                <FigureTile id="bookings" title={words.text('tiles.bookings')} value={count(tiles.bookings.value)} change={change(tiles.bookings.change)} />
                <FigureTile id="revenue" title={words.text('tiles.revenue')} value={formatAmount(tiles.revenue.value.minor, tiles.revenue.value.currency, words.locale)} change={change(tiles.revenue.change)}>
                    {tiles.revenue.elsewhere.map((line) => (
                        <FigureLine key={line.currency}>{words.text('tiles.elsewhere', { amount: formatAmount(line.minor, line.currency, words.locale) })}</FigureLine>
                    ))}
                </FigureTile>
                <FigureTile
                    id="occupancy"
                    title={words.text('tiles.occupancy')}
                    value={occupancy.value === null ? words.text('tiles.no_hours') : formatOccupancy(occupancy.value, words.locale)}
                    change={occupancy.value !== null && occupancy.previous !== null ? change(occupancy.change) : null}
                >
                    {occupancy.value !== null && <FigureLine>{words.text('tiles.occupancy_of', { filled: hoursOf(occupancy.filled_minutes, words), offered: hoursOf(occupancy.offered_minutes, words) })}</FigureLine>}
                </FigureTile>
                <FigureTile id="new_customers" title={words.text('tiles.new_customers')} value={count(tiles.new_customers.value)} change={change(tiles.new_customers.change)}>
                    <FigureLine>{words.choice('tiles.returning', tiles.new_customers.returning, { count: count(tiles.new_customers.returning) })}</FigureLine>
                </FigureTile>
                <FigureTile id="cancelled" title={words.text('tiles.cancelled')} value={count(tiles.cancelled.value)} change={change(tiles.cancelled.change)} />
                <FigureTile id="no_shows" title={words.text('tiles.no_shows')} value={count(tiles.no_shows.value)} change={change(tiles.no_shows.change)} />
            </div>
            <p className="admin-reports-tiles-note text-xs leading-4 text-muted-foreground">{words.text('tiles.note')}</p>
        </>
    );
}
