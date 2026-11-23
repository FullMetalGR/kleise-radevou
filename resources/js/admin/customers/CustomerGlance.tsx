import type { ReactNode } from 'react';
import { CalendarDays, Check, CircleAlert, Clock, Info, X } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { formatAmount, formatCount, formatInstantDay, type TenantClock } from '@/lib/format';
import { listOf } from '@/lib/names';
import { placed } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { type Customer, NO_VISIT, type Spent } from './model';
import { type CustomersKey, useCustomers } from './words';

/*
 * A customer's page's card At a glance, each figure the server's as it came from the list's one
 * grouped statement. First a strip of the four counts, Upcoming, Completed, No-shows and
 * Cancelled, each a large figure under its label and a small mark that only decorates it, the mark
 * over the label wherever the count is too narrow for both on one line. Four to a line from the wide
 * breakpoint where the card holds four in the longer language, two by two otherwise, a hairline
 * between them, and a zero in the muted ink so the counts that hold something stand out. Then Last
 * visit as a panel of its own, the day of the latest first start already passed in confirmed or
 * completed in the tenant's zone and date format, or "No visit yet", which a soft mark of two
 * rings at the panel's lower corner sets apart: beside the strip where the card has room for both
 * in the longer language, under it otherwise, where it reads as one line of its label and its day. Under them the Spent line, what the address's visits came to in each
 * currency the server summed, each in its own currency and scale, or the line saying nothing is
 * spent, followed by the sentence saying what Book now fills in. Every word is a key of the
 * customers section. The four counts are Intl's in the user's language, grouped as it groups them,
 * and the amounts are formatted in it and listed as ICU lists in it, every figure in tabular
 * numerals.
 */

const GLANCE_TITLE = 'tiles.title' satisfies CustomersKey;

const SPENT = 'tiles.spent' satisfies CustomersKey;

const SPENT_NONE = 'tiles.spent_none' satisfies CustomersKey;

const BOOK_NOW_HELP = 'tiles.book_now_help' satisfies CustomersKey;

/**
 * The width of a count's inside below which its mark goes over its label: the mark, the gap and
 * the longest label in the longer language on one line, Greek's Completed, with two pixels spare.
 */
const COUNT_STACKS = '@max-[9.4375rem]:flex-col @max-[9.4375rem]:items-start @max-[9.4375rem]:gap-2';

/**
 * The card's inside from which the counts stand four to a line, from the wide breakpoint: four
 * counts with the longer language's longest label over its mark, two pixels spare each. The card
 * sits in a column whose width the side column and the sidebar decide, so the card's own width
 * says it, never the window's.
 */
const FOUR_TO_A_LINE = 'wide:@min-[39.125rem]:grid-cols-4';

/**
 * The card's inside from which Last visit stands beside the counts: the width the counts need four
 * to a line, then the gap and the panel's fixed width, so the panel goes beside them only where
 * they still stand four to a line.
 */
const LAST_VISIT_BESIDE = '@min-[61.5rem]:grid-cols-[minmax(0,1fr)_21.25rem]';

/** A count's decorative mark: the theme's soft fill, the green of a done visit, or a quiet grey. */
const MARKS = {
    theme: 'bg-muted text-primary-deep',
    done: 'bg-positive-soft text-positive-ink',
    quiet: 'bg-ink-tertiary/15 text-muted-foreground',
} as const;

/** The label row of a figure: its mark in a small circle, then its words. */
function Label({ icon, mark, className, children }: { icon: ReactNode; mark: string; className?: string; children: string }) {
    return (
        <div className={cn('flex min-w-0 items-center gap-2.5', className)}>
            <div aria-hidden="true" className={cn('grid size-7.5 shrink-0 place-items-center rounded-full *:size-4', mark)}>
                {icon}
            </div>
            <span className="min-w-0 text-label text-muted-foreground">{children}</span>
        </div>
    );
}

