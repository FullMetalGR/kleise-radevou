import { act, type ComponentType, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { key, mount, type Mounted, onPhone, press } from '@/components/ui/harness';
import { formatCount } from '@/lib/format';
import { KEYED_SETUP, keyedSetup } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { Setup, StepKey } from './model';
import { useStepDirty, useStepMoves, useStepPage, useStepPrimary } from './step';
import { SetupWizard } from './SetupWizard';

/*
 * The setup wizard and the seam a step talks to it through. The frame fills the viewport; at the
 * breakpoint and above the steps that apply run across the top as buttons, a tick and its state on
 * each closed one and the open one current, below it one line names the step's place and its name.
 * The footer holds Back but on the first step, Skip for now on Team and Online payments while they
 * are open, and the primary: the wizard's Next unless the step sets its own. While the open step's
 * write is in flight Back, Skip for now and the list are held, the close button and Escape not.
 * While a skip's post is out it is the other way round: the primary and Skip for now of the step
 * shown are held, each under its own word, and Back, the list, the close button and Escape are
 * not. A step stands in for each real one here where the test needs one that sets its own primary,
 * holds unsaved edits or reports its save; the rest stand empty, each real panel having its own
 * file's cases, but the Booking link step's, which is the real one. Every word is read through the
 * keyed setup section, so no sentence is spelled here.
 *
 * The router stands in too. A post starts as the framework starts one, its start callback run
 * before the call returns, and ends only when a test runs its finish callback, the one the
 * framework runs however a post ends. The failure listeners a visit puts on are kept by name while
 * they are on.
 */

type Callbacks = { onStart?: () => void; onFinish?: () => void };

const inertia = vi.hoisted(() => ({ post: vi.fn(), heard: [] as string[] }));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: inertia.post,
        on: (type: string) => {
            inertia.heard.push(type);

            return () => {
                inertia.heard = inertia.heard.filter((each) => each !== type);
            };
        },
    },
}));

const stand = vi.hoisted(() => ({ panels: {} as Partial<Record<string, ComponentType>> }));

vi.mock('./panels', async () => {
    const real = await vi.importActual<typeof import('./panels')>('./panels');

    return {
        PANELS: Object.fromEntries(
            Object.entries(real.PANELS).map(([step, Real]) => [
                step,
                function Standing() {
                    const Own = stand.panels[step];

                    if (Own !== undefined) {
                        return <Own />;
                    }

                    return step === 'link' ? <Real /> : null;
                },
            ]),
        ),
    };
});

const words = keyedSetup();

const HOSTED = 'http://luna.localhost/';

/** Setup with the staff feature: Services done, Team skipped, the rest open. */
function setupOf(patch: Partial<Setup> = {}): Setup {
    return {
        steps: [
            { key: 'profile', done: false, skipped: false },
            { key: 'services', done: true, skipped: false },
            { key: 'team', done: false, skipped: true },
            { key: 'hours', done: false, skipped: false },
            { key: 'payments', done: false, skipped: false },
            { key: 'link', done: false, skipped: false },
        ],
        opened: true,
        opens: null,
        data: { link: { hosted_url: HOSTED } },
        ...patch,
    };
}

/** The wizard as Home holds it: the open step and whether it is up in state, each move recorded. */
function Holder({ setup, from, moves }: { setup: Setup; from: StepKey; moves: StepKey[] }) {
    const [step, setStep] = useState<StepKey | null>(from);

    if (step === null) {
        return <p className="closed" />;
    }

    return (
        <SetupWizard
            setup={setup}
            table={KEYED_SETUP}
            words={words}
            step={step}
            onStep={(next) => {
                moves.push(next);
                setStep(next);
            }}
            onClose={() => setStep(null)}
        />
    );
}

let view: Mounted;
let moves: StepKey[];
let undo: (() => void) | null;

beforeEach(() => {
    inertia.post.mockReset();
    inertia.post.mockImplementation((_url: string, _body: unknown, options: Callbacks = {}) => options.onStart?.());
    inertia.heard = [];
    stand.panels = {};
    moves = [];
    undo = null;
    view = mount();
});

