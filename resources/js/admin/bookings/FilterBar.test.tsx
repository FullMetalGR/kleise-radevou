import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, press, type Mounted } from '@/components/ui/harness';
import { FilterBar } from './FilterBar';
import { type Filters, NO_FILTERS, type Options } from './model';
import { keyedBookings, WithBookings } from '@/testing/keyed';

/*
 * The Bookings filter bar's gestures: the Approval inbox button and Clear. Every word renders
 * from the keyed bookings section and is read through it.
 */

const words = keyedBookings();

const OPTIONS: Options = {
    statuses: ['pending', 'awaiting_approval', 'confirmed'],
    staff: [
        { id: 2, name: 'Elena Papadaki', active: true },
        { id: 3, name: 'Nikos Georgiou', active: false },
    ],
    services: [{ id: 7, name: 'Haircut', active: true }],
    members: 3,
    searchable: ['customer_name', 'customer_email'],
};

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function render(filters: Filters, onChange: (filters: Filters) => void = () => {}, staffShown = true, options: Options = OPTIONS) {
    view.render(
        <WithBookings>
            <FilterBar filters={filters} options={options} staffShown={staffShown} clock={{ timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' }} weekStart={1} onChange={onChange} />
        </WithBookings>,
    );
}

const inbox = () => view.host.querySelector('.admin-approval-inbox');

describe('the filter bar', () => {
    it('sets awaiting approval alone from the Approval inbox, leaving From and To, primary while set and secondary otherwise', () => {
        const onChange = vi.fn();
        const dated = { ...NO_FILTERS, from: '2026-11-01', to: '2026-11-30', status: 'confirmed' };
        render(dated, onChange);

        expect(inbox()?.classList.contains('admin-button--secondary')).toBe(true);
        press(inbox());
        expect(onChange).toHaveBeenCalledWith({ ...dated, status: 'awaiting_approval' });

        render({ ...dated, status: 'awaiting_approval' });
        expect(inbox()?.classList.contains('admin-button--primary')).toBe(true);
    });

    it('clears every field', () => {
        const onChange = vi.fn();
        render({ from: '2026-11-01', to: '2026-11-30', status: 'confirmed', staff: 2, service: 7, search: 'ada' }, onChange);

        press(view.host.querySelector('.admin-filter-clear'));
        expect(onChange).toHaveBeenCalledWith(NO_FILTERS);
    });

    it('offers the full roster with inactive rows suffixed, and no staff control while the axis is not real', () => {
        render(NO_FILTERS);
        expect([...view.host.querySelectorAll('#bookings-staff option')].map((option) => option.textContent)).toEqual([words.text('filters.all_staff'), 'Elena Papadaki', words.text('filters.inactive_staff', { staff: 'Nikos Georgiou' })]);
        expect([...view.host.querySelectorAll('#bookings-service option')].map((option) => option.textContent)).toEqual([words.text('filters.all_services'), 'Haircut']);
        expect(view.host.querySelector('#bookings-status option')?.textContent).toBe(words.text('filters.any_status'));
        expect(inbox()?.textContent).toBe(words.text('filters.inbox'));
        expect(view.host.querySelector('.admin-filter-clear')?.textContent).toBe(words.text('filters.clear'));
        expect([...view.host.querySelectorAll('label')].map((label) => label.textContent)).toEqual(
            (['filters.from', 'filters.to', 'filters.status', 'filters.staff', 'filters.service', 'filters.search'] as const).map((key) => words.text(key)),
        );

        render(NO_FILTERS, () => {}, false);
        expect(view.host.querySelector('#bookings-staff')).toBeNull();
    });

    it('offers the email as a search term only where the server says the search reads it, the viewer holding manageBookings', () => {
        const placeholder = () => view.host.querySelector<HTMLInputElement>('#bookings-search')?.placeholder;

        render(NO_FILTERS);
        expect(placeholder()).toBe(words.text('filters.search_name_email'));

        render(NO_FILTERS, () => {}, true, { ...OPTIONS, searchable: ['customer_name'] });
        expect(placeholder()).toBe(words.text('filters.search_name'));
    });
});
