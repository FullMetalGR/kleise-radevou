import { TableScroll } from '@/components/ui/table-scroll';
import { formatAmount, formatCount, formatDate, formatDayMonth, type TenantClock } from '@/lib/format';
import { monthName } from '@/lib/names';
import { Strip } from '@/figures/kit';
import { Card } from './Card';
import { CHART, chartBars, currencyName, type OverTimeRow, type OverTimeUnit, type Report } from './model';
import type { ReportsWords } from './words';

/*
 * Over time: a card with its header strip, two bar charts side by side, the bookings and the
 * revenue per bucket, drawn by hand as SVG (no chart library) with the bars in the theme's
 * primary, the value axis's lines on the border colour and its labels muted; then the table of
 * From, To, Bookings and Revenue, one row a bucket. The buckets are the server's (a day, a week
 * or a month, which the headings follow) and sum to the Bookings and Revenue tiles, the revenue
 * in the tenant's currency.
 *
 * Under a bucket its first day names it: the day of the month for a day, the day and month in
 * the tenant's order for a week, the month's short name for a month; where the buckets are too
 * narrow for every name, every few are named. The revenue chart's spoken label names the
 * currency in words ("in euros"), its code where the locale has no name for it.
 */

/** The room a bucket's name takes on the axis, in the chart's units. */
const LABEL_ROOM: Record<OverTimeUnit, number> = { day: 16, week: 30, month: 24 };

function tickOf(row: OverTimeRow, unit: OverTimeUnit, clock: TenantClock, words: ReportsWords): string {
    if (unit === 'day') {
        return String(Number(row.from.slice(8, 10)));
    }

    return unit === 'week' ? formatDayMonth(row.from, clock) : monthName(Number(row.from.slice(5, 7)), words.language);
}

/**
 * A currency's minor units to its major, at its own scale; the locale only resolves the digits
 * and is never shown.
 */
function majorOf(minor: number, currency: string): number {
    const digits = new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;

    return minor / 10 ** digits;
}

type ChartProps = {
    name: 'bookings' | 'revenue';
    caption: string;
    values: number[];
    spoken: string[];
    /** The currency the values are in, named in the spoken label; none for a count. */
    currency?: string;
    ticks: string[];
    unit: OverTimeUnit;
    range: { from: string; to: string };
    words: ReportsWords;
};

function Chart({ name, caption, values, spoken, currency, ticks, unit, range, words }: ChartProps) {
    const chart = chartBars(values);
    const every = Math.max(1, Math.ceil(LABEL_ROOM[unit] / chart.pitch));
    const said = { chart: caption, from: range.from, to: range.to, values: new Intl.ListFormat(words.locale, { style: 'narrow', type: 'unit' }).format(spoken) };
    const label = currency === undefined ? words.text('over_time.chart', said) : words.text('over_time.chart_revenue', { ...said, currency: currencyName(currency, words.locale) });

    return (
        <figure className={`admin-reports-over-time__${name} m-0 flex flex-col gap-2`}>
            <figcaption className="font-medium">{caption}</figcaption>
            <svg width={chart.width} height={160} viewBox={`0 0 ${String(chart.width)} 160`} role="img" aria-label={label} className="block h-40 max-w-full">
                {chart.ticks.map((tick) => (
                    <g key={tick.value}>
                        <line x1={CHART.left} y1={tick.y} x2={chart.width} y2={tick.y} className="stroke-border" />
                        <text x={30} y={tick.y + 4} fontSize={10} textAnchor="end" className="admin-reports-axis fill-muted-foreground">
                            {formatCount(tick.value, words.locale)}
                        </text>
                    </g>
                ))}
                {chart.bars.map((bar) => (
                    <rect key={bar.index} x={bar.x} y={bar.y} width={bar.width} height={bar.height} rx={Math.min(4, bar.width / 4)} className="fill-primary" />
                ))}
                {chart.centers.map((center, index) =>
                    index % every === 0 ? (
                        <text key={center} x={center} y={152} fontSize={10} textAnchor="middle" className="admin-reports-tick fill-muted-foreground">
                            {ticks[index]}
                        </text>
                    ) : null,
                )}
            </svg>
        </figure>
    );
}

export function OverTime({ overTime, clock, words }: { overTime: Report['over_time']; clock: TenantClock; words: ReportsWords }) {
    const { unit, rows } = overTime;
    const ticks = rows.map((row) => tickOf(row, unit, clock, words));
    const range = { from: formatDate(rows[0]?.from ?? '', clock), to: formatDate(rows[rows.length - 1]?.to ?? '', clock) };

    return (
        <Card name="over-time" labelledBy="reports-over-time-head">
            <Strip titleId="reports-over-time-head" title={words.text('over_time.title')} />
            <div className="grid grid-cols-[repeat(auto-fit,minmax(min(400px,100%),1fr))] gap-4 px-4 py-3">
                <Chart
                    name="bookings"
                    caption={words.text(`over_time.bookings.${unit}`)}
                    values={rows.map((row) => row.bookings)}
                    spoken={rows.map((row) => formatCount(row.bookings, words.locale))}
                    ticks={ticks}
                    unit={unit}
                    range={range}
                    words={words}
                />
                <Chart
                    name="revenue"
                    caption={words.text(`over_time.revenue.${unit}`)}
                    values={rows.map((row) => majorOf(row.revenue.minor, row.revenue.currency))}
                    spoken={rows.map((row) => formatAmount(row.revenue.minor, row.revenue.currency, words.locale))}
                    currency={rows[0]?.revenue.currency}
                    ticks={ticks}
                    unit={unit}
                    range={range}
                    words={words}
                />
            </div>
            <div className="border-t">
                <TableScroll>
                    <table className="admin-table w-full text-sm">
                        <thead>
                            <tr className="text-left">
                                <th className="px-4 py-2">{words.text('over_time.from')}</th>
                                <th className="px-4 py-2">{words.text('over_time.to')}</th>
                                <th className="px-4 py-2 text-right">{words.text('over_time.bookings_head')}</th>
                                <th className="px-4 py-2 text-right">{words.text('over_time.revenue_head')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.from} className="border-t">
                                    <td className="px-4 py-2 font-mono">{formatDate(row.from, clock)}</td>
                                    <td className="px-4 py-2 font-mono">{formatDate(row.to, clock)}</td>
                                    <td className="px-4 py-2 text-right font-mono">{formatCount(row.bookings, words.locale)}</td>
                                    <td className="px-4 py-2 text-right font-mono">{formatAmount(row.revenue.minor, row.revenue.currency, words.locale)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableScroll>
            </div>
        </Card>
    );
}
