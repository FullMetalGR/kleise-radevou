import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { keyedBookings, keyedShell, WithBookings } from '@/testing/keyed';
import type { Booking } from './model';
import { MoveConfirm, type MoveChoice } from './MoveConfirm';
import type { MemberChange, NamedCrossing } from './moving';

/*
 * The confirmation a move opens, from the Move form and from a drop alike: what moves from
 * where to where in the tenant's time, each member change, the warning naming per service
 * whether it is outside working hours or on a closure, and the "Tell the customer" tick box
 * ticked by default, left out where the customer was erased.
 */

const words = keyedBookings();
const shell = keyedShell();
const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const BOOKING: Booking = {
    uuid: 'b1',
    status: 'confirmed',
    customer_name: 'Ada North',
    total_minor: 3500,
    currency: 'EUR',
    movable: true,
    items: [{ sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena Papadaki', start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:45:00+00:00', price_minor: 3500 }],
};

let view: Mounted;
let choices: MoveChoice[];

beforeEach(() => {
    view = mount();
    choices = [];
});

afterEach(() => view.unmount());

function show(options: { booking?: Booking; changes?: MemberChange[]; crossings?: NamedCrossing[]; open?: boolean } = {}) {
    view.render(
        <WithBookings>
            <MoveConfirm
                open={options.open ?? true}
                booking={options.booking ?? BOOKING}
                to="2026-11-04T09:30:00+00:00"
                changes={options.changes ?? []}
                crossings={options.crossings ?? []}
                clock={CLOCK}
                busy={false}
                onConfirm={(choice) => choices.push(choice)}
                onDismiss={() => {}}
            />
        </WithBookings>,
    );
}

const one = (selector: string) => document.body.querySelector(selector);

describe('the move confirmation', () => {
    it('says from where to where in the tenant time, each member change, no warning where nothing crosses, and the tick box ticked', () => {
        show({ changes: [{ segment: 0, service: 'Haircut', from: 'Elena Papadaki', to: 'Nikos Georgiou' }] });

        expect(one('.admin-move-confirm .admin-modal__title')?.textContent).toBe(words.text('move.confirm.title'));
        expect(one('.admin-move-confirm__from-to')?.textContent).toBe(words.text('move.confirm.from_to', { from: '03/11/2026 10:00', to: '04/11/2026 11:30' }));
        expect([...document.body.querySelectorAll('.admin-move-confirm__change')].map((line) => line.textContent)).toEqual([
            words.text('move.confirm.member', { service: 'Haircut', from: 'Elena Papadaki', to: 'Nikos Georgiou' }),
        ]);
        expect(one('.admin-move-confirm__warning')).toBeNull();
        const tick = one('#move-tell');
        expect(tick instanceof HTMLInputElement && tick.checked).toBe(true);
        expect(one('label[for="move-tell"]')?.textContent).toBe(words.text('move.confirm.tell'));
        expect(one('.admin-move-confirm__keep')?.textContent).toBe(shell.text('kit.confirm.keep'));

        press(one('.admin-move-confirm__confirm'));
        expect(choices).toEqual([{ warningAccepted: false, tellCustomer: true }]);
    });

    it('names two changes of a chain holding the same service twice, each keyed by its segment', () => {
        const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
        show({
            changes: [
                { segment: 0, service: 'Haircut', from: 'Elena Papadaki', to: 'Nikos Georgiou' },
                { segment: 2, service: 'Haircut', from: 'Elena Papadaki', to: 'Maria Ioannou' },
            ],
        });

        expect([...document.body.querySelectorAll('.admin-move-confirm__change')].map((line) => line.textContent)).toEqual([
            words.text('move.confirm.member', { service: 'Haircut', from: 'Elena Papadaki', to: 'Nikos Georgiou' }),
            words.text('move.confirm.member', { service: 'Haircut', from: 'Elena Papadaki', to: 'Maria Ioannou' }),
        ]);
        expect(errors.mock.calls.map((call) => String(call[0])).filter((message) => message.includes('same key'))).toEqual([]);
        errors.mockRestore();
    });

    it('says a whole line where a name is missing, never a mark in place of it: a member the segment does not list, a crossing whose service or member it cannot name', () => {
        show({
            changes: [{ segment: 0, service: 'Haircut', from: 'Elena Papadaki', to: null }],
            crossings: [
                { service: null, staff: 'Elena Papadaki', kind: 'hours' },
                { service: 'Colour', staff: null, kind: 'closure' },
            ],
        });

        expect([...document.body.querySelectorAll('.admin-move-confirm__change')].map((line) => line.textContent)).toEqual([words.text('move.confirm.member_unnamed', { service: 'Haircut', from: 'Elena Papadaki' })]);
        expect([...document.body.querySelectorAll('.admin-move-confirm__crossing')].map((line) => line.textContent)).toEqual([words.text('move.confirm.hours_unnamed'), words.text('move.confirm.closure_unnamed')]);
    });

    it('warns per service whether it is outside working hours or on a closure, and confirms with the warning accepted and the tick as left', () => {
        show({
            crossings: [
                { service: 'Haircut', staff: 'Elena Papadaki', kind: 'hours' },
                { service: 'Colour', staff: 'Nikos Georgiou', kind: 'closure' },
            ],
        });

        expect(one('.admin-move-confirm__warning')?.getAttribute('role')).toBe('status');
        expect(one('.admin-move-confirm__warning')?.textContent).toContain(words.text('move.confirm.warning'));
        expect([...document.body.querySelectorAll('.admin-move-confirm__crossing')].map((line) => line.textContent)).toEqual([
            words.text('move.confirm.hours', { service: 'Haircut', staff: 'Elena Papadaki' }),
            words.text('move.confirm.closure', { service: 'Colour', staff: 'Nikos Georgiou' }),
        ]);

        press(one('#move-tell'));
        press(one('.admin-move-confirm__confirm'));
        expect(choices).toEqual([{ warningAccepted: true, tellCustomer: false }]);
    });

    it('leaves the tick box out where the customer was erased, and tells nobody', () => {
        show({ booking: { ...BOOKING, customer_name: null } });

        expect(one('#move-tell')).toBeNull();
        press(one('.admin-move-confirm__confirm'));
        expect(choices).toEqual([{ warningAccepted: false, tellCustomer: false }]);
    });

    it('ticks the box again each time it opens', () => {
        show();
        press(one('#move-tell'));
        show({ open: false });
        show();

        const tick = one('#move-tell');
        expect(tick instanceof HTMLInputElement && tick.checked).toBe(true);
    });
});
