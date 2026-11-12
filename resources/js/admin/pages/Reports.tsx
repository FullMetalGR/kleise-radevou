import { Head, usePage } from '@inertiajs/react';
import { Notice } from '@/components/ui/notice';
import { clockOf } from '@/lib/format';
import { usePartialRead } from '@/lib/partial-read';
import { stringsOf, useShell } from '@/lib/strings';
import { FilterCard } from '@/reports/FilterCard';
import { Figures } from '@/reports/Figures';
import { type RangeFigures, type Report, type ReportFilters, type ReportOptions, reportQuery, type NamedRange } from '@/reports/model';
import { RangeTiles } from '@/reports/RangeTiles';
import type { AdminStrings, ReportsStrings, SharedProps } from '@/types';

/*
 * The Reports screen: the title, the filter card (Range, Staff, Service, Download CSV and the
 * range's line), the six tiles each beside the period before and their footnote, then Over time,
 * the status row, Occupancy by staff and the By tables, every card under the filters, and their
 * footnote. Every figure is computed by the server when asked and stored nowhere. The range and the
 * filters live in the query string, which the server reads, and nowhere else: a reload or a shared
 * link lands on the same answer. A change of filter is a partial reload of the range, the filters,
 * the tiles and the tables with the URL replaced and the scroll kept, and nothing is optimistic:
 * what is on screen is the server's last answer, and a failed read says so in the screen's own
 * words and leaves it standing.
 *
 * Every word is a key of the reports section, which ReportController names.
 */

type Props = SharedProps & {
    strings: AdminStrings & { reports: ReportsStrings };
    range: NamedRange;
    filters: ReportFilters;
    options: ReportOptions;
    figures: RangeFigures;
    report: Report;
};

const REPORTS = '/admin/reports';

/** What a change of filter reads again: the range and the filters answered, the tiles and the tables. */
const READ_PROPS = ['range', 'filters', 'figures', 'report'];

export default function Reports() {
    const { props } = usePage<Props>();
    const shell = useShell();
    const words = stringsOf<ReportsStrings>(props.strings.reports, shell.language);
    const clock = clockOf(props);
    const read = usePartialRead(READ_PROPS);
    const { range, filters, options, figures, report } = props;

    return (
        <>
            <Head title={words.text('page_title')} />
            <div className="admin-reports-screen flex flex-col gap-4">
                <h1 className="text-2xl font-semibold">{words.text('page_title')}</h1>
                <FilterCard
                    key={`${range.word} ${range.from} ${range.to} ${String(filters.staff)} ${String(filters.service)}`}
                    range={range}
                    filters={filters}
                    options={options}
                    clock={clock}
                    weekStart={props.settings.week_start}
                    loading={read.loading}
                    words={words}
                    onRead={(next, chosen) => read.read(REPORTS, reportQuery(next, chosen))}
                />
                {read.error && (
                    <Notice kind="error" className="admin-reports-error">
                        {words.text('filters.error')}
                    </Notice>
                )}
                <RangeTiles figures={figures} words={words} busy={read.loading} />
                <Figures report={report} busy={read.loading} clock={clock} words={words} />
            </div>
        </>
    );
}
