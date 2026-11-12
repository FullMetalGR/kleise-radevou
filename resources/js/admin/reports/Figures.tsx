import type { ReactNode } from 'react';
import { StatusChip } from '@/components/ui/status-chip';
import { TableScroll } from '@/components/ui/table-scroll';
import { formatAmount, formatCount, type TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { Strip } from '@/figures/kit';
import { Card } from './Card';
import { channelLabel, formatRate, type MoneyLine, noShowRate, type Report, type ReportRow, sourceLabel, statusRows } from './model';
import { OccupancyByStaff } from './Occupancy';
import { OverTime } from './OverTime';
import type { ReportsWords } from './words';

/*
 * The Reports screen below the six tiles: Over time; then the status row of four cards, Bookings
 * by status (five statuses always, the other four beneath where a booking holds one), Revenue's
 * two lines, the No-show rate with its two counts and Cancelled by, each a card with its title
 * inside; then Occupancy by staff; then the breakdowns by service, staff, source and channel,
 * each a card with a header strip over its table; and the closing footnote. Every figure is the
 * server's under the screen's filters, each amount in the currency its line carries at that
 * currency's scale, every count, amount and rate spelled by Intl in the user's language. An
 * empty range draws the same cards and tables reading zero, never an empty panel. Every word is
 * a key of the reports section.
 */

type Line = ReportRow & { key: string; label: string };

/** One line per currency, each in its own currency's scale, in the user's language. */
function Amounts({ lines, locale }: { lines: MoneyLine[]; locale: string }) {
    return (
        <>
            {lines.map((line) => (
                <span key={line.currency} className="admin-reports__amount block font-mono">
                    {formatAmount(line.minor, line.currency, locale)}
                </span>
            ))}
        </>
    );
}

/** A status card's row: what it names at the left, its figure at the right. */
function Row({ label, value, className }: { label: ReactNode; value: ReactNode; className?: string }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className={`text-right font-mono ${className ?? ''}`}>{value}</dd>
        </div>
    );
}

function Breakdown({ name, title, head, rows, words }: { name: string; title: string; head: string; rows: Line[]; words: ReportsWords }) {
    const id = `reports-${name}-head`;

    return (
        <Card name={name} labelledBy={id}>
            <Strip titleId={id} title={title} />
            <TableScroll>
                <table className="admin-table w-full text-sm">
                    <thead>
                        <tr className="text-left">
                            <th className="px-4 py-2">{head}</th>
                            <th className="px-4 py-2 text-right">{words.text('tables.bookings')}</th>
                            <th className="px-4 py-2 text-right">{words.text('tables.revenue_head')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.key} className="admin-reports__row border-t">
                                <td className="admin-reports__label px-4 py-2">{row.label}</td>
                                <td className="admin-reports__bookings px-4 py-2 text-right font-mono">{formatCount(row.bookings, words.locale)}</td>
                                <td className="admin-reports__revenue px-4 py-2 text-right">
                                    <Amounts lines={row.revenue} locale={words.locale} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableScroll>
        </Card>
    );
}

/** A status card: the kit's card with its title inside, its rows beneath. */
function StatusCard({ name, title, children }: { name: string; title: string; children: ReactNode }) {
    const id = `reports-${name}-head`;

    return (
        <Card name={name} labelledBy={id} padded>
            <h2 id={id} className="font-semibold">
                {title}
            </h2>
            {children}
        </Card>
    );
}

function NoShows({ noShows, completed, words }: { noShows: number; completed: number; words: ReportsWords }) {
    const rate = noShowRate(noShows, completed);

    return (
        <StatusCard name="no-show" title={words.text('tables.no_show_rate')}>
            {rate === null ? <p className="admin-reports-no-show__empty">{words.text('tables.no_completed')}</p> : <p className="admin-reports-no-show__rate text-2xl leading-8 font-semibold">{formatRate(rate, words.locale)}</p>}
            <dl className="flex flex-col gap-2">
                <Row label={words.text('tables.no_shows')} value={formatCount(noShows, words.locale)} className="admin-reports-no-show__no-shows" />
                <Row label={words.text('tables.completed')} value={formatCount(completed, words.locale)} className="admin-reports-no-show__completed" />
            </dl>
        </StatusCard>
    );
}

export function Figures({ report, busy, clock, words }: { report: Report; busy: boolean; clock: TenantClock; words: ReportsWords }) {
    const shell = useShell();
    const cancelled = report.cancelled_by;

    return (
        <div className="admin-reports flex flex-col gap-4" aria-busy={busy}>
            <OverTime overTime={report.over_time} clock={clock} words={words} />
            <div className="grid grid-cols-[repeat(auto-fit,minmax(min(260px,100%),1fr))] gap-4">
                <StatusCard name="statuses" title={words.text('tables.statuses')}>
                    {statusRows(report.statuses).map((row) => (
                        <div key={row.status} className="admin-reports-statuses__row flex items-center justify-between gap-3">
                            <StatusChip status={row.status} />
                            <span className="admin-reports-statuses__count font-mono">{formatCount(row.bookings, words.locale)}</span>
                        </div>
                    ))}
                </StatusCard>
                <StatusCard name="revenue" title={words.text('tables.revenue')}>
                    <dl className="flex flex-col gap-2">
                        <Row label={words.text('tables.confirmed')} value={<Amounts lines={report.revenue.confirmed} locale={words.locale} />} className="admin-reports-revenue__confirmed" />
                        <Row label={words.text('tables.completed')} value={<Amounts lines={report.revenue.completed} locale={words.locale} />} className="admin-reports-revenue__completed" />
                    </dl>
                </StatusCard>
                <NoShows noShows={report.no_show.no_show} completed={report.no_show.completed} words={words} />
                <StatusCard name="cancelled-by" title={words.text('tables.cancelled_by')}>
                    <dl className="flex flex-col gap-2">
                        <Row label={words.text('tables.by_customer')} value={formatCount(cancelled.customer, words.locale)} />
                        <Row label={words.text('tables.by_business')} value={formatCount(cancelled.business, words.locale)} />
                        {cancelled.unrecorded > 0 && <Row label={words.text('tables.unrecorded')} value={formatCount(cancelled.unrecorded, words.locale)} />}
                    </dl>
                </StatusCard>
            </div>
            <OccupancyByStaff rows={report.occupancy} words={words} />
            <div className="grid grid-cols-[repeat(auto-fit,minmax(min(440px,100%),1fr))] gap-4">
                <Breakdown name="services" title={words.text('tables.by_service')} head={words.text('tables.service')} words={words} rows={report.services.map((row) => ({ ...row, key: String(row.id), label: row.name }))} />
                <Breakdown name="staff" title={words.text('tables.by_staff')} head={words.text('tables.staff')} words={words} rows={report.staff.map((row) => ({ ...row, key: String(row.id), label: row.name }))} />
                <Breakdown name="sources" title={words.text('tables.by_source')} head={words.text('tables.source')} words={words} rows={report.sources.map((row) => ({ ...row, key: row.source, label: sourceLabel(row.source, shell) }))} />
                <Breakdown name="channels" title={words.text('tables.by_channel')} head={words.text('tables.channel')} words={words} rows={report.channels.map((row) => ({ ...row, key: row.channel ?? '', label: channelLabel(row.channel, shell, words) }))} />
            </div>
            <p className="admin-reports-note text-sm text-muted-foreground">{words.text('tables.note')}</p>
        </div>
    );
}
