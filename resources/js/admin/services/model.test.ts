import { describe, expect, it } from 'vitest';
import { keyedServices } from '@/testing/keyed';
import { blankForm, canSave, createLinks, foldNumber, formOf, HORIZON_MAX_DAYS, inactiveCount, linkedMembers, linkWrites, type Member, minorOf, optionsIn, PAYMENT_MODES, paymentLabel, patchOf, type Service, subLine, visibleRows } from './model';

/*
 * The Services screen's folding and sending rules: numeric fields fold unreadable input to zero,
 * the price accepts a comma decimal separator and folds by the currency's scale, creating sends
 * the currency and editing never does, and a changed checklist sends one replace-all list per
 * changed member carrying that member's other services. A word is read through the keyed
 * services section, never as a sentence.
 */

const words = keyedServices();

const HAIRCUT: Service = {
    id: 7, name: 'Haircut', description: 'Wash and cut.', duration_min: 45, processing_gap_min: 0, buffer_before_min: 0, buffer_after_min: 10,
    price_minor: 3500, currency: 'EUR', payment_mode: 'onsite', requires_approval: false, approval_hold_hours: null, on_approval_timeout: 'expire',
    cancel_window_hours: 24, reschedule_window_hours: 24, lead_time_min: 0, horizon_days: 60, status: 'active', category: null, online: true,
};

const ROSTER: Member[] = [
    { id: 1, name: 'Elena', active: true, service_ids: [7, 9], windows: [] },
    { id: 2, name: 'Nikos', active: true, service_ids: [9], windows: [] },
    { id: 3, name: 'Maria', active: false, service_ids: [7], windows: [] },
];

describe('folding', () => {
    it('folds unreadable numeric input to zero and a fraction to its whole part', () => {
        expect(foldNumber('abc')).toBe(0);
        expect(foldNumber('')).toBe(0);
        expect(foldNumber(' 45 ')).toBe(45);
        expect(foldNumber('12.9')).toBe(12);
        expect(foldNumber('-5')).toBe(-5);
    });

    it('reads a price as a person writes it, comma or point, by the currency\'s scale', () => {
        expect(minorOf('12,50', 'EUR')).toBe(1250);
        expect(minorOf('12.50', 'EUR')).toBe(1250);
        expect(minorOf('12', 'EUR')).toBe(1200);
        expect(minorOf('12,5', 'EUR')).toBe(1250);
        expect(minorOf('12,505', 'EUR')).toBe(1250);
        expect(minorOf('1500', 'JPY')).toBe(1500);
        expect(minorOf('1.5', 'JPY')).toBe(1);
        expect(minorOf('1,250', 'KWD')).toBe(1250);
        expect(minorOf('abc', 'EUR')).toBe(0);
        expect(minorOf('', 'EUR')).toBe(0);
        expect(minorOf('-3', 'EUR')).toBe(0);
    });

    it('shows a row\'s price in its own currency and scale, and gates Save on the trimmed name alone', () => {
        expect(formOf(HAIRCUT).price).toBe('35.00');
        expect(formOf({ ...HAIRCUT, currency: 'JPY', price_minor: 5000 }).price).toBe('5000');
        expect(canSave({ name: '   ' })).toBe(false);
        expect(canSave({ name: ' a ' })).toBe(true);
        expect(blankForm(15).duration_min).toBe('15');
    });
});

describe('the patch', () => {
    it('sends the currency when creating and the status when editing, never both, with every number folded', () => {
        const form = { ...formOf(HAIRCUT), name: ' Cut ', price: '12,50', processing_gap_min: 'abc', approval_hold_hours: '48' };

        expect(patchOf(form, 'EUR', true)).toEqual({
            name: 'Cut', description: 'Wash and cut.', duration_min: 45, processing_gap_min: 0, buffer_before_min: 0, buffer_after_min: 10,
            price_minor: 1250, payment_mode: 'onsite', requires_approval: false, approval_hold_hours: null, on_approval_timeout: 'expire',
            cancel_window_hours: 24, reschedule_window_hours: 24, lead_time_min: 0, horizon_days: 60, category: null, online: true, currency: 'EUR',
        });

        const edited = patchOf({ ...form, requires_approval: true, status: 'inactive' }, 'EUR', false);

        expect(edited).not.toHaveProperty('currency');
        expect(edited).toMatchObject({ status: 'inactive', approval_hold_hours: 48, requires_approval: true });
        expect(patchOf({ ...form, requires_approval: true, approval_hold_hours: '' }, 'EUR', false).approval_hold_hours).toBeNull();
    });

    it('sends the category trimmed and an empty one as null, and the online flag as ticked', () => {
        expect(formOf({ ...HAIRCUT, category: 'Hair', online: false })).toMatchObject({ category: 'Hair', online: false });
        expect(blankForm(15)).toMatchObject({ category: '', online: true });
        expect(patchOf({ ...formOf(HAIRCUT), category: '  Hair  ', online: false }, 'EUR', false)).toMatchObject({ category: 'Hair', online: false });
        expect(patchOf({ ...formOf(HAIRCUT), category: '   ' }, 'EUR', true)).toMatchObject({ category: null, online: true });
    });
});