afterEach(() => {
    view.unmount();
    undo?.();
});

function open(from: StepKey, setup: Setup = setupOf()): void {
    view.render(
        <ToastProvider>
            <Holder setup={setup} from={from} moves={moves} />
        </ToastProvider>,
    );
}

/** Setup with Team open again, so its footer offers Skip for now. */
const UNSKIPPED = setupOf({ steps: setupOf().steps.map((fact) => (fact.key === 'team' ? { ...fact, skipped: false } : fact)) });

/** A step holding unsaved edits. */
function Editing() {
    useStepDirty(true);

    return null;
}

const q = (selector: string) => document.body.querySelector(selector);
const texts = (selector: string) => [...document.body.querySelectorAll(selector)].map((el) => el.textContent ?? '');
const closed = () => view.host.querySelector('.closed') !== null;
const button = (selector: string) => q(selector) as HTMLButtonElement;

/** Ends the last post sent, by its finish callback alone. */
const finishPost = () => act(() => (inertia.post.mock.lastCall as [string, unknown, Callbacks])[2].onFinish?.());

describe('the setup wizard', () => {
    it('fills the viewport, titled as setup, the close button on the title\'s row', () => {
        open('profile');

        expect(q('.admin-setup')?.classList.contains('admin-modal__frame--full')).toBe(true);
        expect(q('.admin-modal__title')?.textContent).toBe(words.text('title'));
        expect(q('.admin-modal__head .admin-setup__close')?.textContent).toBe(words.text('close'));
    });

    it('lists the steps that apply as buttons in order at the breakpoint and above, a tick and its state on each closed one, the open one current, its name under the list', () => {
        open('hours');

        expect(q('.admin-setup__steps')?.getAttribute('aria-label')).toBe(words.text('list'));
        const steps = [...document.body.querySelectorAll<HTMLButtonElement>('.admin-setup__step')];
        expect(steps.map((step) => step.dataset['step'])).toEqual(['profile', 'services', 'team', 'hours', 'payments', 'link']);
        expect(steps.map((step) => step.querySelector('svg') !== null)).toEqual([false, true, true, false, false, false]);
        expect(texts('.admin-setup__step .sr-only')).toEqual([words.text('done'), words.text('skipped')]);
        expect(steps.map((step) => step.getAttribute('aria-current'))).toEqual([null, null, null, 'step', null, null]);
        expect(q('h2.admin-setup__name')?.textContent).toBe(words.text('steps.hours.name'));
        expect(q('.admin-setup__position')).toBeNull();
    });

    it('opens the step pressed in the list', () => {
        open('profile');

        press(q('.admin-setup__step[data-step="payments"]'));

        expect(moves).toEqual(['payments']);
        expect(q('.admin-setup__step[aria-current="step"]')?.getAttribute('data-step')).toBe('payments');
    });

    it('gives the list way to one line below the breakpoint: the step\'s place among those that apply, then its name', () => {
        undo = onPhone();
        open('hours', setupOf({ steps: setupOf().steps.filter((fact) => fact.key !== 'team') }));

        expect(q('.admin-setup__steps')).toBeNull();
        expect(q('.admin-setup__position')?.firstElementChild?.textContent).toBe(words.text('position', { number: formatCount(3, words.locale), total: formatCount(5, words.locale) }));
        expect(q('.admin-setup__position .admin-setup__name')?.textContent).toBe(words.text('steps.hours.name'));
    });

    it('holds Back but on the first step, and the wizard\'s Next on a step that sets no primary of its own, which moves on and records nothing', () => {
        open('profile');

        expect(q('.admin-setup__back')).toBeNull();
        expect(q('.admin-setup__primary')?.textContent).toBe(words.text('next'));

        press(q('.admin-setup__primary'));
        expect(moves).toEqual(['services']);
        expect(q('.admin-setup__back')?.textContent).toBe(words.text('back'));

        press(q('.admin-setup__back'));
        expect(moves).toEqual(['services', 'profile']);
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('offers Skip for now on Team and Online payments while they are open, and on no other step', () => {
        const offered = (from: StepKey, setup: Setup) => {
            view.render(<p />);
            open(from, setup);

            return q('.admin-setup__skip')?.textContent ?? null;
        };
        const unskipped = setupOf({ steps: setupOf().steps.map((fact) => (fact.key === 'team' ? { ...fact, skipped: false } : fact)) });

        expect(offered('team', unskipped)).toBe(words.text('skip'));
        expect(offered('payments', unskipped)).toBe(words.text('skip'));
        expect(offered('team', setupOf())).toBeNull();
        expect(offered('profile', unskipped)).toBeNull();
        expect(offered('hours', unskipped)).toBeNull();
        expect(offered('link', unskipped)).toBeNull();
    });

    it('records a skip by an async post that keeps the page, and moves on at once', () => {
        open('payments');

        press(q('.admin-setup__skip'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, body, options] = inertia.post.mock.lastCall as [string, unknown, Record<string, unknown>];
        expect([url, body]).toEqual(['/admin/setup/payments/skip', {}]);
        expect(options).toMatchObject({ async: true, preserveState: true, preserveScroll: true });
        expect(moves).toEqual(['link']);
    });

    it('holds the primary and Skip for now of the step shown after a skip, each under its own word and pointing at no reason, until the skip\'s post has finished', () => {
        // Online payments follows Team here, so the step shown after the skip offers a skip of its own.
        const adjacent = (setup: Setup): Setup => ({ ...setup, steps: setup.steps.filter((fact) => fact.key !== 'hours') });
        const footer = () => ['.admin-setup__primary', '.admin-setup__skip'].map((selector) => [button(selector).textContent, button(selector).disabled, button(selector).getAttribute('aria-describedby')]);

        open('team', adjacent(UNSKIPPED));
        press(q('.admin-setup__skip'));
        expect(moves).toEqual(['payments']);
        expect(footer()).toEqual([
            [words.text('next'), true, null],
            [words.text('skip'), true, null],
        ]);

        press(q('.admin-setup__primary'));
        press(q('.admin-setup__skip'));
        expect(moves).toEqual(['payments']);
        expect(inertia.post).toHaveBeenCalledTimes(1);

        // The answer's steps reach the page before the post finishes: Team reads skipped, the hold stands.
        open('team', adjacent(setupOf()));
        expect(footer().map(([, disabled]) => disabled)).toEqual([true, true]);

        finishPost();
        expect(footer()).toEqual([
            [words.text('next'), false, null],
            [words.text('skip'), false, null],
        ]);

        press(q('.admin-setup__skip'));
        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(inertia.post.mock.lastCall?.[0]).toBe('/admin/setup/payments/skip');
        expect(moves).toEqual(['payments', 'link']);
    });

    it('ends the hold when the skip\'s post finishes without landing, as one cancelled or cut off does, the failure listeners it put on taken off with it', () => {
        const finish = (KEYED_SETUP.steps.link as unknown as { finish: string }).finish;
        const primary = () => [button('.admin-setup__primary').textContent, button('.admin-setup__primary').disabled];

        open('payments');
        press(q('.admin-setup__skip'));
        expect(moves).toEqual(['link']);
        expect(primary()).toEqual([finish, true]);
        expect(inertia.heard).toEqual(['invalid', 'exception']);

        press(q('.admin-setup__primary'));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        finishPost();
        expect(primary()).toEqual([finish, false]);
        expect(inertia.heard).toEqual([]);

        press(q('.admin-setup__primary'));
        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(inertia.post.mock.lastCall?.[0]).toBe('/admin/setup/finish');
    });

    it('leaves Back, the step list, the close button and Escape live while a skip\'s post is out, and takes the post\'s finish in silence once the wizard is closed', () => {
        const said = [vi.spyOn(console, 'error').mockImplementation(() => {}), vi.spyOn(console, 'warn').mockImplementation(() => {})];
        undo = () => said.forEach((spy) => spy.mockRestore());
        const live = () => [...document.body.querySelectorAll<HTMLButtonElement>('.admin-setup__back, .admin-setup__step, .admin-setup__close')];

        open('team', UNSKIPPED);
        press(q('.admin-setup__skip'));
        expect(button('.admin-setup__primary').disabled).toBe(true);
        expect(live()).toHaveLength(8);
        expect(live().map((each) => [each.disabled, each.getAttribute('aria-describedby')])).toEqual(live().map(() => [false, null]));

        // Back on Team, still open while the skip's answer is out, its own Skip for now is the one held.
        press(q('.admin-setup__back'));
        expect(moves).toEqual(['hours', 'team']);
        expect(button('.admin-setup__skip').disabled).toBe(true);

        press(q('.admin-setup__step[data-step="link"]'));
        expect(moves).toEqual(['hours', 'team', 'link']);

        press(q('.admin-setup__close'));
        expect(closed()).toBe(true);

        finishPost();
        expect(said.map((spy) => spy.mock.calls)).toEqual([[], []]);

        view.render(<p />);
        open('team', UNSKIPPED);
        press(q('.admin-setup__skip'));
        key(document.body, 'Escape');
        expect(closed()).toBe(true);
    });

    it('closes by its close button and by Escape', () => {
        open('profile');
        press(q('.admin-setup__close'));
        expect(closed()).toBe(true);

        view.render(<p />);
        open('services');
        key(document.body, 'Escape');
        expect(closed()).toBe(true);
    });
});

describe('a step in the wizard', () => {
    it('reads its own data and its own group of the setup section', () => {
        const seen: Array<[unknown, string]> = [];

        stand.panels = {
            link: function Reading() {
                const { data, words: own } = useStepPage<{ hosted_url: string }, { name: string; help: string }>();

                seen.push([data, own.text('help')]);

                return null;
            },
        };
        open('link');

        expect(seen.at(-1)).toEqual([{ hosted_url: HOSTED }, (KEYED_SETUP.steps.link as unknown as { help: string }).help]);
    });

    it('sets its own primary, the reason it is held said beside it, its busy word while its write is in flight; hidden leaves none', () => {
        const pressed = vi.fn();
        let state: 'held' | 'free' | 'busy' | 'hidden' = 'held';

        stand.panels = {
            services: function Own() {
                useStepPrimary(state === 'hidden' ? 'hidden' : { label: 'Own primary', onPress: pressed, blocked: state === 'held' ? 'Held for a reason' : null, busy: state === 'busy' ? 'Working' : null });

                return null;
            },
        };
        open('services');

        const primary = () => q('.admin-setup__primary') as HTMLButtonElement | null;
        expect(primary()?.textContent).toBe('Own primary');
        expect(primary()?.disabled).toBe(true);
        expect(q('#admin-setup-blocked')?.textContent).toBe('Held for a reason');
        expect(primary()?.getAttribute('aria-describedby')).toBe('admin-setup-blocked');

        state = 'free';
        open('services');
        expect(q('#admin-setup-blocked')).toBeNull();
        press(primary());
        expect(pressed).toHaveBeenCalledTimes(1);
        expect(moves).toEqual([]);

        state = 'busy';
        open('services');
        expect(primary()?.textContent).toBe('Working');

        state = 'hidden';
        open('services');
        expect(primary()).toBeNull();
        expect(q('.admin-setup__back')).not.toBeNull();
    });

    it('moves on once its save has landed, and closes the wizard where it is the last step', () => {
        stand.panels = {
            profile: function Saving() {
                const { saved } = useStepMoves();

                useStepPrimary({ label: 'Save', onPress: saved });

                return null;
            },
            link: function Last() {
                const { saved } = useStepMoves();

                useStepPrimary({ label: 'Save', onPress: saved });

                return null;
            },
        };
        open('profile');

        press(q('.admin-setup__primary'));
        expect(moves).toEqual(['services']);

        view.render(<p />);
        open('link');
        press(q('.admin-setup__primary'));
        expect(closed()).toBe(true);
    });

    it('hands the wizard\'s Next back when it leaves, its own primary gone with it', () => {
        stand.panels = {
            profile: function Own() {
                useStepPrimary({ label: 'Own primary', onPress: () => {} });

                return null;
            },
        };
        open('profile');
        expect(q('.admin-setup__primary')?.textContent).toBe('Own primary');

        press(q('.admin-setup__step[data-step="hours"]'));
        expect(q('.admin-setup__primary')?.textContent).toBe(words.text('next'));
    });

    it('asks before leaving it while it holds unsaved edits, by Next, Back, Skip for now, the list, the close button and Escape; Keep editing stays, Discard changes leaves', () => {
        stand.panels = { team: Editing };
        const unskipped = UNSKIPPED;
        const leaves: Array<[string, () => void]> = [
            ['next', () => press(q('.admin-setup__primary'))],
            ['back', () => press(q('.admin-setup__back'))],
            ['skip', () => press(q('.admin-setup__skip'))],
            ['list', () => press(q('.admin-setup__step[data-step="link"]'))],
            ['close', () => press(q('.admin-setup__close'))],
            ['escape', () => key(document.body, 'Escape')],
        ];

        for (const [, leave] of leaves) {
            view.render(<p />);
            moves = [];
            open('team', unskipped);

            leave();
            expect(q('.admin-setup-discard .admin-modal__title')?.textContent).toBe(words.text('discard.title'));
            expect(q('.admin-setup-discard .admin-modal__body')?.textContent).toBe(words.text('discard.body'));
            expect(q('.admin-setup-discard__keep')?.textContent).toBe(words.text('discard.keep'));
            expect(q('.admin-setup-discard__confirm')?.textContent).toBe(words.text('discard.confirm'));
            expect(moves).toEqual([]);
            expect(closed()).toBe(false);

            press(q('.admin-setup-discard__keep'));
            expect(q('.admin-setup-discard')).toBeNull();
            expect(q('.admin-setup__step[aria-current="step"]')?.getAttribute('data-step')).toBe('team');
            expect(inertia.post).not.toHaveBeenCalled();
        }

        const after = new Map<string, () => boolean>([
            ['next', () => moves.at(-1) === 'hours'],
            ['back', () => moves.at(-1) === 'services'],
            ['skip', () => moves.at(-1) === 'hours' && inertia.post.mock.lastCall?.[0] === '/admin/setup/team/skip'],
            ['list', () => moves.at(-1) === 'link'],
            ['close', closed],
            ['escape', closed],
        ]);

        for (const [name, leave] of leaves) {
            view.render(<p />);
            moves = [];
            open('team', unskipped);

            leave();
            press(q('.admin-setup-discard__confirm'));
            expect(q('.admin-setup-discard')).toBeNull();
            expect([name, after.get(name)?.()]).toEqual([name, true]);
        }
    });

    it('does nothing when the open step\'s own button in the list is pressed, asking nothing and still asking about its unsaved edits after', () => {
        stand.panels = { team: Editing };
        open('team', UNSKIPPED);

        press(q('.admin-setup__step[data-step="team"]'));
        expect(q('.admin-setup-discard')).toBeNull();
        expect(moves).toEqual([]);

        press(q('.admin-setup__close'));
        expect(q('.admin-setup-discard')).not.toBeNull();
        expect(closed()).toBe(false);
    });

    it('closes the question alone on Escape, the wizard left up on its step and the unsaved edits still asked about', () => {
        stand.panels = { team: Editing };
        open('team', UNSKIPPED);

        press(q('.admin-setup__back'));
        expect(q('.admin-setup-discard')).not.toBeNull();

        key(document.body, 'Escape');
        expect(q('.admin-setup-discard')).toBeNull();
        expect(closed()).toBe(false);
        expect(moves).toEqual([]);
        expect(q('.admin-setup__step[aria-current="step"]')?.getAttribute('data-step')).toBe('team');

        press(q('.admin-setup__close'));
        expect(q('.admin-setup-discard')).not.toBeNull();
    });

    it('moves nothing by a save that lands once its step was left, or once the wizard was closed', () => {
        let taken: { saved: () => void; close: () => void } | null = null;

        stand.panels = {
            profile: function Saving() {
                taken = useStepMoves();

                return null;
            },
        };
        const late = (move: 'saved' | 'close') => act(() => (taken as { saved: () => void; close: () => void } | null)?.[move]());

        open('profile');
        press(q('.admin-setup__step[data-step="link"]'));
        expect(moves).toEqual(['link']);

        late('saved');
        expect(moves).toEqual(['link']);
        late('close');
        expect(closed()).toBe(false);

        view.render(<p />);
        moves = [];
        open('profile');
        press(q('.admin-setup__close'));
        expect(closed()).toBe(true);

        late('saved');
        expect(moves).toEqual([]);
        expect(closed()).toBe(true);
    });

    it('moves on by the steps as they stand when its save lands, not as they stood when the save was taken', () => {
        let taken: { saved: () => void } | null = null;

        stand.panels = {
            services: function Saving() {
                taken = useStepMoves();

                return null;
            },
        };
        open('services');
        const before = taken as { saved: () => void } | null;

        open('services', setupOf({ steps: setupOf().steps.filter((fact) => fact.key !== 'team') }));
        act(() => before?.saved());

        expect(moves).toEqual(['hours']);
    });

    it('holds Back, Skip for now and the step list while its write is in flight, each pointing at the busy word, the close button and Escape still closing', () => {
        let busy = false;

        stand.panels = {
            team: function Writing() {
                useStepPrimary({ label: 'Save', onPress: () => {}, busy: busy ? 'Saving' : null });

                return null;
            },
        };
        const held = () => [...document.body.querySelectorAll<HTMLButtonElement>('.admin-setup__back, .admin-setup__skip, .admin-setup__step')];

        open('team', UNSKIPPED);
        expect(held()).toHaveLength(8);
        expect(held().map((button) => [button.disabled, button.getAttribute('aria-describedby')])).toEqual(held().map(() => [false, null]));

        busy = true;
        open('team', UNSKIPPED);
        expect(q('#admin-setup-primary')?.textContent).toBe('Saving');
        expect(held().map((button) => [button.disabled, button.getAttribute('aria-describedby')])).toEqual(held().map(() => [true, 'admin-setup-primary']));

        press(q('.admin-setup__back'));
        press(q('.admin-setup__skip'));
        press(q('.admin-setup__step[data-step="link"]'));
        expect(moves).toEqual([]);
        expect(inertia.post).not.toHaveBeenCalled();
        expect((q('.admin-setup__close') as HTMLButtonElement).disabled).toBe(false);

        press(q('.admin-setup__close'));
        expect(closed()).toBe(true);

        view.render(<p />);
        open('team', UNSKIPPED);
        key(document.body, 'Escape');
        expect(closed()).toBe(true);
    });

    it('hands the step that is drawn its own data and its own words where the step named is not among those that apply', () => {
        const seen: Array<[unknown, string]> = [];

        stand.panels = {
            profile: function Reading() {
                const { data, words: own } = useStepPage<{ about: string }, { name: string }>();

                seen.push([data, own.text('name')]);

                return null;
            },
        };
        open('team', setupOf({ steps: setupOf().steps.filter((fact) => fact.key !== 'team'), data: { profile: { about: 'Haircuts' }, link: { hosted_url: HOSTED } } }));

        expect(q('.admin-setup__step[aria-current="step"]')?.getAttribute('data-step')).toBe('profile');
        expect(seen.at(-1)).toEqual([{ about: 'Haircuts' }, KEYED_SETUP.steps.profile.name]);
    });
});
