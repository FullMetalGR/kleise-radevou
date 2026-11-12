import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { formatAmount, formatCount } from '@/lib/format';
import { SUPPORT_ACTOR, supportNamed } from '@/lib/support';
import { keyedBookings, keyedShell, WithBookings } from '@/testing/keyed';
import { BookingDialog, CHANNEL_LABEL, NOTE_LABEL, SOURCE_LABEL } from './BookingDialog';
import { type Booking, CHANNEL_WORDS, CUSTOMER_ERASED, type TrailEntry } from './model';

/*
 * The booking dialog, read-only: the facts, what was booked, the trail oldest first with every
 * instant in the tenant's zone, and the states a detail can fail into. Every word renders from
 * the keyed bookings section and is read through it.
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

        const facts = Object.fromEntries([...document.body.querySelectorAll('.admin-booking-facts dt')].map((term) => [term.textContent ?? '', term.nextElementSibling?.textContent ?? '']));
        expect(facts[words.text(SOURCE_LABEL)]).toBe(shell.text('shared.sources.hosted'));
        expect(facts[words.text(CHANNEL_LABEL)]).toBe(shell.text('shared.channels.qr'));
        expect(facts[words.text(NOTE_LABEL)]).toBe('Allergic to lavender.\nThe quiet room, please.');
        expect(inDialog('.admin-booking-facts__note')?.className).toContain('whitespace-pre-line');
        expect(Object.keys(facts)).toEqual(
            (['dialog.facts.status', 'dialog.facts.total', 'dialog.facts.email', 'dialog.facts.phone', 'dialog.facts.reason', SOURCE_LABEL, CHANNEL_LABEL, NOTE_LABEL] as const).map((key) => words.text(key)),
        );
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
