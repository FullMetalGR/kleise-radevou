import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { drag, key, mount, type Mounted, press, settle } from '@/components/ui/harness';
import type { Act } from './ActionBar';
import { BookingDialog } from './BookingDialog';
import type { Booking } from './model';
import { keyedBookings, WithBookings } from '@/testing/keyed';

/*
 * The booking dialog's actions: the bar renders exactly the server's list and no bar at all for
 * an empty one, the two confirms reset and dismiss, each lands focus on a control that is not
 * destructive, one Escape peels one layer, and an overlay whose confirm is in flight cannot be
 * dismissed until the server's answer lands. Every word renders from the keyed bookings section
 * and is read through it.
 */

const words = keyedBookings();

const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const BOOKING: Booking = {
    uuid: 'b1',
    status: 'awaiting_approval',
    customer_name: 'Ada North',
    total_minor: 3500,
    currency: 'EUR',
    items: [{ sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena Papadaki', start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:45:00+00:00', price_minor: 3500 }],
};

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

type Call = { action: string; body: Record<string, string>; done: () => void };

function recorder(): { calls: Call[]; onAct: Act } {
    const calls: Call[] = [];

    return { calls, onAct: (action, body, done) => calls.push({ action, body, done }) };
}

function dialog(actions: string[], onAct: Act, options: { busy?: string | null; onClose?: () => void } = {}) {
    view.render(
        <WithBookings>
            <BookingDialog
                state={{ kind: 'detail', detail: { state: 'found', booking: BOOKING, note: null, trail: [], actions } }}
                staffShown
                clock={CLOCK}
                onClose={options.onClose ?? (() => {})}
                busy={options.busy ?? null}
                onAct={onAct}
            />
        </WithBookings>,
    );
}

const one = (selector: string) => document.body.querySelector(selector);

const buttons = () => [...document.body.querySelectorAll('.admin-booking-actions > .admin-booking-action')].map((button) => button.textContent);

