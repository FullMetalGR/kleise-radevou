import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { localeOf } from '../../booking/language';
import { staffShown } from '@/bookings/model';
import { keyedCalendar, keyedShifts } from '@/testing/keyed';
import { applicableFilters, type Block, moveOfDrop, blockHueOf, blocksOf, businessHoursOf, categoryHueOf, CALENDAR_FILTER_SHAPE, CALENDAR_VIEWS, type CalendarBooking, type CalendarItem, closureBlocksOf, colourOf, countsOf, cut, dayAxisOf, dayColumnsOf, weekAxisOf, type DayHours, fillOf, headHoursOf, hoursOn, NO_HOURS_AHEAD, NEXT_WORKING_THIS_DAY, NOT_WORKING_THIS_DAY, nextLineOf, GAP_TITLE, idsOf, legendShown, MORNING, move, NO_CALENDAR_FILTERS, rangeOf, scrollTimeOf, slotDurationOf, slotHeightOf, shownRows, slotStaffOf, statusHueOf, swatchesOf, titleOf, wallOf, whoSummary } from './model';
import { hueOf, NEUTRAL, PALETTE, paletteHue } from '@/lib/hue';

/*
 * The calendar's rules as pure functions. The tenant is in Europe/Athens; 2026-11-03 is a
 * Tuesday.
 */

const ATHENS = 'Europe/Athens';

const CLOCK = { timezone: ATHENS, dateFormat: 'd/m/Y', timeFormat: 'H:i' };

/** The calendar section's words and the shifts section's, keyed. */
const CAL = keyedCalendar();

const SHIFTS = keyedShifts();

const ITEM: CalendarItem = {
    sort: 0,
    service_id: 1,
    service_name: 'Haircut',
    staff_id: 2,
    staff_name: 'Elena Papadaki',
    start: '2026-11-03T08:00:00+00:00',
    end: '2026-11-03T08:45:00+00:00',
    price_minor: 3500,
    block_start_utc: '2026-11-03T07:55:00+00:00',
    block_end_utc: '2026-11-03T08:50:00+00:00',
    processing_ends_utc: null,
};

function booking(overrides: Partial<CalendarBooking> = {}): CalendarBooking {
    return {
        uuid: 'b1',
        status: 'confirmed',
        customer_name: 'Ada North',
        total_minor: 3500,
        currency: 'EUR',
        items: [ITEM],
        ...overrides,
    };
}

describe('the view, the date and the fetch window', () => {
    it('spans the week from the tenant week start with an exclusive end, and a day to the next', () => {
        expect(rangeOf('week', '2026-11-05', 1)).toEqual({ from: '2026-11-02', to: '2026-11-09' });
        expect(rangeOf('week', '2026-11-05', 7)).toEqual({ from: '2026-11-01', to: '2026-11-08' });
        expect(rangeOf('week', '2026-11-02', 1)).toEqual({ from: '2026-11-02', to: '2026-11-09' });
        expect(rangeOf('day', '2026-11-05', 1)).toEqual({ from: '2026-11-05', to: '2026-11-06' });
    });

    it('moves seven days in Week and one in Day, either way', () => {
        expect(move('week', '2026-11-05', 1)).toBe('2026-11-12');
        expect(move('week', '2026-11-05', -1)).toBe('2026-10-29');
        expect(move('day', '2026-11-05', 1)).toBe('2026-11-06');
        expect(move('day', '2026-11-01', -1)).toBe('2026-10-31');
    });

    it('titles the week by its first and last day, and the day by its weekday, ICU\'s long name in the page\'s language, before its date, in the tenant date format', () => {
        const weekday = (language: 'en' | 'el') => new Intl.DateTimeFormat(localeOf(language), { weekday: 'long', timeZone: 'UTC' }).format(new Date('2026-11-05T00:00:00Z'));

        expect(titleOf('week', '2026-11-05', 1, CLOCK, CAL)).toBe('02/11/2026 - 08/11/2026');
        expect(titleOf('day', '2026-11-05', 1, CLOCK, CAL)).toBe(CAL.text('toolbar.day_title', { weekday: weekday('en'), date: '05/11/2026' }));
        expect(titleOf('day', '2026-11-05', 1, CLOCK, keyedCalendar('el'))).toBe(CAL.text('toolbar.day_title', { weekday: weekday('el'), date: '05/11/2026' }));
        expect(weekday('el')).not.toBe(weekday('en'));
    });

    it('offers the views in the order Day, Week, Month', () => {
        expect(CALENDAR_VIEWS).toEqual(['day', 'week', 'month']);
    });

    it('spans the six weeks of the month grid from the week holding the first of the month, 42 days with an exclusive end, whatever day of the month is shown', () => {
        // November 2026 opens on a Sunday and June 2026 on a Monday.
        expect(rangeOf('month', '2026-11-05', 1)).toEqual({ from: '2026-10-26', to: '2026-12-07' });
        expect(rangeOf('month', '2026-11-30', 1)).toEqual({ from: '2026-10-26', to: '2026-12-07' });
        expect(rangeOf('month', '2026-11-05', 7)).toEqual({ from: '2026-11-01', to: '2026-12-13' });
        expect(rangeOf('month', '2026-06-20', 1)).toEqual({ from: '2026-06-01', to: '2026-07-13' });
        expect(rangeOf('month', '2026-02-14', 1)).toEqual({ from: '2026-01-26', to: '2026-03-09' });
    });

    it('moves a month either way in Month, keeping the day of the month where the month has it and its last day where it does not', () => {
        expect(move('month', '2026-11-05', 1)).toBe('2026-12-05');
        expect(move('month', '2026-11-05', -1)).toBe('2026-10-05');
        expect(move('month', '2026-12-15', 1)).toBe('2027-01-15');
        expect(move('month', '2026-01-10', -1)).toBe('2025-12-10');
        expect(move('month', '2026-01-31', 1)).toBe('2026-02-28');
        expect(move('month', '2026-03-31', -1)).toBe('2026-02-28');
        expect(move('month', '2028-01-31', 1)).toBe('2028-02-29');
    });

    it.each(['en', 'el'] as const)('titles the month by its name and year, ICU\'s in %s, read off the date shown with no conversion', (language) => {
        const title = (date: string) => new Intl.DateTimeFormat(localeOf(language), { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));

        expect(titleOf('month', '2026-11-05', 1, CLOCK, keyedCalendar(language))).toBe(title('2026-11-01'));
        expect(titleOf('month', '2026-01-31', 7, CLOCK, keyedCalendar(language))).toBe(title('2026-01-01'));
        expect(titleOf('month', '2027-12-01', 1, CLOCK, keyedCalendar(language))).toBe(title('2027-12-01'));
    });

    it('steps the axis at the granularity and falls back to thirty minutes where it is unset', () => {
        expect(slotDurationOf(15)).toBe('00:15:00');
        expect(slotDurationOf(5)).toBe('00:05:00');
        expect(slotDurationOf(90)).toBe('01:30:00');
        expect(slotDurationOf(0)).toBe('00:30:00');
        expect(slotDurationOf(null)).toBe('00:30:00');
        expect(slotDurationOf(undefined)).toBe('00:30:00');
    });

    it('draws one hour sixty pixels tall whatever the step, so a slot is as many pixels as it is minutes', () => {
        expect(slotHeightOf(15)).toBe('15px');
        expect(slotHeightOf(5)).toBe('5px');
        expect(slotHeightOf(30)).toBe('30px');
        expect(slotHeightOf(90)).toBe('90px');
        expect(slotHeightOf(0)).toBe('30px');
        expect(slotHeightOf(null)).toBe('30px');
        expect(slotHeightOf(undefined)).toBe('30px');
    });
});

