import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { listOf } from '@/lib/names';
import { stringsOf } from '@/lib/strings';
import { keyedBookings, keyedShell, WithBookings } from '@/testing/keyed';
import type { CrossingClosure, CrossingFacts } from '@/lib/visits';
import { ADMIN_LINES_EN } from '@/lines.generated';
import type { BookingsStrings } from '@/types';
import type { Booking } from './model';
import { MoveConfirm, type MoveChoice } from './MoveConfirm';
import type { MemberChange, NamedCrossing } from './moving';
import { BookingsProvider } from './words';

/*
 * The confirmation a move opens, from the Move form and from a drop alike: what moves from
 * where to where in the tenant's time, each member change, and the "Tell the customer" tick box
 * ticked by default, left out where the customer was erased. Where the start crosses closed time
 * it is the warning: its own title, the booking named, each reason in a sentence from the facts
 * the server read, and Move anyway beside Cancel.
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

function show(options: { booking?: Booking; changes?: MemberChange[]; crossings?: NamedCrossing[]; open?: boolean; within?: (children: ReactNode) => ReactNode } = {}) {
    const within = options.within ?? ((children: ReactNode) => <WithBookings>{children}</WithBookings>);

    view.render(
        within(
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
            />,
        ),
    );
}

const one = (selector: string) => document.body.querySelector(selector);

/** The reasons the warning lists, in order. */
const lines = () => [...document.body.querySelectorAll('.admin-move-confirm__crossing')].map((line) => line.textContent);

/** Friday 20 November and Sunday 22 November 2026 as a reason names a day. */
const FRIDAY = words.text('move.confirm.days.friday', { date: '20/11/2026' });
const SUNDAY = words.text('move.confirm.days.sunday', { date: '22/11/2026' });

/** Elena's Haircut crossing one closure. */
function closure(closed: CrossingClosure): NamedCrossing {
    return { service: 'Haircut', staff: 'Elena Papadaki', kind: 'closure', facts: { date: closed.date, hours: [], day_off: null, closures: [closed] } };
}

