import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, press, type Mounted } from '@/components/ui/harness';
import type { TenantClock } from '@/lib/format';
import { BookingsTable } from './BookingsTable';
import { type Booking, CUSTOMER_ERASED } from './model';
import { keyedBookings, WithBookings } from '@/testing/keyed';

/** The keyed bookings section every word renders from and is read through. */
const words = keyedBookings();

/*
 * The bookings table's columns and pagination.
 */

const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

function booking(uuid: string, patch: Partial<Booking> = {}): Booking {
    return {
        uuid,
        status: 'confirmed',
        customer_name: `Customer ${uuid}`,
        total_minor: 3500,
        currency: 'EUR',
        items: [
            { sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena Papadaki', start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:45:00+00:00', price_minor: 1500 },
            { sort: 1, service_id: 1, service_name: 'Haircut', staff_id: 3, staff_name: 'Nikos Georgiou', start: '2026-11-03T08:45:00+00:00', end: '2026-11-03T09:30:00+00:00', price_minor: 2000 },
        ],
        ...patch,
    };
}

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function render(rows: Booking[], props: { total?: number; page?: number; staffShown?: boolean; plainNames?: boolean; onSelect?: (uuid: string) => void; onPage?: (page: number) => void } = {}) {
    view.render(
        <WithBookings>
            <BookingsTable
                rows={rows}
                total={props.total ?? rows.length}
                page={props.page ?? 1}
                pageSize={25}
                staffShown={props.staffShown ?? true}
                plainNames={props.plainNames}
                selected={null}
                clock={CLOCK}
                onSelect={props.onSelect ?? (() => {})}
                onPage={props.onPage ?? (() => {})}
            />
        </WithBookings>,
    );
}


describe('the bookings table', () => {
    it('lists a two-segment single-service booking once, two members once each, the start in Athens, and a JPY total without a decimal shift', () => {
        render([booking('a'), booking('b', { currency: 'JPY', total_minor: 5000 })]);

        expect(q('.admin-bookings__services')?.textContent).toBe('Haircut');
        expect(q('.admin-bookings__staff')?.textContent).toBe('Elena Papadaki, Nikos Georgiou');
        expect(q('.admin-bookings__when')?.textContent).toBe('03/11/2026 10:00');
        expect(view.host.querySelectorAll('.admin-bookings__total')[1]?.textContent).toMatch(/^\D*5,000$/);
    });

    it('carries the void styling on a cancelled row and none on a confirmed one', () => {
        render([booking('a'), booking('b', { status: 'cancelled' })]);

        const rows = view.host.querySelectorAll('.admin-bookings__row');
        expect(rows[0]?.classList.contains('admin-row--void')).toBe(false);
        expect(rows[1]?.classList.contains('admin-row--void')).toBe(true);
        expect(rows[1]?.className).toContain('line-through');
    });

    it('draws the customer as a link of the theme colour, and in the body colour where the table is told the names are plain, a button that selects either way', () => {
        const onSelect = vi.fn();
        render([booking('a')], { onSelect });
        expect(q('.admin-row-select')?.classList.contains('text-primary')).toBe(true);
        expect(q('.admin-row-select')?.classList.contains('admin-row-select--plain')).toBe(false);

        render([booking('a')], { plainNames: true, onSelect });
        const name = q('.admin-row-select');
        expect(name?.tagName).toBe('BUTTON');
        expect(name?.classList.contains('admin-row-select--plain')).toBe(true);
        expect(name?.classList.contains('text-primary')).toBe(false);
        expect(name?.classList.contains('text-foreground')).toBe(true);
        press(name);
        expect(onSelect).toHaveBeenCalledWith('a');
    });

    it('drops the staff column when the staff axis is not real', () => {
        render([booking('a')], { staffShown: false });

        expect(q('.admin-bookings__staff')).toBeNull();
        expect([...view.host.querySelectorAll('th')].map((th) => th.textContent)).toEqual((['table.customer', 'table.service', 'table.when', 'table.status', 'table.total'] as const).map((key) => words.text(key)));
        expect(q('#bookings-head')?.textContent).toBe(words.text('table.title'));
    });

    it('reads 25 of 61 on page one with Previous disabled, and 11 of 61 on the last with Next disabled, inside the card', () => {
        const onPage = vi.fn();
        render(Array.from({ length: 25 }, (_, n) => booking(`r${n}`)), { total: 61, page: 1, onPage });

        expect(q('.admin-bookings__count')?.textContent).toBe(words.text('table.count', { count: '25', total: '61' }));
        expect(q<HTMLButtonElement>('.admin-pagination__previous')?.disabled).toBe(true);
        expect(q('.admin-bookings .admin-pagination')).not.toBeNull();
        press(q('.admin-pagination__next'));
        expect(onPage).toHaveBeenCalledWith(2);

        render(Array.from({ length: 11 }, (_, n) => booking(`r${n}`)), { total: 61, page: 3 });

        expect(q('.admin-bookings__count')?.textContent).toBe(words.text('table.count', { count: '11', total: '61' }));
        expect(q<HTMLButtonElement>('.admin-pagination__next')?.disabled).toBe(true);
        expect(q<HTMLButtonElement>('.admin-pagination__previous')?.disabled).toBe(false);
    });

    it('names the row of a booking whose customer was erased "Customer erased", the button selecting it as any other', () => {
        const onSelect = vi.fn();
        render([booking('a', { customer_name: null, customer_email: null, customer_phone: null }), booking('b')], { onSelect });

        const buttons = [...view.host.querySelectorAll('.admin-row-select')];
        expect(buttons.map((button) => button.textContent)).toEqual([words.text(CUSTOMER_ERASED), 'Customer b']);
        expect(words.text(CUSTOMER_ERASED)).toBe('[customer_erased]');
        press(buttons[0] ?? null);
        expect(onSelect).toHaveBeenCalledWith('a');
    });

    it('selects a row once through the button named for the customer', () => {
        const onSelect = vi.fn();
        render([booking('a')], { onSelect });

        const button = view.host.querySelector('.admin-row-select');
        expect(button?.textContent).toBe('Customer a');
        press(button);
        expect(onSelect).toHaveBeenCalledOnce();
        expect(onSelect).toHaveBeenCalledWith('a');
    });
});

function q<T extends Element = Element>(selector: string): T | null {
    return view.host.querySelector<T>(selector);
}