describe('the client cuts and the remembered card', () => {
    const chain = booking({
        uuid: 'chain',
        status: 'awaiting_approval',
        items: [
            { sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena', start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:45:00+00:00', price_minor: 1 },
            { sort: 1, service_id: 3, service_name: 'Colour', staff_id: 4, staff_name: 'Nikos', start: '2026-11-03T09:00:00+00:00', end: '2026-11-03T10:00:00+00:00', price_minor: 1 },
        ],
    });
    const plain = booking();

    it('narrows by status and by service, a chain surviving the service cut when its second segment alone matches', () => {
        expect(cut([chain, plain], NO_CALENDAR_FILTERS)).toEqual([chain, plain]);
        expect(cut([chain, plain], { ...NO_CALENDAR_FILTERS, status: 'awaiting_approval' })).toEqual([chain]);
        expect(cut([chain, plain], { ...NO_CALENDAR_FILTERS, service: 3 })).toEqual([chain]);
        expect(cut([chain, plain], { ...NO_CALENDAR_FILTERS, service: 1 })).toEqual([chain, plain]);
        expect(cut([chain, plain], { ...NO_CALENDAR_FILTERS, service: 9 })).toEqual([]);
        expect(cut([chain, plain], { status: 'confirmed', who: 'all', staff: [], service: 1 })).toEqual([plain]);
    });

    it('checks each remembered field by shape, Who and the picked list included, and drops a Who while the control would be hidden', () => {
        const active = [
            { id: 2, name: 'Elena' },
            { id: 4, name: 'Nikos' },
        ];
        const own = { id: 4, name: 'Nikos' };
        const picked = { status: 'confirmed', who: 'picked' as const, staff: [4, 2], service: 1 };

        expect(CALENDAR_FILTER_SHAPE.status('awaiting_approval')).toBe(true);
        expect(CALENDAR_FILTER_SHAPE.status('made up')).toBe(false);
        expect(CALENDAR_FILTER_SHAPE.who('picked')).toBe(true);
        expect(CALENDAR_FILTER_SHAPE.who('everyone')).toBe(false);
        expect(CALENDAR_FILTER_SHAPE.staff([2, 4])).toBe(true);
        expect(CALENDAR_FILTER_SHAPE.staff(2)).toBe(false);
        expect(CALENDAR_FILTER_SHAPE.staff(['2'])).toBe(false);
        expect(CALENDAR_FILTER_SHAPE.staff([0])).toBe(false);
        expect(CALENDAR_FILTER_SHAPE.service(null)).toBe(true);
        expect(applicableFilters(picked, [], 1, own, active)).toEqual({ status: 'confirmed', who: 'scheduled', staff: [], service: 1 });
        expect(applicableFilters(picked, ['staff'], 1, own, active)).toEqual({ status: 'confirmed', who: 'picked', staff: [2, 4], service: 1 });
        expect(applicableFilters(picked, [], 2, own, active)).toEqual({ status: 'confirmed', who: 'picked', staff: [2, 4], service: 1 });
        // A picked member since gone from the roster is dropped; none left reads Scheduled staff.
        expect(applicableFilters({ ...picked, staff: [4, 9] }, ['staff'], 3, own, active)).toEqual({ ...picked, staff: [4] });
        expect(applicableFilters({ ...picked, staff: [9] }, ['staff'], 3, own, active)).toEqual({ ...picked, who: 'scheduled', staff: [] });
        // Me holds only for a viewer with an own row; a preset carries no list.
        expect(applicableFilters({ ...picked, who: 'me' }, ['staff'], 3, own, active)).toEqual({ ...picked, who: 'me', staff: [] });
        expect(applicableFilters({ ...picked, who: 'me' }, ['staff'], 3, null, active)).toEqual({ ...picked, who: 'scheduled', staff: [] });
        expect(applicableFilters({ ...picked, who: 'all' }, ['staff'], 3, null, active)).toEqual({ ...picked, who: 'all', staff: [] });
        expect(staffShown([], 1, 2)).toBe(true);
        expect(staffShown([], 1, null)).toBe(false);
    });

    it('summarises the chosen set on the trigger in whole sentences, the picked members in roster order', () => {
        const active = [
            { id: 2, name: 'Elena' },
            { id: 4, name: 'Nikos' },
            { id: 6, name: 'Mira' },
        ];

        expect(whoSummary('scheduled', [], active, CAL)).toBe(CAL.text('who.scheduled'));
        expect(whoSummary('all', [], active, CAL)).toBe(CAL.text('who.all'));
        expect(whoSummary('me', [], active, CAL)).toBe(CAL.text('who.me'));
        expect(whoSummary('picked', [4], active, CAL)).toBe('Nikos');
        expect(whoSummary('picked', [6, 2], active, CAL)).toBe(CAL.choice('who.picked', 1, { staff: 'Elena' }));
        expect(whoSummary('picked', [6, 4, 2], active, CAL)).toBe(CAL.choice('who.picked', 2, { staff: 'Elena' }));
        expect(whoSummary('picked', [9], active, CAL)).toBe(CAL.text('who.scheduled'));
        expect(idsOf([4, 2, 4])).toEqual([2, 4]);
        expect(shownRows({ who: 'all', members: active, implicit: 1 })).toBeNull();
        expect(shownRows({ who: 'scheduled', members: active, implicit: 1 })).toEqual([2, 4, 6, 1]);
        expect(shownRows({ who: 'picked', members: [{ id: 4, name: 'Nikos' }], implicit: null })).toEqual([4]);
        expect(slotStaffOf([{ id: 4, name: 'Nikos' }])).toBe(4);
        expect(slotStaffOf(active)).toBeNull();
        expect(slotStaffOf([])).toBeNull();
    });
});

