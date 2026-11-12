import { describe, expect, it } from 'vitest';
import type { TenantClock } from '@/lib/format';
import { ADMIN_LINES_EN } from '../lines.generated';
import { choose } from '../../booking/choose';
import { keyedWizard } from '@/testing/keyed';
import { ANY_STAFF, addSegment, advance, availabilityQuery, bodyOf, type Catalog, chooseDay, chooseService, chooseSlot, chooseStaff, courtesy, customerAnswered, editCustomer, endOf, gateOpen, groupsOf, initial, itemsOf, NO_ANSWER, performersOf, periodKey, removeSegment, reopen, serviceLines, staffChoiceReal, stripValues, toggleSameStaff } from './model';

/*
 * The wizard's transitions and rules, over luna's catalog: Haircut 45 minutes on both
 * members, Colour 60 with a 30 minute tail on both, Beard trim 20 on Nikos. luna is in
 * Europe/Athens, UTC+2 in November. A worded value reads through the keyed wizard group,
 * never a sentence.
 */

const words = keyedWizard();

const CATALOG: Catalog = {
    services: [
        { id: 1, name: 'Haircut', duration_min: 45, processing_min: 0, staff: [2, 3] },
        { id: 2, name: 'Beard trim', duration_min: 20, processing_min: 0, staff: [3] },
        { id: 3, name: 'Colour', duration_min: 60, processing_min: 30, staff: [2, 3] },
    ],
    staff: [
        { id: 2, name: 'Elena Papadaki' },
        { id: 3, name: 'Nikos Georgiou' },
    ],
    members: 3,
};

const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const TODAY = '2026-11-02';

const TEN = '2026-11-03T08:00:00+00:00';

function answered() {
    let state = chooseService(courtesy(initial(TODAY), CATALOG), 0, 1, CATALOG);
    state = chooseService(addSegment(state), 1, 3, CATALOG);
    state = advance(state);
    state = chooseSlot(chooseDay(state, '2026-11-03'), TEN);

    return advance(state);
}

describe('the courtesy and the segments', () => {
    it('fills the first segment with the first active service while unanswered and leaves a chosen one alone', () => {
        const filled = courtesy(initial(TODAY), CATALOG);
        const chosen = chooseService(filled, 0, 3, CATALOG);

        expect(filled.segments).toEqual([{ service: 1, staff: null }]);
        expect(courtesy(chosen, CATALOG).segments).toEqual([{ service: 3, staff: null }]);
        expect(courtesy(initial(TODAY), { ...CATALOG, services: [] }).segments).toEqual([{ service: null, staff: null }]);
    });

    it('opens with the prefill, keeps a pin the service admits and drops one it does not', () => {
        const pinned = initial(TODAY, { date: '2026-11-10', staffId: 3 });

        expect(pinned.day).toBe('2026-11-10');
        expect(courtesy(pinned, CATALOG).segments[0]).toEqual({ service: 1, staff: 3 });
        expect(chooseService(courtesy(pinned, CATALOG), 0, 2, CATALOG).segments[0]).toEqual({ service: 2, staff: 3 });
        expect(chooseService(courtesy(initial(TODAY, { staffId: 2 }), CATALOG), 0, 2, CATALOG).segments[0]).toEqual({ service: 2, staff: null });
    });

    it('opens with a customer\'s page\'s customer filled in and the step answered, still editable, the gate open once a slot is chosen', () => {
        const customer = { name: 'Casey Customer', email: 'casey@example.com', phone: '+30 210 1111111' };
        const booked = courtesy(initial(TODAY, { customer }), CATALOG);

        expect(booked.customer).toEqual(customer);
        expect(booked.customer).not.toBe(customer);
        expect(customerAnswered(booked)).toBe(true);
        expect(stripValues(booked, CATALOG, CLOCK, words)[2]).toBe('Casey Customer');
        expect(booked).toMatchObject({ step: 0, day: TODAY, slot: null, segments: [{ service: 1, staff: null }] });
        const who = advance(chooseSlot(advance(booked), TEN));
        expect(who.step).toBe(2);
        expect(gateOpen(who)).toBe(true);
        expect(bodyOf(who)).toMatchObject({ customer_name: 'Casey Customer', customer_email: 'casey@example.com', customer_phone: '+30 210 1111111', start: TEN });
        const edited = editCustomer(who, 'email', ' ');
        expect(customerAnswered(edited)).toBe(false);
        expect(gateOpen(edited)).toBe(false);
        expect(customerAnswered(courtesy(initial(TODAY), CATALOG))).toBe(false);
        expect(initial(TODAY, { date: '2026-11-10', staffId: 3 }).customer).toEqual({ name: '', email: '', phone: '' });
    });

    it('refuses to remove the only segment with the state unchanged, and drops the preference when the chain is back to one', () => {
        const one = courtesy(initial(TODAY), CATALOG);
        const two = toggleSameStaff(addSegment(one));

        expect(removeSegment(one, 0)).toBe(one);
        expect(two.sameStaff).toBe(true);
        expect(removeSegment(two, 1)).toMatchObject({ segments: [{ service: 1, staff: null }], sameStaff: false });
    });

    it('lists the active performers of a segment\'s service in catalog order', () => {
        expect(performersOf(CATALOG, 2).map((member) => member.name)).toEqual(['Nikos Georgiou']);
        expect(performersOf(CATALOG, 1).map((member) => member.name)).toEqual(['Elena Papadaki', 'Nikos Georgiou']);
        expect(performersOf(CATALOG, null)).toEqual([]);
    });

    it('makes the staff choice real for the entitlement, for more than one staff row, or for a pin already held', () => {
        const state = courtesy(initial(TODAY), CATALOG);

        expect(staffChoiceReal([], { ...CATALOG, members: 1 }, state)).toBe(false);
        expect(staffChoiceReal(['staff'], { ...CATALOG, members: 1 }, state)).toBe(true);
        expect(staffChoiceReal([], CATALOG, state)).toBe(true);
        expect(staffChoiceReal([], { ...CATALOG, members: 1 }, chooseStaff(state, 0, 2))).toBe(true);
    });
});

