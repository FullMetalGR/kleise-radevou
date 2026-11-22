import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { formatAmount, formatCount } from '@/lib/format';
import { unitFigure } from '@/lib/names';
import { SUPPORT_ACTOR, supportNamed } from '@/lib/support';
import { keyedBookings, keyedShell, WithBookings } from '@/testing/keyed';
import { BookingDialog, CHANNEL_LABEL, NOTE_LABEL, SOURCE_LABEL } from './BookingDialog';
import { type Booking, CHANNEL_WORDS, CUSTOMER_ERASED, type TrailEntry } from './model';

/*
 * The booking dialog, read-only: the status in its head, the when panel and the contact facts,
 * what was booked as the shared table, the trail oldest first with every instant in the tenant's
 * zone, and the states a detail can fail into. Every word renders from the keyed bookings section
 * and is read through it.
 */

const shell = keyedShell();
const words = keyedBookings();
const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const BOOKING: Booking = {
    uuid: 'b1',
    status: 'rejected',
    customer_name: 'Ada North',
    customer_email: 'ada@example.test',
    customer_phone: '+30 210 7654321',
    total_minor: 3500,
    currency: 'EUR',
    rejection_reason: 'Fully booked that week',
    items: [{ sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena Papadaki', start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:45:00+00:00', price_minor: 3500 }],
};

const TRAIL: TrailEntry[] = [
    { action: 'held', actor: 'customer', kind: 'customer', name: null, at: '2026-10-01T08:00:00+00:00', facts: { status_to: 'awaiting_approval' } },
    { action: 'rejected', actor: 'user:4', kind: 'user', name: 'Alex Owner', at: '2026-10-02T08:00:00+00:00', facts: { hold_expires_at: '2026-10-03T08:00:00.000000+00:00' } },
];

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

/** The dialog under the keyed bookings section, as a screen provides it. */
const show = (node: ReactNode) => view.render(<WithBookings>{node}</WithBookings>);

const inDialog = (selector: string) => document.body.querySelector(`.admin-booking-dialog ${selector}`);