describe('the blocks one window answer draws', () => {
    it('draws a block per item over its occupied range, in the tenant wall clock, titled with the customer and the service', () => {
        const [block] = blocksOf([booking()], null, ATHENS, CAL);

        expect(block).toEqual({
            id: 'b1:0',
            kind: 'booking',
            uuid: 'b1',
            staffId: 2,
            start: '2026-11-03T09:55:00',
            end: '2026-11-03T10:50:00',
            minutes: 55,
            title: CAL.text('grid.block', { customer: 'Ada North', service: 'Haircut' }),
            badge: null,
            category: null,
            status: 'confirmed',
            segment: 0,
            movable: false,
        });
    });

    it('titles the block of a booking whose customer was erased "Customer erased" and the service', () => {
        const [block] = blocksOf([booking({ customer_name: null, customer_email: null, customer_phone: null })], null, ATHENS, CAL);

        expect(block?.title).toBe(CAL.text('grid.block_erased', { service: 'Haircut' }));
        expect(block?.title).toBe('[grid.block_erased Haircut]');
    });

    it('draws two blocks with distinct ids for a two-segment chain, and a gap only where the processing end runs past the end', () => {
        const chain = booking({
            uuid: 'chain',
            items: [
                { sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena', start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:45:00+00:00', price_minor: 1, block_start_utc: '2026-11-03T08:00:00+00:00', block_end_utc: '2026-11-03T08:45:00+00:00', processing_ends_utc: '2026-11-03T08:45:00+00:00' },
                { sort: 1, service_id: 3, service_name: 'Colour', staff_id: 4, staff_name: 'Nikos', start: '2026-11-03T09:00:00+00:00', end: '2026-11-03T10:00:00+00:00', price_minor: 1, block_start_utc: '2026-11-03T09:00:00+00:00', block_end_utc: '2026-11-03T10:00:00+00:00', processing_ends_utc: '2026-11-03T10:30:00+00:00' },
            ],
        });

        const blocks = blocksOf([chain], null, ATHENS, CAL);

        expect(blocks.map((block) => [block.id, block.kind, block.staffId, block.start, block.end, block.title])).toEqual([
            ['chain:0', 'booking', 2, '2026-11-03T10:00:00', '2026-11-03T10:45:00', CAL.text('grid.block', { customer: 'Ada North', service: 'Haircut' })],
            ['chain:1', 'booking', 4, '2026-11-03T11:00:00', '2026-11-03T12:00:00', CAL.text('grid.block', { customer: 'Ada North', service: 'Colour' })],
            ['chain:1:gap', 'gap', 4, '2026-11-03T12:00:00', '2026-11-03T12:30:00', CAL.text(GAP_TITLE)],
        ]);
        expect(blocksOf([chain], [4], ATHENS, CAL).map((block) => block.id)).toEqual(['chain:1', 'chain:1:gap']);
        expect(blocksOf([chain], [2], ATHENS, CAL).map((block) => block.id)).toEqual(['chain:0']);
        expect(blocksOf([chain], [2, 4], ATHENS, CAL).map((block) => block.id)).toEqual(['chain:0', 'chain:1', 'chain:1:gap']);
        expect(blocksOf([chain], [9], ATHENS, CAL)).toEqual([]);
        expect(blocksOf([chain], [], ATHENS, CAL)).toEqual([]);
    });

    it('carries on each block what a drag addresses the move with, the segment from 0 and the server\'s movable fact, false where the payload is silent', () => {
        const chain = booking({
            uuid: 'chain',
            movable: true,
            items: [
                { sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena', start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:45:00+00:00', price_minor: 1 },
                { sort: 1, service_id: 3, service_name: 'Colour', staff_id: 4, staff_name: 'Nikos', start: '2026-11-03T09:00:00+00:00', end: '2026-11-03T10:00:00+00:00', price_minor: 1, processing_ends_utc: '2026-11-03T10:30:00+00:00' },
            ],
        });

        expect(blocksOf([chain], null, ATHENS, CAL).map((block) => [block.id, block.uuid, block.segment, block.movable])).toEqual([
            ['chain:0', 'chain', 0, true],
            ['chain:1', 'chain', 1, true],
            ['chain:1:gap', 'chain', 1, true],
        ]);
        expect(blocksOf([booking({})], null, ATHENS, CAL).map((block) => block.movable)).toEqual([false]);
        expect(blocksOf([booking({ movable: false })], null, ATHENS, CAL).map((block) => block.movable)).toEqual([false]);
    });

    it('badges a block for a booking awaiting approval and for one awaiting payment alone, never its gap, and falls back to the customer range where the occupied one is withheld', () => {
        const request = booking({ status: 'awaiting_approval', items: [{ sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena', start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:45:00+00:00', price_minor: 1 }] });
        const [block] = blocksOf([request], null, ATHENS, CAL);
        const unpaid = blocksOf([booking({ status: 'awaiting_payment', items: [{ ...ITEM, processing_ends_utc: '2026-11-03T09:00:00+00:00' }] })], null, ATHENS, CAL);

        expect(block?.badge).toBe('awaiting_approval');
        expect(block?.start).toBe('2026-11-03T10:00:00');
        expect(block?.minutes).toBe(45);
        expect(unpaid.map((each) => [each.kind, each.badge])).toEqual([
            ['booking', 'awaiting_payment'],
            ['gap', null],
        ]);
        expect(['pending', 'confirmed', 'completed', 'no_show', 'cancelled', 'rejected', 'expired'].map((status) => blocksOf([booking({ status })], null, ATHENS, CAL)[0]?.badge)).toEqual(Array(7).fill(null));
    });

    it('spells a booking near midnight in Athens on the Athens day, and a daylight-saving day at its wall-clock hours on both sides', () => {
        expect(wallOf('2026-11-02T22:15:00+00:00', ATHENS)).toBe('2026-11-03T00:15:00');
        expect(wallOf('2026-10-24T23:30:00+00:00', ATHENS)).toBe('2026-10-25T02:30:00');
        expect(wallOf('2026-10-25T02:30:00+00:00', ATHENS)).toBe('2026-10-25T04:30:00');
    });
});

