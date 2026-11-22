import { router } from '@inertiajs/react';
import { useCallback, useMemo, useRef, useState } from 'react';
import { Cross, Tick } from '@/components/glyphs';
import { Button } from '@/components/ui/button';
import { Confirm } from '@/components/ui/confirm';
import { Modal } from '@/components/ui/modal';
import { formatCount } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { useDesktop } from '@/lib/viewport';
import { NOT_DONE, owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import { isClosed, type Setup, type StepFact, type StepKey, skippable } from './model';
import { PANELS } from './panels';
import { type Primary, type PrimaryChoice, type StepHost, StepHostProvider } from './step';
import type { SetupStrings, SetupWords } from './words';

/*
 * The setup wizard: the house modal filling the viewport at every width, the open step's panel in
 * its body, every part kept to the modal's centred column. At the admin's breakpoint and above, the
 * step list runs across the top as round-ended buttons, each its number in a circle and its name,
 * the circle ticked on each step done or skipped and the open one ringed as current, any of them
 * opening its step; below it the list gives way to one line, the step's place among those that
 * apply and its name. The close button is round and carries a cross alone, its name for a screen
 * reader, as a dialog's close is drawn, at the end of the title's row. The footer holds Back, but on the first step; Skip for now on Team and Online payments
 * while still open, which records the skip and moves on; and the primary button, the open step's
 * own or the wizard's Next, which moves on and records nothing. The close button and Escape close
 * the wizard. Leaving a step that says it holds unsaved edits, by any of these or by the list,
 * asks first whether to discard them; the open step's own button in the list goes nowhere, so it
 * asks nothing. While the open step's primary is busy with its write, Back, Skip for now and the
 * list are held, each pointing at the primary, whose busy word says why; the close button and
 * Escape still close. While a skip's post is still out, the primary and Skip for now of whichever
 * step is shown are held under their own words, with no reason to point at, until that post ends:
 * either would send a visit that cancels it. Back, the list, the close button and Escape stay live
 * then. The step is the one drawn: where the step named is not among those that apply, the first
 * is, and it is that step's data and words its panel reads.
 */

/** The primary button, which a control held while it is busy points at for the reason. */
const PRIMARY_ID = 'admin-setup-primary';

/** Skip for now on a step, POST /admin/setup/{step}/skip, body-less. */
const skipUrl = (step: StepKey): string => `/admin/setup/${step}/skip`;

type Props = {
    setup: Setup;
    /** The setup section as the page carries it, each step's own group read from its steps. */
    table: SetupStrings | undefined;
    words: SetupWords;
    step: StepKey;
    onStep: (step: StepKey) => void;
    onClose: () => void;
};

export function SetupWizard({ setup, table, words, step, onStep, onClose }: Props) {
    const [primary, setPrimary] = useState<PrimaryChoice>(null);
    const { asking, setDirty, forget, leave, discard, keep } = useGuard();
    const { at, fact, drawn, next, previous } = placeOf(setup, step);

    const moveOn = useCallback(() => {
        forget();

        if (next === null) {
            onClose();
        } else {
            onStep(next);
        }
    }, [forget, next, onClose, onStep]);

    const host = useMemo<StepHost>(
        () => ({ data: setup.data[drawn] ?? null, table: table?.steps[drawn] ?? null, setup: words, setPrimary, setDirty, saved: moveOn, close: onClose }),
        [setup, table, drawn, words, setDirty, moveOn, onClose],
    );

    const { skipping, skip } = useSkip(moveOn);

    if (fact === undefined) {
        return null;
    }

    const Panel = PANELS[fact.key];
    const shown = primaryOf(primary, { label: words.text('next'), onPress: () => leave(moveOn) });
    const heldBy = (shown?.busy ?? null) === null ? undefined : PRIMARY_ID;

    return (
        <Modal
            open
            title={words.text('title')}
            variant="full"
            className="admin-setup"
            onClose={() => leave(onClose)}
            aside={
                <Button iconOnly icon={<Cross />} className="admin-setup__close relative size-10 after:absolute after:-inset-0.5" onClick={() => leave(onClose)}>
                    {words.text('close')}
                </Button>
            }
            footer={
                <Footer
                    words={words}
                    primary={shown}
                    heldBy={heldBy}
                    skipping={skipping}
                    onBack={previous === null ? null : () => leave(() => onStep(previous))}
                    onSkip={skippable(fact) ? () => leave(() => skip(fact.key)) : null}
                />
            }
        >
            <Heading setup={setup} fact={fact} at={at} words={words} heldBy={heldBy} onChoose={(key) => leave(() => onStep(key))} />
            <StepHostProvider host={host}>
                <Panel key={fact.key} />
            </StepHostProvider>
            <Discard open={asking} words={words} onConfirm={discard} onDismiss={keep} />
        </Modal>
    );
}

/**
 * Where the wizard stands: the step drawn, the first where the one named is not among those that
 * apply, its place among them and the steps either side of it.
 */
function placeOf(setup: Setup, step: StepKey) {
    const at = Math.max(
        0,
        setup.steps.findIndex((fact) => fact.key === step),
    );
    const fact = setup.steps[at];

    return { at, fact, drawn: fact?.key ?? step, next: setup.steps[at + 1]?.key ?? null, previous: setup.steps[at - 1]?.key ?? null };
}

/** The footer's primary: the step's own, the wizard's Next where the step set none, none where it hid it. */
function primaryOf(choice: PrimaryChoice, next: Primary): Primary | null {
    if (choice === 'hidden') {
        return null;
    }

    return choice ?? next;
}

/**
 * Skip for now: the post that records the skip, the move on at once, and whether the post is still
 * out. A visit to any address but the page's own cancels the posts still out beside it, and every
 * write the footer sends is such a visit, so the footer holds its primary and Skip for now while
 * this one is out. The post's start begins the hold and its finish ends it, the finish being run
 * however the post ends: answered, refused, cancelled or cut off. A failure is said in a toast.
 */
function useSkip(moveOn: () => void): { skipping: boolean; skip: (step: StepKey) => void } {
    const shell = useShell();
    const toasts = useToasts();
    const [skipping, setSkipping] = useState(false);

    const skip = (step: StepKey) => {
        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? shell.text(NOT_DONE)), shell);

        router.post(
            skipUrl(step),
            {},
            {
                async: true,
                preserveState: true,
                preserveScroll: true,
                onStart: () => {
                    owned.onStart();
                    setSkipping(true);
                },
                onFinish: () => {
                    owned.onFinish();
                    setSkipping(false);
                },
            },
        );
        moveOn();
    };

    return { skipping, skip };
}

