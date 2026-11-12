import { useEffect, useRef, type ReactNode } from 'react';
import type { Catalog } from './catalog';
import { backTargetOf, stripOf, type FlowState, type Step, type StripName } from './flow';
import { BackIcon, OnwardIcon } from './icons';
import { useWords, type LineKey } from './words';

/*
 * The crumb, above the step on service, staff, when and details: the round back control, the one
 * Back, then the text trail of this journey's steps before review, Services, Professional, Time
 * and Details, Professional only once some segment's service lists staff, and Confirm at its end,
 * which is never a button.
 *
 * A finished step is a live button back to itself, a customer-initiated move whose target is one
 * Back's table reaches (stripOf), so nothing resets on the way (1.5) and focus lands on the
 * container (7.4). The back control goes to the last of them, the target Back's table names from
 * here, and renders only where there is one. While a hold flies both are disabled, said on the
 * busy submit beside them. The current step carries aria-current="step" and the ones ahead are
 * disabled: forward travel is Continue's and a pick's alone. Each disabled step says why in its
 * own text, words the stylesheet hides from sight and keeps for assistive technology. The answers
 * live in the summary card, so the crumb holds none; it carries no live region and announces
 * nothing.
 *
 * At a phone's width the trail is one line that scrolls sideways, so when the step changes the
 * current crumb is brought into view by scrolling the trail alone: the trail's own scroll offset
 * is written, never a scrollIntoView, which could scroll the host page to an embed below the
 * fold. Where the trail does not overflow the write changes nothing.
 */

export const STRIP_LABEL: LineKey = 'strip.label';

export const STRIP_NAMES: Readonly<Record<StripName, LineKey>> = {
    service: 'strip.names.service',
    staff: 'strip.names.staff',
    when: 'strip.names.when',
    details: 'strip.names.details',
};

/** The trail's last entry, the review step, which the crumb names and never offers. */
export const STRIP_CONFIRM: LineKey = 'strip.confirm';

/** The back control's name. */
export const BACK_LABEL: LineKey = 'strip.back';

/** What the current step says within itself, beside its aria-current. */
export const STRIP_CURRENT: LineKey = 'strip.current';

/** What a step not reached yet says within itself. */
export const STRIP_AHEAD: LineKey = 'strip.ahead';

interface StripProps {
    readonly state: Pick<FlowState, 'step' | 'segments' | 'start'>;
    readonly catalog: Catalog | undefined;
    /** A hold in flight, which disables the back control and the finished steps. */
    readonly busy: boolean;
    readonly onGo: (step: Step) => void;
}

export function Strip({ state, catalog, busy, onGo }: StripProps): ReactNode {
    const { text } = useWords();
    const steps = stripOf(state.step, state.segments, catalog, state.start);
    const trail = useRef<HTMLOListElement>(null);

    useEffect(() => {
        const list = trail.current;
        const current = list?.querySelector<HTMLElement>('[aria-current="step"]');

        if (list !== null && list !== undefined && current !== null && current !== undefined) {
            list.scrollLeft = offsetToCentre(list, current);
        }
    }, [state.step]);

    if (steps.length === 0) {
        return null;
    }

    const back = backTargetOf(state.step, state.segments, catalog, state.start);

    return (
        <div className="kr-booking__crumbbar">
            {back !== null && (
                <button type="button" className="kr-booking__back" aria-label={text(BACK_LABEL)} disabled={busy} onClick={() => onGo(back)}>
                    <BackIcon />
                </button>
            )}
            <ol className="kr-booking__crumbs" aria-label={text(STRIP_LABEL)} ref={trail}>
                {steps.map((entry, index) => (
                    <li key={entry.step} className="kr-booking__crumb-item">
                        {index > 0 && <Separator />}
                        <button
                            type="button"
                            className="kr-booking__crumb"
                            value={entry.step}
                            disabled={entry.status !== 'finished' || busy}
                            aria-current={entry.status === 'current' ? 'step' : undefined}
                            onClick={() => onGo(entry.step)}
                        >
                            <span className="kr-booking__crumb-name">{text(STRIP_NAMES[entry.step])}</span>
                            {entry.status !== 'finished' && <span className="kr-booking__crumb-state">{text(entry.status === 'current' ? STRIP_CURRENT : STRIP_AHEAD)}</span>}
                        </button>
                    </li>
                ))}
                <li className="kr-booking__crumb-item">
                    <Separator />
                    <span className="kr-booking__crumb-end">{text(STRIP_CONFIRM)}</span>
                </li>
            </ol>
        </div>
    );
}

/**
 * The scroll offset that centres a crumb in the trail, never below zero; the browser clamps the
 * other end to what the trail can scroll.
 */
function offsetToCentre(list: HTMLElement, crumb: HTMLElement): number {
    const box = list.getBoundingClientRect();
    const target = crumb.getBoundingClientRect();

    return Math.max(0, target.left - box.left + list.scrollLeft + target.width / 2 - box.width / 2);
}

function Separator(): ReactNode {
    return (
        <span className="kr-booking__crumb-sep" aria-hidden="true">
            <OnwardIcon />
        </span>
    );
}