describe('the month counts', () => {
    const at = (uuid: string, status: string, start: string, staff = 2, service = 1): CalendarBooking =>
        booking({ uuid, status, items: [{ sort: 0, service_id: service, service_name: 'Haircut', staff_id: staff, staff_name: 'Elena', start, end: start, price_minor: 1 }] });
    const chain = booking({
        uuid: 'chain',
        items: [
            { sort: 0, service_id: 1, service_name: 'Haircut', staff_id: 2, staff_name: 'Elena', start: '2026-11-03T21:00:00+00:00', end: '2026-11-03T21:45:00+00:00', price_minor: 1 },
            { sort: 1, service_id: 3, service_name: 'Colour', staff_id: 4, staff_name: 'Nikos', start: '2026-11-03T22:00:00+00:00', end: '2026-11-03T23:00:00+00:00', price_minor: 1 },
        ],
    });

    it('counts each booking once on the tenant day its first item starts, a status per chip in the order the status filter lists them, and nothing for a day with none', () => {
        const counts = countsOf(
            [
                at('a', 'confirmed', '2026-11-03T08:00:00+00:00'),
                at('b', 'awaiting_approval', '2026-11-03T09:00:00+00:00'),
                at('c', 'confirmed', '2026-11-03T12:00:00+00:00'),
                at('d', 'pending', '2026-11-03T13:00:00+00:00'),
                at('e', 'cancelled', '2026-11-02T22:30:00+00:00'),
                chain,
            ],
            null,
            ATHENS,
        );

        // 22:30 UTC on the second is 00:30 on the third in Athens; the chain starts at 23:00 on
        // the third in Athens and its second item at midnight, and counts once, on the third.
        expect(Object.fromEntries(counts)).toEqual({
            '2026-11-03': [
                { status: 'pending', count: 1 },
                { status: 'awaiting_approval', count: 1 },
                { status: 'confirmed', count: 3 },
                { status: 'cancelled', count: 1 },
            ],
        });
        expect(countsOf([], null, ATHENS).size).toBe(0);
    });

    it('places a booking across a clock change on its wall-clock day, and lists a status the client does not know after the nine', () => {
        const counts = countsOf([at('late', 'confirmed', '2026-10-24T21:30:00+00:00'), at('odd', 'on_hold_forever', '2026-10-25T08:00:00+00:00'), at('early', 'expired', '2026-10-25T08:00:00+00:00')], null, ATHENS);

        expect(Object.fromEntries(counts)).toEqual({
            '2026-10-25': [
                { status: 'confirmed', count: 1 },
                { status: 'expired', count: 1 },
                { status: 'on_hold_forever', count: 1 },
            ],
        });
    });

    it('counts the bookings the status and service cuts leave, and under a set of members only those with an item on one of their rows, as the read narrows them', () => {
        const bookings = [at('a', 'confirmed', '2026-11-03T08:00:00+00:00'), at('b', 'awaiting_approval', '2026-11-04T08:00:00+00:00', 2, 5), chain];

        expect(Object.fromEntries(countsOf(cut(bookings, { ...NO_CALENDAR_FILTERS, status: 'awaiting_approval' }), null, ATHENS))).toEqual({ '2026-11-04': [{ status: 'awaiting_approval', count: 1 }] });
        expect(Object.fromEntries(countsOf(cut(bookings, { ...NO_CALENDAR_FILTERS, service: 3 }), null, ATHENS))).toEqual({ '2026-11-03': [{ status: 'confirmed', count: 1 }] });
        expect(Object.fromEntries(countsOf(bookings, [4], ATHENS))).toEqual({ '2026-11-03': [{ status: 'confirmed', count: 1 }] });
        expect(countsOf(bookings, [9], ATHENS).size).toBe(0);
    });
});