/**
 * The guard on a step's unsaved edits. leave() takes a way out at once, or, while the step says it
 * holds edits, keeps the way out and asks first; discard() then forgets the edits and takes it,
 * keep() stays on the step.
 */
function useGuard() {
    const dirty = useRef(false);
    const [kept, setKept] = useState<(() => void) | null>(null);

    const setDirty = useCallback((value: boolean) => {
        dirty.current = value;
    }, []);

    const forget = useCallback(() => setDirty(false), [setDirty]);

    const leave = (then: () => void) => {
        if (dirty.current) {
            setKept(() => then);
        } else {
            then();
        }
    };

    const discard = () => {
        setKept(null);
        forget();
        kept?.();
    };

    return { asking: kept !== null, setDirty, forget, leave, discard, keep: () => setKept(null) };
}

/** The question asked before a step's unsaved edits are left behind. */
function Discard({ open, words, onConfirm, onDismiss }: { open: boolean; words: SetupWords; onConfirm: () => void; onDismiss: () => void }) {
    return (
        <Confirm
            open={open}
            title={words.text('discard.title')}
            name="admin-setup-discard"
            confirmLabel={words.text('discard.confirm')}
            keepLabel={words.text('discard.keep')}
            busyLabel={words.text('discard.confirm')}
            pending=""
            busy={false}
            onConfirm={onConfirm}
            onDismiss={onDismiss}
        >
            <p className="text-sm">{words.text('discard.body')}</p>
        </Confirm>
    );
}

type HeadingProps = { setup: Setup; fact: StepFact; at: number; words: SetupWords; heldBy: string | undefined; onChoose: (step: StepKey) => void };

/**
 * The step list and the open step's name at the breakpoint and above; below it the one line. Each
 * step's button keeps to one line, its number's circle filled once the step is open or closed and
 * a tick in place of the number once it is closed. The open step's own button goes nowhere, and
 * every button is held while the step's write is in flight.
 *
 * The list stands a pixel below the body's top, so a first-row step's focus outline, drawn five
 * pixels out, is not cut by the scrolling body. In the compact layout each 42px step reaches a
 * 44px touch area a pixel past its top and bottom, well short of the next row.
 */
