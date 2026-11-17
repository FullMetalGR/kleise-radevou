import { act } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { listOf } from '@/lib/names';
import { stringsOf } from '@/lib/strings';
import { NOT_DONE } from '@/lib/visits';
import type { WeeklyWindow } from '@/shifts/model';
import { KEYED_SETUP, keyedSetup, keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { Setup, StepKey } from '../model';
import { SetupWizard } from '../SetupWizard';
import { HOURS_URL } from './HoursStep';

/*
 * The Opening hours step, open in the wizard: Settings' weekly editor over the week the server
 * hands it, with the staff feature the members it gives the week to named above it and a warning
 * where their weeks differ now. Save and continue writes the whole week by a put that keeps the
 * page, busy until it lands, and moves on once it has; a step already done whose week is
 * unchanged offers the wizard's Next and writes nothing, unless nobody the week is for holds hours
 * yet, when the week shown is still to be given. A change is an unsaved edit the wizard asks
 * about, and a week put back to the one handed is no change, in whatever order its windows were
 * added; a week with no window cannot be saved, and a refusal naming a window is shown under it.
 * Without the staff feature and with several members the step only says there is nothing to set.
 */

const inertia = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn(), props: {} as Record<string, unknown>, listeners: new Map<string, (event: Event) => void>() }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: inertia.props }),
    router: {
        put: inertia.put,
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

type HoursStrings = { name: string; help: string; members: string; differs: string; roster: string; empty: string; save: string; saving: string };

const words = keyedSetup();
const hours = stringsOf<HoursStrings>(KEYED_SETUP.steps.hours as unknown as HoursStrings, 'en');

const WEEK: WeeklyWindow[] = [
    { weekday: 1, start: '09:00', end: '17:00' },
    { weekday: 2, start: '09:00', end: '13:00' },
    { weekday: 2, start: '14:00', end: '18:00' },
];

const MEMBERS = ['Elena Papadaki', 'Nikos Georgiou'];

type HoursData = { mode: 'week' | 'members' | 'roster'; week: WeeklyWindow[]; members: string[]; differs: boolean; hourless: boolean };

function setupOf(data: HoursData, done = false): Setup {
    return {
        steps: [
            { key: 'profile', done: true, skipped: false },
            { key: 'hours', done, skipped: false },
            { key: 'payments', done: false, skipped: false },
            { key: 'link', done: false, skipped: false },
        ],
        opened: true,
        opens: null,
        data: { hours: data },
    };
}

type Visit = { preserveState?: boolean; preserveScroll?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

let view: Mounted | null = null;
let moves: StepKey[] = [];

function open(setup: Setup) {
    inertia.put.mockReset();
    inertia.post.mockReset();
    inertia.listeners.clear();
    inertia.props = {
        tenant: { name: 'Luna', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', colour_by: 'staff', messages: [] },
        setup,
    };
    moves = [];
    view = mount();
    view.render(
        <ToastProvider>
            <SetupWizard setup={setup} table={KEYED_SETUP} words={words} step="hours" onStep={(key) => moves.push(key)} onClose={() => {}} />
        </ToastProvider>,
    );
}

afterEach(() => {
    view?.unmount();
    view = null;
});

const q = (selector: string) => document.body.querySelector(selector);

const windowsOn = (weekday: number) => document.body.querySelectorAll(`.admin-weekly__day[data-weekday="${weekday}"] .admin-weekly__window`);

function lastPut(): [string, unknown, Visit] {
    return inertia.put.mock.lastCall as [string, unknown, Visit];
}

/** A refusal's envelope as the server answers it, naming the field given or none. */
function refuse(detail: string, field: string | null) {
    const data = field === null ? { status: 400, detail } : { status: 400, detail, field };
    const event = new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 400, data: { code: 'invalid_rules', message: detail, data } } } });

    act(() => inertia.listeners.get('invalid')?.(event));
}