describe('staleness, 4.3', () => {
    it('kills the slot on a different day, keeps it on the same day, on any change to the chain\'s shape, and on the preference alone', () => {
        const state = answered();

        expect(state.slot).toBe(TEN);
        expect(chooseDay(state, '2026-11-03')).toBe(state);
        expect(chooseDay(state, '2026-11-04')).toMatchObject({ day: '2026-11-04', slot: null });
        expect(addSegment(state).slot).toBeNull();
        expect(removeSegment(state, 1).slot).toBeNull();
        expect(chooseService(state, 0, 3, CATALOG).slot).toBeNull();
        expect(chooseStaff(state, 0, 2).slot).toBeNull();
        expect(toggleSameStaff(state)).toMatchObject({ sameStaff: true, slot: null, day: '2026-11-03', step: 2, segments: state.segments });
    });
});

describe('the gates and the strip', () => {
    it('opens each gate on its own answer, moves forward through Continue alone, and reopens finished steps only', () => {
        const blank = initial(TODAY);

        expect(gateOpen(blank)).toBe(false);
        expect(advance(blank).step).toBe(0);
        const services = courtesy(blank, CATALOG);
        expect(gateOpen(services)).toBe(true);
        const when = advance(services);
        expect(when.step).toBe(1);
        expect(gateOpen(when)).toBe(false);
        expect(advance(when).step).toBe(1);
        const who = advance(chooseSlot(when, TEN));
        expect(who.step).toBe(2);
        expect(gateOpen(who)).toBe(false);
        expect(gateOpen(editCustomer(editCustomer(who, 'name', ' Ada '), 'email', 'ada@example.test'))).toBe(true);
        expect(gateOpen(editCustomer(editCustomer(who, 'name', ' '), 'email', 'ada@example.test'))).toBe(false);
        expect(advance(editCustomer(editCustomer(who, 'name', 'Ada'), 'email', 'a@b')).step).toBe(2);
        expect(reopen(who, 0).step).toBe(0);
        expect(reopen(who, 2)).toBe(who);
        expect(reopen(when, 2)).toBe(when);
    });

    it('spells the running answers with a non-breaking space where none exists', () => {
        expect(stripValues(initial(TODAY), CATALOG, CLOCK, words)).toEqual([NO_ANSWER, NO_ANSWER, NO_ANSWER]);
        const services = courtesy(initial(TODAY), CATALOG);
        expect(stripValues(services, CATALOG, CLOCK, words)).toEqual(['Haircut', NO_ANSWER, NO_ANSWER]);
        expect(stripValues(addSegment(services), CATALOG, CLOCK, words)[0]).toBe(words.choice('first_more', 1, { service: 'Haircut' }));
        expect(stripValues(advance(services), CATALOG, CLOCK, words)[1]).toBe('02/11/2026');
        const done = editCustomer(answered(), 'name', '  Ada North ');
        expect(stripValues(done, CATALOG, CLOCK, words)).toEqual([words.choice('first_more', 1, { service: 'Haircut' }), words.text('when', { date: '03/11/2026', time: '10:00' }), 'Ada North']);
    });

    it('reads the first answer as a choice by the services after the first, its English line a choice whose one case and many case differ', () => {
        const services = courtesy(initial(TODAY), CATALOG);
        expect(stripValues(addSegment(addSegment(services)), CATALOG, CLOCK, words)[0]).toBe(words.choice('first_more', 2, { service: 'Haircut' }));

        const line = ADMIN_LINES_EN.customers.wizard.first_more;
        expect(choose(line, 1)).not.toBe(choose(line, 2));
        expect(choose(line, 2)).toBe(choose(line, 7));
    });
});

