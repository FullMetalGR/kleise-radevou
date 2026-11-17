import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useShell } from '@/lib/strings';
import { useToasts } from '@/toast/toasts';
import { firstOpen, type Setup, type StepKey } from './model';
import { SetupBanner } from './SetupBanner';
import { SetupWizard } from './SetupWizard';
import { type SetupStrings, setupWordsOf } from './words';

/*
 * Setup on Home, for an owner Home's answer carries setup to: the banner above Home's content, and
 * the wizard over the page, open on the step the answer Home is first drawn with names to open on
 * by itself, or on the first open step from Continue. That step is taken once, as Home comes up:
 * an answer arriving later moves nothing, whatever it names, and the step is then spent in the
 * page the browser keeps, so that page restored from history does not bring the wizard up again.
 * The first time the wizard is on screen while the business has no record of it coming up, the
 * page records that, an async visit that keeps the page as the Copy button's record does, so an
 * answer fetched and never shown does not spend the opening; a record that did not land is made
 * again at the next step. When an answer comes back with no setup while the wizard is open, the
 * wizard closes; it says setup is complete, in the words the answer before it carried, only where
 * the answer says setup was finished just now, so an owner whose setup was taken away for another
 * reason, or finished elsewhere with no wizard up here, is told nothing. When the wizard closes
 * with the banner still there, focus goes to the banner's Continue: the modal hands it back to
 * what held it as the wizard came up, which is the page itself when the wizard came up by itself.
 */

/** The wizard came up by itself, POST /admin/setup/opened, body-less. */
const OPENED_URL = '/admin/setup/opened';

type Props = {
    setup: Setup | undefined;
    strings: SetupStrings | undefined;
    /** Whether this answer is the one that recorded setup as finished. */
    finished: boolean;
};

export function SetupOnHome({ setup, strings, finished }: Props) {
    const words = setupWordsOf({ setup: strings }, useShell().language);
    const [step, setStep] = useOpenStep(setup);
    const close = useCallback(() => setStep(null), [setStep]);
    const continueButton = useFocusOnceClosed(step !== null);

    useWithdrawal({ offered: setup !== undefined, up: step !== null, finished, complete: strings === undefined ? null : words.text('complete'), close });

    if (setup === undefined) {
        return null;
    }

    return (
        <>
            <SetupBanner setup={setup} words={words} onContinue={() => setStep(firstOpen(setup))} continueRef={continueButton} />
            {step !== null && <SetupWizard setup={setup} table={strings} words={words} step={step} onStep={setStep} onClose={close} />}
        </>
    );
}

/** The page's props with the step to open on spent, every other prop the object it was. */
function spent<Carried extends { setup?: Setup }>(props: Carried): Carried {
    return props.setup === undefined || props.setup.opens === null ? props : { ...props, setup: { ...props.setup, opens: null } };
}

/**
 * The step the wizard is open on, null while it is closed: the one the first answer names, then
 * whatever the owner moves to. Spends that step in the kept page, and records the wizard coming
 * up while the business has no record of it, again at the next step where the record did not land.
 */
function useOpenStep(setup: Setup | undefined): [StepKey | null, (step: StepKey | null) => void] {
    const opens = setup?.opens ?? null;
    const [step, setStep] = useState<StepKey | null>(opens);
    const unrecorded = setup !== undefined && !setup.opened;
    const recorded = useRef(false);

    useEffect(() => {
        if (opens !== null) {
            router.replace<{ setup?: Setup }>({ preserveState: true, preserveScroll: true, props: spent });
        }
    }, [opens]);

    useEffect(() => {
        if (step === null || !unrecorded || recorded.current) {
            return;
        }

        let landed = false;

        recorded.current = true;
        router.post(
            OPENED_URL,
            {},
            {
                async: true,
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    landed = true;
                },
                onFinish: () => {
                    recorded.current = landed;
                },
            },
        );
    }, [step, unrecorded]);

    return [step, setStep];
}

/** The ref of the control that takes the focus once the wizard, having been up, is closed. */
function useFocusOnceClosed(up: boolean) {
    const control = useRef<HTMLButtonElement>(null);
    const wasUp = useRef(false);

    useEffect(() => {
        const closed = wasUp.current && !up;

        wasUp.current = up;

        if (!closed) {
            return undefined;
        }

        // After the modal's own hand-back, which runs a task after it unmounts.
        const later = setTimeout(() => control.current?.focus(), 0);

        return () => clearTimeout(later);
    }, [up]);

    return control;
}

type Withdrawal = {
    /** Whether the answer carries setup. */
    offered: boolean;
    /** Whether the wizard is up. */
    up: boolean;
    finished: boolean;
    /** The sentence that says setup is complete, null on an answer that carries no words for it. */
    complete: string | null;
    close: () => void;
};

/**
 * Closes the wizard once an answer carries no setup while it is up, and says setup is complete
 * where that answer finished it, in the last sentence an answer carried.
 */
function useWithdrawal({ offered, up, finished, complete, close }: Withdrawal): void {
    const toasts = useToasts();
    const upWithSetup = useRef(false);
    const sentence = useRef(complete);

    useEffect(() => {
        sentence.current = complete ?? sentence.current;
    });

    // Read against the render before as well: a step's own close, called as its write lands, can
    // reach the page in the same render as the answer with no setup.
    useEffect(() => {
        if (!offered && (up || upWithSetup.current)) {
            close();

            if (finished && sentence.current !== null) {
                toasts.push('success', sentence.current);
            }
        }

        upWithSetup.current = offered && up;
    }, [offered, up, finished, toasts, close]);
}
