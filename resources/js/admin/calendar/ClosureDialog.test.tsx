import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { drag, key, mount, type Mounted, press, settle } from '@/components/ui/harness';
import { formatInstant } from '@/lib/format';
import { ShellProvider, stringsOf } from '@/lib/strings';
import { keyedCalendar, keyedShell, WithBookings, WithCalendar } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import { ADMIN_LINES_EN } from '../lines.generated';
import { ClosureDialog } from './ClosureDialog';

/*
 * The Calendar's closure dialog over time that holds bookings: the add posted with no answer, the
 * server's list shown in place of the fields with one button per answer it offers and Back, each
 * answer posted as the same body with the rows shown, a changed list replacing the one shown, Back
 * returning to the fields as typed, a refusal of the answer kept in the list, any other refusal
 * toasted with the list kept for another try, one press posted once and the dialog held open
 * while it is in flight, and after a move the list of bookings to move, each opening the usual
 * Move form and staying movable where its move is refused. The post is the mocked router and
 * the Move form's read a mocked fetch; every word renders from the keyed sections, the English
 * table alone where the singular is in question.
 */

const shell = keyedShell();
const calendar = keyedCalendar();
const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };
const ELENA = 2;
const MEMBERS = [
    { id: ELENA, name: 'Elena Papadaki' },
    { id: 3, name: 'Nikos Georgiou' },
];

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

const item = (service: string, start: string, end: string, staff: string | null = 'Elena Papadaki') => ({ sort: 0, service_id: 1, service_name: service, staff_id: ELENA, staff_name: staff, start, end });

/** Ten in the morning in Athens on the fourth, a haircut with Elena. */
const ADA = { uuid: 'b1', status: 'confirmed', start: '2026-11-04T08:00:00+00:00', end: '2026-11-04T08:45:00+00:00', customer_name: 'Ada North', items: [item('Haircut', '2026-11-04T08:00:00+00:00', '2026-11-04T08:45:00+00:00')] };

/** Noon in Athens on the fourth, a colour awaiting approval. */
const BEA = { uuid: 'b2', status: 'awaiting_approval', start: '2026-11-04T10:00:00+00:00', end: '2026-11-04T11:00:00+00:00', customer_name: 'Bea South', items: [item('Colour', '2026-11-04T10:00:00+00:00', '2026-11-04T11:00:00+00:00')] };

const PLAIN = { date: '2026-11-04', until: null, start_time: null, end_time: null, reason: null };

const SEEN = [
    { uuid: 'b1', status: 'confirmed' },
    { uuid: 'b2', status: 'awaiting_approval' },
];

const FREE = '2026-11-05T08:00:00+00:00';

let view: Mounted;
let closed: number;
let fetched: ReturnType<typeof vi.fn>;

beforeEach(() => {
    view = mount();
    closed = 0;
    fetched = vi.fn(
        async () =>
            new Response(
                JSON.stringify({
                    date: '2026-11-04',
                    segments: [{ segment: 0, service_id: 1, service_name: 'Haircut', staff_id: ELENA, staff_name: 'Elena Papadaki', members: [MEMBERS[0]] }],
                    starts: [{ start: FREE, crossings: [] }],
                }),
                { status: 200 },
            ),
    );
    vi.stubGlobal('fetch', fetched);
    inertia.post.mockReset();
    inertia.listeners.clear();
});

afterEach(() => {
    view.unmount();
    vi.unstubAllGlobals();
});

function dialog(english = false): void {
    const node = (
        <WithCalendar>
            <WithBookings>
                <ToastProvider>
                    <ClosureDialog onClose={() => (closed += 1)} members={MEMBERS} clock={CLOCK} weekStart={1} granularity={5} prefill={{ date: '2026-11-04' }} staffShown />
                </ToastProvider>
            </WithBookings>
        </WithCalendar>
    );

    view.render(
        english ? (
            <ShellProvider strings={ADMIN_LINES_EN.shell} language="en">
                {node}
            </ShellProvider>
        ) : (
            node
        ),
    );
}

const one = <T extends Element = Element>(selector: string) => document.body.querySelector<T>(selector);

const all = (selector: string) => [...document.body.querySelectorAll(selector)];

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

/** The last post left with no answer at all, as the framework reports a transport failure. */
function unanswered(): void {
    const { visit } = lastPost();

    act(() => visit.onStart?.());
    act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: { exception: new Error('offline') } })));
    act(() => visit.onFinish?.());
}

/** The last post landed, as a redirect back does. */
function land(): void {
    const { visit } = lastPost();

    act(() => visit.onStart?.());
    act(() => visit.onSuccess?.());
    act(() => visit.onFinish?.());
}