describe('colour', () => {
    it('gives the same member the same hue from the fixed palette, two members two hues, and ids a palette apart the same one', () => {
        expect(hueOf(2, [])).toBe(hueOf(2, []));
        expect(hueOf(2, [])).not.toBe(hueOf(4, []));
        expect(PALETTE).toContain(hueOf(2, []));
        expect(hueOf(2, [])).toBe(hueOf(2 + PALETTE.length, []));
        expect(hueOf(0, [])).toBe(PALETTE[0]);
        expect(PALETTE).toHaveLength(7);
    });

    it('mixes the fill toward the surface and never as alpha, and wears the neutral grey where the choice is not real', () => {
        expect(fillOf('#e69f00')).toBe('color-mix(in oklab, #e69f00 18%, var(--card))');
        expect(fillOf('#e69f00')).not.toMatch(/rgba|\/\s*\d|opacity/);
        expect(colourOf(2, true, [])).toBe(hueOf(2, []));
        expect(colourOf(2, false, [])).toBe(NEUTRAL);
        expect(colourOf(4, false, [{ id: 4, colour: 'purple' }])).toBe(NEUTRAL);
    });

    it('draws a member who picked a colour in it while the palette is in play', () => {
        expect(colourOf(2, true, [{ id: 2, colour: 'purple' }])).toBe('#cc79a7');
        expect(colourOf(3, true, [{ id: 2, colour: 'purple' }])).toBe(PALETTE[3]);
    });
});

describe('the initial scroll', () => {
    const at = (start: string, id = start) => ({ id, kind: 'booking' as const, uuid: 'u', staffId: 1, start, end: start, minutes: 30, title: '', badge: null, category: null, status: 'confirmed', segment: 0, movable: false });

    it('lands one hour before the earliest block of the day shown, and ignores other days', () => {
        expect(scrollTimeOf([at('2026-11-03T10:30:00'), at('2026-11-03T09:15:00'), at('2026-11-04T06:00:00')], '2026-11-03')).toBe('08:15:00');
    });

    it('clamps a block at 00:30 to the same day and opens an empty day at the morning hour', () => {
        expect(scrollTimeOf([at('2026-11-03T00:30:00')], '2026-11-03')).toBe('00:00:00');
        expect(scrollTimeOf([at('2026-11-03T01:00:00')], '2026-11-03')).toBe('00:00:00');
        expect(scrollTimeOf([at('2026-11-04T06:00:00')], '2026-11-03')).toBe(MORNING);
        expect(scrollTimeOf([], '2026-11-03')).toBe('08:00:00');
    });
});