describe('the wire', () => {
    it('sends the chain in the create endpoint\'s shape with staff_id always present, and asks nothing until every segment names a service', () => {
        const state = chooseStaff(courtesy(initial(TODAY), CATALOG), 0, 2);

        expect(itemsOf(addSegment(state))).toEqual([{ service_id: 1, staff_id: 2 }]);
        expect(availabilityQuery(addSegment(state))).toBeNull();
        expect(availabilityQuery(toggleSameStaff(addSegment(chooseService(addSegment(state), 1, 3, CATALOG))))).toBeNull();
        expect(availabilityQuery(chooseDay(state, '2026-11-03'))).toBe('items=%5B%7B%22service_id%22%3A1%2C%22staff_id%22%3A2%7D%5D&from=2026-11-03&to=2026-11-04&same_staff=false');
        expect(availabilityQuery(toggleSameStaff(state))).toContain('same_staff=true');
        expect(itemsOf(state)[0]).toHaveProperty('staff_id');
        expect(itemsOf(courtesy(initial(TODAY), CATALOG))).toEqual([{ service_id: 1, staff_id: null }]);
    });

    it('groups the starts at noon and at five in the tenant\'s clock, labels them from the wall clock, and omits empty groups', () => {
        const groups = groupsOf(['2026-11-03T07:00:00+00:00', '2026-11-03T09:55:00+00:00', '2026-11-03T10:00:00+00:00', '2026-11-03T14:55:00+00:00', '2026-11-03T15:00:00+00:00'], CLOCK);

        expect(groups.map((group) => [group.name, group.slots.map((slot) => slot.label)])).toEqual([
            ['morning', ['09:00', '11:55']],
            ['afternoon', ['12:00', '16:55']],
            ['evening', ['17:00']],
        ]);
        expect(groups.map((group) => periodKey(group.name))).toEqual(['periods.morning', 'periods.afternoon', 'periods.evening']);
        expect(groups[0]?.slots[0]?.wire).toBe('2026-11-03T07:00:00+00:00');
        expect(groupsOf(['2026-11-03T15:00:00+00:00'], CLOCK).map((group) => group.name)).toEqual(['evening']);
        expect(groupsOf([], CLOCK)).toEqual([]);
    });

    it('computes the end from the geometry on the grid with the last tail excluded, and sends the start verbatim', () => {
        const state = answered();

        expect(endOf(TEN, state, CATALOG, 5)).toBe('2026-11-03T09:45:00.000Z');
        const reversed = { ...state, segments: [{ service: 3, staff: null }, { service: 1, staff: null }] };
        expect(endOf(TEN, reversed, CATALOG, 5)).toBe('2026-11-03T10:15:00.000Z');
        expect(endOf(TEN, reversed, CATALOG, 15)).toBe('2026-11-03T10:15:00.000Z');
        const beard = { ...state, segments: [{ service: 2, staff: null }] };
        expect(endOf(TEN, beard, CATALOG, 15)).toBe('2026-11-03T08:30:00.000Z');
        expect(endOf(TEN, beard, CATALOG, 0)).toBe('2026-11-03T08:20:00.000Z');

        const body = bodyOf(editCustomer(editCustomer(state, 'name', 'Ada'), 'email', 'ada@example.test'));

        expect(body).toEqual({ customer_name: 'Ada', customer_email: 'ada@example.test', customer_phone: '', items: [{ service_id: 1, staff_id: null }, { service_id: 3, staff_id: null }], start: TEN, same_staff: false });
    });

    it('names the member on each Service line where the choice was real, Any staff for no pick, and the service alone where it never was', () => {
        const state = chooseStaff(answered(), 0, 2);

        expect(serviceLines(state, CATALOG, true, words)).toEqual([words.text('summary.service_staff', { service: 'Haircut', staff: 'Elena Papadaki' }), words.text('summary.service_staff', { service: 'Colour', staff: words.text(ANY_STAFF) })]);
        expect(serviceLines(state, CATALOG, false, words)).toEqual(['Haircut', 'Colour']);
    });
});
