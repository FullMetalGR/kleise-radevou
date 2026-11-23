import { CalendarDays } from 'lucide-react';
import type { WizardWords } from '@/customers/words';
import { isoWeekday } from '@/lib/day';
import { formatAmount, formatDate, type TenantClock } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { cn } from '@/lib/utils';
import { ANY_STAFF, type Catalog, lengthLabel, spanMinutes, type State, summaryLines, timeLabel, totalOf } from './model';

/*
 * The booking summary the wizard draws beside every step: a line per segment holding a service,
 * with its price and, once a start is chosen, the segment's own start and end and its member where
 * members are offered; the chosen day and time on a chip; then the chain's duration and the total
 * of its prices, the total left out when the services do not share one currency. Below the
 * two-column width the lines and the chip are hidden, so the heading, Duration and Total follow
 * the step's content on their own.
 */

type Props = {
    state: State;
    catalog: Catalog;
    clock: TenantClock;
    granularity: number;
    staffReal: boolean;
    words: WizardWords;
    className?: string;
};

export function Summary({ state, catalog, clock, granularity, staffReal, words, className }: Props) {
    const lines = summaryLines(state, catalog, granularity);
    const total = totalOf(state, catalog);
    const spanOf = (start: string, end: string, staff: number | null): string => {
        const span = words.text('summary.span', { time: timeLabel(start, clock), end: timeLabel(end, clock) });

        return staffReal ? words.text('joined', { first: span, second: catalog.staff.find((member) => member.id === staff)?.name ?? words.text(ANY_STAFF) }) : span;
    };

    return (
        <section aria-labelledby="wizard-summary-title" className={cn('admin-wizard-summary flex min-w-0 flex-col gap-3 rounded-inner bg-muted px-4.5 pt-4.5 pb-4 wrap-anywhere', className)}>
            <h3 id="wizard-summary-title" className="font-display text-base font-semibold">
                {words.text('summary.title')}
            </h3>
            {lines.length > 0 && (
                <ul className="admin-wizard-summary__lines flex flex-col @max-3xl:hidden">
                    {lines.map((line, at) => (
                        <li key={at} className="flex flex-col gap-0.5 border-t border-border py-2.5 first:border-t-0 first:pt-0 last:pb-0">
                            <span className="flex items-baseline justify-between gap-3">
                                <span className="admin-wizard-summary__service min-w-0 text-body font-bold">{line.service.name}</span>
                                <span className="admin-wizard-summary__price shrink-0 text-body font-bold tabular-nums">{formatAmount(line.service.price_minor, line.service.currency, words.locale)}</span>
                            </span>
                            {line.start !== null && line.end !== null && (
                                <span className="admin-wizard-summary__span text-label font-medium text-muted-foreground tabular-nums">
                                    {spanOf(line.start, line.end, line.staff)}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {state.slot !== null && (
                <p className="admin-wizard-summary__when flex items-center gap-2 rounded-field bg-card px-3 py-2 text-label font-semibold tabular-nums @max-3xl:hidden">
                    <CalendarDays aria-hidden="true" className="size-4 shrink-0 text-primary-deep" />
                    <span>{words.text('joined', { first: `${weekdayName(isoWeekday(state.day), 'short', words.language)} ${formatDate(state.day, clock)}`, second: timeLabel(state.slot, clock) })}</span>
                </p>
            )}
            <dl className="admin-wizard-summary__totals flex items-end justify-between gap-3 border-t border-dashed border-tint-strong pt-3">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <dt className="text-small text-muted-foreground">{words.text('summary.duration')}</dt>
                    <dd className="admin-wizard-summary__duration text-body font-semibold tabular-nums">{lengthLabel(spanMinutes(state, catalog, granularity), words)}</dd>
                </div>
                {total !== null && (
                    <div className="flex min-w-0 flex-col items-end gap-0.5 text-right">
                        <dt className="text-small text-muted-foreground">{words.text('summary.total')}</dt>
                        <dd className="admin-wizard-summary__total font-display text-dialog-title tabular-nums">{formatAmount(total.minor, total.currency, words.locale)}</dd>
                    </div>
                )}
            </dl>
        </section>
    );
}
