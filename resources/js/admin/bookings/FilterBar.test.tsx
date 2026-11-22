import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, onPhone, press, type Mounted } from '@/components/ui/harness';
import { FORCED_CHOSEN } from '@/components/ui/segmented';
import { FilterBar } from './FilterBar';
import { type Filters, NO_FILTERS, type Options } from './model';
import { keyedBookings, WithBookings } from '@/testing/keyed';

/*
 * The Bookings filter bar's gestures: the Approval inbox button and Clear, and on a phone the
 * Filters button that folds the fields away, counting the filters set. Every word renders from the
 * keyed bookings section and is read through it.
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
    it('sets awaiting approval alone from the Approval inbox, leaving From and To, pressed and in the attention look while set, secondary and not pressed otherwise', () => {
        const onChange = vi.fn();
        const dated = { ...NO_FILTERS, from: '2026-11-01', to: '2026-11-30', status: 'confirmed' };
        render(dated, onChange);

        expect(inbox()?.classList.contains('admin-button--secondary')).toBe(true);
        expect(inbox()?.getAttribute('aria-pressed')).toBe('false');
        expect(inbox()?.classList.contains('bg-attention-soft')).toBe(false);
        press(inbox());
        expect(onChange).toHaveBeenCalledWith({ ...dated, status: 'awaiting_approval' });

        render({ ...dated, status: 'awaiting_approval' }, onChange);
        expect(inbox()?.getAttribute('aria-pressed')).toBe('true');
        expect([...(inbox()?.classList ?? [])]).toEqual(expect.arrayContaining(['bg-attention-soft', 'inset-ring-attention-line', 'text-attention-ink']));
        expect([...(inbox()?.classList ?? [])].filter((name) => name.includes('primary'))).toEqual([]);
        expect(inbox()?.querySelector('.admin-button__icon[aria-hidden="true"] svg')).not.toBeNull();
        press(inbox());
        expect(onChange).toHaveBeenLastCalledWith({ ...dated, status: 'awaiting_approval' });
    });

    it('rings the pressed inbox under the pointer in the attention ink, which stands out from the white card, never in the bare amber', () => {
        render({ ...NO_FILTERS, status: 'awaiting_approval' }, vi.fn());

        expect([...(inbox()?.classList ?? [])].filter((name) => name.startsWith('enabled:hover:inset-ring-'))).toEqual(['enabled:hover:inset-ring-attention-ink']);
    });

    it('paints the pressed inbox in the system\'s highlight where the system forces its own colours, and nothing of it while it is not pressed', () => {
        render({ ...NO_FILTERS, status: 'awaiting_approval' }, vi.fn());
        expect(FORCED_CHOSEN.split(' ').every((name) => inbox()?.classList.contains(name))).toBe(true);

        render(NO_FILTERS, vi.fn());
        expect(FORCED_CHOSEN.split(' ').filter((name) => inbox()?.classList.contains(name))).toEqual([]);
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

    it('lays the fields out by the room its own card has: one to a row on a phone, then two, three and six as the card holds them whole', () => {
        render(NO_FILTERS);

        const card = view.host.querySelector('.admin-filter-bar');
        const fields = [...(view.host.querySelector('.admin-filter-bar__fields')?.classList ?? [])];

        expect(card?.classList.contains('@container')).toBe(true);
        expect(fields).toEqual(
            expect.arrayContaining(['grid', 'grid-cols-1', 'desktop:@min-[31rem]:grid-cols-2', 'desktop:@min-[47rem]:grid-cols-3', 'desktop:@min-[95rem]:grid-cols-6']),
        );
        // No column count of the window's width alone: the sidebar takes a different share of each window.
        expect(fields.filter((name) => /^(sm|md|lg|xl|2xl|desktop|wide):grid-cols/.test(name))).toEqual([]);
    });

    it('draws no Filters button from the breakpoint, the fields always open', () => {
        render({ ...NO_FILTERS, status: 'confirmed' });

        expect(view.host.querySelector('.admin-filters-toggle')).toBeNull();
        expect(view.host.querySelector<HTMLElement>('.admin-filter-bar__fields')?.hidden).toBe(false);
        expect(view.host.querySelector('.admin-filter-clear')).not.toBeNull();
    });
});

describe('the filter bar on a phone', () => {
    let undo: () => void = () => {};

    beforeEach(() => {
        undo = onPhone();
    });

    afterEach(() => undo());

    const toggle = () => view.host.querySelector<HTMLButtonElement>('.admin-filters-toggle');

    const fields = () => view.host.querySelector<HTMLElement>('.admin-filter-bar__fields');

    it('folds the fields behind the Filters button, which names them, says whether they are open, and opens and closes them, beside the inbox and Clear', () => {
        render(NO_FILTERS);

        expect(toggle()?.tagName).toBe('BUTTON');
        expect(toggle()?.textContent).toBe(words.text('filters.label'));
        expect(toggle()?.querySelector('.admin-filters-toggle__count')).toBeNull();
        expect(toggle()?.getAttribute('aria-expanded')).toBe('false');
        expect(fields()?.id).not.toBe('');
        expect(toggle()?.getAttribute('aria-controls')).toBe(fields()?.id);
        expect(fields()?.hidden).toBe(true);
        expect(toggle()?.closest('.admin-filter-bar__gestures')).not.toBeNull();
        expect([inbox(), view.host.querySelector('.admin-filter-clear')].every((control) => control?.closest('.admin-filter-bar__gestures') !== null)).toBe(true);

        press(toggle());
        expect(toggle()?.getAttribute('aria-expanded')).toBe('true');
        expect(fields()?.hidden).toBe(false);
        expect(view.host.querySelector('#bookings-from')).not.toBeNull();

        press(toggle());
        expect(toggle()?.getAttribute('aria-expanded')).toBe('false');
        expect(fields()?.hidden).toBe(true);
    });

    it('counts each field set apart from the cleared bar on the button, the inbox once as the status it sets, and the count in words for a screen reader', () => {
        render({ ...NO_FILTERS, status: 'awaiting_approval' });
        expect(toggle()?.querySelector('.admin-filters-toggle__count')?.textContent).toBe('1');
        expect(toggle()?.textContent).toBe(`${words.text('filters.label')}1, ${words.choice('filters.active', 1)}`);

        render({ ...NO_FILTERS, status: 'awaiting_approval', staff: 2, search: 'ada' });
        expect(toggle()?.querySelector('.admin-filters-toggle__count')?.textContent).toBe('3');
        expect(toggle()?.querySelector('.sr-only')?.textContent).toBe(`, ${words.choice('filters.active', 3)}`);

        render({ from: '2026-11-01', to: '2026-11-30', status: 'confirmed', staff: 2, service: 7, search: 'ada' });
        expect(toggle()?.querySelector('.admin-filters-toggle__count')?.textContent).toBe('6');
    });

    it('keeps the inbox\'s pressed state and Clear, the one way to clear, while the fields are folded', () => {
        const onChange = vi.fn();
        render({ ...NO_FILTERS, status: 'awaiting_approval', search: 'ada' }, onChange);

        expect(fields()?.hidden).toBe(true);
        expect(inbox()?.getAttribute('aria-pressed')).toBe('true');
        expect(view.host.querySelectorAll('.admin-filter-clear')).toHaveLength(1);
        press(view.host.querySelector('.admin-filter-clear'));
        expect(onChange).toHaveBeenCalledWith(NO_FILTERS);
    });
});
