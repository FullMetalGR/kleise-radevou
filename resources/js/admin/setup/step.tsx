import { createContext, type ReactNode, useContext, useLayoutEffect, useMemo, useRef } from 'react';
import { type KeysOf, type Strings, stringsOf, useShell } from '@/lib/strings';
import { type SetupWords, setupWordsOf } from './words';

/*
 * The seam between the setup wizard and the step open in it. The wizard draws the frame, the step
 * list, the footer's Back and Skip for now, and the close; a step draws its panel and nothing
 * else, and talks to the wizard through the hooks here alone, never through props:
 *
 * - useStepPage() hands the step its own data, Home's setup.data under the step's key, and its own
 *   words, the step's group under steps in the setup section, read in the page's language; each
 *   typed by the step itself. Beside them the wizard's own words, for a primary reading Next. A
 *   step borrowing another screen's words reads that section from the page's strings, the server
 *   sending it because the step names it.
 * - useStepPrimary() sets the footer's primary button: its word, what a press does, why it cannot
 *   be pressed now in words (shown beside it, as every disabled control says why), and its busy
 *   word while the step's write is in flight. A step that sets none gets the wizard's Next, which
 *   moves on and records nothing; 'hidden' leaves the footer without one. While the busy word is
 *   set the wizard holds Back, Skip for now and the step list, so the step cannot be left by them
 *   under its own write; the close button and Escape stay live.
 * - useStepDirty() says whether the step holds edits not yet saved: leaving the step or closing
 *   the wizard while it does asks first, and discarding them leaves.
 * - useStepMoves() hands the step saved(), to call once its save has landed, which forgets the
 *   unsaved edits and moves on to the next step, and close(), which closes the wizard at once.
 *   Both act on the wizard as it stands when they are called, and both do nothing once the panel
 *   that took them is off the screen: a write that lands after its step was left, or after the
 *   wizard was closed, moves nobody.
 *
 * A step's write goes the way its full screen's does, a visit that keeps the page and comes back
 * to Home re-read, so the step's fact and data arrive fresh; a refusal naming a field is shown on
 * that field by the house's Field error, and one naming none is said in the house's error toast.
 */

export type Primary = {
    label: string;
    onPress: () => void;
    /** Why the button cannot be pressed now, said beside it; null or absent while it can. */
    blocked?: string | null;
    /** The button's busy word while the step's write is in flight; null or absent while none is. */
    busy?: string | null;
};

/** What the footer's primary is: the step's own, none, or the wizard's Next where null. */
export type PrimaryChoice = Primary | 'hidden' | null;

export type StepHost = {
    data: unknown;
    table: unknown;
    setup: SetupWords;
    setPrimary: (primary: PrimaryChoice) => void;
    setDirty: (dirty: boolean) => void;
    saved: () => void;
    close: () => void;
};

const nothing = (): void => {};

const HostContext = createContext<StepHost>({ data: null, table: null, setup: setupWordsOf(undefined, 'en'), setPrimary: nothing, setDirty: nothing, saved: nothing, close: nothing });

/** The host the wizard hands the step open in it. */
export function StepHostProvider({ host, children }: { host: StepHost; children: ReactNode }) {
    return <HostContext.Provider value={host}>{children}</HostContext.Provider>;
}

/**
 * The step's own data and its own words, as the step types them, null data where the answer
 * carries none; and the wizard's words.
 */
export function useStepPage<Data, Words>(): { data: Data | null; words: Strings<KeysOf<Words>>; setup: SetupWords } {
    const host = useContext(HostContext);
    const language = useShell().language;

    return { data: (host.data ?? null) as Data | null, words: stringsOf<Words>((host.table ?? null) as Words | null, language), setup: host.setup };
}

/** Sets the footer's primary button while the step is open; the wizard's Next where null. */
export function useStepPrimary(primary: PrimaryChoice): void {
    const { setPrimary } = useContext(HostContext);
    const press = useRef<() => void>(nothing);
    const hidden = primary === 'hidden';
    const own = primary !== null && primary !== 'hidden' ? primary : null;
    const label = own?.label ?? null;
    const blocked = own?.blocked ?? null;
    const busy = own?.busy ?? null;

    useLayoutEffect(() => {
        press.current = own?.onPress ?? nothing;
    });

    useLayoutEffect(() => {
        setPrimary(hidden ? 'hidden' : label === null ? null : { label, blocked, busy, onPress: () => press.current() });

        return () => setPrimary(null);
    }, [setPrimary, hidden, label, blocked, busy]);
}

/** Says whether the step holds edits not yet saved, forgotten as the step closes. */
export function useStepDirty(dirty: boolean): void {
    const { setDirty } = useContext(HostContext);

    useLayoutEffect(() => {
        setDirty(dirty);

        return () => setDirty(false);
    }, [setDirty, dirty]);
}

/**
 * The step's two moves: saved(), once its save has landed, and close(). The pair is the same two
 * functions for as long as the component that took them is on screen, each reaching the wizard
 * as it stands at the call, and neither does anything once that component is gone.
 */
export function useStepMoves(): { saved: () => void; close: () => void } {
    const host = useContext(HostContext);
    const latest = useRef(host);
    const live = useRef(true);

    useLayoutEffect(() => {
        latest.current = host;
    });

    useLayoutEffect(() => {
        live.current = true;

        return () => {
            live.current = false;
        };
    }, []);

    return useMemo(() => {
        const whileLive = (move: 'saved' | 'close') => () => {
            if (live.current) {
                latest.current[move]();
            }
        };

        return { saved: whileLive('saved'), close: whileLive('close') };
    }, []);
}