function Count({ id, label, value, icon, mark }: { id: string; label: string; value: number; icon: ReactNode; mark: string }) {
    const words = useCustomers();

    return (
        <div className={`admin-customer-glance__figure admin-customer-glance__figure--${id} @container flex min-w-0 flex-col bg-card px-5.5 pt-5 pb-5.5 max-desktop:p-4`}>
            <Label icon={icon} mark={mark} className={COUNT_STACKS}>
                {label}
            </Label>
            <strong className={cn('mt-4 text-[2.875rem] leading-none font-display font-semibold tracking-[-0.05em] tabular-nums max-desktop:mt-3', value === 0 ? 'text-muted-foreground' : 'text-foreground')}>
                {formatCount(value, words.locale)}
            </strong>
        </div>
    );
}

export function CustomerGlance({ customer, spent, clock }: { customer: Customer; spent: readonly Spent[]; clock: TenantClock }) {
    const words = useCustomers();
    const amount = listOf(
        spent.map((sum) => formatAmount(sum.amount_minor, sum.currency, words.locale)),
        words.language,
    );

    return (
        <Card className="admin-card admin-customer-glance @container flex flex-col gap-4.5" aria-labelledby="customer-glance-head">
            <h2 id="customer-glance-head" className="text-card-title">
                {words.text(GLANCE_TITLE)}
            </h2>
            <div className={cn('grid gap-4.5', LAST_VISIT_BESIDE)}>
                <div className={cn('admin-customer-glance__counts grid grid-cols-2 gap-[1.5px] overflow-hidden rounded-inner bg-border ring-[1.5px] ring-border', FOUR_TO_A_LINE)}>
                    <Count id="upcoming" label={words.text('tiles.upcoming')} value={customer.upcoming} icon={<CalendarDays />} mark={MARKS.theme} />
                    <Count id="completed" label={words.text('tiles.completed')} value={customer.completed} icon={<Check />} mark={MARKS.done} />
                    <Count id="no-shows" label={words.text('tiles.no_shows')} value={customer.no_shows} icon={<CircleAlert />} mark={MARKS.quiet} />
                    <Count id="cancelled" label={words.text('tiles.cancelled')} value={customer.cancelled} icon={<X />} mark={MARKS.quiet} />
                </div>
                <div className="admin-customer-glance__figure admin-customer-glance__figure--last-visit relative isolate flex min-w-0 overflow-hidden flex-wrap items-center justify-between gap-x-5 gap-y-1.5 rounded-inner bg-muted px-6 py-5 max-desktop:p-4.5 @min-[61.5rem]:flex-col @min-[61.5rem]:flex-nowrap @min-[61.5rem]:items-start @min-[61.5rem]:justify-center @min-[61.5rem]:gap-3">
                    <Label icon={<Clock />} mark="bg-card text-primary-deep">
                        {words.text('tiles.last_visit')}
                    </Label>
                    {customer.last_visit === null ? (
                        <strong className="text-tile-title text-muted-foreground">{words.text(NO_VISIT)}</strong>
                    ) : (
                        <strong className="text-[1.875rem] leading-[1.15] font-display font-semibold tracking-[-0.04em] tabular-nums">{formatInstantDay(customer.last_visit, clock)}</strong>
                    )}
                    {customer.last_visit === null && (
                        // A circle drawn only by the two bands its shadow spreads around it, in the
                        // theme colour, clipped by the panel and set behind its words.
                        <span aria-hidden="true" className="admin-customer-glance__mark pointer-events-none absolute -right-8.5 -bottom-11 -z-10 size-30 rounded-full shadow-[0_0_0_12px_color-mix(in_oklab,var(--primary)_8%,transparent),0_0_0_26px_color-mix(in_oklab,var(--primary)_5%,transparent)]" />
                    )}
                </div>
            </div>
            <div className="flex items-start gap-2.5 border-t pt-4 text-body-sm leading-[1.55] text-muted-foreground">
                <Info aria-hidden="true" className="mt-0.75 size-4 shrink-0 text-ink-tertiary" />
                <p className="admin-customer-glance__spent">
                    {spent.length === 0 ? (
                        words.text(SPENT_NONE)
                    ) : (
                        placed(words.text(SPENT), { amount: <span className="tabular-nums">{amount}</span> })
                    )}{' '}
                    {words.text(BOOK_NOW_HELP)}
                </p>
            </div>
        </Card>
    );
}
