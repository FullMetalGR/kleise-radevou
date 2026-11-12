import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';
import { useDownload } from '@/lib/download';
import { formatCount, type TenantClock } from '@/lib/format';
import { csvUrl, customProblem, MOST_DAYS, RANGE_WORDS, rangeLine, type RangeWord, type ReportFilters, type ReportOptions, type NamedRange } from './model';
import type { ReportsWords } from './words';

/*
 * Reports' filter card: Range, Staff and Service in one row, and under them the range the figures
 * answer, restated in the tenant's date format, which is the server's answer and never the
 * choice. A word of Range, a member and a service each read at once; Custom range opens From and
 * To, seeded with the range on screen, and reads nothing until Apply, which says first why a pair
 * cannot be read (the To date before the From, more than MOST_DAYS days). Staff is left out where
 * no member but the business itself is offered. While a read is in flight a sentence says so, and
 * Apply is busy where it started it. At the row's end, Download CSV fetches the file of the range
 * and the filters the screen answered (GET /admin/reports/csv with the page's own query) through
 * the export buttons' one road: busy while on its way, a failure a toast.
 *
 * The screen keys this card on the answer it was given, so each answer seeds it afresh.
 */

type Props = {
    range: NamedRange;
    filters: ReportFilters;
    options: ReportOptions;
    clock: TenantClock;
    weekStart: number;
    loading: boolean;
    words: ReportsWords;
    onRead: (range: NamedRange, filters: ReportFilters) => void;
};

/** A select's value as a filter's id, the empty choice none. */
function idOf(value: string): number | null {
    return value === '' ? null : Number(value);
}

export function FilterCard({ range, filters, options, clock, weekStart, loading, words, onRead }: Props) {
    const [word, setWord] = useState<RangeWord>(range.word);
    const [from, setFrom] = useState(range.from);
    const [to, setTo] = useState(range.to);
    const [applying, setApplying] = useState(false);
    const [problem, setProblem] = useState<'to_before_from' | 'too_long' | null>(null);
    const download = useDownload({ type: 'text/csv', fallback: 'reports.csv', failure: words.text('filters.download_error') });

    const chooseWord = (next: string) => {
        const chosen = RANGE_WORDS.find((candidate) => candidate === next) ?? 'week';

        setWord(chosen);
        setApplying(false);
        setProblem(null);

        if (chosen !== 'custom') {
            onRead({ ...range, word: chosen }, filters);
        }
    };

    const apply = () => {
        const found = customProblem(from, to);

        setProblem(found);

        if (found === null) {
            setApplying(true);
            onRead({ word: 'custom', from, to }, filters);
        }
    };

    const error = problem === null ? null : problem === 'to_before_from' ? words.text('filters.to_before_from') : words.text('filters.too_long', { count: formatCount(MOST_DAYS, words.locale) });

    return (
        <section className="admin-reports-filters flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-end gap-3">
                <Field id="reports-range" label={words.text('filters.range')} className="w-44">
                    {(control) => <Select {...control} className="admin-reports-range-word w-full" options={RANGE_WORDS.map((value) => ({ value, label: words.text(`filters.words.${value}`) }))} value={word} onChange={chooseWord} />}
                </Field>
                {options.staff.length > 0 && (
                    <Field id="reports-staff" label={words.text('filters.staff')} className="w-50">
                        {(control) => (
                            <Select
                                {...control}
                                className="admin-reports-staff-filter w-full"
                                options={[{ value: '', label: words.text('filters.all_staff') }, ...options.staff.map((member) => ({ value: String(member.id), label: member.name }))]}
                                value={filters.staff === null ? '' : String(filters.staff)}
                                onChange={(value) => onRead(range, { ...filters, staff: idOf(value) })}
                            />
                        )}
                    </Field>
                )}
                <Field id="reports-service" label={words.text('filters.service')} className="w-50">
                    {(control) => (
                        <Select
                            {...control}
                            className="admin-reports-service-filter w-full"
                            options={[{ value: '', label: words.text('filters.all_services') }, ...options.services.map((service) => ({ value: String(service.id), label: service.name }))]}
                            value={filters.service === null ? '' : String(filters.service)}
                            onChange={(value) => onRead(range, { ...filters, service: idOf(value) })}
                        />
                    )}
                </Field>
                <Button className="admin-reports-download ml-auto" busy={download.busy} busyLabel={words.text('filters.downloading')} onClick={() => download.start(csvUrl(range, filters))}>
                    {words.text('filters.download')}
                </Button>
            </div>
            {word === 'custom' && (
                <div className="admin-reports-custom flex flex-wrap items-start gap-3">
                    <Field id="reports-from" label={words.text('filters.from')} className="w-44">
                        {(control) => <DateField {...control} className="admin-reports-from" value={from} clock={clock} weekStart={weekStart} onChange={setFrom} />}
                    </Field>
                    <Field id="reports-to" label={words.text('filters.to')} error={error} className="w-44">
                        {(control) => <DateField {...control} className="admin-reports-to" value={to} clock={clock} weekStart={weekStart} onChange={setTo} />}
                    </Field>
                    <Button variant="primary" className="admin-reports-apply mt-6" busy={loading && applying} busyLabel={words.text('filters.applying')} onClick={apply}>
                        {words.text('filters.apply')}
                    </Button>
                </div>
            )}
            <p className="admin-reports-filters__stated text-sm text-muted-foreground">{rangeLine(range, clock, words)}</p>
            {loading && (
                <p role="status" className="admin-reports-loading text-sm">
                    {words.text('filters.loading')}
                </p>
            )}
        </section>
    );
}