describe('the staff links', () => {
    it('reads the ticks from the roster and sends one replace-all list per changed member, the other services kept, none when untouched', () => {
        expect(linkedMembers(ROSTER, 7)).toEqual([1, 3]);
        expect(linkWrites([1, 3], ROSTER, 7)).toEqual([]);
        expect(linkWrites([3, 1], ROSTER, 7)).toEqual([]);
        expect(linkWrites([1, 2], ROSTER, 7)).toEqual([
            { staffId: 2, name: 'Nikos', service_ids: [9, 7] },
            { staffId: 3, name: 'Maria', service_ids: [] },
        ]);
        expect(linkWrites([2], ROSTER, 42)).toEqual([{ staffId: 2, name: 'Nikos', service_ids: [9, 42] }]);
    });

    it('starts a new service linked to the roster\'s one row, the implicit member the server links it to, and to nobody on a longer roster', () => {
        const alone: Member[] = [{ id: 1, name: 'Luna Studio', active: true, service_ids: [7], windows: [] }];
        const landed: Member[] = [{ id: 1, name: 'Luna Studio', active: true, service_ids: [7, 42], windows: [] }];

        expect(createLinks(alone)).toEqual([1]);
        expect(createLinks(ROSTER)).toEqual([]);
        expect(createLinks([])).toEqual([]);
        // Untouched, the staged tick matches the link the server wrote, so nothing is sent;
        // unticked, the owner's word is.
        expect(linkWrites(createLinks(alone), landed, 42)).toEqual([]);
        expect(linkWrites([], landed, 42)).toEqual([{ staffId: 1, name: 'Luna Studio', service_ids: [7] }]);
    });
});

describe('the booking horizon', () => {
    it('reaches at most 365 days, the column\'s CHECK and the write\'s refusal', () => {
        expect(HORIZON_MAX_DAYS).toBe(365);
    });
});

describe('the catalog', () => {
    it('spells the sub-line as the duration then the gap when non-zero, and hides inactive rows until asked', () => {
        expect(subLine(HAIRCUT, words)).toBe(words.text('table.minutes', { n: '45' }));
        expect(subLine({ duration_min: 60, processing_gap_min: 30 }, words)).toBe(words.choice('table.minutes_gap', 30, { n: '60' }));
        expect(subLine({ duration_min: 60, processing_gap_min: 1 }, words)).toBe(words.choice('table.minutes_gap', 1, { n: '60' }));

        const rows = [HAIRCUT, { ...HAIRCUT, id: 8, name: 'Perm', status: 'inactive' }];

        expect(inactiveCount(rows)).toBe(1);
        expect(visibleRows(rows, false).map((row) => row.name)).toEqual(['Haircut']);
        expect(visibleRows(rows, true).map((row) => row.name)).toEqual(['Haircut', 'Perm']);
    });

    it('names a payment mode and a select\'s options by their keys in the page\'s words, a mode the form does not offer as sent', () => {
        expect(paymentLabel('onsite', words)).toBe(words.text('modes.onsite'));
        expect(paymentLabel('cheque', words)).toBe('cheque');
        expect(optionsIn(PAYMENT_MODES, words)).toEqual([
            { value: 'free', label: words.text('modes.free') },
            { value: 'online', label: words.text('modes.online') },
            { value: 'onsite', label: words.text('modes.onsite') },
        ]);
    });
});
