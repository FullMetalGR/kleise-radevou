import { useQuery } from '@tanstack/react-query';
import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode, type RefObject } from 'react';
import type { Api, Notice } from './api';
import { windowOf } from './availability';
import { CLOSE_LABEL, moveAvailabilityQuery, MOVING_SENTENCE, PICK_SENTENCE } from './move';
import { NoticeRegion } from './NoticeRegion';
import { SlotList, useListNotices } from './WhenStep';
import { useWords } from './words';

/*
 * The reschedule dialog: a real dialog, role dialog and aria-modal, labelled by its own heading,
 * whose focusable container takes the focus when it opens, so a screen reader starts at the
 * heading rather than at a first control.
 *
 * The list inside it is the when step's, the same SlotList over the move availability query,
 * windowed by the same seven days from the tenant's today, which the manage view hands down:
 * the dialog reads the browser clock for nothing. The window and the selected day are the
 * dialog's own and die with it; the list fills the selected day as it does on the step.
 *
 * The first region holds the move's progress line while one flies, else its refusal, else the
 * read's failure when there is no list at all, classified by 4.1 and rendered here, inside the
 * dialog: it never becomes the neutral panel of 6.1, which classifies the booking read alone.
 * While a move flies the pickers are not rendered, so the progress line stands in their place
 * and nothing can be pressed but Close.
 *
 * Close is always rendered and never disabled: the request has no timeout and a customer must
 * never be trapped behind it. The open state, the close and the success are the manage view's
 * (useReschedule); this component reports the press and the pick and decides nothing else.
 *
 * The keyboard half of a modal is here too (7.5). Escape is Close by another name, the same
 * onClose, and it stops there, so one press peels back exactly one layer and a listener on the
 * host page hears nothing. Tab cycles within the dialog with the container as the first stop, so
 * Shift+Tab from the container wraps to the last control rather than escaping onto the page.
 * Returning the focus to the trigger once the dialog is gone is the manage view's, the one owner
 * for every way out.
 */

interface RescheduleDialogProps {
    readonly api: Api;
    readonly uuid: string | null;
    readonly token: string | null;
    readonly timezone: string;
    /** The tenant's today, the first day of the first window. */
    readonly today: string;
    readonly moving: boolean;
    readonly refusal: Notice | null;
    readonly onPick: (start: string) => void;
    readonly onClose: () => void;
}

const MOVING_NOTICE: Notice = { key: MOVING_SENTENCE, tone: 'polite' };

let dialogs = 0;

export function RescheduleDialog({ api, uuid, token, timezone, today, moving, refusal, onPick, onClose }: RescheduleDialogProps): ReactNode {
    const { text } = useWords();
    const [headingId] = useState(() => `kr-booking-dialog-${(dialogs += 1)}`);
    const [window, setWindow] = useState(0);
    const [selectedDay, setSelectedDay] = useState<string | null>(null);
    const { container, arm } = useArmedFocus();

    const { from, to } = windowOf(today, window);
    const read = useQuery(moveAvailabilityQuery(api, uuid, token, from, to));
    const availability = { data: read.data, error: read.error, isFetching: read.isFetching };
    const { primary, stale } = useListNotices(availability, moving ? MOVING_NOTICE : refusal);

    function pick(start: string): void {
        arm();
        onPick(start);
    }

    return (
        <div
            className="kr-booking__dialog"
            role="dialog"
            aria-modal={true}
            aria-labelledby={headingId}
            tabIndex={-1}
            ref={container}
            aria-busy={availability.isFetching || moving}
            onKeyDown={(event) => keepInside(event, onClose)}
        >
            <h3 id={headingId} className="kr-booking__subtitle kr-booking__dialog-title">
                {text(PICK_SENTENCE)}
            </h3>
            <NoticeRegion notice={primary} />
            <NoticeRegion notice={stale} />
            {!moving && (
                <SlotList
                    timezone={timezone}
                    availability={availability}
                    window={window}
                    selectedDay={selectedDay}
                    onWindow={(index) => setWindow(Math.max(0, index))}
                    onSelectDay={setSelectedDay}
                    onPick={pick}
                />
            )}
            <div className="kr-booking__actions">
                <button type="button" className="kr-booking__button kr-booking__close" onClick={onClose}>
                    {text(CLOSE_LABEL)}
                </button>
            </div>
        </div>
    );
}

/**
 * What a Tab can land on. The dialog holds buttons alone today; the rest is here so that a control
 * added later is not silently skipped by the cycle.
 */
const TABBABLE = 'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Escape closes and stops propagating; Tab and Shift+Tab step through the container and then its
 * controls in document order, wrapping at both ends. Every Tab is moved here rather than left to
 * the browser, so the cycle is one rule and not the browser's order plus two corrections.
 */
function keepInside(event: KeyboardEvent<HTMLDivElement>, onClose: () => void): void {
    if (event.key === 'Escape') {
        event.stopPropagation();
        onClose();

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const dialog = event.currentTarget;
    const cycle: HTMLElement[] = [dialog, ...Array.from(dialog.querySelectorAll<HTMLElement>(TABBABLE))];
    const at = cycle.findIndex((element) => element === event.target);
    const step = event.shiftKey ? cycle.length - 1 : 1;

    event.preventDefault();
    cycle[(at + step) % cycle.length]?.focus();
}

/**
 * Focus follows the customer (7.4): the dialog mounts only on the press that opened it, and a
 * pick unmounts the control that held the focus, so both land it on the container. The flag
 * starts armed for the mount; a pick arms it again and the effect after that render consumes it.
 */
function useArmedFocus(): { readonly container: RefObject<HTMLDivElement | null>; readonly arm: () => void } {
    const container = useRef<HTMLDivElement>(null);
    const armed = useRef(true);

    useEffect(() => {
        if (armed.current) {
            armed.current = false;
            container.current?.focus();
        }
    });

    return {
        container,
        arm: () => {
            armed.current = true;
        },
    };
}