/** Elena's Haircut crossing her working hours on a date, with the facts given. */
function hours(date: string, facts: Partial<CrossingFacts>): NamedCrossing {
    return { service: 'Haircut', staff: 'Elena Papadaki', kind: 'hours', facts: { date, hours: [], day_off: null, closures: [], ...facts } };
}

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

    it('opens as a warning where the start crosses closed time: its own title, the booking named, a line per service with no more read of it, the question, Move anyway and Cancel, confirming with the warning accepted and the tick as left', () => {
        show({
            crossings: [
                { service: 'Haircut', staff: 'Elena Papadaki', kind: 'hours' },
                { service: 'Colour', staff: 'Nikos Georgiou', kind: 'closure' },
            ],
        });

        expect(one('.admin-move-confirm .admin-modal__title')?.textContent).toBe(words.text('move.confirm.closed_title'));
        expect(one('.admin-move-confirm__booking')?.textContent).toBe(words.text('move.confirm.booking', { customer: 'Ada North', services: 'Haircut' }));
        expect(one('.admin-move-confirm__from-to')?.textContent).toBe(words.text('move.confirm.from_to', { from: '03/11/2026 10:00', to: '04/11/2026 11:30' }));
        expect(one('.admin-move-confirm__warning')?.getAttribute('role')).toBe('status');
        expect(lines()).toEqual([words.text('move.confirm.hours', { service: 'Haircut', staff: 'Elena Papadaki' }), words.text('move.confirm.closure', { service: 'Colour', staff: 'Nikos Georgiou' })]);
        expect(one('.admin-move-confirm__sure')?.textContent).toBe(words.text('move.confirm.sure'));
        expect(one('.admin-move-confirm__confirm')?.textContent).toBe(words.text('move.confirm.move_anyway'));
        expect(one('.admin-move-confirm__keep')?.textContent).toBe(words.text('move.confirm.cancel'));

        press(one('#move-tell'));
        press(one('.admin-move-confirm__confirm'));
        expect(choices).toEqual([{ warningAccepted: true, tellCustomer: false }]);
    });

    it('names the booking of an erased customer by the word for one, and every service once', () => {
        const second = { ...BOOKING.items[0]!, sort: 1, service_name: 'Colour' };
        show({ booking: { ...BOOKING, customer_name: null, items: [BOOKING.items[0]!, second, { ...second, sort: 2, service_name: 'Haircut' }] }, crossings: [hours('2026-11-21', { day_off: 'weekday' })] });

        expect(one('.admin-move-confirm__booking')?.textContent).toBe(words.text('move.confirm.booking', { customer: words.text('customer_erased'), services: listOf(['Haircut', 'Colour'], 'en') }));
    });

    it('names a closure of the business by its day and its reason, or by its run of days, the window each day where it has one', () => {
        show({
            crossings: [
                closure({ business: true, date: '2026-11-20', until: null, start: null, end: null, reason: 'Holiday' }),
                closure({ business: true, date: '2026-11-20', until: '2026-11-22', start: null, end: null, reason: null }),
                closure({ business: true, date: '2026-11-20', until: null, start: '15:00', end: '16:30', reason: null }),
                closure({ business: true, date: '2026-11-20', until: '2026-11-22', start: '09:00', end: '10:00', reason: 'Stocktake' }),
            ],
        });

        expect(lines()).toEqual([
            words.text('move.confirm.business_closed_reason', { when: words.text('move.confirm.on_day', { day: FRIDAY }), reason: 'Holiday' }),
            words.text('move.confirm.business_closed', { when: words.text('move.confirm.run', { day: FRIDAY, until: SUNDAY }) }),
            words.text('move.confirm.business_closed', { when: words.text('move.confirm.on_day_window', { day: FRIDAY, start: '15:00', end: '16:30' }) }),
            words.text('move.confirm.business_closed_reason', { when: words.text('move.confirm.run_window', { day: FRIDAY, until: SUNDAY, start: '09:00', end: '10:00' }), reason: 'Stocktake' }),
        ]);
    });

    it('names a closure of the member by name, with its window and reason, and a whole line where the member cannot be named', () => {
        show({
            crossings: [
                closure({ business: false, date: '2026-11-20', until: null, start: '15:00', end: '16:00', reason: null }),
                closure({ business: false, date: '2026-11-20', until: '2026-11-22', start: null, end: null, reason: 'Leave' }),
                { ...closure({ business: false, date: '2026-11-20', until: null, start: null, end: null, reason: null }), service: 'Colour', staff: null },
            ],
        });

        expect(lines()).toEqual([
            words.text('move.confirm.staff_closed', { staff: 'Elena Papadaki', when: words.text('move.confirm.on_day_window', { day: FRIDAY, start: '15:00', end: '16:00' }) }),
            words.text('move.confirm.staff_closed_reason', { staff: 'Elena Papadaki', when: words.text('move.confirm.run', { day: FRIDAY, until: SUNDAY }), reason: 'Leave' }),
            words.text('move.confirm.closure_unnamed'),
        ]);
    });

    it('names the member\'s hours that day, their weekday off, the business closed that weekday, or the date taken off', () => {
        show({
            crossings: [
                hours('2026-11-20', { hours: [{ start: '09:00', end: '13:00' }, { start: '14:00', end: '18:00' }] }),
                hours('2026-11-21', { day_off: 'weekday' }),
                hours('2026-11-22', { day_off: 'business' }),
                hours('2026-11-24', { day_off: 'date' }),
            ],
        });

        const windows = [words.text('move.confirm.window', { start: '09:00', end: '13:00' }), words.text('move.confirm.window', { start: '14:00', end: '18:00' })];

        expect(lines()).toEqual([
            words.text('move.confirm.outside_hours', { staff: 'Elena Papadaki', hours: listOf(windows, 'en') }),
            words.text('move.confirm.off_weekday', { staff: 'Elena Papadaki', weekdays: words.text('move.confirm.weekdays.saturday') }),
            words.text('move.confirm.business_weekday', { weekdays: words.text('move.confirm.weekdays.sunday') }),
            words.text('move.confirm.off_date', { staff: 'Elena Papadaki', when: words.text('move.confirm.on_day', { day: words.text('move.confirm.days.tuesday', { date: '24/11/2026' }) }) }),
        ]);
    });

    it('says the plain line where a day has no hours and no reason it knows: none given, or one this page has no words for', () => {
        show({ crossings: [hours('2026-11-21', {}), { ...hours('2026-11-21', { day_off: 'season' }), service: 'Colour' }, { ...hours('2026-11-21', { day_off: 'season' }), staff: null }] });

        expect(lines()).toEqual([
            words.text('move.confirm.hours', { service: 'Haircut', staff: 'Elena Papadaki' }),
            words.text('move.confirm.hours', { service: 'Colour', staff: 'Elena Papadaki' }),
            words.text('move.confirm.hours_unnamed'),
        ]);
    });

    it('says a reason once where two services meet the same closure', () => {
        const holiday: CrossingClosure = { business: true, date: '2026-11-20', until: null, start: null, end: null, reason: 'Holiday' };
        show({ crossings: [closure(holiday), { ...closure(holiday), service: 'Colour' }] });

        expect(lines()).toEqual([words.text('move.confirm.business_closed_reason', { when: words.text('move.confirm.on_day', { day: FRIDAY }), reason: 'Holiday' })]);
    });

    it('reads in English as a person would say it, the day in the tenant\'s date format', () => {
        const english = stringsOf<BookingsStrings>(ADMIN_LINES_EN.bookings, 'en');
        show({
            crossings: [closure({ business: true, date: '2026-11-20', until: null, start: null, end: null, reason: 'Holiday' }), hours('2026-11-20', { hours: [{ start: '10:00', end: '18:00' }] }), hours('2026-11-21', { day_off: 'weekday' })],
            within: (children) => <BookingsProvider words={english}>{children}</BookingsProvider>,
        });

        expect(one('.admin-move-confirm .admin-modal__title')?.textContent).toBe('This time is closed');
        expect(lines()).toEqual(['The business is closed on Friday 20/11/2026 (Holiday).', 'Elena Papadaki does not work at this time. Their hours that day are 10:00 to 18:00.', 'Elena Papadaki is off on Saturdays.']);
        expect(one('.admin-move-confirm__confirm')?.textContent).toBe('Move anyway');
        expect(one('.admin-move-confirm__keep')?.textContent).toBe('Cancel');
    });

    it('names a day by the line of its weekday, each of the seven its own, which a language that says a day behind an article fills with it', () => {
        // Monday 23 to Sunday 29 November 2026, each a date the member took off.
        const week = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const;
        const dayOfMonth = (index: number) => String(23 + index);

        show({ crossings: week.map((_, index) => hours(`2026-11-${dayOfMonth(index)}`, { day_off: 'date' })) });

        expect(lines()).toEqual(
            week.map((weekday, index) =>
                words.text('move.confirm.off_date', { staff: 'Elena Papadaki', when: words.text('move.confirm.on_day', { day: words.text(`move.confirm.days.${weekday}`, { date: `${dayOfMonth(index)}/11/2026` }) }) }),
            ),
        );
        expect(lines()[1]).toContain('move.confirm.days.tuesday 24/11/2026');
        expect(lines()[5]).toContain('move.confirm.days.saturday 28/11/2026');
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