describe('Colour by', () => {
    const at = (staffId: number, category: string | null, status: string) => ({ staffId, category, status });

    it('carries each item\'s category and the booking\'s status onto its blocks, a gap inheriting both', () => {
        const chain = booking({ status: 'awaiting_approval' });
        const first = chain.items[0] as CalendarItem;
        chain.items = [{ ...first, category: 'Hair', processing_ends_utc: '2026-11-03T09:15:00+00:00' }];

        expect(blocksOf([chain], null, ATHENS, CAL).map((block) => [block.kind, block.category, block.status])).toEqual([
            ['booking', 'Hair', 'awaiting_approval'],
            ['gap', 'Hair', 'awaiting_approval'],
        ]);
    });

    it('hues a category by its place in the sorted categories with the member function, the blank bucket and an unlisted one grey', () => {
        const categories = ['Colouring', 'Hair', 'Nails'];

        expect(categoryHueOf('Colouring', categories)).toBe(paletteHue(0));
        expect(categoryHueOf('Nails', categories)).toBe(paletteHue(2));
        expect(categoryHueOf(null, categories)).toBe(NEUTRAL);
        expect(categoryHueOf('Gone', categories)).toBe(NEUTRAL);
    });

    it('hues a status by its chip family, one hue per family, an unknown status grey', () => {
        expect(statusHueOf('cancelled')).toBe(statusHueOf('rejected'));
        expect(statusHueOf('pending')).toBe(statusHueOf('awaiting_payment'));
        expect(new Set(['confirmed', 'awaiting_approval', 'pending', 'cancelled', 'completed'].map(statusHueOf)).size).toBe(5);
        expect(statusHueOf('teleported')).toBe(NEUTRAL);
    });

    it('reads each status hue off a token the stylesheet defines once, the chips painting with the same tokens', () => {
        const css = readFileSync(resolve(process.cwd(), 'resources/js/admin/app.css'), 'utf8');
        const tokens = ['confirmed', 'awaiting_approval', 'pending', 'cancelled', 'completed'].map((status) => statusHueOf(status).match(/^var\((--admin-status-[a-z]+)\)$/)?.[1]);

        expect(tokens).toEqual(['--admin-status-live', '--admin-status-attention', '--admin-status-provisional', '--admin-status-void', '--admin-status-past']);

        for (const token of tokens) {
            expect(css.split(`${token ?? ''}:`)).toHaveLength(2);
        }

        expect(css).not.toMatch(/#047857|#f59e0b|#0284c7/);
        expect(css).toContain('bg-(--admin-status-live)');
    });

    it('picks the block hue by the choice, the Staff choice grey where the staff choice is not real', () => {
        const block = at(4, 'Hair', 'confirmed');

        expect(blockHueOf(block, { by: 'staff', palette: true, categories: ['Hair'], own: [] })).toBe(hueOf(4, []));
        expect(blockHueOf(block, { by: 'staff', palette: true, categories: ['Hair'], own: [{ id: 4, colour: 'green' }] })).toBe(PALETTE[2]);
        expect(blockHueOf(block, { by: 'staff', palette: false, categories: ['Hair'], own: [] })).toBe(NEUTRAL);
        expect(blockHueOf(block, { by: 'category', palette: false, categories: ['Hair'], own: [] })).toBe(paletteHue(0));
        expect(blockHueOf(block, { by: 'status', palette: false, categories: ['Hair'], own: [] })).toBe(statusHueOf('confirmed'));
    });

    it('draws the legend under the other two choices on any tenant, under Staff member only with the palette, and never in Month', () => {
        expect(legendShown({ by: 'staff', palette: true, categories: [], own: [] }, 'week')).toBe(true);
        expect(legendShown({ by: 'staff', palette: false, categories: [], own: [] }, 'week')).toBe(false);
        expect(legendShown({ by: 'category', palette: false, categories: [], own: [] }, 'day')).toBe(true);
        expect(legendShown({ by: 'status', palette: true, categories: [], own: [] }, 'month')).toBe(false);
        expect(swatchesOf({ by: 'staff', palette: false, categories: [], own: [] }, [{ id: 2, name: 'Elena' }], [], String, CAL)).toEqual([]);
        expect(swatchesOf({ by: 'staff', palette: true, categories: [], own: [{ id: 2, colour: 'purple' }] }, [{ id: 2, name: 'Elena' }], [], String, CAL).map((swatch) => swatch.hue)).toEqual(['#cc79a7']);
    });
});

describe('Day as member columns', () => {
    const DAY = '2026-11-03';
    const ELENA_ROW = { id: 2, name: 'Elena Papadaki' };
    const NIKOS_ROW = { id: 4, name: 'Nikos Georgiou' };
    const SPLIT = [
        { start: '09:00', end: '13:00' },
        { start: '14:00', end: '18:00' },
    ];
    const ELENA_DAY: DayHours = { windows: SPLIT, closures: [], source: 'pattern', pattern: SPLIT };
    const NONE: DayHours = { windows: [], closures: [], source: 'none', pattern: [] };

    function at(staffId: number, start: string, end: string, id = `${staffId}:${start}`): Block {
        return { id, kind: 'booking', uuid: id, staffId, start: `${DAY}T${start}:00`, end: `${DAY}T${end}:00`, minutes: 60, title: 'Ada North, Haircut', badge: null, category: null, status: 'confirmed', segment: 0, movable: false };
    }

    const window = {
        from: DAY,
        to: '2026-11-04',
        members: [ELENA_ROW, NIKOS_ROW],
        hours: { '2': { [DAY]: ELENA_DAY }, '4': { [DAY]: NONE } },
        next_working: { '4': { date: '2026-11-05', windows: [{ start: '10:00', end: '15:00' }] } },
    };

    it('makes a column per member the window answers, with their hours of the day, their next working day and their blocks, then the other bookings\' column where one is drawn', () => {
        const elena = at(2, '10:00', '11:00');
        const implicit = at(1, '12:00', '13:00');
        const inactive = at(9, '15:00', '16:00');

        expect(dayColumnsOf(window, [elena, implicit, inactive], DAY)).toEqual([
            { key: 'member-2', member: ELENA_ROW, hours: ELENA_DAY, next: undefined, blocks: [elena] },
            { key: 'member-4', member: NIKOS_ROW, hours: NONE, next: { date: '2026-11-05', windows: [{ start: '10:00', end: '15:00' }] }, blocks: [] },
            { key: 'other', member: null, hours: null, next: undefined, blocks: [implicit, inactive] },
        ]);
        expect(dayColumnsOf(window, [elena], DAY).map((column) => column.key)).toEqual(['member-2', 'member-4']);
        // An answer with nothing keyed may arrive as lists.
        expect(dayColumnsOf({ from: DAY, to: '2026-11-04', members: [ELENA_ROW], hours: [], next_working: [] }, [], DAY)).toEqual([{ key: 'member-2', member: ELENA_ROW, hours: NONE, next: undefined, blocks: [] }]);
        expect(hoursOn({ hours: [] }, 2, DAY)).toEqual(NONE);
    });

    it('spans the axis from the earliest start to the latest end of the hours, the timed closures and the blocks, at least 08:00 to 20:00, rounded out to the hour', () => {
        const columns = dayColumnsOf(window, [], DAY);
        expect(dayAxisOf(columns, DAY)).toEqual({ min: '08:00:00', max: '20:00:00' });

        const early = dayColumnsOf({ ...window, hours: { '2': { [DAY]: { ...ELENA_DAY, windows: [{ start: '07:30', end: '20:15' }] } } } }, [], DAY);
        expect(dayAxisOf(early, DAY)).toEqual({ min: '07:00:00', max: '21:00:00' });

        const closed = dayColumnsOf({ ...window, hours: { '2': { [DAY]: { ...ELENA_DAY, closures: [{ start: '20:00', end: '22:30', reason: null, business: true }, { start: null, end: null, reason: 'Leave', business: false }] } } } }, [], DAY);
        expect(dayAxisOf(closed, DAY)).toEqual({ min: '08:00:00', max: '23:00:00' });

        const late = dayColumnsOf(window, [at(1, '06:10', '06:40'), { ...at(2, '23:00', '23:30'), end: '2026-11-04T00:30:00' }], DAY);
        expect(dayAxisOf(late, DAY)).toEqual({ min: '06:00:00', max: '24:00:00' });
    });

    it('bounds Week from an hour before the earliest hour any shown member works that week to an hour after the latest, the whole day where nobody has hours, widened to hold every block of the week', () => {
        const WEEK = '2026-11-02';
        const LATE: DayHours = { windows: [{ start: '12:30', end: '19:15' }], closures: [], source: 'override', pattern: [] };
        const week = { members: [ELENA_ROW, NIKOS_ROW], hours: { '2': { [DAY]: ELENA_DAY }, '4': { '2026-11-06': LATE } } };

        // Elena from 09:00 on Tuesday, Nikos to 19:15 on Friday: 08:00 to 20:15, rounded out to the
        // hour.
        expect(weekAxisOf(week, [], WEEK)).toEqual({ min: '08:00:00', max: '21:00:00' });
        // Hours outside the week shown and a member not shown do not count.
        expect(weekAxisOf({ ...week, hours: { ...week.hours, '2': { '2026-11-09': { ...ELENA_DAY, windows: [{ start: '05:00', end: '23:00' }] } }, '9': { [DAY]: { ...ELENA_DAY, windows: [{ start: '01:00', end: '02:00' }] } } } }, [], WEEK)).toEqual({ min: '11:00:00', max: '21:00:00' });
        // Nobody with hours that week: the whole day.
        expect(weekAxisOf({ members: [ELENA_ROW], hours: [] }, [], WEEK)).toEqual({ min: '00:00:00', max: '24:00:00' });
        expect(weekAxisOf({ members: [], hours: [] }, [at(2, '10:00', '11:00')], WEEK)).toEqual({ min: '00:00:00', max: '24:00:00' });
        // A block outside the hours widens the span to hold it, one past midnight to the day's end;
        // one outside the week does not.
        const early = at(2, '06:10', '06:40');
        const overnight = { ...at(4, '22:30', '23:00'), end: '2026-11-04T00:30:00' };
        const outside = { ...at(4, '03:00', '04:00'), start: '2026-11-12T03:00:00', end: '2026-11-12T04:00:00' };

        expect(weekAxisOf(week, [early, outside], WEEK)).toEqual({ min: '06:00:00', max: '21:00:00' });
        expect(weekAxisOf(week, [overnight], WEEK)).toEqual({ min: '00:00:00', max: '24:00:00' });
    });

    it('hands the windows as business hours on the date\'s weekday, a day with none business hours on another weekday alone', () => {
        expect(businessHoursOf(ELENA_DAY, DAY)).toEqual([
            { daysOfWeek: [2], startTime: '09:00', endTime: '13:00' },
            { daysOfWeek: [2], startTime: '14:00', endTime: '18:00' },
        ]);
        expect(businessHoursOf(NONE, '2026-11-08')).toEqual([{ daysOfWeek: [1], startTime: '00:00', endTime: '24:00' }]);
    });

    it('spells the head\'s hours, the pill and the closures in the tenant\'s time format', () => {
        const twelve = { ...CLOCK, timeFormat: 'g:i A' };

        expect(headHoursOf(ELENA_DAY, CLOCK, CAL)).toBe('09:00-13:00, 14:00-18:00');
        expect(headHoursOf(ELENA_DAY, twelve, CAL)).toBe('9:00 AM-1:00 PM, 2:00 PM-6:00 PM');
        expect(headHoursOf(NONE, CLOCK, CAL)).toBe(CAL.text(NOT_WORKING_THIS_DAY));
        expect(nextLineOf({ date: '2026-11-05', windows: [{ start: '10:00', end: '15:00' }] }, CLOCK, CAL)).toBe(
            CAL.text(NEXT_WORKING_THIS_DAY, { weekday: new Intl.DateTimeFormat(localeOf('en'), { weekday: 'long', timeZone: 'UTC' }).format(new Date('2026-11-05T00:00:00Z')), date: '05/11/2026', hours: '10:00-15:00' }),
        );
        // The weekday is ICU's in the words' language, filled into the one whole sentence.
        expect(nextLineOf({ date: '2026-11-05', windows: [{ start: '10:00', end: '15:00' }] }, CLOCK, keyedCalendar('el'))).toContain(new Intl.DateTimeFormat(localeOf('el'), { weekday: 'long', timeZone: 'UTC' }).format(new Date('2026-11-05T00:00:00Z')));
        expect(nextLineOf(null, CLOCK, CAL)).toBe(CAL.text(NO_HOURS_AHEAD));
        // No entry: the member has hours in the window, so no pill.
        expect(nextLineOf(undefined, CLOCK, CAL)).toBeNull();
        expect(
            closureBlocksOf(
                {
                    ...ELENA_DAY,
                    closures: [
                        { start: '14:00', end: '16:00', reason: 'Dentist', business: false },
                        { start: '20:00', end: '24:00', reason: null, business: true },
                        { start: null, end: null, reason: 'Christmas Day', business: true },
                    ],
                },
                DAY,
                CLOCK,
                'member-2',
                SHIFTS,
            ),
        ).toEqual([
            { id: 'member-2:closure:0', title: SHIFTS.text('page.closed_reason', { start: '14:00', end: '16:00', reason: 'Dentist' }), start: `${DAY}T14:00:00`, end: `${DAY}T16:00:00` },
            { id: 'member-2:closure:1', title: SHIFTS.text('page.closed', { start: '20:00', end: '24:00' }), start: `${DAY}T20:00:00`, end: '2026-11-04T00:00:00' },
            { id: 'member-2:closure:2', title: SHIFTS.text('page.closed_all_day_reason', { reason: 'Christmas Day' }), start: `${DAY}T00:00:00`, end: '2026-11-04T00:00:00' },
        ]);
    });

    it('titles each closure from one whole sentence per case of the shifts section, the Shifts week\'s own, its placeholders filled, never a fragment appended', () => {
        const titles = closureBlocksOf(
            {
                ...ELENA_DAY,
                closures: [
                    { start: '14:00', end: '16:00', reason: 'Dentist', business: false },
                    { start: '14:00', end: '16:00', reason: null, business: false },
                    { start: null, end: null, reason: 'Leave', business: false },
                    { start: null, end: null, reason: null, business: true },
                ],
            },
            DAY,
            CLOCK,
            'member-2',
            SHIFTS,
        ).map((closure) => closure.title);

        expect(titles).toEqual(['[page.closed_reason 14:00 16:00 Dentist]', '[page.closed 14:00 16:00]', '[page.closed_all_day_reason Leave]', '[page.closed_all_day]']);
    });

    it('reads a date the window does not cover as not yet read: each member\'s column with no hours and no next working day, the other bookings\' as ever', () => {
        const stale = { ...window, from: '2026-11-02', to: DAY };
        const implicit = at(1, '12:00', '13:00');

        expect(dayColumnsOf(stale, [implicit], DAY)).toEqual([
            { key: 'member-2', member: ELENA_ROW, hours: null, next: undefined, blocks: [] },
            { key: 'member-4', member: NIKOS_ROW, hours: null, next: undefined, blocks: [] },
            { key: 'other', member: null, hours: null, next: undefined, blocks: [implicit] },
        ]);
        expect(dayColumnsOf({ ...window, from: '2026-11-04', to: '2026-11-05' }, [], DAY).every((column) => column.hours === null)).toBe(true);
    });
});

describe('a drop on the grid as a move', () => {
    /** Haircut with Elena at 10:00 Athens, then Colour with Nikos at 10:45, on Tuesday the third. */
    const CHAIN = booking({
        uuid: 'chain',
        items: [
            { ...ITEM, start: '2026-11-03T08:00:00+00:00', end: '2026-11-03T08:45:00+00:00' },
            { ...ITEM, sort: 1, service_id: 4, service_name: 'Colour', staff_id: 4, staff_name: 'Nikos Georgiou', start: '2026-11-03T08:45:00+00:00', end: '2026-11-03T09:30:00+00:00', block_start_utc: null, block_end_utc: null },
        ],
    });
    const [first, second] = blocksOf([CHAIN], null, ATHENS, CAL);

    if (first === undefined || second === undefined) {
        throw new Error('The chain draws two blocks.');
    }

    it('moves the booking by the time the block moved, the member kept, where the drop changed the time alone', () => {
        // The first block starts at its buffer, 09:55; dropped at 11:25 it moved an hour and a
        // half.
        expect(moveOfDrop(CHAIN, { block: first, start: '2026-11-03T11:25:00', staffId: null }, ATHENS)).toEqual({ uuid: 'chain', start: '2026-11-03T09:30:00+00:00', staff: {} });
    });

    it('gives the dropped block\'s segment to the column\'s member where the drop changed the column alone', () => {
        expect(moveOfDrop(CHAIN, { block: first, start: first.start, staffId: 7 }, ATHENS)).toEqual({ uuid: 'chain', start: '2026-11-03T08:00:00+00:00', staff: { 0: 7 } });
    });

    it('does both where the drop changed the column and the time', () => {
        expect(moveOfDrop(CHAIN, { block: first, start: '2026-11-03T09:25:00', staffId: 7 }, ATHENS)).toEqual({ uuid: 'chain', start: '2026-11-03T07:30:00+00:00', staff: { 0: 7 } });
    });

    it('moves the whole chain from its first start by a later segment\'s move, and names that segment where its column changed', () => {
        expect(second.segment).toBe(1);
        expect(moveOfDrop(CHAIN, { block: second, start: '2026-11-03T12:45:00', staffId: 2 }, ATHENS)).toEqual({ uuid: 'chain', start: '2026-11-03T10:00:00+00:00', staff: { 1: 2 } });
    });

    it('is nothing where the drop changed neither the time nor the member, the same member\'s column counting as no change', () => {
        expect(moveOfDrop(CHAIN, { block: first, start: first.start, staffId: null }, ATHENS)).toBeNull();
        expect(moveOfDrop(CHAIN, { block: second, start: second.start, staffId: 4 }, ATHENS)).toBeNull();
    });

    it('keeps the wall clock across a change of the clocks: a Week drag from the Saturday before the clocks go back to the Monday after lands at the same hour', () => {
        const saturday = booking({ uuid: 'sat', items: [{ ...ITEM, start: '2026-10-24T07:00:00+00:00', end: '2026-10-24T07:45:00+00:00', block_start_utc: null, block_end_utc: null }] });
        const [block] = blocksOf([saturday], null, ATHENS, CAL);

        if (block === undefined) {
            throw new Error('The booking draws a block.');
        }

        expect(block.start).toBe('2026-10-24T10:00:00');
        expect(moveOfDrop(saturday, { block, start: '2026-10-26T10:00:00', staffId: null }, ATHENS)).toEqual({ uuid: 'sat', start: '2026-10-26T08:00:00+00:00', staff: {} });
    });

    it('lands a drag onto the day the clocks go back at that day\'s hour: Saturday 10:00 to Sunday 10:00 in Athens is 08:00 UTC, an hour later than the day before', () => {
        const saturday = booking({ uuid: 'sat', items: [{ ...ITEM, start: '2026-10-24T07:00:00+00:00', end: '2026-10-24T07:45:00+00:00', block_start_utc: null, block_end_utc: null }] });
        const [block] = blocksOf([saturday], null, ATHENS, CAL);

        if (block === undefined) {
            throw new Error('The booking draws a block.');
        }

        expect(moveOfDrop(saturday, { block, start: '2026-10-25T10:00:00', staffId: null }, ATHENS)).toEqual({ uuid: 'sat', start: '2026-10-25T08:00:00+00:00', staff: {} });
        // The hour the clocks repeat reads its earlier instant, summer time's.
        expect(moveOfDrop(saturday, { block, start: '2026-10-25T03:30:00', staffId: null }, ATHENS)).toEqual({ uuid: 'sat', start: '2026-10-25T00:30:00+00:00', staff: {} });
    });
});