describe('the booking dialog', () => {
    it('titles itself with the customer and shows the facts, the items and the trail oldest first in Athens time', () => {
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: BOOKING, note: null, trail: TRAIL, actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

        expect(document.body.querySelector('.admin-modal__title')?.textContent).toBe('Ada North');
        expect(inDialog('.admin-booking-facts__email')?.textContent).toBe('ada@example.test');
        expect(inDialog('.admin-booking-facts__phone')?.textContent).toBe('+30 210 7654321');
        expect(inDialog('.admin-booking-facts__reason')?.textContent).toBe('Fully booked that week');
        expect(inDialog('.admin-booking-items__row')?.textContent).toContain('03/11/2026 10:00');
        expect(inDialog('#booking-items-head')?.textContent).toBe(words.text('dialog.items.title'));
        expect([...document.body.querySelectorAll('.admin-booking-items th')].map((head) => head.textContent)).toEqual(
            (['dialog.items.service', 'dialog.items.staff', 'dialog.items.start', 'dialog.items.end', 'dialog.items.price'] as const).map((key) => words.text(key)),
        );
        expect(inDialog('#booking-trail-head')?.textContent).toBe(words.text('dialog.history'));
        const entries = [...document.body.querySelectorAll('.admin-booking-trail__entry')].map((entry) => entry.textContent);
        expect(entries[0]).toContain('01/10/2026 11:00');
        expect(entries[0]).toContain(words.text('trail.actions.held'));
        expect(entries[0]).toContain(words.text('trail.actors.customer'));
        expect(entries[1]).toContain('Alex Owner');
        expect(entries[1]).toContain('03/10/2026 11:00');
        expect(inDialog('.admin-booking-close')).not.toBeNull();
    });

    it('heads itself with the customer as its title, the status chip and the round close, and keeps Close in the footer', () => {
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: BOOKING, note: null, trail: TRAIL, actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

        const head = inDialog('.admin-modal__head');

        expect(head?.querySelector('.admin-modal__title')?.textContent).toBe('Ada North');
        expect(head?.querySelector('.admin-booking-status .admin-chip')?.getAttribute('data-status')).toBe('rejected');
        expect(head?.querySelector('.admin-booking-status .admin-chip')?.textContent).toBe(shell.text('kit.statuses.rejected'));
        expect(head?.querySelector('.admin-booking-status .sr-only')?.textContent).toBe(words.text('dialog.facts.status'));
        expect(inDialog('.admin-modal__close')).not.toBeNull();
        expect(head?.querySelector('.admin-booking-close')).toBeNull();
        expect(inDialog('.admin-modal__footer .admin-booking-close')).not.toBeNull();
        expect(inDialog('.admin-modal__body .admin-chip')).toBeNull();
    });

    it('shows no status chip in the head while the detail is loading, has failed or names no booking', () => {
        show(<BookingDialog state={{ kind: 'loading' }} staffShown clock={CLOCK} onClose={() => {}} />);
        expect(inDialog('.admin-booking-status')).toBeNull();

        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'missing', uuid: 'x' } }} staffShown clock={CLOCK} onClose={() => {}} />);
        expect(inDialog('.admin-booking-status')).toBeNull();
    });

    it('lists what was booked as the shared table that turns its rows into blocks, every cell labelled by its column', () => {
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: BOOKING, note: null, trail: TRAIL, actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

        const table = inDialog('table.admin-booking-items');
        const heads = (['dialog.items.service', 'dialog.items.staff', 'dialog.items.start', 'dialog.items.end', 'dialog.items.price'] as const).map((key) => words.text(key));

        expect([...(table?.classList ?? [])]).toEqual(expect.arrayContaining(['admin-table', 'max-wide:block', '@max-2xl:block']));
        expect(table?.closest('.admin-table-scroll')).not.toBeNull();
        expect([...document.body.querySelectorAll('.admin-booking-items__row td .admin-table__label')].map((label) => label.textContent)).toEqual(heads);
        expect([...document.body.querySelectorAll('.admin-booking-items__row td .admin-table__value')].map((value) => value.textContent)).toEqual([
            'Haircut',
            'Elena Papadaki',
            '03/11/2026 10:00',
            '03/11/2026 10:45',
            formatAmount(3500, 'EUR'),
        ]);

        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: BOOKING, note: null, trail: TRAIL, actions: [] } }} staffShown={false} clock={CLOCK} onClose={() => {}} />);
        expect([...document.body.querySelectorAll('.admin-booking-items__row td .admin-table__label')].map((label) => label.textContent)).toEqual(heads.filter((_, at) => at !== 1));
    });

    it('opens with the when panel: the date under its weekday, the time from the first service\'s start to the last one\'s end with its length, and the total', () => {
        const two: Booking = {
            ...BOOKING,
            status: 'confirmed',
            total_minor: 6000,
            items: [
                { ...BOOKING.items[0]!, sort: 1, service_name: 'Colour', start: '2026-11-03T08:45:00+00:00', end: '2026-11-03T09:30:00+00:00', price_minor: 2500 },
                { ...BOOKING.items[0]!, sort: 0 },
            ],
        };
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: two, note: null, trail: [], actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

        const panel = inDialog('.admin-booking-facts');

        expect(panel?.tagName).toBe('SECTION');
        expect([...(panel?.classList ?? [])]).toEqual(expect.arrayContaining(['rounded-inner', 'ring-border']));
        expect(panel?.closest('.admin-modal__body')).not.toBeNull();
        expect(panel?.querySelector('.admin-booking-facts__weekday')?.textContent).toBe('Tuesday');
        expect(panel?.querySelector('.admin-booking-facts__date')?.textContent).toBe('03/11/2026');
        expect(panel?.querySelector('.admin-booking-facts__time')?.textContent).toBe(words.text('move.confirm.window', { start: '10:00', end: '11:30' }));
        expect(panel?.querySelector('.admin-booking-facts__length')?.textContent).toBe(unitFigure(90, 'minute', 'en'));
        expect(panel?.querySelector('.admin-booking-facts__total')?.textContent).toBe(formatAmount(6000, 'EUR'));
        expect(panel?.textContent).toContain(words.text('dialog.facts.total'));
        // Three blocks and nothing more: the booking carries no deadline, so no decide-by line.
        expect(panel?.children).toHaveLength(3);

        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: { ...two, items: [{ ...BOOKING.items[0]!, end: '2026-11-03T10:00:00+00:00' }] }, note: null, trail: [], actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);
        expect(inDialog('.admin-booking-facts__length')?.textContent).toBe(unitFigure(2, 'hour', 'en'));

        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: BOOKING, note: null, trail: [], actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);
        expect(inDialog('.admin-booking-facts__length')?.textContent).toBe(unitFigure(45, 'minute', 'en'));
    });

    it('renders a booking whose start or end is no instant: the when panel its total alone, with no day, time or length, and the items each time as it came', () => {
        const item = BOOKING.items[0]!;
        const panelOf = (items: Booking['items']) => {
            show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: { ...BOOKING, items }, note: null, trail: [], actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

            return {
                blocks: inDialog('.admin-booking-facts')?.children.length,
                timed: ['weekday', 'date', 'time', 'length'].filter((part) => inDialog(`.admin-booking-facts__${part}`) !== null),
                total: inDialog('.admin-booking-facts__total')?.textContent,
                times: [...document.body.querySelectorAll('.admin-booking-items__row td .admin-table__value')].slice(2, 4).map((value) => value.textContent),
            };
        };
        const alone = { blocks: 1, timed: [], total: formatAmount(3500, 'EUR') };

        // The space-separated form is no instant: the day cannot be read off it.
        expect(panelOf([{ ...item, start: '2026-11-03 08:00:00' }])).toEqual({ ...alone, times: ['2026-11-03 08:00:00', '03/11/2026 10:45'] });
        expect(panelOf([{ ...item, end: 'later' }])).toEqual({ ...alone, times: ['03/11/2026 10:00', 'later'] });
        // One service that cannot be read leaves the booking no range to show, wherever it sits.
        expect(panelOf([item, { ...item, sort: 1, start: 'later', end: 'later still' }])).toMatchObject(alone);
        expect(panelOf([item])).toMatchObject({ blocks: 3, timed: ['weekday', 'date', 'time', 'length'] });
    });

    it('strikes the time of a cancelled, rejected or no-show booking and of no other, and sets a request awaiting approval on the attention fill', () => {
        const looks = (status: string) => {
            show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: { ...BOOKING, status }, note: null, trail: [], actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);
            const time = [...(inDialog('.admin-booking-facts__time')?.classList ?? [])];
            const panel = [...(inDialog('.admin-booking-facts')?.classList ?? [])];

            return { struck: time.includes('line-through'), attention: panel.includes('bg-attention-soft') && panel.includes('ring-attention-line') };
        };

        expect(['cancelled', 'rejected', 'no_show'].map(looks)).toEqual(Array(3).fill({ struck: true, attention: false }));
        expect(['confirmed', 'completed', 'pending', 'expired'].map(looks)).toEqual(Array(4).fill({ struck: false, attention: false }));
        expect(looks('awaiting_approval')).toEqual({ struck: false, attention: true });
    });

    it('lays the trail on a rail, a dot beside each entry, oldest first', () => {
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: BOOKING, note: null, trail: TRAIL, actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

        const entries = [...document.body.querySelectorAll('ol.admin-booking-trail > li.admin-booking-trail__entry')];

        expect(entries).toHaveLength(2);
        expect(entries.map((entry) => entry.querySelector(':scope > .admin-booking-trail__dot')?.getAttribute('aria-hidden'))).toEqual(['true', 'true']);
        expect(entries.map((entry) => entry.querySelector('.admin-booking-trail__action')?.textContent)).toEqual([words.text('trail.actions.held'), words.text('trail.actions.rejected')]);
        expect([...(inDialog('.admin-booking-trail')?.classList ?? [])]).toContain('before:w-0.5');
    });

    it('shows no email and no phone where the payload carries neither, and says so when nothing has happened', () => {
        const { customer_email: _email, customer_phone: _phone, ...withheld } = BOOKING;
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: withheld, note: null, trail: [], actions: [] } }} staffShown={false} clock={CLOCK} onClose={() => {}} />);

        expect(inDialog('.admin-booking-facts__email')).toBeNull();
        expect(inDialog('.admin-booking-facts__phone')).toBeNull();
        expect(inDialog('.admin-booking-trail__none')?.textContent).toBe(words.text('dialog.no_history'));
    });

    it('titles a booking whose customer was erased "Customer erased" and shows no email and no phone, the rest of its facts as they stand', () => {
        const erased: Booking = { ...BOOKING, customer_name: null, customer_email: null, customer_phone: null };
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: erased, note: null, trail: TRAIL, actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

        expect(document.body.querySelector('.admin-modal__title')?.textContent).toBe(words.text(CUSTOMER_ERASED));
        expect(words.text(CUSTOMER_ERASED)).toBe('[customer_erased]');
        expect(inDialog('.admin-booking-facts__email')).toBeNull();
        expect(inDialog('.admin-booking-facts__phone')).toBeNull();
        expect(inDialog('.admin-booking-facts__reason')?.textContent).toBe('Fully booked that week');
        expect(inDialog('.admin-booking-items__row')?.textContent).toContain('Haircut');
        expect(document.body.querySelectorAll('.admin-booking-trail__entry')).toHaveLength(2);
    });

    it('shows where the booking came from and what the customer wrote, in words, where each is set', () => {
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: { ...BOOKING, source: 'hosted', channel: 'qr' }, note: 'Allergic to lavender.\nThe quiet room, please.', trail: [], actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

        const facts = Object.fromEntries([...document.body.querySelectorAll('.admin-booking-contact dt')].map((term) => [term.textContent ?? '', term.nextElementSibling?.textContent ?? '']));
        expect(facts[words.text(SOURCE_LABEL)]).toBe(shell.text('shared.sources.hosted'));
        expect(facts[words.text(CHANNEL_LABEL)]).toBe(shell.text('shared.channels.qr'));
        expect(facts[words.text(NOTE_LABEL)]).toBe('Allergic to lavender.\nThe quiet room, please.');
        expect(inDialog('.admin-booking-facts__note')?.className).toContain('whitespace-pre-line');
        expect(Object.keys(facts)).toEqual((['dialog.facts.email', 'dialog.facts.phone', SOURCE_LABEL, CHANNEL_LABEL, 'dialog.facts.reason', NOTE_LABEL] as const).map((key) => words.text(key)));
    });

    it('sets the contact facts in three columns where the dialog is wide and one below, the reason and the note across them', () => {
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: { ...BOOKING, source: 'hosted' }, note: 'Window seat.', trail: [], actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

        const list = inDialog('dl.admin-booking-contact');
        const across = (block: string) => [...(inDialog(`.admin-booking-facts__${block}`)?.parentElement?.classList ?? [])];

        expect([...(list?.classList ?? [])]).toEqual(expect.arrayContaining(['grid', 'grid-cols-1', '@xl:grid-cols-3']));
        expect(list?.closest('.\\@container')).not.toBeNull();
        expect(across('reason')).toContain('@xl:col-span-3');
        expect(across('note')).toContain('@xl:col-span-3');
        expect(across('email')).not.toContain('@xl:col-span-3');
        expect(inDialog('.admin-booking-facts .admin-booking-facts__email')).toBeNull();
    });

    it('names each source and channel in its words and an unknown one verbatim, and leaves out a channel and a note that are not set', () => {
        const shown = (booking: Booking, note: string | null) => {
            show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking, note, trail: [], actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

            return [inDialog('.admin-booking-facts__source')?.textContent ?? null, inDialog('.admin-booking-facts__channel')?.textContent ?? null, inDialog('.admin-booking-facts__note')?.textContent ?? null];
        };

        expect(shown({ ...BOOKING, source: 'embed', channel: null }, null)).toEqual([shell.text('shared.sources.embed'), null, null]);
        expect(shown({ ...BOOKING, source: 'admin', channel: null }, null)).toEqual([shell.text('shared.sources.admin'), null, null]);
        expect(shown({ ...BOOKING, source: 'kiosk', channel: 'tiktok' }, null)).toEqual(['kiosk', 'tiktok', null]);
        expect(Object.entries(CHANNEL_WORDS).map(([channel]) => shown({ ...BOOKING, source: 'hosted', channel }, null)[1])).toEqual(Object.values(CHANNEL_WORDS).map((key) => shell.text(key)));
        expect(Object.keys(CHANNEL_WORDS)).toEqual(['instagram', 'facebook', 'google', 'qr', 'other']);
        expect(shown(BOOKING, null)).toEqual([null, null, null]);
    });

    it('renders the not-found state and a load failure inside the dialog', () => {
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'missing', uuid: 'x' } }} staffShown clock={CLOCK} onClose={() => {}} />);
        expect(inDialog('.admin-booking-missing')?.getAttribute('role')).toBe('status');
        expect(inDialog('.admin-booking-missing')?.textContent).toBe(words.text('dialog.missing'));
        expect(inDialog('.admin-booking-back')?.textContent).toBe(words.text('dialog.back'));
        expect(document.body.querySelector('.admin-modal__title')?.textContent).toBe(words.text('dialog.title'));

        show(<BookingDialog state={{ kind: 'error' }} staffShown clock={CLOCK} onClose={() => {}} />);
        expect(inDialog('[role="alert"]')?.textContent).toBe(words.text('dialog.load_error'));

        show(<BookingDialog state={{ kind: 'loading' }} staffShown clock={CLOCK} onClose={() => {}} />);
        expect(inDialog('.admin-spinner')?.getAttribute('aria-label')).toBe(words.text('dialog.loading'));
        expect(inDialog('.admin-spinner')?.getAttribute('role')).toBe('status');
        expect([...(inDialog('.admin-spinner')?.classList ?? [])]).toEqual(expect.arrayContaining(['size-6', 'rounded-full', 'border-2']));
    });

    it('names the two rows a customer\'s page writes, the erasure and the export, in words', () => {
        const trail: TrailEntry[] = [
            { action: 'customer_exported', actor: 'user:4', kind: 'user', name: 'Alex Owner', at: '2026-10-02T08:00:00+00:00', facts: {} },
            { action: 'customer_erased', actor: 'user:4', kind: 'user', name: 'Alex Owner', at: '2026-10-02T09:00:00+00:00', facts: {} },
        ];
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: BOOKING, note: null, trail, actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

        const actions = [...document.body.querySelectorAll('.admin-booking-trail__entry strong')].map((action) => action.textContent);
        expect(actions).toEqual([words.text('trail.actions.customer_exported'), words.text('trail.actions.customer_erased')]);
    });

    it('names support acting in a session as Support and the operator in brackets, and as Support alone where the operator is gone', () => {
        const trail: TrailEntry[] = [
            { action: 'cancelled', actor: 'support:3', kind: 'support', name: 'Pat Operator', at: '2026-10-02T08:00:00+00:00', facts: {} },
            { action: 'approved', actor: 'support:4', kind: 'support', name: null, at: '2026-10-02T09:00:00+00:00', facts: {} },
        ];
        show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: BOOKING, note: null, trail, actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);

        const actors = [...document.body.querySelectorAll('.admin-booking-trail__actor')].map((actor) => actor.textContent);
        expect(actors).toEqual([shell.text('shared.support.named', { operator: 'Pat Operator' }), shell.text(SUPPORT_ACTOR)]);
        expect(actors[0]).toBe('[shared.support.named Pat Operator]');
        expect(supportNamed('Pat Operator', shell)).toBe(actors[0]);
    });

    it('renders each trail fact as what it carries: a status as its chip label, a hold class in words or verbatim, an instant on the tenant clock, the rest verbatim', () => {
        const facts = (entry: TrailEntry): Record<string, string> => {
            show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: BOOKING, note: null, trail: [entry], actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);
            const terms = [...document.body.querySelectorAll('.admin-booking-trail__facts dt')];

            return Object.fromEntries(terms.map((term) => [term.textContent ?? '', term.nextElementSibling?.textContent ?? '']));
        };
        const entry: TrailEntry = { action: 'approved', actor: 'user:4', kind: 'user', name: 'Alex Owner', at: '2026-10-02T08:00:00+00:00', facts: {} };

        expect(
            facts({
                ...entry,
                facts: { status_from: 'awaiting_approval', status_to: 'confirmed', hold_class: 'approval', hold_expires_at: '2026-10-03T08:00:00+00:00', item_count: 2, colour: '2026-10-03T08:00:00+00:00' },
            }),
        ).toEqual({
            [words.text('trail.facts.status_from')]: shell.text('kit.statuses.awaiting_approval'),
            [words.text('trail.facts.status_to')]: shell.text('kit.statuses.confirmed'),
            [words.text('trail.facts.hold_class')]: words.text('trail.holds.approval'),
            [words.text('trail.facts.hold_expires_at')]: '03/10/2026 11:00',
            [words.text('trail.facts.item_count')]: '2',
            colour: '2026-10-03T08:00:00+00:00',
        });
        expect(facts({ ...entry, facts: { hold_class: 'layaway', status_to: 'mystery' } })).toEqual({ [words.text('trail.facts.hold_class')]: 'layaway', [words.text('trail.facts.status_to')]: 'mystery' });
    });

    it('shows the amount a payment took as one line in the currency it was paid in, at that currency\'s scale, and the currency no line of its own', () => {
        const paid = (facts: Record<string, unknown>): Record<string, string> => {
            show(<BookingDialog state={{ kind: 'detail', detail: { state: 'found', booking: BOOKING, note: null, trail: [{ action: 'confirmed', actor: 'job:payment', kind: 'job', name: null, at: '2026-10-02T08:00:00+00:00', facts }], actions: [] } }} staffShown clock={CLOCK} onClose={() => {}} />);
            const terms = [...document.body.querySelectorAll('.admin-booking-trail__facts dt')];

            return Object.fromEntries(terms.map((term) => [term.textContent ?? '', term.nextElementSibling?.textContent ?? '']));
        };

        expect(paid({ status_from: 'pending', status_to: 'confirmed', paid_minor: 4500, paid_currency: 'EUR' })).toEqual({
            [words.text('trail.facts.status_from')]: shell.text('kit.statuses.pending'),
            [words.text('trail.facts.status_to')]: shell.text('kit.statuses.confirmed'),
            [words.text('trail.facts.paid')]: formatAmount(4500, 'EUR', words.locale),
        });
        expect(paid({ paid_minor: 5000, paid_currency: 'JPY' })).toEqual({ [words.text('trail.facts.paid')]: formatAmount(5000, 'JPY', words.locale) });
    });

    it('words a move by the business, one line each: each service given to another member by name, a whole line where a member is gone, each warning it overrode, and whether the customer was told', () => {
        show(
            <BookingDialog
                state={{
                    kind: 'detail',
                    detail: {
                        state: 'found',
                        booking: BOOKING,
                        note: null,
                        trail: [
                            {
                                action: 'rescheduled',
                                actor: 'user:4',
                                kind: 'user',
                                name: 'Alex Owner',
                                at: '2026-10-02T08:00:00+00:00',
                                facts: {
                                    staff_changed: [
                                        { segment: 0, from: 2, to: 3, from_name: 'Elena', to_name: 'Nikos' },
                                        { segment: 1, from: 2, to: 9, from_name: 'Elena', to_name: null },
                                        { segment: 2, from: 8, to: 3, from_name: null, to_name: 'Nikos' },
                                        { segment: 3, from: 8, to: 9, from_name: null, to_name: null },
                                        { from: 2, to: 3, from_name: 'Elena', to_name: 'Nikos' },
                                    ],
                                    overridden: [
                                        { segment: 0, kind: 'hours' },
                                        { segment: 1, kind: 'closure' },
                                        { kind: 'closure' },
                                    ],
                                    customer_told: false,
                                },
                            },
                        ],
                        actions: [],
                    },
                }}
                staffShown
                clock={CLOCK}
                onClose={() => {}}
            />,
        );
        const terms = [...document.body.querySelectorAll('.admin-booking-trail__facts dt')];
        const facts = Object.fromEntries(terms.map((term) => [term.textContent ?? '', term.nextElementSibling?.textContent ?? '']));
        const lines = Object.fromEntries(terms.map((term) => [term.textContent ?? '', [...(term.nextElementSibling?.querySelectorAll('.admin-booking-trail__line') ?? [])].map((line) => line.textContent)]));

        expect(lines).toEqual({
            [words.text('trail.facts.staff_changed')]: [
                words.text('trail.values.staff_changed', { position: formatCount(1, words.locale), from: 'Elena', to: 'Nikos' }),
                words.text('trail.values.staff_changed_to_removed', { position: formatCount(2, words.locale), from: 'Elena' }),
                words.text('trail.values.staff_changed_from_removed', { position: formatCount(3, words.locale), to: 'Nikos' }),
                words.text('trail.values.staff_changed_removed', { position: formatCount(4, words.locale) }),
            ],
            [words.text('trail.facts.overridden')]: [words.text('trail.values.hours', { position: formatCount(1, words.locale) }), words.text('trail.values.closure', { position: formatCount(2, words.locale) })],
            [words.text('trail.facts.customer_told')]: [],
        });
        expect(facts[words.text('trail.facts.customer_told')]).toBe(words.text('trail.values.no'));
        expect(document.body.querySelector('.admin-booking-trail__facts')?.textContent).not.toMatch(/[?#;]/);
    });
});
