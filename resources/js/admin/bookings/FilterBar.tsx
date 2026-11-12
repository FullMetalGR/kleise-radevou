import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { statusLabel } from '@/components/ui/status-chip';
import type { TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { AWAITING_APPROVAL, type Choice, type Filters, NO_FILTERS, type Options } from './model';
import { type BookingsKey, type BookingsWords, useBookings } from './words';

/*
 * The Bookings filter bar: one gesture row over a grid of fields. The Approval inbox button is
 * primary while the status filter is awaiting approval and sets that one filter alone; Clear
 * resets every field. Every change is handed up whole, and the screen resets the page and
 * remembers the bar. The search offers the email only where the server's options say the search
 * reads it, which is where the viewer may see it. Below the admin's breakpoint the fields stack
 * one to a row. Every word is a key of the bookings section, the search's hint one whole key by
 * whether it reads the email.
 */

type Props = {
    filters: Filters;
    options: Options;
    staffShown: boolean;
    clock: TenantClock;
    weekStart: number;
    onChange: (filters: Filters) => void;
};

/** An inactive row's line, and the placeholder its name fills. */
type Inactive = { key: BookingsKey; name: 'staff' | 'service' };

/** A select's choices: the "any" choice, then each row, an inactive one in its own whole line. */
function choices(rows: readonly Choice[], any: string, inactive: Inactive, words: BookingsWords) {
    return [{ value: '', label: any }, ...rows.map((row) => ({ value: String(row.id), label: row.active ? row.name : words.text(inactive.key, { [inactive.name]: row.name }) }))];
}

function idOf(value: string): number | null {
    return value === '' ? null : Number(value);
}

export function FilterBar({ filters, options, staffShown, clock, weekStart, onChange }: Props) {
    const shell = useShell();
    const words = useBookings();
    const set = (patch: Partial<Filters>) => onChange({ ...filters, ...patch });
    const inbox = filters.status === AWAITING_APPROVAL;
    const searchHint = options.searchable.includes('customer_email') ? words.text('filters.search_name_email') : words.text('filters.search_name');

    return (
        <section className="admin-filter-bar flex flex-col gap-3 rounded-xl border p-4" aria-label={words.text('filters.label')}>
            <div className="admin-filter-bar__gestures flex items-center gap-3">
                <Button variant={inbox ? 'primary' : 'secondary'} className="admin-approval-inbox" onClick={() => set({ status: AWAITING_APPROVAL })}>
                    {words.text('filters.inbox')}
                </Button>
                <Button variant="link" className="admin-filter-clear" onClick={() => onChange(NO_FILTERS)}>
                    {words.text('filters.clear')}
                </Button>
            </div>
            <div className="admin-filter-bar__fields grid grid-cols-1 gap-3 desktop:grid-cols-3 xl:grid-cols-6">
                <Field id="bookings-from" label={words.text('filters.from')}>
                    {(control) => <DateField {...control} className="admin-filter-from" value={filters.from} clock={clock} weekStart={weekStart} onChange={(from) => set({ from })} />}
                </Field>
                <Field id="bookings-to" label={words.text('filters.to')}>
                    {(control) => <DateField {...control} className="admin-filter-to" value={filters.to} clock={clock} weekStart={weekStart} onChange={(to) => set({ to })} />}
                </Field>
                <Field id="bookings-status" label={words.text('filters.status')}>
                    {(control) => (
                        <Select
                            {...control}
                            options={[{ value: '', label: words.text('filters.any_status') }, ...options.statuses.map((status) => ({ value: status, label: statusLabel(status, shell) }))]}
                            value={filters.status}
                            onChange={(status) => set({ status })}
                        />
                    )}
                </Field>
                {staffShown && (
                    <Field id="bookings-staff" label={words.text('filters.staff')}>
                        {(control) => <Select {...control} options={choices(options.staff, words.text('filters.all_staff'), { key: 'filters.inactive_staff', name: 'staff' }, words)} value={filters.staff === null ? '' : String(filters.staff)} onChange={(staff) => set({ staff: idOf(staff) })} />}
                    </Field>
                )}
                <Field id="bookings-service" label={words.text('filters.service')}>
                    {(control) => (
                        <Select {...control} options={choices(options.services, words.text('filters.all_services'), { key: 'filters.inactive_service', name: 'service' }, words)} value={filters.service === null ? '' : String(filters.service)} onChange={(service) => set({ service: idOf(service) })} />
                    )}
                </Field>
                <Field id="bookings-search" label={words.text('filters.search')}>
                    {(control) => <TextInput {...control} type="search" value={filters.search} placeholder={searchHint} onChange={(event) => set({ search: event.target.value })} />}
                </Field>
            </div>
        </section>
    );
}