/** The list the server answers an add over bookings with. */
function inside(bookings: unknown[] = [ADA, BEA], choices: string[] = ['keep', 'move', 'cancel'], detail = 'inside-token', changed = false): void {
    refuse(409, 'bookings_inside', detail, { count: bookings.length, changed, bookings, choices });
}

function type(selector: string, value: string): void {
    const input = document.body.querySelector<HTMLInputElement>(selector);

    act(() => {
        if (input !== null) {
            Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(input, value);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
}

function choose(selector: string, value: string): void {
    const select = document.body.querySelector<HTMLSelectElement>(selector);

    act(() => {
        if (select !== null) {
            Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')?.set?.call(select, value);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
}

/** The footer's buttons, each by its class's last part. */
const buttons = () => all('.admin-inside__choices button').map((button) => button.className.match(/admin-inside__([a-z]+)/)?.[1]);

/** Every toast's sentence, the oldest first. */
const toasted = () => all('.admin-toast p').map((sentence) => sentence.textContent);

/** Asks the dialog to close both ways it hears outside its buttons: Escape, and a press on the backdrop. */
function dismiss(): void {
    key(document.activeElement, 'Escape');
    drag(one('.admin-modal__backdrop'), one('.admin-modal__backdrop'));
}

describe('the closure dialog\'s fields', () => {
    it('lays its fields out by the dialog\'s own width and never the window\'s, Date and Until side by side where it has the room, Who, Reason and Closed all day across both columns', () => {
        dialog();
        press(one('#closure-all-day'));

        const grid = one('.admin-closure__fields');
        const widths = /^(sm|md|lg|xl|2xl):/;

        expect(all('.admin-closure *').flatMap((el) => [...el.classList].filter((name) => widths.test(name)))).toEqual([]);
        expect(grid?.parentElement?.classList.contains('@container')).toBe(true);
        expect([...(grid?.classList ?? [])].filter((name) => name.includes('grid-cols'))).toEqual(['@md:grid-cols-2']);
        expect([...(grid?.children ?? [])].map((child) => child.classList.contains('@md:col-span-2'))).toEqual([true, false, false, true, true, false, false]);
        expect([...(grid?.children ?? [])].map((child) => child.querySelector('input, select, button')?.id)).toEqual(['closure-who', 'closure-date', 'closure-until', 'closure-reason', 'closure-all-day', 'closure-start', 'closure-end']);
    });
});

describe('the closure dialog over bookings', () => {
    it('posts the add with no answer and, refused over bookings, shows the sentence and every row in place of the fields, with Back and the answers offered', () => {
        dialog();
        press(one('.admin-closure__add'));

        expect(lastPost().url).toBe('/admin/blackouts');
        expect(lastPost().body).toEqual(PLAIN);
        expect(Object.keys(lastPost().body)).not.toContain('choice');
        expect(Object.keys(lastPost().body)).not.toContain('seen');

        const frame = one('.admin-closure');
        inside();

        expect(one('.admin-closure')).toBe(frame);
        expect(frame).not.toBeNull();
        expect(one('.admin-closure__date')).toBeNull();
        expect(one('.admin-inside__detail')?.textContent).toBe('inside-token');
        expect(all('.admin-inside__row').map((row) => [row.querySelector('.admin-inside__when')?.textContent, row.querySelector('.admin-inside__customer')?.textContent, row.querySelector('.admin-inside__services')?.textContent, row.querySelector('.admin-inside__staff')?.textContent])).toEqual([
            [formatInstant(ADA.start, CLOCK), 'Ada North', 'Haircut', 'Elena Papadaki'],
            [formatInstant(BEA.start, CLOCK), 'Bea South', 'Colour', 'Elena Papadaki'],
        ]);
        expect(one('.admin-inside__what')?.textContent).toBe(shell.text('shared.closure.row_staff', { customer: 'Ada North', services: 'Haircut', staff: 'Elena Papadaki' }));
        expect(one('.admin-inside__list')?.className).toContain('overflow-y-auto');
        expect(buttons()).toEqual(['back', 'keep', 'move', 'cancel']);
        expect(all('.admin-inside__choices button').map((button) => button.textContent)).toEqual([shell.text('shared.closure.back'), shell.choice('shared.closure.keep', 2), shell.choice('shared.closure.move', 2), shell.choice('shared.closure.cancel', 2)]);
        expect(one('.admin-inside__final')?.textContent).toBe(shell.text('shared.closure.final'));
        expect(one('.admin-inside__reason-sent')).toBeNull();
        expect(one('.admin-toast')).toBeNull();
    });

    it('shows only the answers the server offers, and no word on undoing where cancelling is not one of them', () => {
        dialog();
        press(one('.admin-closure__add'));
        inside([ADA, BEA], ['keep']);

        expect(buttons()).toEqual(['back', 'keep']);
        expect(one('.admin-inside__final')).toBeNull();
    });

    it.each(['keep', 'move', 'cancel'])('posts %s as the same body again with the answer and each row shown by its id and status', (choice) => {
        dialog();
        press(one('.admin-closure__add'));
        inside();
        press(one(`.admin-inside__${choice}`));

        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(lastPost().url).toBe('/admin/blackouts');
        expect(lastPost().body).toEqual({ ...PLAIN, choice, seen: SEEN });
    });

    it('replaces the list, its answers and its sentence where the bookings changed meanwhile, and answers for the new list', () => {
        dialog();
        press(one('.admin-closure__add'));
        inside();
        press(one('.admin-inside__cancel'));
        inside([BEA], ['keep'], 'changed-token', true);

        expect(one('.admin-inside__detail')?.textContent).toBe('changed-token');
        expect(all('.admin-inside__row').map((row) => row.querySelector('.admin-inside__customer')?.textContent)).toEqual(['Bea South']);
        expect(buttons()).toEqual(['back', 'keep']);

        press(one('.admin-inside__keep'));
        expect(lastPost().body).toEqual({ ...PLAIN, choice: 'keep', seen: [SEEN[1]] });
    });

    it('returns to the fields as typed on Back and posts nothing', () => {
        dialog();
        // The all-day tick is the check field's box.
        expect(['size-5', 'accent-primary', 'focus-ring-field'].every((look) => one('.admin-closure__all-day')?.classList.contains(look))).toBe(true);
        type('.admin-closure__reason', 'Inspection');
        press(one('.admin-closure__all-day'));
        press(one('.admin-closure__add'));
        const sent = lastPost().body;
        inside();
        press(one('.admin-inside__back'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(one('.admin-inside')).toBeNull();
        expect(one<HTMLInputElement>('.admin-closure__reason')?.value).toBe('Inspection');
        expect(one<HTMLInputElement>('.admin-closure__all-day')?.checked).toBe(false);
        expect(one('.admin-closure__start')).not.toBeNull();

        press(one('.admin-closure__add'));
        expect(lastPost().body).toEqual(sent);
        expect(Object.keys(sent)).not.toContain('choice');
    });

    it('words the answers for one booking in the singular', () => {
        const english = stringsOf(ADMIN_LINES_EN.shell, 'en');
        dialog(true);
        press(one('.admin-closure__add'));
        inside([ADA]);

        expect(all('.admin-inside__choices button').slice(1).map((button) => button.textContent)).toEqual([english.choice('shared.closure.keep', 1), english.choice('shared.closure.move', 1), english.choice('shared.closure.cancel', 1)]);
        expect(english.choice('shared.closure.keep', 1)).not.toBe(english.choice('shared.closure.keep', 2));
        expect(english.choice('shared.closure.move', 1)).not.toBe(english.choice('shared.closure.move', 2));
        expect(english.choice('shared.closure.cancel', 1)).not.toBe(english.choice('shared.closure.cancel', 2));
    });

    it('says the email carries the reason where one was typed and cancelling is offered, and not otherwise', () => {
        dialog();
        type('.admin-closure__reason', 'Inspection');
        press(one('.admin-closure__add'));
        inside();
        expect(one('.admin-inside__reason-sent')?.textContent).toBe(shell.text('shared.closure.reason_sent'));

        inside([ADA, BEA], ['keep', 'move']);
        expect(one('.admin-inside__reason-sent')).toBeNull();

        press(one('.admin-inside__back'));
        type('.admin-closure__reason', '   ');
        press(one('.admin-closure__add'));
        inside();
        expect(one('.admin-inside__reason-sent')).toBeNull();
    });

    it('keeps a refusal of the answer itself in the list, and toasts a busy refusal as before', () => {
        dialog();
        press(one('.admin-closure__add'));
        inside();
        press(one('.admin-inside__cancel'));
        refuse(400, 'invalid_request', 'seen-token', { field: 'seen.0' });

        expect(one('.admin-inside__refused')?.textContent).toBe('seen-token');
        expect(all('.admin-inside__row')).toHaveLength(2);
        expect(one('.admin-toast')).toBeNull();

        press(one('.admin-inside__keep'));
        refuse(503, 'busy', 'busy-token');
        expect(one('.admin-inside__refused')).toBeNull();
        expect(one('.admin-toast')?.textContent).toContain('busy-token');
        expect(all('.admin-inside__row')).toHaveLength(2);
    });

    it('draws a row of an id and status alone by its status, and names nobody for a service with no named member', () => {
        dialog();
        press(one('.admin-closure__add'));
        inside([{ uuid: 'b3', status: 'confirmed' }, { ...ADA, items: [item('Haircut', ADA.start, ADA.end, null)] }]);

        const [bare, nameless] = all('.admin-inside__row');
        expect(bare?.querySelector('.admin-inside__status')).not.toBeNull();
        expect(bare?.querySelector('.admin-inside__when')).toBeNull();
        expect(bare?.querySelector('.admin-inside__customer')).toBeNull();
        expect(nameless?.querySelector('.admin-inside__customer')?.textContent).toBe('Ada North');
        expect(nameless?.querySelector('.admin-inside__staff')).toBeNull();
        expect(nameless?.querySelector('.admin-inside__what')?.textContent).toBe(shell.text('shared.closure.row', { customer: 'Ada North', services: 'Haircut' }));

        press(one('.admin-inside__keep'));
        expect(lastPost().body.seen).toEqual([
            { uuid: 'b3', status: 'confirmed' },
            { uuid: 'b1', status: 'confirmed' },
        ]);
    });

    it('writes a row in English as its customer, its services and its members with a comma between, and without the members where none is named', () => {
        const english = stringsOf(ADMIN_LINES_EN.shell, 'en');
        dialog(true);
        press(one('.admin-closure__add'));
        inside([ADA, { ...ADA, uuid: 'b4', items: [item('Haircut', ADA.start, ADA.end, null)] }]);

        expect(all('.admin-inside__what').map((line) => line.textContent)).toEqual(['Ada North, Haircut, Elena Papadaki', 'Ada North, Haircut']);
        expect(english.text('shared.closure.row_staff', { customer: 'Ada North', services: 'Haircut', staff: 'Elena Papadaki' })).toBe('Ada North, Haircut, Elena Papadaki');
    });

    it('keeps a refusal that names the answer in the list, as one that names a row', () => {
        dialog();
        press(one('.admin-closure__add'));
        inside();
        press(one('.admin-inside__move'));
        refuse(400, 'invalid_request', 'choice-token', { field: 'choice' });

        expect(one('.admin-inside__refused')?.textContent).toBe('choice-token');
        expect(all('.admin-inside__row')).toHaveLength(2);
        expect(buttons()).toEqual(['back', 'keep', 'move', 'cancel']);
        expect(toasted()).toEqual([]);
        expect(closed).toBe(0);
    });

    it('keeps the list for another try where the answer is forbidden or never arrives, and toasts each as the dialog says any refusal', () => {
        dialog();
        press(one('.admin-closure__add'));
        inside();
        press(one('.admin-inside__cancel'));
        refuse(403, 'forbidden', 'forbidden-token');

        expect(toasted()).toEqual(['forbidden-token']);
        expect(all('.admin-inside__row')).toHaveLength(2);
        expect(one('.admin-inside__refused')).toBeNull();

        press(one('.admin-inside__cancel'));
        unanswered();

        expect(toasted()).toEqual(['forbidden-token', calendar.text('closure.not_added')]);
        expect(all('.admin-inside__row')).toHaveLength(2);
        expect(closed).toBe(0);

        press(one('.admin-inside__cancel'));
        expect(inertia.post).toHaveBeenCalledTimes(4);
        expect(lastPost().body).toEqual({ ...PLAIN, choice: 'cancel', seen: SEEN });
    });

    it('posts the add and an answer once each however often they are pressed while in flight, and no other answer or Back meanwhile', () => {
        dialog();
        press(one('.admin-closure__add'));
        act(() => lastPost().visit.onStart?.());
        press(one('.admin-closure__add'));
        press(one('.admin-closure__cancel'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(closed).toBe(0);

        inside();
        press(one('.admin-inside__keep'));
        act(() => lastPost().visit.onStart?.());

        expect(one('.admin-inside__keep')?.getAttribute('aria-busy')).toBe('true');

        press(one('.admin-inside__keep'));
        press(one('.admin-inside__cancel'));
        press(one('.admin-inside__back'));

        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(all('.admin-inside__row')).toHaveLength(2);
    });

    it('stays open while the add or an answer is in flight, whatever asks it to close, and closes as before once nothing is', () => {
        dialog();
        expect(one<HTMLButtonElement>('.admin-modal__close')?.disabled).toBe(false);
        press(one('.admin-closure__add'));
        act(() => lastPost().visit.onStart?.());
        dismiss();

        expect(closed).toBe(0);
        expect(one<HTMLButtonElement>('.admin-modal__close')?.disabled).toBe(true);

        inside();
        expect(one<HTMLButtonElement>('.admin-modal__close')?.disabled).toBe(false);
        press(one('.admin-inside__keep'));
        const { visit } = lastPost();
        act(() => visit.onStart?.());
        dismiss();

        expect(closed).toBe(0);
        expect(all('.admin-inside__row')).toHaveLength(2);
        expect(one<HTMLButtonElement>('.admin-modal__close')?.disabled).toBe(true);

        act(() => visit.onFinish?.());
        expect(one<HTMLButtonElement>('.admin-modal__close')?.disabled).toBe(false);
        key(document.activeElement, 'Escape');

        expect(closed).toBe(1);
    });

    it('closes on a landed add or a landed keep', () => {
        dialog();
        press(one('.admin-closure__add'));
        land();
        expect(closed).toBe(1);

        dialog();
        press(one('.admin-closure__add'));
        inside();
        press(one('.admin-inside__keep'));
        land();
        expect(closed).toBe(2);
    });
});

describe('the bookings to move', () => {
    async function moveLanded(): Promise<void> {
        dialog();
        press(one('.admin-closure__add'));
        inside([ADA, { uuid: 'b3', status: 'confirmed' }]);
        press(one('.admin-inside__move'));
        land();
        await settle();
    }

    it('opens after Add and move them lands, a row each, Move on every row that can be moved', async () => {
        await moveLanded();

        expect(closed).toBe(0);
        expect(one('.admin-closure')).toBeNull();
        expect(one('.admin-to-move .admin-modal__title')?.textContent).toBe(shell.text('shared.closure.to_move'));
        expect(all('.admin-to-move__row').map((row) => [row.getAttribute('data-uuid'), row.querySelector('.admin-to-move__move')?.textContent ?? null])).toEqual([
            ['b1', shell.text('shared.closure.move_one')],
            ['b3', null],
        ]);
    });

    it('opens the usual Move form from a row\'s Move, ticks the row once the move lands, and closes on Done', async () => {
        await moveLanded();
        press(one('.admin-to-move__row[data-uuid="b1"] .admin-to-move__move'));
        await settle();

        expect(fetched).toHaveBeenCalledTimes(1);
        expect(String(fetched.mock.calls[0]?.[0])).toContain('/bookings/b1/move?date=2026-11-04');
        expect(one('#move-time')).not.toBeNull();
        expect(one('.admin-to-move__list')).toBeNull();

        choose('#move-time', FREE);
        press(one('.admin-move__save'));
        await settle();
        press(one('.admin-move-confirm__confirm'));
        expect(lastPost().url).toBe('/admin/bookings/b1/move');
        expect(lastPost().body).toMatchObject({ start: FREE });
        land();

        const moved = one('.admin-to-move__row[data-uuid="b1"]');
        expect(moved?.querySelector('.admin-to-move__move')).toBeNull();
        expect(moved?.querySelector('.admin-to-move__moved')?.textContent).toBe(shell.text('shared.closure.moved'));
        expect(moved?.querySelector('.admin-to-move__moved svg')).not.toBeNull();
        expect(closed).toBe(0);

        press(one('.admin-to-move__done'));
        expect(closed).toBe(1);
    });

    it('leaves a row movable where its move was refused, the refusal said over the form', async () => {
        await moveLanded();
        press(one('.admin-to-move__row[data-uuid="b1"] .admin-to-move__move'));
        await settle();
        choose('#move-time', FREE);
        press(one('.admin-move__save'));
        await settle();
        press(one('.admin-move-confirm__confirm'));
        refuse(403, 'forbidden', 'forbidden-token');

        expect(one('.admin-to-move .admin-notice--error')?.textContent).toBe('forbidden-token');
        expect(one('#move-time')).not.toBeNull();

        press(one('.admin-move__back'));

        const row = one('.admin-to-move__row[data-uuid="b1"]');
        expect(row?.querySelector('.admin-to-move__move')).not.toBeNull();
        expect(row?.querySelector('.admin-to-move__moved')).toBeNull();
        expect(closed).toBe(0);

        press(row?.querySelector('.admin-to-move__move') ?? null);
        await settle();
        expect(one('#move-time')).not.toBeNull();
    });

    it('returns to the list from the Move form\'s Back with nothing moved', async () => {
        await moveLanded();
        press(one('.admin-to-move__move'));
        await settle();
        press(one('.admin-move__back'));

        expect(one('.admin-to-move__row[data-uuid="b1"] .admin-to-move__move')).not.toBeNull();
        expect(one('.admin-to-move__moved')).toBeNull();
    });
});