function Heading({ setup, fact, at, words, heldBy, onChoose }: HeadingProps) {
    const desktop = useDesktop();
    const name = words.text(`steps.${fact.key}.name`);

    if (!desktop) {
        return (
            <h2 className="admin-setup__position mb-4 flex flex-wrap items-baseline gap-x-2">
                <span className="text-body text-muted-foreground">{words.text('position', { number: formatCount(at + 1, words.locale), total: formatCount(setup.steps.length, words.locale) })}</span>
                <span className="admin-setup__name text-card-title">{name}</span>
            </h2>
        );
    }

    return (
        <>
            <nav className="admin-setup__steps mb-4 pt-px" aria-label={words.text('list')}>
                <ol className="flex flex-wrap gap-2">
                    {setup.steps.map((each, index) => (
                        <li key={each.key}>
                            <button
                                type="button"
                                className="admin-setup__step focus-ring relative inline-flex items-center gap-2.5 rounded-full bg-card py-1.5 pr-4 pl-1.5 text-body font-semibold whitespace-nowrap inset-ring-[1.5px] inset-ring-border enabled:hover:not-aria-[current=step]:inset-ring-ink-tertiary disabled:cursor-not-allowed disabled:opacity-60 aria-[current=step]:inset-ring-2 aria-[current=step]:inset-ring-primary max-wide:after:absolute max-wide:after:inset-x-0 max-wide:after:-inset-y-px motion-safe:transition motion-safe:duration-200 motion-safe:ease-soft"
                                aria-current={each.key === fact.key ? 'step' : undefined}
                                data-step={each.key}
                                disabled={heldBy !== undefined}
                                aria-describedby={heldBy}
                                onClick={each.key === fact.key ? undefined : () => onChoose(each.key)}
                            >
                                <span
                                    className={cn(
                                        'admin-setup__number flex size-7.5 shrink-0 items-center justify-center rounded-full font-display text-label',
                                        each.key === fact.key || isClosed(each) ? 'bg-primary text-primary-foreground' : 'bg-muted text-primary-deep',
                                    )}
                                    aria-hidden="true"
                                >
                                    {isClosed(each) ? <Tick /> : formatCount(index + 1, words.locale)}
                                </span>
                                {words.text(`steps.${each.key}.name`)}
                                {isClosed(each) && <span className="sr-only">{words.text(each.done ? 'done' : 'skipped')}</span>}
                            </button>
                        </li>
                    ))}
                </ol>
            </nav>
            <h2 className="admin-setup__name mb-3 text-section-title">{name}</h2>
        </>
    );
}

type FooterProps = {
    words: SetupWords;
    primary: Primary | null;
    heldBy: string | undefined;
    /** A skip's post is still out. */
    skipping: boolean;
    onBack: (() => void) | null;
    onSkip: (() => void) | null;
};

/**
 * Back, Skip for now and the primary, each where the step has it; the primary's reason beside it
 * while it cannot be pressed, and Back and Skip for now held while it is busy. While a skip's post
 * is out, Skip for now and the primary are held as well, Back left live and nothing pointed at.
 */
function Footer({ words, primary, heldBy, skipping, onBack, onSkip }: FooterProps) {
    const blocked = primary?.blocked ?? null;
    const held = heldBy !== undefined;

    return (
        <>
            {blocked !== null && (
                <p id="admin-setup-blocked" className="admin-setup__blocked mr-auto self-center text-sm text-muted-foreground max-desktop:mr-0 max-desktop:basis-full">
                    {blocked}
                </p>
            )}
            {onBack !== null && (
                <Button className="admin-setup__back" disabled={held} aria-describedby={heldBy} onClick={onBack}>
                    {words.text('back')}
                </Button>
            )}
            {onSkip !== null && (
                <Button className="admin-setup__skip" disabled={held || skipping} aria-describedby={heldBy} onClick={onSkip}>
                    {words.text('skip')}
                </Button>
            )}
            {primary !== null && <PrimaryButton primary={primary} held={skipping} />}
        </>
    );
}

/**
 * The primary: held while the step says why it cannot be pressed, the reason pointed at; held under
 * its own word, pointing at nothing, while a skip's post is out; busy under the step's word while
 * its write is in flight.
 */
function PrimaryButton({ primary, held }: { primary: Primary; held: boolean }) {
    const blocked = (primary.blocked ?? null) !== null;
    const busy = primary.busy ?? null;

    return (
        <Button id={PRIMARY_ID} variant="primary" className="admin-setup__primary" disabled={blocked || held} aria-describedby={blocked ? 'admin-setup-blocked' : undefined} busy={busy !== null} busyLabel={busy ?? ''} onClick={primary.onPress}>
            {primary.label}
        </Button>
    );
}