function typeReason(text: string): void {
    const box = one('.admin-reject-prompt__reason');

    if (!(box instanceof HTMLTextAreaElement)) {
        throw new Error('No reason box.');
    }

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set?.call(box, text);
        box.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

describe('the action bar', () => {
    it('renders exactly the list the server sent, in its order, and no bar for an empty one while Close stays', () => {
        const { onAct } = recorder();

        dialog([], onAct);
        expect(one('.admin-booking-actions')).toBeNull();
        expect(one('.admin-booking-close')?.textContent).toBe(words.text('dialog.close'));

        dialog(['approve', 'reject'], onAct);
        expect(buttons()).toEqual([words.text('actions.approve'), words.text('actions.reject')]);

        dialog(['cancel'], onAct);
        expect(buttons()).toEqual([words.text('actions.cancel')]);

        dialog(['cancel', 'complete', 'no-show'], onAct);
        expect(buttons()).toEqual([words.text('actions.cancel'), words.text('actions.complete'), words.text('actions.no_show')]);

        // A name the client does not know is still the server's decision, rendered verbatim.
        dialog(['refund'], onAct);
        expect(buttons()).toEqual(['refund']);
        expect(one('.admin-booking-close')?.textContent).toBe(words.text('dialog.close'));
    });

    it('fires Approve, Completed and No-show at once with no body', () => {
        const { calls, onAct } = recorder();

        dialog(['approve', 'complete', 'no-show'], onAct);
        press(one('.admin-booking-action--approve'));
        press(one('.admin-booking-action--complete'));
        press(one('.admin-booking-action--no-show'));

        expect(calls.map((call) => [call.action, call.body])).toEqual([
            ['approve', {}],
            ['complete', {}],
            ['no-show', {}],
        ]);
    });

    it('shows the pressed action busy, its label the reason in text', () => {
        const { onAct } = recorder();

        dialog(['approve', 'reject'], onAct, { busy: 'approve' });

        expect(one('.admin-booking-action--approve')?.getAttribute('aria-busy')).toBe('true');
        expect(one('.admin-booking-action--approve')?.textContent).toBe(words.text('busy.approve'));
        expect(one('.admin-booking-action--reject')?.hasAttribute('aria-busy')).toBe(false);
    });
});

describe('the reject overlay', () => {
    it('opens with the reason box focused, fires the reason typed, and opens empty the next time', async () => {
        const { calls, onAct } = recorder();

        dialog(['approve', 'reject'], onAct);
        press(one('.admin-booking-action--reject'));
        await settle();

        expect(document.activeElement).toBe(one('.admin-reject-prompt__reason'));

        typeReason('Fully booked that week');
        press(one('.admin-reject-prompt__confirm'));

        expect(calls.map((call) => [call.action, call.body])).toEqual([['reject', { reason: 'Fully booked that week' }]]);

        act(() => calls[0]?.done());
        expect(one('.admin-reject-prompt')).toBeNull();

        press(one('.admin-booking-action--reject'));
        expect((one('.admin-reject-prompt__reason') as HTMLTextAreaElement).value).toBe('');
    });

    it('fires with the empty string when confirmed with nothing typed', () => {
        const { calls, onAct } = recorder();

        dialog(['reject'], onAct);
        press(one('.admin-booking-action--reject'));
        press(one('.admin-reject-prompt__confirm'));

        expect(calls.map((call) => [call.action, call.body])).toEqual([['reject', { reason: '' }]]);
    });

    it('fires nothing on Keep it or on Escape, and a reason typed before either does not ride into the next opening', () => {
        const { calls, onAct } = recorder();

        dialog(['reject'], onAct);
        press(one('.admin-booking-action--reject'));
        typeReason('Not this one');
        press(one('.admin-reject-prompt__keep'));
        expect(one('.admin-reject-prompt')).toBeNull();

        press(one('.admin-booking-action--reject'));
        expect((one('.admin-reject-prompt__reason') as HTMLTextAreaElement).value).toBe('');
        typeReason('Nor this');
        key(one('.admin-reject-prompt__reason'), 'Escape');
        expect(one('.admin-reject-prompt')).toBeNull();

        expect(calls).toEqual([]);
    });

    it('peels one layer per Escape: the overlay first, the dialog under it on the second', () => {
        const { onAct } = recorder();
        const onClose = vi.fn();

        dialog(['approve', 'reject'], onAct, { onClose });
        press(one('.admin-booking-action--reject'));

        key(one('.admin-reject-prompt__reason'), 'Escape');
        expect(one('.admin-reject-prompt')).toBeNull();
        expect(one('.admin-booking-dialog')).not.toBeNull();
        expect(onClose).not.toHaveBeenCalled();

        key(one('.admin-booking-close'), 'Escape');
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});

describe('the cancel confirm', () => {
    it('says it cannot be undone, lands focus on Keep it, fires nothing when kept and cancels when confirmed', async () => {
        const { calls, onAct } = recorder();

        dialog(['cancel'], onAct);
        press(one('.admin-booking-action--cancel'));
        await settle();

        expect(one('.admin-cancel-prompt__warning')?.textContent).toBe(words.text('cancel.warning'));
        expect(document.activeElement).toBe(one('.admin-cancel-prompt__keep'));

        press(one('.admin-cancel-prompt__keep'));
        expect(one('.admin-cancel-prompt')).toBeNull();
        expect(calls).toEqual([]);

        press(one('.admin-booking-action--cancel'));
        press(one('.admin-cancel-prompt__confirm'));
        expect(calls.map((call) => [call.action, call.body])).toEqual([['cancel', {}]]);
    });
});

describe('an overlay whose confirm is in flight', () => {
    it.each([
        ['reject', '.admin-reject-prompt', words.text('reject.pending')],
        ['cancel', '.admin-cancel-prompt', words.text('cancel.pending')],
    ])('refuses Keep it, Escape and the backdrop while %s is busy, Keep it saying why, and closes once the answer lands', (action, prompt, why) => {
        const { calls, onAct } = recorder();

        dialog([action], onAct);
        press(one(`.admin-booking-action--${action}`));
        press(one(`${prompt}__confirm`));
        expect(calls.map((call) => call.action)).toEqual([action]);

        // The screen marks the act busy as its visit starts, and holds it until the answer lands.
        dialog([action], onAct, { busy: action });
        expect(one(`${prompt}__confirm`)?.getAttribute('aria-busy')).toBe('true');

        press(one(`${prompt}__keep`));
        expect(one(prompt)).not.toBeNull();

        key(one(prompt), 'Escape');
        expect(one(prompt)).not.toBeNull();

        const backdrop = [...document.querySelectorAll('.admin-modal__backdrop')].at(-1) ?? null;
        drag(backdrop, backdrop);
        expect(one(prompt)).not.toBeNull();
        expect(one('.admin-booking-dialog')).not.toBeNull();

        // Keep it is disabled, and says why in text beside it.
        const keep = one(`${prompt}__keep`);
        const reason = document.getElementById(keep?.getAttribute('aria-describedby') ?? '');
        expect(keep?.hasAttribute('disabled')).toBe(true);
        expect(reason?.textContent).toContain(why);
        expect(one(prompt)?.contains(reason ?? null)).toBe(true);

        // The answer lands: the screen clears busy and hands back the visit's end.
        dialog([action], onAct, { busy: null });
        act(() => calls[0]?.done());
        expect(one(prompt)).toBeNull();
        expect(calls).toHaveLength(1);
    });

    it('offers Keep it again, live and with no reason attached, once nothing is in flight', () => {
        const { calls, onAct } = recorder();

        dialog(['reject'], onAct);
        press(one('.admin-booking-action--reject'));

        const keep = one('.admin-reject-prompt__keep');
        expect(keep?.hasAttribute('disabled')).toBe(false);
        expect(keep?.hasAttribute('aria-describedby')).toBe(false);
        expect(one('.admin-reject-prompt__pending')).toBeNull();

        press(keep);
        expect(one('.admin-reject-prompt')).toBeNull();
        expect(calls).toEqual([]);
    });
});
