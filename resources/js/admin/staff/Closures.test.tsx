import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press, settle } from '@/components/ui/harness';
import type { TenantClock } from '@/lib/format';
import type { Failure } from '@/lib/visits';
import { keyedBookings, keyedShell, WithStaff } from '@/testing/keyed';
import { Closures } from './Closures';

/*
 * The Staff screen's closures card over time that holds bookings: the same list step as the
 * Calendar's dialog, shown in the card in place of its form. The add posts with no answer, the
 * list shows with the answers offered and Back, an answer posts the body again with the rows
 * shown, Back returns to the form as typed, a landed answer clears the card, any other refusal is
 * the screen's to say, and after a move the list of bookings to move opens over the page, each
 * row opening the usual Move form. One write is in flight at a time: the answers and Back wait
 * for a removal, and Delete for an answer. The Move form's read is a mocked fetch.
 */

const shell = keyedShell();
const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

type Visit = { onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    delete: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: inertia.post,
        delete: inertia.delete,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const ADA = {
    uuid: 'b1',
    status: 'confirmed',
    start: '2026-11-04T08:00:00+00:00',
    end: '2026-11-04T08:45:00+00:00',
    customer_name: 'Ada North',
    items: [{ sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena Papadaki', start: '2026-11-04T08:00:00+00:00', end: '2026-11-04T08:45:00+00:00' }],
};

const BEA = { uuid: 'b2', status: 'awaiting_payment' };

/** A closure the card already lists, the whole business's. */
const HOLIDAY = { date: '2026-12-24', until: null, start: null, end: null, reason: 'Holiday', staff_id: null };

/** A start the Move form's read offers. */
const FREE = '2026-11-05T08:00:00+00:00';

let view: Mounted;
let failures: Failure[];
let fetched: ReturnType<typeof vi.fn>;

beforeEach(() => {
    view = mount();
    failures = [];
    fetched = vi.fn(
        async () =>
            new Response(
                JSON.stringify({
                    date: '2026-11-04',
                    segments: [{ segment: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena Papadaki', members: [{ id: 2, name: 'Elena Papadaki' }] }],
                    starts: [{ start: FREE, crossings: [] }],
                }),
                { status: 200 },
            ),
    );
    vi.stubGlobal('fetch', fetched);
    inertia.post.mockReset();
    inertia.delete.mockReset();
    inertia.listeners.clear();
});

afterEach(() => {
    view.unmount();
    vi.unstubAllGlobals();
});

function card(blackouts: readonly (typeof HOLIDAY)[] = []): void {
    view.render(
        <WithStaff>
            <Closures
                pathOf={(staffId) => (staffId === null ? '/admin/blackouts' : `/admin/staff/${staffId}/blackouts`)}
                blackouts={blackouts}
                who={null}
                clock={CLOCK}
                weekStart={1}
                granularity={5}
                title="card-title-token"
                idPrefix="blackout"
                onFailure={(failure) => failures.push(failure)}
                staffShown
                bookings={keyedBookings()}
            />
        </WithStaff>,
    );
}

const one = <T extends Element = Element>(selector: string) => document.body.querySelector<T>(selector);

const all = (selector: string) => [...document.body.querySelectorAll(selector)];

/** Picks the tenant's today in the card's date field, whatever the clock says. */
function pickToday(): string | null {
    press(one('.admin-blackouts__date'));
    const today = one('button[data-date][aria-current="date"]');
    press(today);

    return today?.getAttribute('data-date') ?? null;
}

function type(selector: string, value: string): void {
    const input = one<HTMLInputElement>(selector);

    act(() => {
        if (input !== null) {
            Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(input, value);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
}

function lastPost(): { url: string; body: Record<string, unknown>; visit: Visit } {
    const call = inertia.post.mock.lastCall as [string, Record<string, unknown>, Visit] | undefined;

    if (call === undefined) {
        throw new Error('Nothing was posted.');
    }

    return { url: call[0], body: call[1], visit: call[2] };
}

function refuse(status: number, code: string, detail: string, data: Record<string, unknown> = {}): void {
    const { visit } = lastPost();
    const event = new CustomEvent('invalid', { cancelable: true, detail: { response: { status, data: { code, message: code, data: { status, detail, ...data } } } } });

    act(() => visit.onStart?.());
    act(() => inertia.listeners.get('invalid')?.(event));
    act(() => visit.onFinish?.());
}

/** The last post left with no answer at all, as the framework reports a transport failure. */
function unanswered(): void {
    const { visit } = lastPost();

    act(() => visit.onStart?.());
    act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: { exception: new Error('offline') } })));
    act(() => visit.onFinish?.());
}

function land(): void {
    const { visit } = lastPost();

    act(() => visit.onStart?.());
    act(() => visit.onSuccess?.());
    act(() => visit.onFinish?.());
}

/** The last removal's visit. */
function lastRemoval(): Visit {
    const call = inertia.delete.mock.lastCall as [string, Visit] | undefined;

    if (call === undefined) {
        throw new Error('Nothing was removed.');
    }

    return call[1];
}

function choose(selector: string, value: string): void {
    const select = one<HTMLSelectElement>(selector);

    act(() => {
        if (select !== null) {
            Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')?.set?.call(select, value);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
}

function inside(bookings: unknown[] = [ADA, BEA], choices: string[] = ['keep', 'move', 'cancel'], detail = 'inside-token', changed = false): void {
    refuse(409, 'bookings_inside', detail, { count: bookings.length, changed, bookings, choices });
}

/** Adds a closure of today with the reason given and answers it with the list. */
function addOver(reason = 'Inspection', blackouts: readonly (typeof HOLIDAY)[] = []): string | null {
    card(blackouts);
    const date = pickToday();
    type('.admin-blackouts__reason-input', reason);
    press(one('.admin-blackouts__add'));
    inside();

    return date;
}

const buttons = () => all('.admin-inside__choices button').map((button) => button.className.match(/admin-inside__([a-z]+)/)?.[1]);

/** Whether each of the step's buttons is held, in their order. */
const held = () => all('.admin-inside__choices button').map((button) => button instanceof HTMLButtonElement && button.disabled);

/** Opens the Move form of the first booking, picks the start offered and confirms the move. */
async function moveFirst(): Promise<void> {
    press(one('.admin-to-move__row[data-uuid="b1"] .admin-to-move__move'));
    await settle();
    choose('#move-time', FREE);
    press(one('.admin-move__save'));
    await settle();
    press(one('.admin-move-confirm__confirm'));
}

describe('the closures card over bookings', () => {
    it('shows the list in the card in place of its form, with the sentence, the rows, Back and the answers offered', () => {
        const date = addOver();

        expect(lastPost().body).toEqual({ date, until: null, start_time: null, end_time: null, reason: 'Inspection' });
        expect(one('.admin-blackouts__form')).toBeNull();
        expect(one('.admin-blackouts__inside .admin-inside__detail')?.textContent).toBe('inside-token');
        expect(all('.admin-inside__row').map((row) => row.querySelector('.admin-inside__customer')?.textContent ?? null)).toEqual(['Ada North', null]);
        expect(buttons()).toEqual(['back', 'keep', 'move', 'cancel']);
        expect(one('.admin-inside__cancel')?.textContent).toBe(shell.choice('shared.closure.cancel', 2));
        expect(one('.admin-inside__final')?.textContent).toBe(shell.text('shared.closure.final'));
        expect(one('.admin-inside__reason-sent')?.textContent).toBe(shell.text('shared.closure.reason_sent'));
        expect(failures).toEqual([]);
    });

    it('posts an answer as the same body with the answer and the rows shown, and replaces the list where it changed', () => {
        const date = addOver();
        press(one('.admin-inside__cancel'));

        expect(lastPost().body).toEqual({
            date,
            until: null,
            start_time: null,
            end_time: null,
            reason: 'Inspection',
            choice: 'cancel',
            seen: [
                { uuid: 'b1', status: 'confirmed' },
                { uuid: 'b2', status: 'awaiting_payment' },
            ],
        });

        inside([ADA], ['keep'], 'changed-token', true);
        expect(one('.admin-inside__detail')?.textContent).toBe('changed-token');
        expect(all('.admin-inside__row')).toHaveLength(1);
        expect(buttons()).toEqual(['back', 'keep']);
    });

    it('returns to the form as typed on Back and posts nothing', () => {
        const date = addOver();
        press(one('.admin-inside__back'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(one('.admin-inside')).toBeNull();
        expect(one<HTMLInputElement>('.admin-blackouts__reason-input')?.value).toBe('Inspection');

        press(one('.admin-blackouts__add'));
        expect(lastPost().body).toEqual({ date, until: null, start_time: null, end_time: null, reason: 'Inspection' });
    });

    it('clears the card once an answer lands, and hands any other refusal to the screen', () => {
        addOver();
        press(one('.admin-inside__keep'));
        refuse(503, 'busy', 'busy-token');

        expect(failures.map((failure) => failure.sentence)).toEqual(['busy-token']);
        expect(all('.admin-inside__row')).toHaveLength(2);

        press(one('.admin-inside__keep'));
        land();
        expect(one('.admin-inside')).toBeNull();
        expect(one('.admin-blackouts__form')).not.toBeNull();
        expect(one<HTMLInputElement>('.admin-blackouts__reason-input')?.value).toBe('');
    });

    it('opens the bookings to move once Add and move them lands, and closes it on Done', async () => {
        addOver();
        press(one('.admin-inside__move'));
        expect(lastPost().body.choice).toBe('move');
        land();
        await settle();

        expect(one('.admin-to-move .admin-modal__title')?.textContent).toBe(shell.text('shared.closure.to_move'));
        expect(all('.admin-to-move__row').map((row) => [row.getAttribute('data-uuid'), row.querySelector('.admin-to-move__move') !== null])).toEqual([
            ['b1', true],
            ['b2', false],
        ]);
        expect(one('.admin-blackouts__form')).not.toBeNull();

        press(one('.admin-to-move__done'));
        expect(one('.admin-to-move')).toBeNull();
    });

    it('writes a row as one line of the language: the customer, the services and the members', () => {
        addOver();

        expect(one('.admin-inside__what')?.textContent).toBe(shell.text('shared.closure.row_staff', { customer: 'Ada North', services: 'Haircut', staff: 'Elena Papadaki' }));
    });

    it('keeps a refusal of the answer itself in the list, one naming a row or one naming the answer, and hands the screen nothing', () => {
        addOver();
        press(one('.admin-inside__cancel'));
        refuse(400, 'invalid_request', 'seen-token', { field: 'seen.0' });

        expect(one('.admin-blackouts__inside .admin-inside__refused')?.textContent).toBe('seen-token');
        expect(all('.admin-inside__row')).toHaveLength(2);

        press(one('.admin-inside__keep'));
        refuse(400, 'invalid_request', 'choice-token', { field: 'choice' });

        expect(one('.admin-inside__refused')?.textContent).toBe('choice-token');
        expect(buttons()).toEqual(['back', 'keep', 'move', 'cancel']);
        expect(failures).toEqual([]);
    });

    it('keeps the list for another try where the answer is forbidden or never arrives, each handed to the screen', () => {
        addOver();
        press(one('.admin-inside__cancel'));
        refuse(403, 'forbidden', 'forbidden-token');
        press(one('.admin-inside__cancel'));
        unanswered();

        expect(failures.map((failure) => [failure.status, failure.sentence])).toEqual([
            [403, 'forbidden-token'],
            [null, null],
        ]);
        expect(all('.admin-inside__row')).toHaveLength(2);
        expect(one('.admin-inside__refused')).toBeNull();

        press(one('.admin-inside__cancel'));
        expect(inertia.post).toHaveBeenCalledTimes(4);
        expect(lastPost().body.choice).toBe('cancel');
    });

    it('posts an answer once however often it is pressed while it is in flight, and no other answer or Back meanwhile', () => {
        addOver();
        press(one('.admin-inside__keep'));
        act(() => lastPost().visit.onStart?.());

        expect(one('.admin-inside__keep')?.getAttribute('aria-busy')).toBe('true');

        press(one('.admin-inside__keep'));
        press(one('.admin-inside__cancel'));
        press(one('.admin-inside__back'));

        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(all('.admin-inside__row')).toHaveLength(2);
    });

    it('holds the answers and Back while a removal is in flight, and Delete while an answer is', () => {
        addOver('Inspection', [HOLIDAY]);
        press(one('.admin-blackouts__delete'));
        act(() => lastRemoval().onStart?.());

        expect(held()).toEqual([true, true, true, true]);

        press(one('.admin-inside__keep'));
        press(one('.admin-inside__back'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(all('.admin-inside__row')).toHaveLength(2);

        act(() => lastRemoval().onFinish?.());
        expect(held()).toEqual([false, false, false, false]);

        press(one('.admin-inside__keep'));
        act(() => lastPost().visit.onStart?.());

        expect(one<HTMLButtonElement>('.admin-blackouts__delete')?.disabled).toBe(true);

        press(one('.admin-blackouts__delete'));
        expect(inertia.delete).toHaveBeenCalledTimes(1);
    });

    it('opens the usual Move form from a row\'s Move, leaves the row movable where the move is refused, and ticks it once the move lands', async () => {
        addOver();
        press(one('.admin-inside__move'));
        land();
        await settle();
        await moveFirst();

        expect(String(fetched.mock.calls[0]?.[0])).toContain('/bookings/b1/move?date=2026-11-04');
        expect(lastPost().url).toBe('/admin/bookings/b1/move');
        expect(lastPost().body).toMatchObject({ start: FREE });

        refuse(403, 'forbidden', 'forbidden-token');
        expect(one('.admin-to-move .admin-notice--error')?.textContent).toBe('forbidden-token');
        expect(failures).toEqual([]);

        press(one('.admin-move__back'));
        expect(one('.admin-to-move__row[data-uuid="b1"] .admin-to-move__move')).not.toBeNull();
        expect(one('.admin-to-move__moved')).toBeNull();

        await moveFirst();
        land();

        const moved = one('.admin-to-move__row[data-uuid="b1"]');
        expect(moved?.querySelector('.admin-to-move__move')).toBeNull();
        expect(moved?.querySelector('.admin-to-move__moved')?.textContent).toBe(shell.text('shared.closure.moved'));

        press(one('.admin-to-move__done'));
        expect(one('.admin-to-move')).toBeNull();
    });
});
