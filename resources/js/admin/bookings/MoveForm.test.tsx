import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press, settle } from '@/components/ui/harness';
import { listOf } from '@/lib/names';
import { keyedBookings, keyedShell, WithBookings } from '@/testing/keyed';
import { BookingDialog } from './BookingDialog';
import type { Booking } from './model';

/*
 * The Move form inside the booking dialog: Move shown exactly where the server says the booking is
 * movable, the form's line and defaults, the move's read asked with the day and the members that
 * change and asked again on a change, the times in the tenant's clock marked where they cross the
 * hours or a closure, Save waiting for a time with its reason, Back, the confirmation's choices
 * posted as the move's body, and each refusal read as the server words it. The read is a mocked
 * fetch and the post the mocked router, as the wizard's tests have them; every word renders from
 * the keyed bookings section.
 */

const words = keyedBookings();
const shell = keyedShell();
const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

type Visit = { onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

/** Two services with Elena, starting half past midnight in Athens on the fourth, the evening of the third in UTC. */
const BOOKING: Booking = {
    uuid: 'b1',
    status: 'confirmed',
    customer_name: 'Ada North',
    total_minor: 7000,
    currency: 'EUR',
    movable: true,
    items: [
        { sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena Papadaki', start: '2026-11-03T22:30:00+00:00', end: '2026-11-03T23:15:00+00:00', price_minor: 3500 },
        { sort: 1, service_id: 4, service_name: 'Colour', staff_id: 2, staff_name: 'Elena Papadaki', start: '2026-11-03T23:15:00+00:00', end: '2026-11-04T00:00:00+00:00', price_minor: 3500 },
    ],
};

const SEGMENTS = [
    { segment: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena Papadaki', members: [{ id: 2, name: 'Elena Papadaki' }, { id: 3, name: 'Nikos Georgiou' }] },
    { segment: 1, service_id: 4, service_name: 'Colour', staff_id: 2, staff_name: 'Elena Papadaki', members: [{ id: 2, name: 'Elena Papadaki' }] },
];

const FREE = '2026-11-04T08:00:00+00:00';
const AFTER_HOURS = '2026-11-04T09:00:00+00:00';
const BOTH = '2026-11-04T10:00:00+00:00';

const STARTS = [
    { start: FREE, crossings: [] },
    { start: AFTER_HOURS, crossings: [{ segment: 0, staff_id: 2, kind: 'hours' }] },
    { start: BOTH, crossings: [{ segment: 0, staff_id: 2, kind: 'hours' }, { segment: 1, staff_id: 2, kind: 'closure' }] },
];

let view: Mounted;
let fetched: ReturnType<typeof vi.fn>;
let answer: (url: string) => Response | Promise<Response>;

const day = (starts: unknown[] = STARTS) => new Response(JSON.stringify({ date: '2026-11-04', segments: SEGMENTS, starts }), { status: 200 });

beforeEach(() => {
    view = mount();
    answer = () => day();
    fetched = vi.fn(async (url: string) => answer(url));
    vi.stubGlobal('fetch', fetched);
    inertia.post.mockReset();
    inertia.listeners.clear();
});

afterEach(() => {
    view.unmount();
    vi.unstubAllGlobals();
});

function dialog(options: { booking?: Booking; staffShown?: boolean } = {}) {
    const booking = options.booking ?? BOOKING;
    view.render(
        <WithBookings>
            <BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking, note: null, trail: [], actions: ['cancel'] } }} staffShown={options.staffShown ?? true} clock={CLOCK} weekStart={1} onClose={() => {}} onAct={() => {}} />
        </WithBookings>,
    );
}

const one = (selector: string) => document.body.querySelector(selector);

/** The query of the nth read, from one. */
function readQuery(nth: number): { path: string; query: URLSearchParams } {
    const url = String(fetched.mock.calls[nth - 1]?.[0] ?? '');
    const [path = '', query = ''] = url.split('?');

    return { path, query: new URLSearchParams(query) };
}

async function openMove(options: { booking?: Booking; staffShown?: boolean } = {}): Promise<void> {
    dialog(options);
    press(one('.admin-booking-move'));
    await settle();
}