describe('the Opening hours step', () => {
    it('names the members it gives the week to, warns where their weeks differ, and edits the week handed to it', () => {
        open(setupOf({ mode: 'members', week: WEEK, members: MEMBERS, differs: true, hourless: false }));

        expect(q('.admin-setup-hours__help')?.textContent).toBe(hours.text('help'));
        expect(q('.admin-setup-hours__members')?.textContent).toBe(hours.text('members', { names: listOf(MEMBERS, 'en') }));
        expect(q('.admin-setup-hours__differs')?.textContent).toContain(hours.text('differs'));
        expect([windowsOn(1).length, windowsOn(2).length, windowsOn(3).length]).toEqual([1, 2, 0]);
        expect(q('.admin-setup__primary')?.textContent).toBe(hours.text('save'));
        expect(inertia.put).not.toHaveBeenCalled();
    });

    it('shows the business\'s week alone, with no member named and no warning, where the step gives it to no member', () => {
        open(setupOf({ mode: 'week', week: WEEK, members: [], differs: false, hourless: false }));

        expect(q('.admin-setup-hours__help')?.textContent).toBe(hours.text('help'));
        expect(q('.admin-setup-hours__members')).toBeNull();
        expect(q('.admin-setup-hours__differs')).toBeNull();
        expect(windowsOn(2)).toHaveLength(2);
    });

    it('saves the whole week by a put that keeps the page, busy until it lands, then moves on', () => {
        open(setupOf({ mode: 'members', week: WEEK, members: MEMBERS, differs: false, hourless: false }));

        press(q('.admin-setup__primary'));

        expect(inertia.put).toHaveBeenCalledTimes(1);
        const [url, body, visit] = lastPut();
        expect([url, body]).toEqual([HOURS_URL, { rules: WEEK }]);
        expect(visit).toMatchObject({ preserveState: true, preserveScroll: true });

        act(() => visit.onStart?.());
        expect(q('.admin-setup__primary')?.textContent).toBe(hours.text('saving'));
        expect(moves).toEqual([]);

        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });
        expect(moves).toEqual(['payments']);
    });

    it('offers the wizard\'s Next on a done step whose week is unchanged, the members\' own weeks differing, which moves on and writes nothing', () => {
        open(setupOf({ mode: 'members', week: WEEK, members: MEMBERS, differs: true, hourless: false }, true));

        expect(q('.admin-setup__primary')?.textContent).toBe(words.text('next'));
        press(q('.admin-setup__primary'));

        expect(inertia.put).not.toHaveBeenCalled();
        expect(moves).toEqual(['payments']);
    });

    it('offers Save and continue on a done step whose week is unchanged where nobody holds hours yet, writing the week shown and asking nothing on the way out', () => {
        open(setupOf({ mode: 'members', week: WEEK, members: MEMBERS, differs: false, hourless: true }, true));

        expect(q('.admin-setup__primary')?.textContent).toBe(hours.text('save'));
        expect(q('.admin-setup-hours__differs')).toBeNull();
        press(q('.admin-setup__primary'));

        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(lastPut().slice(0, 2)).toEqual([HOURS_URL, { rules: WEEK }]);

        press(q('.admin-setup__back'));
        expect(q('.admin-setup-discard')).toBeNull();
        expect(moves).toEqual(['profile']);
    });

    it('reads a week put back to the one handed as unchanged, its windows in whatever order they were added', () => {
        open(setupOf({ mode: 'members', week: WEEK, members: MEMBERS, differs: true, hourless: false }, true));

        press(q('.admin-weekly__day[data-weekday="1"] .admin-weekly__remove'));
        expect(q('.admin-setup__primary')?.textContent).toBe(hours.text('save'));

        // The editor adds a day's window after every other, at the times Monday's was handed with.
        press(q('.admin-weekly__day[data-weekday="1"] .admin-weekly__add'));
        expect(q('.admin-setup__primary')?.textContent).toBe(words.text('next'));

        press(q('.admin-setup__primary'));
        expect(inertia.put).not.toHaveBeenCalled();
        expect(moves).toEqual(['payments']);
    });

    it('offers Save and continue again once a done step\'s week is changed, and asks before the change is left', () => {
        open(setupOf({ mode: 'week', week: WEEK, members: [], differs: false, hourless: false }, true));

        press(document.body.querySelector('.admin-weekly__day[data-weekday="2"] .admin-weekly__remove'));

        expect(q('.admin-setup__primary')?.textContent).toBe(hours.text('save'));
        press(q('.admin-setup__back'));
        expect(q('.admin-setup-discard')).not.toBeNull();
        expect(moves).toEqual([]);

        press(q('.admin-setup-discard__keep'));
        press(q('.admin-setup__primary'));
        expect(lastPut()[1]).toEqual({ rules: [WEEK[0], WEEK[2]] });
    });

    it('holds the save while the week has no window, saying why', () => {
        open(setupOf({ mode: 'week', week: [WEEK[0] as WeeklyWindow], members: [], differs: false, hourless: false }));

        press(q('.admin-weekly__remove'));

        expect(document.body.querySelector<HTMLButtonElement>('.admin-setup__primary')?.disabled).toBe(true);
        expect(q('.admin-setup__blocked')?.textContent).toBe(hours.text('empty'));
        press(q('.admin-setup__primary'));
        expect(inertia.put).not.toHaveBeenCalled();
    });

    it('shows a refusal naming a window under it and stays on the step, and says one naming none in an error toast', () => {
        open(setupOf({ mode: 'week', week: WEEK, members: [], differs: false, hourless: false }));

        press(q('.admin-setup__primary'));
        const [, , visit] = lastPut();
        act(() => visit.onStart?.());
        refuse('refused-window', 'rules.2');
        act(() => visit.onFinish?.());

        const second = windowsOn(2)[1];
        expect(second?.querySelector('.admin-field__error')?.textContent).toBe('refused-window');
        expect(windowsOn(2)[0]?.querySelector('.admin-field__error')).toBeNull();
        expect(moves).toEqual([]);
        expect(q('.admin-setup__primary')?.textContent).toBe(hours.text('save'));

        press(q('.admin-setup__primary'));
        const [, , again] = lastPut();
        act(() => again.onStart?.());
        expect(document.body.querySelector('.admin-field__error')).toBeNull();
        act(() => inertia.listeners.get('exception')?.(new Event('exception', { cancelable: true })));
        act(() => again.onFinish?.());

        expect([...document.body.querySelectorAll('.admin-toast[data-kind="error"] p')].map((line) => line.textContent)).toEqual([keyedShell().text(NOT_DONE)]);
    });

    it('says there is nothing to set where the members keep their own hours, with no editor and the wizard\'s Next', () => {
        open(setupOf({ mode: 'roster', week: [], members: [], differs: false, hourless: false }));

        expect(q('.admin-setup-hours__roster')?.textContent).toContain(hours.text('roster'));
        expect(q('.admin-weekly')).toBeNull();
        expect(q('.admin-setup__primary')?.textContent).toBe(words.text('next'));

        press(q('.admin-setup__primary'));
        expect(inertia.put).not.toHaveBeenCalled();
        expect(moves).toEqual(['payments']);
    });
});
