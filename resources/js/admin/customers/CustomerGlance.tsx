import { formatAmount, formatCount, formatInstantDay, type TenantClock } from '@/lib/format';
import { listOf } from '@/lib/names';
import { placed } from '@/lib/strings';
import { type Customer, NO_VISIT, type Spent } from './model';
import { type CustomersKey, useCustomers } from './words';

/*
 * A customer's page's card At a glance: one card holding five figures in a row, each the server's
 * figure as it came from the list's one grouped statement, Upcoming, Completed, No-shows,
 * Cancelled and Last visit, the day of the latest first start already passed in confirmed or
 * completed in the tenant's zone and date format, or "No visit yet"; under them the Spent line,
 * what the address's visits came to in each currency the server summed, each in its own currency
 * and scale, or the line saying nothing is spent, followed by the sentence saying what Book now
 * fills in. Every word is a key of the customers section. The four counts are Intl's in the
 * user's language, grouped as it groups them, and the amounts are formatted in it and listed as
 * ICU lists in it.
 */

const GLANCE_TITLE = 'tiles.title' satisfies CustomersKey;

const SPENT = 'tiles.spent' satisfies CustomersKey;

const SPENT_NONE = 'tiles.spent_none' satisfies CustomersKey;

const BOOK_NOW_HELP = 'tiles.book_now_help' satisfies CustomersKey;

function Figure({ id, label, value, figures = false }: { id: string; label: string; value: string; figures?: boolean }) {
    return (
        <div className={`admin-customer-glance__figure admin-customer-glance__figure--${id} flex min-w-0 flex-col gap-0.5`}>
            <span className="text-xs text-muted-foreground">{label}</span>
            <strong className={figures ? 'font-mono text-base leading-7 font-semibold' : 'text-xl font-semibold'}>{value}</strong>
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
        <section className="admin-card admin-customer-glance flex flex-col gap-3 rounded-xl border p-4" aria-labelledby="customer-glance-head">
            <h2 id="customer-glance-head" className="font-semibold">
                {words.text(GLANCE_TITLE)}
            </h2>
            <div className="grid grid-cols-3 gap-3 desktop:grid-cols-5">
                <Figure id="upcoming" label={words.text('tiles.upcoming')} value={formatCount(customer.upcoming, words.locale)} />
                <Figure id="completed" label={words.text('tiles.completed')} value={formatCount(customer.completed, words.locale)} />
                <Figure id="no-shows" label={words.text('tiles.no_shows')} value={formatCount(customer.no_shows, words.locale)} />
                <Figure id="cancelled" label={words.text('tiles.cancelled')} value={formatCount(customer.cancelled, words.locale)} />
                <Figure id="last-visit" label={words.text('tiles.last_visit')} value={customer.last_visit === null ? words.text(NO_VISIT) : formatInstantDay(customer.last_visit, clock)} figures={customer.last_visit !== null} />
            </div>
            <p className="admin-customer-glance__spent text-sm">
                {spent.length === 0 ? (
                    words.text(SPENT_NONE)
                ) : (
                    placed(words.text(SPENT), { amount: <span className="font-mono">{amount}</span> })
                )}{' '}
                {words.text(BOOK_NOW_HELP)}
            </p>
        </section>
    );
}