function choose(selector: string, value: string): void {
    const select = document.body.querySelector<HTMLSelectElement>(selector);

    if (select === null) {
        throw new Error(`No ${selector}.`);
    }

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')?.set?.call(select, value);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

const options = (selector: string) => [...document.body.querySelectorAll<HTMLOptionElement>(`${selector} option`)].map((option) => [option.value, option.textContent]);

const save = () => one('.admin-move__save') as HTMLButtonElement | null;

/** The last post's address, body and visit. */
function lastPost(): { url: string; body: Record<string, unknown>; visit: Visit } {
    const call = inertia.post.mock.lastCall as [string, Record<string, unknown>, Visit] | undefined;

    if (call === undefined) {
        throw new Error('Nothing was posted.');
    }

    return { url: call[0], body: call[1], visit: call[2] };
}

/** The last post refused with the envelope given, as the framework reports a JSON answer. */
function refuse(status: number, code: string, detail: string, data: Record<string, unknown> = {}): void {
    const { visit } = lastPost();
    const event = new CustomEvent('invalid', { cancelable: true, detail: { response: { status, data: { code, message: code, data: { status, detail, ...data } } } } });

    act(() => visit.onStart?.());
    act(() => inertia.listeners.get('invalid')?.(event));
    act(() => visit.onFinish?.());
}

async function toConfirmation(time: string): Promise<void> {
    choose('#move-time', time);
    press(save());
    await settle();
}

describe('the Move form', () => {
    it('offers Move exactly where the server says the booking is movable', () => {
        dialog();
        expect(one('.admin-booking-move')?.textContent).toBe(words.text('move.open'));

        dialog({ booking: { ...BOOKING, movable: false } });
        expect(one('.admin-booking-move')).toBeNull();

        const { movable: _movable, ...silent } = BOOKING;
        dialog({ booking: silent });
        expect(one('.admin-booking-move')).toBeNull();
        expect(fetched).not.toHaveBeenCalled();
    });

    it('turns the dialog body into the form: what moves and where it sits now, the day in the tenant zone, and the read asked for it with no member changed', async () => {
        await openMove();

        expect(document.body.querySelectorAll('.admin-modal__frame')).toHaveLength(1);
        expect(one('.admin-booking-facts')).toBeNull();
        expect(one('.admin-booking-actions')).toBeNull();
        expect(one('.admin-move__now')?.textContent).toBe(words.text('move.now', { services: listOf(['Haircut', 'Colour'], 'en'), when: '04/11/2026 00:30', staff: 'Elena Papadaki' }));
        expect(one('.admin-move__date')?.getAttribute('value')).toBe('2026-11-04');
        expect(one('label[for="move-date"]')?.textContent).toBe(words.text('move.date'));
        expect(fetched).toHaveBeenCalledTimes(1);
        const read = readQuery(1);
        expect(read.path).toBe('/admin/api/bookings/b1/move');
        expect(read.query.get('date')).toBe('2026-11-04');
        expect([...read.query.keys()]).toEqual(['date']);
    });

    it('lists the day\'s times in the tenant clock, each crossing marked in its own words', async () => {
        await openMove();

        expect(one('label[for="move-time"]')?.textContent).toBe(words.text('move.time'));
        expect(options('#move-time')).toEqual([
            ['', words.text('move.choose')],
            [FREE, '10:00'],
            [AFTER_HOURS, words.text('move.time_hours', { time: '11:00' })],
            [BOTH, words.text('move.time_both', { time: '12:00' })],
        ]);
    });

    it('groups the times, first those that cross nothing and then the marked ones, each in time order under its label', async () => {
        const EARLY = '2026-11-04T06:00:00+00:00';
        answer = () => day([{ start: EARLY, crossings: [{ segment: 0, staff_id: 2, kind: 'hours' }] }, ...STARTS]);
        await openMove();

        const groups = [...document.body.querySelectorAll<HTMLOptGroupElement>('#move-time optgroup')].map((group) => [group.label, [...group.querySelectorAll('option')].map((option) => option.value)]);

        expect(groups).toEqual([
            [words.text('move.group_free'), [FREE]],
            [words.text('move.group_marked'), [EARLY, AFTER_HOURS, BOTH]],
        ]);
        expect(options('#move-time')[0]).toEqual(['', words.text('move.choose')]);
    });

    it('shows the marked group alone on a day with only marked times', async () => {
        answer = () => day([STARTS[1], STARTS[2]]);
        await openMove();

        expect([...document.body.querySelectorAll<HTMLOptGroupElement>('#move-time optgroup')].map((group) => group.label)).toEqual([words.text('move.group_marked')]);
    });

    it('marks a time on a closure alone as on a closure', async () => {
        answer = () => day([{ start: FREE, crossings: [{ segment: 1, staff_id: 2, kind: 'closure' }] }]);
        await openMove();

        expect(options('#move-time')[1]).toEqual([FREE, words.text('move.time_closure', { time: '10:00' })]);
    });

    it('labels a member select per segment by its service, only where a segment has more than one member and the dialog shows staff', async () => {
        await openMove();

        expect(one('label[for="move-staff-0"]')?.textContent).toBe(words.text('move.staff_for', { service: 'Haircut' }));
        expect(options('#move-staff-0')).toEqual([
            ['2', 'Elena Papadaki'],
            ['3', 'Nikos Georgiou'],
        ]);
        expect((one('#move-staff-0') as HTMLSelectElement | null)?.value).toBe('2');
        expect(one('#move-staff-1')).toBeNull();

        view.unmount();
        view = mount();
        await openMove({ staffShown: false });
        expect(one('#move-staff-0')).toBeNull();
        expect(one('.admin-move__now')?.textContent).toBe(words.text('move.now_alone', { services: listOf(['Haircut', 'Colour'], 'en'), when: '04/11/2026 00:30' }));
    });

    it('shows the segment\'s own member as chosen where the members listed leave them out, and posts the first member listed once chosen', async () => {
        const absent = [{ ...SEGMENTS[0]!, members: [{ id: 3, name: 'Nikos Georgiou' }, { id: 5, name: 'Maria Ioannou' }] }, SEGMENTS[1]!];
        answer = () => new Response(JSON.stringify({ date: '2026-11-04', segments: absent, starts: STARTS }), { status: 200 });
        await openMove();

        expect(options('#move-staff-0')).toEqual([
            ['2', 'Elena Papadaki'],
            ['3', 'Nikos Georgiou'],
            ['5', 'Maria Ioannou'],
        ]);
        expect((one('#move-staff-0') as HTMLSelectElement | null)?.value).toBe('2');

        choose('#move-staff-0', '3');
        await settle();
        expect(readQuery(2).query.get('staff[0]')).toBe('3');
        await toConfirmation(FREE);
        press(one('.admin-move-confirm__confirm'));
        expect(lastPost().body).toEqual({ start: FREE, staff: { 0: 3 }, warning_accepted: false, tell_customer: true });
    });

    it('labels the one segment\'s select Staff member', async () => {
        const single: Booking = { ...BOOKING, items: [BOOKING.items[0]!] };
        answer = () => new Response(JSON.stringify({ date: '2026-11-04', segments: [SEGMENTS[0]], starts: STARTS }), { status: 200 });
        await openMove({ booking: single });

        expect(one('label[for="move-staff-0"]')?.textContent).toBe(words.text('move.staff'));
    });

    it('reads again on a member change with the changed segment alone, keeping a time still offered and clearing one that is not', async () => {
        await openMove();
        choose('#move-time', AFTER_HOURS);

        answer = () => day([{ start: AFTER_HOURS, crossings: [] }]);
        choose('#move-staff-0', '3');
        await settle();
        expect(fetched).toHaveBeenCalledTimes(2);
        expect(readQuery(2).query.get('staff[0]')).toBe('3');
        expect(readQuery(2).query.get('staff[1]')).toBeNull();
        expect((one('#move-time') as HTMLSelectElement | null)?.value).toBe(AFTER_HOURS);

        answer = () => day([{ start: FREE, crossings: [] }]);
        choose('#move-staff-0', '2');
        await settle();
        expect(fetched).toHaveBeenCalledTimes(3);
        expect([...readQuery(3).query.keys()]).toEqual(['date']);
        expect((one('#move-time') as HTMLSelectElement | null)?.value).toBe('');
    });

    it('reads again on a day change', async () => {
        await openMove();
        press(one('.admin-move__date'));
        press(one('button[data-date="2026-11-06"]'));
        await settle();

        expect(fetched).toHaveBeenCalledTimes(2);
        expect(readQuery(2).query.get('date')).toBe('2026-11-06');
    });

    it('says while the times load, when the read fails, when its answer is unreadable and when the day has none', async () => {
        let release: (response: Response) => void = () => {};
        answer = () => new Promise<Response>((resolve) => (release = resolve));
        dialog();
        press(one('.admin-booking-move'));
        expect(one('.admin-move__loading')?.textContent).toBe(words.text('move.loading'));
        expect(one('#move-time')).toBeNull();
        await act(async () => release(day([])));
        await settle();
        expect(one('.admin-move__none')?.textContent).toBe(words.text('move.none'));

        view.unmount();
        view = mount();
        answer = () => new Response(JSON.stringify({ code: 'staff_not_eligible', message: 'staff_not_eligible', data: { status: 400, detail: 'Nikos does not do that.' } }), { status: 400 });
        await openMove();
        expect(one('.admin-move__error')?.textContent).toBe('Nikos does not do that.');

        view.unmount();
        view = mount();
        answer = () => new Response('<!DOCTYPE html>', { status: 200 });
        await openMove();
        expect(one('.admin-move__error')?.textContent).toBe(words.text('move.unreadable'));
    });

    it('holds Save until a time is chosen, saying why beside it, and Back returns to the detail moving nothing', async () => {
        await openMove();

        expect(save()?.disabled).toBe(true);
        expect(save()?.textContent).toBe(words.text('move.save'));
        const why = one('.admin-move__why');
        expect(why?.textContent).toBe(words.text('move.save_why'));
        expect(save()?.getAttribute('aria-describedby')).toBe(why?.id);

        choose('#move-time', FREE);
        expect(save()?.disabled).toBe(false);
        expect(one('.admin-move__why')).toBeNull();

        press(one('.admin-move__back'));
        expect(one('.admin-move__now')).toBeNull();
        expect(one('.admin-booking-facts')).not.toBeNull();
        expect(one('.admin-booking-move')).not.toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('opens the confirmation from Save with from and to and no warning on a free time, and posts the start with the customer told', async () => {
        await openMove();
        await toConfirmation(FREE);

        expect(one('.admin-move-confirm__from-to')?.textContent).toBe(words.text('move.confirm.from_to', { from: '04/11/2026 00:30', to: '04/11/2026 10:00' }));
        expect(one('.admin-move-confirm__warning')).toBeNull();
        expect(one('.admin-move-confirm__change')).toBeNull();

        press(one('.admin-move-confirm__confirm'));
        const { url, body } = lastPost();
        expect(url).toBe('/admin/bookings/b1/move');
        expect(body).toEqual({ start: FREE, warning_accepted: false, tell_customer: true });
    });

    it('warns per service on a crossing time and posts the warning accepted, the customer not told where the box was unticked', async () => {
        await openMove();
        await toConfirmation(BOTH);

        expect([...document.body.querySelectorAll('.admin-move-confirm__crossing')].map((line) => line.textContent)).toEqual([
            words.text('move.confirm.hours', { service: 'Haircut', staff: 'Elena Papadaki' }),
            words.text('move.confirm.closure', { service: 'Colour', staff: 'Elena Papadaki' }),
        ]);
        press(one('#move-tell'));
        press(one('.admin-move-confirm__confirm'));
        expect(lastPost().body).toEqual({ start: BOTH, warning_accepted: true, tell_customer: false });
    });

    it('names a member change in the confirmation and posts the changed segment alone', async () => {
        await openMove();
        choose('#move-staff-0', '3');
        await settle();
        await toConfirmation(FREE);

        expect([...document.body.querySelectorAll('.admin-move-confirm__change')].map((line) => line.textContent)).toEqual([
            words.text('move.confirm.member', { service: 'Haircut', from: 'Elena Papadaki', to: 'Nikos Georgiou' }),
        ]);
        press(one('.admin-move-confirm__confirm'));
        expect(lastPost().body).toEqual({ start: FREE, staff: { 0: 3 }, warning_accepted: false, tell_customer: true });
    });

    it('tells nobody where the customer was erased', async () => {
        await openMove({ booking: { ...BOOKING, customer_name: null } });
        await toConfirmation(FREE);

        expect(one('#move-tell')).toBeNull();
        press(one('.admin-move-confirm__confirm'));
        expect(lastPost().body).toEqual({ start: FREE, warning_accepted: false, tell_customer: false });
    });

    it('returns to the detail once the move lands', async () => {
        await openMove();
        await toConfirmation(FREE);
        press(one('.admin-move-confirm__confirm'));
        const { visit } = lastPost();

        act(() => visit.onStart?.());
        expect(one('.admin-move-confirm__confirm')?.getAttribute('aria-busy')).toBe('true');
        act(() => visit.onSuccess?.());
        act(() => visit.onFinish?.());

        expect(one('.admin-move-confirm')).toBeNull();
        expect(one('.admin-move__now')).toBeNull();
        expect(one('.admin-booking-facts')).not.toBeNull();
    });

    it('puts the warning the write names into the open confirmation where none was shown, and posts it accepted on the second confirm', async () => {
        await openMove();
        await toConfirmation(FREE);
        press(one('.admin-move-confirm__confirm'));
        refuse(409, 'unaccepted_warning', 'The time crosses something.', { crossings: [{ segment: 1, staff_id: 2, kind: 'closure' }] });

        expect(one('.admin-move-confirm')).not.toBeNull();
        expect([...document.body.querySelectorAll('.admin-move-confirm__crossing')].map((line) => line.textContent)).toEqual([
            words.text('move.confirm.closure', { service: 'Colour', staff: 'Elena Papadaki' }),
        ]);
        press(one('.admin-move-confirm__confirm'));
        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(lastPost().body).toEqual({ start: FREE, warning_accepted: true, tell_customer: true });
    });

    it.each(['overlap', 'unavailable'])('closes the confirmation on %s, shows the server sentence in the form and reads the times again', async (code) => {
        await openMove();
        await toConfirmation(FREE);
        press(one('.admin-move-confirm__confirm'));
        refuse(409, code, 'That time was just taken. Please pick another.', { segment: 0 });
        await settle();

        expect(one('.admin-move-confirm')).toBeNull();
        expect(one('.admin-move__refused')?.textContent).toBe('That time was just taken. Please pick another.');
        expect(fetched).toHaveBeenCalledTimes(2);
        expect(one('.admin-move__now')).not.toBeNull();
    });

    it('shows any other refusal in the server sentence as a failed action reads, and a transport failure in the dialog\'s own', async () => {
        await openMove();
        await toConfirmation(FREE);
        press(one('.admin-move-confirm__confirm'));
        refuse(409, 'not_reschedulable', 'This booking can no longer be moved.');

        expect(one('.admin-move-confirm')).toBeNull();
        expect(one('.admin-booking-dialog [role="alert"]')?.textContent).toBe('This booking can no longer be moved.');

        await toConfirmation(FREE);
        press(one('.admin-move-confirm__confirm'));
        const { visit } = lastPost();
        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new Event('exception', { cancelable: true })));
        act(() => visit.onFinish?.());
        expect(one('.admin-booking-dialog [role="alert"]')?.textContent).toBe(words.text('acting.error'));
        expect(shell.text('toaster.fallback')).not.toBe('');
    });

    it('leaves a refusal behind on Back, the detail showing no failure of the form', async () => {
        await openMove();
        await toConfirmation(FREE);
        press(one('.admin-move-confirm__confirm'));
        refuse(409, 'not_reschedulable', 'This booking can no longer be moved.');
        expect(one('.admin-booking-dialog [role="alert"]')?.textContent).toBe('This booking can no longer be moved.');

        press(one('.admin-move__back'));
        expect(one('.admin-booking-facts')).not.toBeNull();
        expect(one('.admin-booking-dialog [role="alert"]')).toBeNull();
    });
});
