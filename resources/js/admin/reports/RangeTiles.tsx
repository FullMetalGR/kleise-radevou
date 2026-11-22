import { FigureLine, FigureTile } from '@/figures/kit';
import { formatAmount, formatCount } from '@/lib/format';
import { changeSentence, chipKind, type FigureChange, formatOccupancy, hoursOf, type RangeFigures } from './model';
import type { ReportsWords } from './words';

/*
 * The six tiles over a range: Bookings, Revenue, Occupancy, New customers, Cancelled and
 * No-shows, each its title, its figure and its change against the period before in its kind of
 * range's sentence (model's changeSentence), then the footnote, each tile the figures kit's, as
 * Home's are. The chip points the way the figure went and is green for a rise only where more is
 * better, which is not so of Cancelled or No-shows. Revenue stands on the strong fill and
 * Cancelled on the attention fill.
 *
 * The tiles are laid out by the screen's own width, not the window's, which the sidebar shares.
 * On a narrow screen they stand in two columns with Bookings and Revenue each on a line of its
 * own; as the screen widens they stand two to a line, then three, then six. Each of those steps
 * starts at the narrowest screen, in whole rems, on which an amount of six figures with its
 * cents and its currency, written in Greek, about 233px at the figure's size, keeps to one line
 * in a tile of that many columns with at least 2px to spare. A larger amount breaks inside the
 * number; it is never cut.
 *
 * Nothing to compare reads "No change", but for Occupancy: where the range offered no minute
 * there is no figure, only a short phrase in the tile's quiet line, with no comparison and no
 * hours, and where the period before offered none there is no comparison line. Occupancy never
 * passes 100 percent, the minutes booked outside the hours being offered too; revenue in another
 * currency is a quiet line beneath Revenue, uncompared.
 */

type Props = { figures: RangeFigures; words: ReportsWords; busy: boolean };

/**
 * A tile on a line of its own while the screen is too narrow for two side by side to hold the
 * amount: Revenue, and Bookings before it, which would stand beside an empty place otherwise.
 */
const OWN_LINE = 'col-span-2 @[37rem]:col-span-1';

export function RangeTiles({ figures, words, busy }: Props) {
    const { range, tiles } = figures;
    const { occupancy } = tiles;
    const count = (value: number): string => formatCount(value, words.locale);
    const compared = (figure: FigureChange) => ({ change: changeSentence(figure, range, words), kind: chipKind(figure) });

    return (
        <>
            <div className="admin-reports-tiles grid grid-cols-2 gap-4 @[56rem]:grid-cols-3 @[113rem]:grid-cols-6" aria-busy={busy}>
                <FigureTile id="bookings" title={words.text('tiles.bookings')} value={count(tiles.bookings.value)} {...compared(tiles.bookings.change)} moreIsBetter className={OWN_LINE} />
                <FigureTile id="revenue" title={words.text('tiles.revenue')} value={formatAmount(tiles.revenue.value.minor, tiles.revenue.value.currency, words.locale)} {...compared(tiles.revenue.change)} moreIsBetter fill="strong" className={OWN_LINE}>
                    {tiles.revenue.elsewhere.map((line) => (
                        <FigureLine key={line.currency}>{words.text('tiles.elsewhere', { amount: formatAmount(line.minor, line.currency, words.locale) })}</FigureLine>
                    ))}
                </FigureTile>
                <FigureTile
                    id="occupancy"
                    title={words.text('tiles.occupancy')}
                    value={occupancy.value === null ? undefined : formatOccupancy(occupancy.value, words.locale)}
                    {...(occupancy.value !== null && occupancy.previous !== null ? compared(occupancy.change) : { change: null, kind: null })}
                    moreIsBetter
                >
                    <FigureLine>{occupancy.value === null ? words.text('tiles.no_hours') : words.text('tiles.occupancy_of', { filled: hoursOf(occupancy.filled_minutes, words), offered: hoursOf(occupancy.offered_minutes, words) })}</FigureLine>
                </FigureTile>
                <FigureTile id="new_customers" title={words.text('tiles.new_customers')} value={count(tiles.new_customers.value)} {...compared(tiles.new_customers.change)} moreIsBetter>
                    <FigureLine>{words.choice('tiles.returning', tiles.new_customers.returning, { count: count(tiles.new_customers.returning) })}</FigureLine>
                </FigureTile>
                <FigureTile id="cancelled" title={words.text('tiles.cancelled')} value={count(tiles.cancelled.value)} {...compared(tiles.cancelled.change)} fill="attention" />
                <FigureTile id="no_shows" title={words.text('tiles.no_shows')} value={count(tiles.no_shows.value)} {...compared(tiles.no_shows.change)} />
            </div>
            <p className="admin-reports-tiles-note text-xs leading-4 text-muted-foreground">{words.text('tiles.note')}</p>
        </>
    );
}
