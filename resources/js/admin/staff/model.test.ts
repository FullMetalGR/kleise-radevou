import { describe, expect, it } from 'vitest';
import { formatCount } from '@/lib/format';
import { unitFigure, weekdayName } from '@/lib/names';
import { keyedStaff } from '../testing/keyed';
import { blankForm, canSave, characters, formOf, hoursFigure, hoursLine, inactiveCount, membersShown, patchOf, patternLine, searchRows, type StaffMember, userOptions, visibleRows } from './model';

const ELENA: StaffMember = {
    id: 2, name: 'Elena Papadaki', email: null, status: 'active', online: true, is_implicit: false, user_id: null, title: 'Senior stylist', bio: null, service_ids: [7],
    pattern: { cadence_weeks: 1, starts_on: '1999-12-27', ends_on: null, windows: [{ weekday: 1, start: '09:07', end: '13:00' }, { weekday: 1, start: '14:00', end: '18:00' }] },
    blackouts: [{ date: '2026-12-04', until: null, start: '14:00', end: '16:00', reason: 'Dentist' }],
    colour: null,
    photo: null,
    hours: { minutes: 2640, weekly: true, closures: [] },
};

describe('the staff form', () => {
    it('seeds from the row as stored, the pattern left out since Shifts edits it, and a blank form for a new member', () => {
        expect(formOf(ELENA)).toEqual({ name: 'Elena Papadaki', email: '', user_id: null, status: 'active', online: true, title: 'Senior stylist', bio: '', service_ids: [7] });
        expect(formOf(ELENA).service_ids).not.toBe(ELENA.service_ids);
        expect(blankForm()).toEqual({ name: '', email: '', user_id: null, status: 'active', online: true, title: '', bio: '', service_ids: [] });
    });

    it('sends the name trimmed, the email as null when blank, the linked user, the status, and the links whole, and no week', () => {
        const form = { ...formOf(ELENA), name: '  Elena P. ', email: ' ', user_id: 4 };

        expect(patchOf(form, false)).toEqual({ name: 'Elena P.', email: null, user_id: 4, status: 'active', online: true, title: 'Senior stylist', bio: null, service_ids: [7] });
        expect(patchOf({ ...form, email: ' e@x.test ' }, false)).toMatchObject({ email: 'e@x.test' });
    });

    it('sends the title and the bio trimmed, each null when blank, and counts the bio in characters', () => {
        const form = { ...formOf(ELENA), title: ' Colourist ', bio: '  Ten years at the chair.\nAsk about balayage. ' };

        expect(patchOf(form, false)).toMatchObject({ title: 'Colourist', bio: 'Ten years at the chair.\nAsk about balayage.' });
        expect(patchOf({ ...form, title: '  ', bio: '\n' }, false)).toMatchObject({ title: null, bio: null });
        expect(characters('\u0395\u03bb\u03ad\u03bd\u03b7')).toBe(5);
        expect(characters('a\u{1F600}b')).toBe(3);
    });

    it('leaves the title and the bio out of the implicit member\'s patch, which the server leaves as stored on that row, and sends the rest', () => {
        const form = { ...formOf({ ...ELENA, is_implicit: true }), name: ' Luna Hair Studio ', title: 'Colourist', bio: 'Ten years.' };
        const patch = patchOf(form, true);

        expect(patch).toEqual({ name: 'Luna Hair Studio', email: null, user_id: null, status: 'active', online: true, service_ids: [7] });
        expect(patch).not.toHaveProperty('title');
        expect(patch).not.toHaveProperty('bio');
        expect(patchOf(form, false)).toMatchObject({ title: 'Colourist', bio: 'Ten years.' });
    });

    it('gates the save on the name alone', () => {
        expect(canSave({ name: '  ' })).toBe(false);
        expect(canSave({ name: 'E' })).toBe(true);
    });

    it('lists users with their address, and filters inactive rows behind the toggle with their count', () => {
        expect(userOptions([{ id: 3, name: 'Alex', email: 'alex@luna.example' }], keyedStaff())).toEqual([{ value: 3, label: keyedStaff().text('form.user_option', { user: 'Alex', address: 'alex@luna.example' }) }]);
        const rows = [ELENA, { ...ELENA, id: 3, status: 'inactive' }];
        expect(inactiveCount(rows)).toBe(1);
        expect(visibleRows(rows, false).map((row) => row.id)).toEqual([2]);
        expect(visibleRows(rows, true).map((row) => row.id)).toEqual([2, 3]);
    });
});

describe('the screen without the staff feature', () => {
    const IMPLICIT: StaffMember = { ...ELENA, id: 1, name: 'Luna Hair Studio', is_implicit: true };

    it('renders the member list for any row beside the implicit one, an inactive one included, and not for a roster holding the implicit row alone', () => {
        const inactive = (member: StaffMember): StaffMember => ({ ...member, status: 'inactive' });

        expect(membersShown([IMPLICIT, ELENA])).toBe(true);
        expect(membersShown([IMPLICIT, inactive(ELENA)])).toBe(true);
        expect(membersShown([inactive(IMPLICIT), ELENA])).toBe(true);
        expect(membersShown([IMPLICIT])).toBe(false);
        expect(membersShown([inactive(IMPLICIT)])).toBe(false);
        expect(membersShown([])).toBe(false);
    });

    describe('over a roster whose every row is inactive, the implicit one included', () => {
        const rows: StaffMember[] = [{ ...IMPLICIT, status: 'inactive' }, { ...ELENA, status: 'inactive' }, { ...ELENA, id: 3, status: 'inactive' }];

        it('counts every row on the toggle', () => {
            expect(inactiveCount(rows)).toBe(3);
        });

        it('lists none behind the toggle and every row once it is ticked', () => {
            expect(visibleRows(rows, false)).toEqual([]);
            expect(visibleRows(rows, true).map((row) => row.id)).toEqual([1, 2, 3]);
        });

        it('renders the member list', () => {
            expect(membersShown(rows)).toBe(true);
        });
    });

    it('spells the pattern as the page\'s read-only line, one whole line per case: the usual week\'s hours and the cadence, the start of a longer one in the tenant\'s date format with no conversion, or none', () => {
        const clock = { timezone: 'Pacific/Kiritimati', dateFormat: 'm/d/Y', timeFormat: 'g:i A' };
        const fortnightly = { cadence_weeks: 2, starts_on: '2026-10-29', ends_on: null, windows: [1, 2, 3, 4].map((weekday) => ({ weekday, start: '08:00', end: '19:00' })) };
        const staff = keyedStaff();

        expect(patternLine(ELENA.pattern, clock, staff)).toBe(staff.text('pattern.weekly', { hours: staff.text('figure', { hours: hour(7), minutes: minute(53) }) }));
        expect(patternLine(fortnightly, clock, staff)).toBe(staff.text('pattern.cadence', { hours: hour(44), weeks: formatCount(2, staff.locale), date: '10/29/2026' }));
        expect(patternLine(null, clock, staff)).toBe(staff.text('pattern.none'));
    });
});

/** ICU's figures in English, which the lines carry as their units, never a unit glued by hand. */
const hour = (value: number) => unitFigure(value, 'hour', 'en');
const minute = (value: number) => unitFigure(value, 'minute', 'en');

describe('hours this week', () => {
    const clock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

    it('spells the figure through ICU, the minutes where there are any through one key', () => {
        const staff = keyedStaff();
        const figure = (hours: number, minutes: number) => staff.text('figure', { hours: hour(hours), minutes: minute(minutes) });

        expect(hoursFigure({ minutes: 2640, weekly: true, closures: [] }, staff)).toBe(hour(44));
        expect(hoursFigure({ minutes: 930, weekly: false, closures: [] }, staff)).toBe(figure(15, 30));
        expect(hoursFigure({ minutes: 0, weekly: false, closures: [] }, staff)).toBe(hour(0));
        expect(hoursFigure({ minutes: 45, weekly: false, closures: [] }, staff)).toBe(figure(0, 45));
    });

    it('reads one whole line per case, every week or this week, each with or without the week\'s closures by weekday, the figure left as its placeholder', () => {
        const staff = keyedStaff();
        const friday = weekdayName(5, 'short', 'en');
        const closures = [{ date: '2026-12-11', start: null, end: null }];

        expect(hoursLine({ minutes: 2640, weekly: true, closures: [] }, clock, staff)).toBe(staff.text('table.hours_weekly'));
        expect(hoursLine({ minutes: 900, weekly: false, closures: [] }, clock, staff)).toBe(staff.text('table.hours_this_week'));
        expect(hoursLine({ minutes: 2640, weekly: true, closures }, clock, staff)).toBe(staff.text('table.hours_weekly_closed', { closures: friday }));
        expect(hoursLine({ minutes: 900, weekly: false, closures }, clock, staff)).toBe(staff.text('table.hours_this_week_closed', { closures: friday }));
        expect(hoursLine({ minutes: 900, weekly: false, closures }, clock, staff)).toContain(':hours');
    });

    it('spells the figures in the page\'s language, ICU\'s units in Greek where the words are Greek', () => {
        const greek = keyedStaff('el');

        expect(hoursFigure({ minutes: 2640, weekly: false, closures: [] }, greek)).toBe(unitFigure(44, 'hour', 'el'));
        expect(hoursFigure({ minutes: 2640, weekly: false, closures: [] }, greek)).not.toBe(hour(44));
    });
});

describe('the search', () => {
    it('keeps the rows whose name, email or title holds the words, case-blind, and every row for a blank search', () => {
        const rows = [ELENA, { ...ELENA, id: 3, name: 'Nikos Georgiou', email: 'nikos@luna.example', title: null }];

        expect(searchRows(rows, '  ').map((row) => row.id)).toEqual([2, 3]);
        expect(searchRows(rows, 'GEORG').map((row) => row.id)).toEqual([3]);
        expect(searchRows(rows, 'nikos@').map((row) => row.id)).toEqual([3]);
        expect(searchRows(rows, 'senior').map((row) => row.id)).toEqual([2]);
        expect(searchRows(rows, 'nobody')).toEqual([]);
    });
});
